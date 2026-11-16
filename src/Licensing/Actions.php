<?php
namespace Invoqk\Licensing;

use Invoqk\Jobs\Scheduler;
use Invoqk\Validation\Client;

/** Every license change the plugin makes, each one a call to the license API. A failed call never wipes a working license. */
final class Actions {
	public const PENDING       = 'invoqk_checkout_pending';
	public const TRIAL_PENDING = 'invoqk_trial_pending';
	private const PLANS  = [ 'pro', 'pro3', 'agency' ];
	private const POLL   = 300;
	private const POLL_FOR = 3 * 86400;

	public static function boot(): void {
		add_action( 'admin_post_invoqk_license', [ self::class, 'post' ] );
		add_action( Scheduler::SWEEP, static fn() => self::refreshIfStale( time() ) );
	}

	/** admin-post entry: capability and nonce first, then redirect (the provider checkout URL is external). */
	public static function post(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'invoqk_license' );
		$url = self::handle( $_POST, time() ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		str_starts_with( $url, admin_url() ) ? wp_safe_redirect( $url ) : wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- provider URL from our own API
		exit;
	}

	/** @return string where to send the browser: the settings page with a result code, or the checkout URL */
	public static function handle( array $post, int $now, ?callable $transport = null ): string {
		$act = sanitize_key( (string) ( $post['license_action'] ?? '' ) );
		switch ( $act ) {
			case 'trial':
				$r = self::trial( sanitize_text_field( wp_unslash( (string) ( $post['email'] ?? '' ) ) ), $now, $transport );
				break;
			case 'trial_confirm':
				$r = self::trialConfirm( sanitize_text_field( wp_unslash( (string) ( $post['code'] ?? '' ) ) ), $now, $transport );
				break;
			case 'activate':
				$r = self::activate( sanitize_text_field( wp_unslash( (string) ( $post['key'] ?? '' ) ) ), $now, $transport );
				break;
			case 'deactivate':
				$r = self::deactivate( $now, $transport );
				break;
			case 'checkout':
				$r = self::checkout( sanitize_key( (string) ( $post['plan'] ?? '' ) ), $now, $transport );
				if ( $r['ok'] ) {
					return (string) $r['url'];
				}
				break;
			case 'portal':
				$r = self::portal( $transport );
				if ( $r['ok'] ) {
					return (string) $r['url'];
				}
				break;
			default:
				$r = self::fail( 'unknown' );
		}
		$flash = $r['ok'] ? ( 'trial' === $act ? 'check_email' : 'ok' ) : (string) $r['error'];
		return admin_url( 'admin.php?page=invoqk' ) . '&invoqk_license=' . $flash;
	}

	/** @return array{ok:bool,error:?string} */
	public static function trial( string $email, int $now, ?callable $transport = null ): array {
		$email = strtolower( trim( $email ) );
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return self::fail( 'invalid_email' );
		}
		$res = Api::call( 'trial', [ 'email' => $email, 'domain' => Api::domain() ], false, $transport );
		if ( 202 !== $res['http'] ) {
			return self::fail( Api::error( $res ) );
		}
		update_option( self::TRIAL_PENDING, $email, false ); // the code arrives by email; trialConfirm finishes it
		return [ 'ok' => true, 'error' => null ];
	}

	/** @return array{ok:bool,error:?string} */
	public static function trialConfirm( string $code, int $now, ?callable $transport = null ): array {
		$email = (string) get_option( self::TRIAL_PENDING, '' );
		if ( '' === $email ) {
			return self::fail( 'no_pending' );
		}
		$code = trim( $code );
		if ( 1 !== preg_match( '/^\d{6}$/', $code ) ) {
			return self::fail( 'invalid_code' );
		}
		$res = Api::call( 'trial/confirm', [ 'email' => $email, 'domain' => Api::domain(), 'code' => $code ], false, $transport );
		$out = self::install( $res, $now );
		// A wrong code can be retried; any other answer ends this attempt.
		if ( $out['ok'] || ! in_array( $out['error'], [ 'invalid_code', 'unreachable', 'unknown', 'rate_limited' ], true ) ) {
			delete_option( self::TRIAL_PENDING );
		}
		return $out;
	}

	/** @return array{ok:bool,error:?string} */
	public static function activate( string $key, int $now, ?callable $transport = null ): array {
		$key = trim( $key );
		if ( '' === $key ) {
			return self::fail( 'invalid_key' );
		}
		// The license key is the account API key: it authenticates the call and, once accepted, becomes this shop's key.
		return self::install( Api::call( 'activate', [], true, $transport, $key ), $now, $key );
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
		if ( 200 === $res['http'] && 'free' === ( $res['body']['plan'] ?? null ) ) {
			License::clear(); // the account has nothing licensed any more (trial ended, subscription over)
			delete_option( self::PENDING );
			return [ 'ok' => true, 'error' => null ];
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
		if ( ! is_string( $url ) || 'https' !== parse_url( $url, PHP_URL_SCHEME ) ) { // the browser is redirected here: never a javascript: or http: URL
			return self::fail( 200 === $res['http'] ? 'unknown' : Api::error( $res ) );
		}
		update_option( self::PENDING, $now, false ); // the purchase arrives by webhook; poll until it does
		return [ 'ok' => true, 'error' => null, 'url' => $url ];
	}

	/** Customer portal link (card, invoices, cancel) of the paid license. A trial has none.
	 * @return array{ok:bool,error:?string,url?:string}
	 */
	public static function portal( ?callable $transport = null ): array {
		if ( '' === Client::apiKey() ) {
			return self::fail( 'no_key' );
		}
		$res = Api::call( 'portal', [], true, $transport );
		$url = 200 === $res['http'] ? ( $res['body']['url'] ?? null ) : null;
		if ( ! is_string( $url ) || 'https' !== parse_url( $url, PHP_URL_SCHEME ) ) { // the browser is redirected here: never a javascript: or http: URL
			return self::fail( 200 === $res['http'] ? 'unknown' : Api::error( $res ) );
		}
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
	private static function install( array $res, int $now, ?string $enteredKey = null ): array {
		if ( 200 === $res['http'] && null !== $enteredKey && 'free' === ( $res['body']['plan'] ?? null ) ) {
			update_option( 'invoqk_api_key', $enteredKey, false ); // the key works for the free tier; it just carries no plan
			update_option( 'invoqk_key_changed_at', $now );
			return self::fail( 'no_license' );
		}
		$license = 200 === $res['http'] && is_array( $res['body'] ) ? License::fromResponse( $res['body'], $now ) : null;
		if ( null === $license ) {
			return self::fail( 200 === $res['http'] ? 'unknown' : Api::error( $res ) );
		}
		License::save( $license );
		$siteKey = $enteredKey ?? $res['body']['api_key'] ?? '';
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
