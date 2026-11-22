<?php
namespace Invoqk\Validation;

use Invoqk\Licensing\License;

/** Pro gate. Validation itself is free and unlimited. */
final class Limit {
	public const DEFAULT_UPGRADE_URL = 'https://invoqk.com/plugin'; // plans and the free trial

	public static function isPro(): bool {
		return (bool) apply_filters( 'invoqk_is_pro', License::isActive() );
	}

	public static function upgradeUrl(): string {
		return defined( 'INVOQK_UPGRADE_URL' ) ? (string) INVOQK_UPGRADE_URL : self::DEFAULT_UPGRADE_URL;
	}
}
