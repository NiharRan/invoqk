<?php
namespace Invoqk\Onboarding;

/** Onboarding wizard state, turnover band and activation time. All options autoload off. */
final class State {
	private const STATES      = [ 'new', 'skipped', 'running', 'done' ];
	private const STALE_AFTER = 600;

	public static function get(): string {
		$s = get_option( 'invoqk_onboarding', 'new' );
		$s = in_array( $s, self::STATES, true ) ? $s : 'new';
		if ( 'running' === $s ) {
			$r = self::firstReport();
			if ( $r && time() - $r['startedAt'] > self::STALE_AFTER ) {
				return 'done';
			}
		}
		return $s;
	}

	public static function set( string $s ): void {
		if ( in_array( $s, self::STATES, true ) ) {
			update_option( 'invoqk_onboarding', $s, false );
		}
	}

	public static function turnover(): ?string {
		$t = get_option( 'invoqk_turnover', '' );
		return in_array( $t, [ 'over', 'under' ], true ) ? $t : null;
	}

	public static function setTurnover( string $band ): bool {
		if ( ! in_array( $band, [ 'over', 'under' ], true ) ) {
			return false;
		}
		update_option( 'invoqk_turnover', $band, false );
		return true;
	}

	public static function deadline(): ?string {
		$t = self::turnover();
		return null === $t ? null : ( 'over' === $t ? '1.1.2027' : '1.1.2028' );
	}

	public static function activatedAt(): int {
		$v = (int) get_option( 'invoqk_activated_at', 0 );
		if ( $v <= 0 ) {
			$v = time();
			add_option( 'invoqk_activated_at', $v, '', false );
		}
		return $v;
	}

	/** @return array{ids:int[],startedAt:int}|null */
	public static function firstReport(): ?array {
		$r = get_option( 'invoqk_first_report', null );
		if ( ! is_array( $r ) || ! isset( $r['ids'], $r['startedAt'] ) || ! is_array( $r['ids'] ) ) {
			return null;
		}
		return [ 'ids' => array_map( 'intval', $r['ids'] ), 'startedAt' => (int) $r['startedAt'] ];
	}
}
