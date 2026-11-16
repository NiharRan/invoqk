<?php
namespace Invoqk\Tests\Licensing;

use Invoqk\Licensing\Actions;
use Invoqk\Licensing\License;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase {
	/** @var array<int,array{string,array}> */
	private array $calls = [];
	private array $queue = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [];
		$this->calls = [];
		$this->queue = [];
	}

	private function reply( int $code, array $body ): void {
		$this->queue[] = [ 'response' => [ 'code' => $code ], 'body' => json_encode( (object) $body ) ];
	}

	private function t(): callable {
		return function ( string $url, array $args ) {
			$this->calls[] = [ $url, $args ];
			return array_shift( $this->queue ) ?? new \WP_Error();
		};
	}

	private function lic( array $over = [] ): array {
		return $over + [ 'plan' => 'pro', 'valid_until' => 5000, 'seats_used' => 1, 'seats_max' => 1, 'api_key' => 'sk_new', 'key' => 'LIC-1' ];
	}

	private function seedPro( array $over = [] ): void {
		License::save( $over + [ 'plan' => 'pro', 'valid_until' => 5000, 'seats_used' => 1, 'seats_max' => 1, 'key' => 'LIC-1', 'checked_at' => 100, 'check_failed' => false, 'trial_used' => false ] );
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
	}

	public function test_trial_request_only_remembers_the_email(): void {
		$this->reply( 202, [ 'status' => 'check_email' ] );
		$r = Actions::trial( 'a@b.de', 1000, $this->t() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'a@b.de', $GLOBALS['invoqk_options']['invoqk_trial_pending'] );
		$this->assertSame( 'free', License::get()['plan'] );
		$this->assertStringEndsWith( '/license/trial', $this->calls[0][0] );
	}

	public function test_trial_confirm_saves_license_and_site_key_and_clears_pending(): void {
		$GLOBALS['invoqk_options']['invoqk_trial_pending'] = 'a@b.de';
		$this->reply( 200, $this->lic( [ 'plan' => 'trial', 'key' => '' ] ) );
		$r = Actions::trialConfirm( '123456', 1000, $this->t() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'trial', License::get()['plan'] );
		$this->assertSame( 'sk_new', $GLOBALS['invoqk_options']['invoqk_api_key'] );
		$this->assertArrayNotHasKey( 'invoqk_trial_pending', $GLOBALS['invoqk_options'] );
		$this->assertStringEndsWith( '/license/trial/confirm', $this->calls[0][0] );
		$this->assertSame( '{"email":"a@b.de","domain":"shop.example","code":"123456"}', $this->calls[0][1]['body'] );
	}

	public function test_trial_confirm_wrong_code_keeps_pending_and_changes_nothing(): void {
		$GLOBALS['invoqk_options']['invoqk_trial_pending'] = 'a@b.de';
		$this->reply( 400, [ 'error' => 'invalid_code' ] );
		$this->assertSame( 'invalid_code', Actions::trialConfirm( '000000', 1000, $this->t() )['error'] );
		$this->assertSame( 'a@b.de', $GLOBALS['invoqk_options']['invoqk_trial_pending'] );
		$this->assertSame( 'free', License::get()['plan'] );
	}

	public function test_trial_confirm_needs_pending_email_and_six_digits(): void {
		$this->assertSame( 'no_pending', Actions::trialConfirm( '123456', 1000, $this->t() )['error'] );
		$GLOBALS['invoqk_options']['invoqk_trial_pending'] = 'a@b.de';
		$this->assertSame( 'invalid_code', Actions::trialConfirm( '12', 1000, $this->t() )['error'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_trial_confirm_final_errors_drop_pending(): void {
		foreach ( [ 'trial_used', 'account_exists' ] as $err ) {
			$GLOBALS['invoqk_options']['invoqk_trial_pending'] = 'a@b.de';
			$this->reply( 409, [ 'error' => $err ] );
			$this->assertSame( $err, Actions::trialConfirm( '123456', 1000, $this->t() )['error'] );
			$this->assertArrayNotHasKey( 'invoqk_trial_pending', $GLOBALS['invoqk_options'] );
		}
	}

	public function test_trial_used_changes_nothing(): void {
		$this->seedPro();
		$this->reply( 409, [ 'error' => 'trial_used' ] );
		$r = Actions::trial( 'a@b.de', 1000, $this->t() );
		$this->assertSame( [ 'ok' => false, 'error' => 'trial_used' ], $r );
		$this->assertSame( 'pro', License::get()['plan'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
	}

	public function test_activate_success_stores_key_plan_and_site_key(): void {
		$this->reply( 200, $this->lic( [ 'plan' => 'pro3', 'seats_max' => 3 ] ) );
		$r = Actions::activate( ' LIC-9 ', 1000, $this->t() );
		$this->assertTrue( $r['ok'] );
		$l = License::get();
		$this->assertSame( 'pro3', $l['plan'] );
		$this->assertSame( 3, $l['seats_max'] );
		$this->assertSame( 'LIC-9', $GLOBALS['invoqk_options']['invoqk_api_key'] );
		$this->assertSame( 'Bearer LIC-9', $this->calls[0][1]['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'LIC-9', $this->calls[0][1]['body'] );
	}

	public function test_activate_empty_key_makes_no_call(): void {
		$this->assertSame( 'invalid_key', Actions::activate( '  ', 1000, $this->t() )['error'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_bad_key_keeps_working_license_and_site_key(): void {
		$this->seedPro();
		$this->reply( 404, [ 'error' => 'invalid_key' ] );
		$this->assertSame( 'invalid_key', Actions::activate( 'TYPO', 1000, $this->t() )['error'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
		$this->assertSame( 'LIC-1', License::get()['key'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
	}

	public function test_malformed_success_body_changes_nothing(): void {
		$this->seedPro();
		$this->reply( 200, [] );
		$this->assertSame( 'unknown', Actions::activate( 'K', 1000, $this->t() )['error'] );
		$this->assertSame( 'LIC-1', License::get()['key'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
	}

	public function test_refresh_updates_on_200(): void {
		$this->seedPro();
		$this->reply( 200, $this->lic( [ 'valid_until' => 9000, 'api_key' => '' ] ) );
		$this->assertTrue( Actions::refresh( 2000, $this->t() )['ok'] );
		$l = License::get();
		$this->assertSame( 9000, $l['valid_until'] );
		$this->assertSame( 2000, $l['checked_at'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
	}

	public function test_refresh_401_clears_but_trial_used_survives(): void {
		$this->seedPro( [ 'trial_used' => true ] );
		$this->reply( 401, [] );
		Actions::refresh( 2000, $this->t() );
		$l = License::get();
		$this->assertSame( 'free', $l['plan'] );
		$this->assertTrue( $l['trial_used'] );
		$this->assertFalse( License::isActive( 2000 ) );
	}

	public function test_refresh_failure_keeps_plan_and_starts_grace(): void {
		$this->seedPro( [ 'valid_until' => 1000 ] );
		$r = Actions::refresh( 1500, $this->t() ); // empty queue = WP_Error
		$this->assertSame( 'unreachable', $r['error'] );
		$this->assertSame( 'pro', License::get()['plan'] );
		$this->assertSame( 1500, License::get()['checked_at'] );
		$this->assertTrue( License::isActive( 1500 ) );
		$this->assertFalse( License::isActive( 1000 + License::GRACE ) );
	}

	public function test_refresh_malformed_body_behaves_like_failure(): void {
		$this->seedPro();
		$this->reply( 200, [] );
		$this->assertSame( 'unknown', Actions::refresh( 2000, $this->t() )['error'] );
		$this->assertSame( 'pro', License::get()['plan'] );
		$this->assertTrue( License::get()['check_failed'] );
	}

	public function test_refresh_without_site_key_makes_no_call(): void {
		$this->assertSame( 'no_key', Actions::refresh( 2000, $this->t() )['error'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_deactivate_clears_on_200_and_401_keeps_on_failure(): void {
		foreach ( [ 200, 401 ] as $code ) {
			$this->seedPro();
			$this->reply( $code, [] );
			$this->assertTrue( Actions::deactivate( 3000, $this->t() )['ok'] );
			$this->assertSame( 'free', License::get()['plan'] );
			$this->assertArrayNotHasKey( 'invoqk_api_key', $GLOBALS['invoqk_options'] );
		}
		$this->seedPro();
		$this->reply( 500, [] );
		$this->assertFalse( Actions::deactivate( 3000, $this->t() )['ok'] );
		$this->assertSame( 'pro', License::get()['plan'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
	}

	public function test_checkout_returns_url_and_marks_pending(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		$this->reply( 200, [ 'url' => 'https://pay.example/c/1' ] );
		$r = Actions::checkout( 'pro3', 4000, $this->t() );
		$this->assertSame( [ 'ok' => true, 'error' => null, 'url' => 'https://pay.example/c/1' ], $r );
		$this->assertSame( 4000, $GLOBALS['invoqk_options']['invoqk_checkout_pending'] );
	}

	public function test_checkout_rejects_unknown_plan_and_missing_key_without_call(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		$this->assertSame( 'unknown', Actions::checkout( 'gold', 4000, $this->t() )['error'] );
		unset( $GLOBALS['invoqk_options']['invoqk_api_key'] );
		$this->assertSame( 'no_key', Actions::checkout( 'pro', 4000, $this->t() )['error'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_portal_returns_https_url_and_needs_a_key(): void {
		$this->assertSame( 'no_key', Actions::portal( $this->t() )['error'] );
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		$this->reply( 200, [ 'url' => 'https://pay.example/portal' ] );
		$this->assertSame( [ 'ok' => true, 'error' => null, 'url' => 'https://pay.example/portal' ], Actions::portal( $this->t() ) );
		$this->assertStringEndsWith( '/api/v1/license/portal', $this->calls[0][0] );
	}

	public function test_portal_rejects_non_https_url_and_passes_api_errors(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		$this->reply( 200, [ 'url' => 'javascript:alert(1)' ] );
		$this->assertSame( 'unknown', Actions::portal( $this->t() )['error'] );
		$this->reply( 409, [ 'error' => 'no_license' ] );
		$this->assertSame( 'no_license', Actions::portal( $this->t() )['error'] );
	}

	public function test_checkout_rejects_non_https_url(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		foreach ( [ 'javascript:alert(1)', 'http://pay.example/c', '//pay.example/c' ] as $bad ) {
			$this->reply( 200, [ 'url' => $bad ] );
			$this->assertSame( 'unknown', Actions::checkout( 'pro', 4000, $this->t() )['error'], $bad );
		}
		$this->assertArrayNotHasKey( 'invoqk_checkout_pending', $GLOBALS['invoqk_options'] );
	}

	public function test_checkout_without_url_in_reply_is_unknown(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		$this->reply( 200, [] );
		$this->assertSame( 'unknown', Actions::checkout( 'pro', 4000, $this->t() )['error'] );
	}

	public function test_refresh_if_stale_skips_fresh_and_never_licensed(): void {
		Actions::refreshIfStale( 10000, $this->t() ); // free, no key
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		Actions::refreshIfStale( 10000, $this->t() ); // free with key, nothing pending
		$this->seedPro( [ 'checked_at' => 10000 - License::STALE + 1 ] );
		Actions::refreshIfStale( 10000, $this->t() ); // fresh
		$this->assertSame( [], $this->calls );
	}

	public function test_refresh_if_stale_calls_for_stale_license(): void {
		$this->seedPro( [ 'checked_at' => 10000 - License::STALE - 1 ] );
		$this->reply( 200, $this->lic( [ 'valid_until' => 99999 ] ) );
		Actions::refreshIfStale( 10000, $this->t() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_pending_checkout_polls_every_five_minutes_until_license_arrives(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key']          = 'sk_old';
		$GLOBALS['invoqk_options']['invoqk_checkout_pending'] = 9000;
		Actions::refreshIfStale( 9100, $this->t() ); // 5 minutes not passed since pending
		$this->assertSame( [], $this->calls );
		$this->reply( 200, $this->lic( [ 'valid_until' => 99999 ] ) );
		Actions::refreshIfStale( 9400, $this->t() );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'pro', License::get()['plan'] );
		$this->assertArrayNotHasKey( 'invoqk_checkout_pending', $GLOBALS['invoqk_options'] );
	}

	public function test_handle_trial_redirects_to_settings_with_ok(): void {
		$this->reply( 202, [ 'status' => 'check_email' ] );
		$url = Actions::handle( [ 'license_action' => 'trial', 'email' => 'a@b.de' ], 1000, $this->t() );
		$this->assertSame( '/wp-admin/admin.php?page=invoqk&tab=license&invoqk_license=check_email', $url );
		$GLOBALS['invoqk_options']['invoqk_trial_pending'] = 'a@b.de';
		$this->reply( 200, $this->lic( [ 'plan' => 'trial', 'key' => '' ] ) );
		$url = Actions::handle( [ 'license_action' => 'trial_confirm', 'code' => ' 123456 ' ], 1000, $this->t() );
		$this->assertSame( '/wp-admin/admin.php?page=invoqk&tab=license&invoqk_license=ok', $url );
	}

	public function test_handle_activate_sanitizes_and_passes_key(): void {
		$this->reply( 404, [ 'error' => 'invalid_key' ] );
		$url = Actions::handle( [ 'license_action' => 'activate', 'key' => ' <b>LIC-5</b> ' ], 1000, $this->t() );
		$this->assertStringContainsString( 'invoqk_license=invalid_key', $url );
		$this->assertSame( 'Bearer LIC-5', $this->calls[0][1]['headers']['Authorization'] );
	}

	public function test_handle_checkout_returns_provider_url(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_old';
		$this->reply( 200, [ 'url' => 'https://pay.example/c/1' ] );
		$this->assertSame( 'https://pay.example/c/1', Actions::handle( [ 'license_action' => 'checkout', 'plan' => 'pro' ], 1000, $this->t() ) );
	}

	public function test_handle_unknown_action_makes_no_call(): void {
		$url = Actions::handle( [ 'license_action' => 'wipe' ], 1000, $this->t() );
		$this->assertStringContainsString( 'invoqk_license=unknown', $url );
		$this->assertSame( [], $this->calls );
	}

	public function test_boot_registers_post_handler_and_sweep_refresh(): void {
		$GLOBALS['invoqk_actions'] = [];
		Actions::boot();
		$this->assertArrayHasKey( 'admin_post_invoqk_license', $GLOBALS['invoqk_actions'] );
		$this->assertArrayHasKey( \Invoqk\Jobs\Scheduler::SWEEP, $GLOBALS['invoqk_actions'] );
	}

	public function test_trial_sends_email_and_domain_and_needs_a_valid_email(): void {
		$this->reply( 202, [ 'status' => 'check_email' ] );
		Actions::trial( ' Owner@Shop.de ', 1000, $this->t() );
		$this->assertSame( '{"email":"owner@shop.de","domain":"shop.example"}', $this->calls[0][1]['body'] );
		$this->calls = [];
		$this->assertSame( 'invalid_email', Actions::trial( 'nope', 1000, $this->t() )['error'] );
		$this->assertSame( [], $this->calls );
	}

	public function test_refresh_with_server_plan_free_clears_the_license(): void {
		$this->seedPro( [ 'trial_used' => true ] );
		$this->reply( 200, [ 'plan' => 'free', 'valid_until' => 0 ] );
		$this->assertTrue( Actions::refresh( 2000, $this->t() )['ok'] );
		$this->assertSame( 'free', License::get()['plan'] );
		$this->assertTrue( License::get()['trial_used'] );
		$this->assertFalse( License::get()['check_failed'] );
		$this->assertSame( 'sk_old', $GLOBALS['invoqk_options']['invoqk_api_key'] );
	}

	public function test_activating_a_key_of_a_free_account_keeps_the_key_but_reports_no_license(): void {
		$this->reply( 200, [ 'plan' => 'free', 'valid_until' => 0 ] );
		$this->assertSame( 'no_license', Actions::activate( 'iqk_free', 1000, $this->t() )['error'] );
		$this->assertSame( 'iqk_free', $GLOBALS['invoqk_options']['invoqk_api_key'] );
		$this->assertSame( 'free', License::get()['plan'] );
	}
}
