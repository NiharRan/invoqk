<?php
namespace Invoqk\Validation;

use Invoqk\Licensing\License;

/** Pro gate. Validation itself is free and unlimited. */
final class Limit {
	public const DEFAULT_UPGRADE_URL = 'https://invoqk.com/plugin'; // plans and the free trial

	/** Pro screens (license, trial, pricing, archive) stay hidden until a release defines INVOQK_PRO_UI as true. */
	public static function proUi(): bool {
		return (bool) apply_filters( 'invoqk_pro_ui', defined( 'INVOQK_PRO_UI' ) && INVOQK_PRO_UI );
	}

	public static function isPro(): bool {
		return (bool) apply_filters( 'invoqk_is_pro', License::isActive() );
	}

	public static function upgradeUrl(): string {
		return defined( 'INVOQK_UPGRADE_URL' ) ? (string) INVOQK_UPGRADE_URL : self::DEFAULT_UPGRADE_URL;
	}
}
