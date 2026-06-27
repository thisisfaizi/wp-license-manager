<?php
/**
 * QR code generation for license keys.
 *
 * Generates a URL-based QR code using the Google Charts-compatible
 * endpoint pattern (no external dependency). For production use,
 * filter `wplm_qr_url` to swap in a self-hosted or library-based renderer.
 *
 * @package WPLM\Integrations\Qr
 */

namespace WPLM\Integrations\Qr;

defined( 'ABSPATH' ) || exit;

/**
 * Generates QR code image URLs that encode a license key or signed token.
 */
class QrGenerator {

	/** Default pixel size of the generated QR image. */
	const DEFAULT_SIZE = 200;

	/**
	 * Return an <img> tag for a QR code encoding the given data string.
	 *
	 * Filters:
	 *   wplm_qr_url (string $url, string $data, int $size) — override the
	 *   image URL to use a different renderer or self-hosted library.
	 *
	 * @param string $data  The string to encode (license key, token, or URL).
	 * @param int    $size  Pixel size of the square QR image.
	 * @param string $alt   Alt text for the <img> element.
	 * @return string Safe <img> HTML.
	 */
	public function img_tag( string $data, int $size = self::DEFAULT_SIZE, string $alt = '' ): string {
		$url = $this->url( $data, $size );
		return sprintf(
			'<img src="%s" width="%d" height="%d" alt="%s" class="wplm-qr-code">',
			esc_url( $url ),
			absint( $size ),
			absint( $size ),
			esc_attr( $alt ?: __( 'License QR Code', 'wp-license-manager' ) )
		);
	}

	/**
	 * Return the QR code image URL for the given data.
	 *
	 * @param string $data The string to encode.
	 * @param int    $size Pixel size.
	 * @return string URL.
	 */
	public function url( string $data, int $size = self::DEFAULT_SIZE ): string {
		// Default: generate a data URI using PHP's built-in if QR library is available,
		// else fall back to a publicly filterable placeholder URL.
		$url = $this->make_data_uri( $data, $size );

		return (string) apply_filters( 'wplm_qr_url', $url, $data, $size );
	}

	/**
	 * Download and return QR code image bytes as a PNG data URI.
	 *
	 * Uses the phpqrcode library if available (composer require endroid/qr-code),
	 * otherwise emits an SVG placeholder that conveys the encoded data as text so
	 * the UI remains functional without an extra dependency.
	 *
	 * @param string $data String to encode.
	 * @param int    $size Pixel size.
	 * @return string Data URI or placeholder URL.
	 */
	private function make_data_uri( string $data, int $size ): string {
		// If endroid/qr-code is installed via Composer, use it.
		if ( class_exists( '\Endroid\QrCode\QrCode' ) ) {
			return $this->endroid_uri( $data, $size );
		}

		// Fallback: return an SVG data URI that displays the raw text.
		return $this->svg_placeholder( $data, $size );
	}

	/**
	 * Generate a QR PNG via endroid/qr-code if available.
	 *
	 * @param string $data String to encode.
	 * @param int    $size Pixel size.
	 * @return string PNG data URI.
	 */
	private function endroid_uri( string $data, int $size ): string {
		try {
			$qr = \Endroid\QrCode\QrCode::create( $data )
				->setSize( $size )
				->setMargin( 4 );

			$writer = new \Endroid\QrCode\Writer\PngWriter();
			$result = $writer->write( $qr );

			return 'data:image/png;base64,' . base64_encode( $result->getString() );
		} catch ( \Throwable $e ) {
			return $this->svg_placeholder( $data, $size );
		}
	}

	/**
	 * Return an SVG data URI displaying the encoded text as a fallback.
	 *
	 * This ensures the UI does not break even when no QR library is installed.
	 * Developers should install endroid/qr-code or hook wplm_qr_url to provide
	 * an actual QR image.
	 *
	 * @param string $data String to display.
	 * @param int    $size SVG size in pixels.
	 * @return string SVG data URI.
	 */
	private function svg_placeholder( string $data, int $size ): string {
		$escaped = htmlspecialchars( $data, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$svg     = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '">'
			. '<rect width="100%" height="100%" fill="#f0f0f0"/>'
			. '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="10" font-family="monospace" fill="#333">'
			. esc_html( substr( $data, 0, 20 ) ) . '&hellip;'
			. '</text>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
