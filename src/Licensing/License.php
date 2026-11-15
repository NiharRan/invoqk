<?php
namespace Invoqk\Licensing;

/** The one stored license state. Plan `free` means no license; every other plan is active until valid_until. */
final class License {
	public const OPTION = 'invoqk_license';
	public const GRACE  = 604800; // 7 days a paid shop stays Pro when the server cannot be reached
	public const STALE  = 86400;
	private const PAID  = [ 'trial', 'pro', 'pro3', 'agency' ];

	/** @return array{plan:string,valid_until:int,seats_used:int,seats_max:int,key:string,checked_at:int,check_failed:bool,trial_used:bool} */
	public static function get(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];
		$plan = (string) ( $raw['plan'] ?? 'free' );
		return [
			'plan'         => 'free' === $plan || in_array( $plan, self::PAID, true ) ? $plan : 'free',
			'valid_until'  => (int) ( $raw['valid_until'] ?? 0 ),
			'seats_used'   => (int) ( $raw['seats_used'] ?? 0 ),
			'seats_max'    => (int) ( $raw['seats_max'] ?? 0 ),
			'key'          => (string) ( $raw['key'] ?? '' ),
			'checked_at'   => (int) ( $raw['checked_at'] ?? 0 ),
			'check_failed' => (bool) ( $raw['check_failed'] ?? false ),
			'trial_used'   => (bool) ( $raw['trial_used'] ?? false ),
		];
	}

	public static function save( array $license ): void {
		update_option( self::OPTION, $license, false );
	}

	/** Back to free; remembers that the trial was used. */
	public static function clear(): void {
		self::save( [ 'trial_used' => self::get()['trial_used'] ] + self::defaults() );
	}

	/** @return ?array the normalized license, null when the server body is not a usable license */
	public static function fromResponse( array $body, int $now ): ?array {
		$plan = $body['plan'] ?? null;
		$until = $body['valid_until'] ?? null;
		if ( ! is_string( $plan ) || ! in_array( $plan, self::PAID, true ) || ! is_int( $until ) ) {
			return null;
		}
		return [
			'plan'         => $plan,
			'valid_until'  => $until,
			'seats_used'   => (int) ( $body['seats_used'] ?? 0 ),
			'seats_max'    => (int) ( $body['seats_max'] ?? 0 ),
			'key'          => (string) ( $body['key'] ?? '' ),
			'checked_at'   => $now,
			'check_failed' => false,
			'trial_used'   => 'trial' === $plan || self::get()['trial_used'],
		];
	}

	public static function isActive( ?int $now = null ): bool {
		return self::activeAt( self::get(), $now ?? time() );
	}

	public static function activeAt( array $l, int $now ): bool {
		return 'free' !== $l['plan'] && $now < $l['valid_until'] + ( $l['check_failed'] ? self::GRACE : 0 );
	}

	public static function daysLeft( int $now, ?array $l = null ): int {
		return max( 0, (int) ceil( ( ( $l ?? self::get() )['valid_until'] - $now ) / 86400 ) );
	}

	private static function defaults(): array {
		return [ 'plan' => 'free', 'valid_until' => 0, 'seats_used' => 0, 'seats_max' => 0, 'key' => '', 'checked_at' => 0, 'check_failed' => false ];
	}
}
