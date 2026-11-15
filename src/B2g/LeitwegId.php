<?php
namespace Invoqk\B2g;

/**
 * Shape check for a Leitweg-ID (buyer routing ID for German public-sector e-invoices):
 * coarse[-fine]-check, coarse 2-12 digits, fine 1-30 of A-Z0-9, check 2 digits.
 * The check number is NOT computed, only its shape. Empty input is valid (field is optional).
 * Pure: no I/O.
 *
 * Error codes of check(), tested in this order on the normalised value:
 *   chars  -> anything besides A-Z, 0-9 and "-" after normalising (underscores, punctuation, non-ASCII letters)
 *   parts  -> not 2 or 3 "-" separated parts, empty part, non-digit coarse, or check not exactly 2 digits
 *   length -> coarse not 2-12 digits, or fine not 1-30 characters (checked before the check part)
 */
final class LeitwegId {
	/** Map dash look-alikes to "-", drop invisible characters and whitespace around hyphens or at the ends, turn other spaces into "-", ASCII upper-case. */
	public static function normalise( string $raw ): string {
		$s = preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $raw ) ?? $raw;
		$s = preg_replace( '/[\x{00AD}\x{200B}\x{FEFF}]+/u', '', $s ) ?? $s;
		// No whitespace in a real ID: drop it around hyphens and at the ends; a space between
		// parts is a pasted separator and becomes "-".
		$s = preg_replace( '/[\s\x{00A0}]*-[\s\x{00A0}]*/u', '-', trim( $s, " \t\n\r\0\x0B" ) ) ?? $s;
		$s = preg_replace( '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $s ) ?? $s;
		$s = preg_replace( '/[\s\x{00A0}]+/u', '-', $s ) ?? $s;
		// ASCII only: mb_strtoupper would turn "ß" into "SS" and let it pass.
		return strtoupper( $s );
	}

	/** @return ?string null when plausible, else chars|length|parts. */
	public static function check( string $raw ): ?string {
		$s = self::normalise( $raw );
		if ( '' === $s ) {
			return null;
		}
		if ( ! preg_match( '/\A[A-Z0-9-]+\z/', $s ) ) {
			return 'chars';
		}
		$p = explode( '-', $s );
		$n = count( $p );
		if ( $n < 2 || $n > 3 || in_array( '', $p, true ) || ! ctype_digit( $p[0] ) ) {
			return 'parts';
		}
		$fine = 3 === $n ? $p[1] : null;
		if ( strlen( $p[0] ) < 2 || strlen( $p[0] ) > 12 || ( null !== $fine && strlen( $fine ) > 30 ) ) {
			return 'length';
		}
		return preg_match( '/\A[0-9]{2}\z/', $p[ $n - 1 ] ) ? null : 'parts';
	}

	public static function message( string $code ): string {
		switch ( $code ) {
			case 'chars':
				return __( 'The Leitweg-ID may only contain letters, digits and hyphens, for example 04011000-12345-67.', 'invoqk' );
			case 'length':
				return __( 'The first part of the Leitweg-ID needs 2 to 12 digits and the optional middle part at most 30 characters, for example 04011000-12345-67.', 'invoqk' );
			default:
				return __( 'The Leitweg-ID must look like 04011000-12345-67: digits, an optional middle part, and a two-digit check number, separated by hyphens.', 'invoqk' );
		}
	}
}
