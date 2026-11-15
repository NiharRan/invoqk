<?php
namespace Invoqk\Validation;

use Invoqk\Licensing\License;
use Invoqk\Onboarding\State;

/** Free tier: 20 distinct orders may be validated. The only writer of invoqk_free_slots. */
final class Limit {
	public const MAX = 20;
	public const DEFAULT_UPGRADE_URL = 'https://invoqk.com/plugin'; // plans and the free trial
	private const OPTION = 'invoqk_free_slots';

	public static function isPro(): bool {
		return (bool) apply_filters( 'invoqk_is_pro', License::isActive() );
	}

	public static function upgradeUrl(): string {
		return defined( 'INVOQK_UPGRADE_URL' ) ? (string) INVOQK_UPGRADE_URL : self::DEFAULT_UPGRADE_URL;
	}

	public static function allow( int $orderId ): bool {
		if ( self::isPro() ) {
			return true;
		}
		if ( in_array( $orderId, State::firstReport()['ids'] ?? [], true ) ) {
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
		// shortcut: options are not locked, two concurrent first validations can both take slot 20 and give 21; upgrade to an atomic counter if abuse matters
		$slots[] = $orderId;
		update_option( self::OPTION, $slots );
		return true;
	}
}
