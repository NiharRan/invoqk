<?php
namespace Invoqk\Licensing;

use Invoqk\Validation\Client;

/** Every license change the plugin makes, each one a call to the license API. A failed call never wipes a working license. */
final class Actions {
	public const PENDING = 'invoqk_checkout_pending';
	private const PLANS  = [ 'pro', 'pro3', 'agency' ];
	private const POLL   = 300;
	private const POLL_FOR = 3 * 86400;

	/** @return array{ok:bool,error:?string} */
	public static function trial( int $now, ?callable $transport = null ): array {
		return self::install( Api::call( 'trial', [ 'domain' => Api::domain() ], false, $transport ), $now );
	}

	/** @return array{ok:bool,error:?string} */
	public static function activate( string $key, int $now, ?callable $transport = null ): array {
		$key = trim( $key );
		if ( '' === $key ) {
			return self::fail( 'invalid_key' );
		}
		return self::install( Api::call( 'activate', [ 'key' => $key, 'domain' => Api::domain() ], false, $transport ), $now );
	}

	/** @return array{ok:bool,error:?string} */
	public static function refresh( int $now, ?callable $transport = null ): array {
		if ( '' === Client::apiKey() ) {
			return self::fail( 'no_key' );
		}
		$res = Api::call( 'check', [ 'domain' => Api::domain() ], true, $transport );
		if ( 401 === $res['http'] ) {
			License::clear();
			return self::fail( 'revoked' );
		}
		$license = 200 === $res['http'] && is_array( $res['body'] ) ? License::fromResponse( $res['body'], $now ) : null;
		if ( null === $license ) {
			// Keep the plan; the grace window in License::isActive covers a server outage.
			License::save( [ 'checked_at' => $now, 'check_failed' => true ] + License::get() );
			return self::fail( 200 === $res['http'] ? 'unknown' : Api::error( $res ) );
		}
		$license['key'] = '' !== $license['key'] ? $license['key'] : License::get()['key'];
		License::save( $license );
		delete_option( self::PENDING );
		return [ 'ok' => true, 'error' => null ];
	}

	/** @return array{ok:bool,error:?string} */
	public static function deactivate( int $now, ?callable $transport = null ): array {
		if ( '' === Client::apiKey() ) {
			License::clear();
			return [ 'ok' => true, 'error' => null ];
		}
		$res = Api::call( 'deactivate', [ 'domain' => Api::domain() ], true, $transport );
		if ( ! in_array( $res['http'], [ 200, 401 ], true ) ) {
			return self::fail( Api::error( $res ) );
		}
		License::clear();
		delete_option( 'invoqk_api_key' );
		return [ 'ok' => true, 'error' => null ];
	}

	/** @return array{ok:bool,error:?string,url?:string} */
	public static function checkout( string $plan, int $now, ?callable $transport = null ): array {
		if ( ! in_array( $plan, self::PLANS, true ) ) {
			return self::fail( 'unknown' );
		}
		if ( '' === Client::apiKey() ) {
			return self::fail( 'no_key' );
		}
		$res = Api::call( 'checkout', [ 'plan' => $plan ], true, $transport );
		$url = 200 === $res['http'] ? ( $res['body']['url'] ?? null ) : null;
		if ( ! is_string( $url ) || '' === $url ) {
			return self::fail( 200 === $res['http'] ? 'unknown' : Api::error( $res ) );
		}
		update_option( self::PENDING, $now, false ); // the purchase arrives by webhook; poll until it does
		return [ 'ok' => true, 'error' => null, 'url' => $url ];
	}

	/** Daily for a known license; every 5 minutes for 3 days after a checkout started. */
	public static function refreshIfStale( int $now, ?callable $transport = null ): void {
		if ( '' === Client::apiKey() ) {
			return;
		}
		$pending = (int) get_option( self::PENDING, 0 );
		$polling = $pending > 0 && $now - $pending < self::POLL_FOR;
		$l       = License::get();
		if ( ! $polling && 'free' === $l['plan'] ) {
			return;
		}
		$last = $polling ? max( $l['checked_at'], $pending ) : $l['checked_at'];
		if ( $now - $last >= ( $polling ? self::POLL : License::STALE ) ) {
			self::refresh( $now, $transport );
		}
	}

	/** @return array{ok:bool,error:?string} */
	private static function install( array $res, int $now ): array {
		$license = 200 === $res['http'] && is_array( $res['body'] ) ? License::fromResponse( $res['body'], $now ) : null;
		if ( null === $license ) {
			return self::fail( 200 === $res['http'] ? 'unknown' : Api::error( $res ) );
		}
		License::save( $license );
		$siteKey = $res['body']['api_key'] ?? '';
		if ( is_string( $siteKey ) && '' !== $siteKey ) {
			update_option( 'invoqk_api_key', $siteKey, false );
			update_option( 'invoqk_key_changed_at', $now ); // lets the sweep retry 401/403 records
		}
		return [ 'ok' => true, 'error' => null ];
	}

	/** @return array{ok:bool,error:?string} */
	private static function fail( string $error ): array {
		return [ 'ok' => false, 'error' => $error ];
	}
}
