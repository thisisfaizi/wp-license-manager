<?php
/**
 * The setup sheet a customer receives with their order.
 *
 * @package WPLM\Support
 */

namespace WPLM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a one-page PDF holding everything an office needs to start: its licence key, the address
 * its phones connect to, and the command that connects the office PC.
 *
 * Hand-written rather than pulled from a PDF library, because the plugin ships without a `vendor/`
 * directory and a one-page text sheet needs none of what a library provides. It uses only the two
 * fonts every PDF reader has built in, so nothing is embedded and the file stays a few kilobytes.
 */
class SetupPdf {

	private const PAGE_WIDTH  = 595;
	private const PAGE_HEIGHT = 842;
	private const MARGIN      = 56;

	/** Courier glyphs are 0.6 em wide, which is what makes wrapping predictable. */
	private const MONO_WIDTH_RATIO = 0.6;

	/** @var array<int, array{text:string, font:string, size:int, gap:int}> */
	private array $lines = array();

	/**
	 * Add a heading.
	 *
	 * @param string $text Heading text.
	 * @return self
	 */
	public function heading( string $text ): self {
		$this->lines[] = array(
			'text' => $text,
			'font' => 'F1',
			'size' => 17,
			'gap'  => 30,
		);
		return $this;
	}

	/**
	 * Add a label above a value.
	 *
	 * @param string $text Label text.
	 * @return self
	 */
	public function label( string $text ): self {
		$this->lines[] = array(
			'text' => $text,
			'font' => 'F1',
			'size' => 10,
			'gap'  => 16,
		);
		return $this;
	}

	/**
	 * Add a line of ordinary prose.
	 *
	 * @param string $text The text.
	 * @return self
	 */
	public function text( string $text ): self {
		$this->lines[] = array(
			'text' => $text,
			'font' => 'F3',
			'size' => 10,
			'gap'  => 15,
		);
		return $this;
	}

	/**
	 * Add a value to be copied exactly — a key, an address, a command.
	 *
	 * Set in Courier and wrapped at the page width, because a connector token runs to a couple of
	 * hundred characters and a value the customer cannot read in full is worse than none.
	 *
	 * @param string $value The value.
	 * @return self
	 */
	public function value( string $value ): self {
		$size    = 10;
		$perline = (int) floor( ( self::PAGE_WIDTH - ( 2 * self::MARGIN ) ) / ( $size * self::MONO_WIDTH_RATIO ) );

		foreach ( str_split( $value, max( 8, $perline ) ) as $chunk ) {
			$this->lines[] = array(
				'text' => $chunk,
				'font' => 'F2',
				'size' => $size,
				'gap'  => 14,
			);
		}

		$this->lines[] = array(
			'text' => '',
			'font' => 'F3',
			'size' => 10,
			'gap'  => 10,
		);
		return $this;
	}

	/** Add vertical space. */
	public function space(): self {
		$this->lines[] = array(
			'text' => '',
			'font' => 'F3',
			'size' => 10,
			'gap'  => 12,
		);
		return $this;
	}

	/**
	 * Render the PDF.
	 *
	 * @return string The file's bytes.
	 */
	public function render(): string {
		$content = $this->content_stream();

		$objects = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . ']'
				. ' /Resources << /Font << /F1 5 0 R /F2 6 0 R /F3 7 0 R >> >> /Contents 4 0 R >>',
			4 => '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream",
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
			6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
			7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		);

		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $objects as $number => $body ) {
			$offsets[ $number ] = strlen( $pdf );
			$pdf               .= $number . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_at = strlen( $pdf );
		$count   = count( $objects ) + 1;

		$pdf .= "xref\n0 " . $count . "\n";
		$pdf .= "0000000000 65535 f \n";

		foreach ( $objects as $number => $body ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $number ] );
		}

		$pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref_at . "\n%%EOF";

		return $pdf;
	}

	/**
	 * The page's drawing instructions.
	 *
	 * @return string
	 */
	private function content_stream(): string {
		$y   = self::PAGE_HEIGHT - self::MARGIN;
		$out = "BT\n";

		foreach ( $this->lines as $line ) {
			$y -= $line['gap'];

			if ( '' !== $line['text'] ) {
				$out .= '/' . $line['font'] . ' ' . $line['size'] . " Tf\n";
				$out .= '1 0 0 1 ' . self::MARGIN . ' ' . $y . " Tm\n";
				$out .= '(' . self::escape( $line['text'] ) . ") Tj\n";
			}
		}

		return $out . 'ET';
	}

	/**
	 * Escape a string for a PDF literal, and drop anything the built-in fonts cannot draw.
	 *
	 * @param string $text The text.
	 * @return string
	 */
	private static function escape( string $text ): string {
		$ascii = preg_replace( '/[^\x20-\x7E]/', '', $text );

		return str_replace(
			array( '\\', '(', ')' ),
			array( '\\\\', '\\(', '\\)' ),
			(string) $ascii
		);
	}
}
