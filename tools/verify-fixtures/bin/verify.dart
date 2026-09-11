// Verifies WPLM's Super Ledger contract fixtures with a Dart Ed25519 implementation (M5-27a §9).
//
//   dart run bin/verify.dart <fixtures-dir>
//
// Exit 0 when every case's signature verifies exactly when cases.json says it should, and every
// accepted token has the v2 shape the app decodes (JSON maps for modules and limits). Exit 1 otherwise.
import 'dart:convert';
import 'dart:io';

import 'package:cryptography/cryptography.dart';

Future<void> main(List<String> args) async {
  if (args.length != 1) {
    stderr.writeln('usage: dart run bin/verify.dart <fixtures-dir>');
    exit(2);
  }
  final dir = args.single;
  final manifest =
      jsonDecode(File('$dir/cases.json').readAsStringSync()) as Map<String, dynamic>;
  final publicKey = SimplePublicKey(
    base64.decode(File('$dir/public_key.txt').readAsStringSync().trim()),
    type: KeyPairType.ed25519,
  );
  final algorithm = Ed25519();

  var failures = 0;
  for (final raw in manifest['cases'] as List<dynamic>) {
    final c = raw as Map<String, dynamic>;
    final name = c['name'] as String;
    final expect = c['expect'] as Map<String, dynamic>;
    final token = File('$dir/${c['file']}').readAsStringSync().trim();

    final dot = token.indexOf('.');
    final body = token.substring(0, dot);
    final signature = base64Url.decode(base64Url.normalize(token.substring(dot + 1)));
    final valid = await algorithm.verify(
      utf8.encode(body),
      signature: Signature(signature, publicKey: publicKey),
    );

    final problems = <String>[];
    if (valid != (expect['signature'] == 'valid')) {
      problems.add('signature verified=$valid but expected ${expect['signature']}');
    }
    if (valid) {
      final payload =
          jsonDecode(utf8.decode(base64Url.decode(base64Url.normalize(body)))) as Map<String, dynamic>;
      if (payload['kind'] == 'extend-check-in') {
        final span = (payload['until'] as int) - (payload['iat'] as int);
        final acceptable = span > 0 && span <= 30 * 86400;
        if (acceptable != (expect['accepted'] == true)) {
          problems.add('notice span ${span}s acceptable=$acceptable but expected accepted=${expect['accepted']}');
        }
      } else {
        if (payload['v'] != 2) problems.add('v is ${payload['v']}');
        if (payload['modules'] is! Map) problems.add('modules is not a JSON map');
        if (payload['limits'] is! Map) problems.add('limits is not a JSON map');
        final pidOk = payload['pid'] == 'super-ledger';
        if (!pidOk && expect['accepted'] != false) problems.add('pid ${payload['pid']} but expected accepted');
        if (pidOk && payload['fp'] is! String) problems.add('fp missing');
      }
    }

    if (problems.isEmpty) {
      stdout.writeln('ok    $name');
    } else {
      failures++;
      stdout.writeln('FAIL  $name: ${problems.join('; ')}');
    }
  }

  stdout.writeln(failures == 0 ? 'All ${(manifest['cases'] as List).length} cases match.' : '$failures case(s) failed.');
  exit(failures == 0 ? 0 : 1);
}
