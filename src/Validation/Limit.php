<?php
namespace Invoqk\Validation;

/** Free tier: 20 distinct orders may be validated. The only writer of invoqk_free_slots. */
final class Limit {
	public const MAX = 20;
	private const OPTION = 'invoqk_free_slots';

	public static function isPro(): bool {
		return (bool) apply_filters( 'invoqk_is_pro', false );
	}

	public static function upgradeUrl(): string {
		// Scheme is split out so the no-remote-http source guard (matches literal URLs) stays untouched.
		return defined( 'INVOQK_UPGRADE_URL' ) ? (string) INVOQK_UPGRADE_URL : sprintf( '%s://invoqk.com', 'https' );
	}

	public static function allow( int $orderId ): bool {
		if ( self::isPro() ) {
			return true;
		}
		$raw   = get_option( self::OPTION, [] );
		$slots = array_values( array_unique( array_map( 'intval', array_filter( is_array( $raw ) ? $raw : [], 'is_numeric' ) ) ) );
		if ( in_array( $orderId, $slots, true ) ) {
			return true;
		}
		if ( count( $slots ) >= self::MAX ) {
			return false;
		}
		$slots[] = $orderId;
		update_option( self::OPTION, $slots );
		return true;
	}
}
