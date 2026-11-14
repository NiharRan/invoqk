<?php
namespace Invoqk;

final class Requirements {
	public const MIN_WP  = '6.5';
	public const MIN_PHP = '8.1';
	public const MIN_WC  = '9.0';

	/** @return string[] human-readable failures, empty when the stack is supported */
	public static function problems( string $wp, string $php, ?string $wc ): array {
		$out = [];
		if ( version_compare( $wp, self::MIN_WP, '<' ) ) {
			$out[] = sprintf( __( 'Invoqk needs WordPress %s or newer.', 'invoqk' ), self::MIN_WP );
		}
		if ( version_compare( $php, self::MIN_PHP, '<' ) ) {
			$out[] = sprintf( __( 'Invoqk needs PHP %s or newer.', 'invoqk' ), self::MIN_PHP );
		}
		if ( null === $wc ) {
			$out[] = sprintf( __( 'Invoqk needs WooCommerce %s or newer, which is not active.', 'invoqk' ), self::MIN_WC );
		} elseif ( version_compare( $wc, self::MIN_WC, '<' ) ) {
			$out[] = sprintf( __( 'Invoqk needs WooCommerce %s or newer.', 'invoqk' ), self::MIN_WC );
		}
		return $out;
	}
}
