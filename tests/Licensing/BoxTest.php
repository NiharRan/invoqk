<?php
namespace Invoqk\Tests\Licensing;

use Invoqk\Licensing\Box;
use Invoqk\Licensing\License;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase {
	private function lic( array $over = [] ): array {
		return $over + License::get();
	}

	public function test_free_site_offers_trial_key_and_all_plans(): void {
		$h = Box::html( $this->lic(), 1000 );
		$this->assertStringContainsString( 'Free plan', $h );
		$this->assertStringContainsString( 'value="trial"', $h );
		$this->assertStringContainsString( 'name="email" type="email"', $h );
		$this->assertStringContainsString( 'name="key"', $h );
		foreach ( [ '€79', '1 shop', '€149', '3 shops', '€399', '25 shops' ] as $needle ) {
			$this->assertStringContainsString( $needle, $h );
		}
		$this->assertStringContainsString( 'value="checkout"', $h );
		$this->assertSame( substr_count( $h, '<form' ), substr_count( $h, 'name="_wpnonce"' ) );
	}

	public function test_trial_used_hides_trial_button(): void {
		$h = Box::html( $this->lic( [ 'trial_used' => true ] ), 1000 );
		$this->assertStringNotContainsString( 'value="trial"', $h );
	}

	public function test_active_pro_shows_seats_renewal_and_deactivate_only(): void {
		$h = Box::html( $this->lic( [ 'plan' => 'pro', 'valid_until' => 1000 + 86400 * 100, 'seats_used' => 1, 'seats_max' => 1, 'key' => 'SECRET-KEY' ] ), 1000 );
		$this->assertStringContainsString( 'Pro', $h );
		$this->assertStringContainsString( '1 of 1 shops', $h );
		$this->assertStringContainsString( 'value="deactivate"', $h );
		$this->assertStringContainsString( 'value="portal"', $h );
		$this->assertStringNotContainsString( 'name="key"', $h );
		$this->assertStringNotContainsString( 'value="checkout"', $h );
		$this->assertStringNotContainsString( 'SECRET-KEY', $h );
	}

	public function test_active_trial_shows_days_left_and_buy_table(): void {
		$h = Box::html( $this->lic( [ 'plan' => 'trial', 'valid_until' => 1000 + 86400 * 4 - 1, 'trial_used' => true ] ), 1000 );
		$this->assertStringContainsString( '4 days left', $h );
		$this->assertStringNotContainsString( 'value="portal"', $h ); // a trial has no billing
		$this->assertStringContainsString( 'value="checkout"', $h );
		$this->assertStringContainsString( 'value="deactivate"', $h );
	}

	public function test_expired_shows_buy_table_and_ended_text(): void {
		$h = Box::html( $this->lic( [ 'plan' => 'pro', 'valid_until' => 500 ] ), 1000 );
		$this->assertStringContainsString( 'ended', $h );
		$this->assertStringContainsString( 'value="checkout"', $h );
		$this->assertStringContainsString( 'name="key"', $h );
	}

	public function test_flash_codes(): void {
		$this->assertStringContainsString( 'seats', Box::html( $this->lic(), 1, 'seats_full' ) );
		$this->assertStringContainsString( 'notice-error', Box::html( $this->lic(), 1, 'whatever' ) );
		$this->assertStringContainsString( 'account', Box::html( $this->lic(), 1, 'account_exists' ) );
		$this->assertStringNotContainsString( 'notice-error', Box::html( $this->lic(), 1, 'ok' ) );
	}

	public function test_pending_trial_shows_code_form_with_escaped_email(): void {
		$h = Box::html( $this->lic(), 1000, 'check_email', 'a<b>@x.de' );
		$this->assertStringContainsString( 'name="code"', $h );
		$this->assertStringContainsString( 'value="trial_confirm"', $h );
		$this->assertStringContainsString( 'a&lt;b&gt;@x.de', $h );
		$this->assertStringNotContainsString( 'notice-error', $h );
		$this->assertStringNotContainsString( 'name="code"', Box::html( $this->lic(), 1000 ) );
	}

	public function test_code_flash_and_no_code_form_for_active_license(): void {
		$this->assertStringContainsString( 'code', Box::html( $this->lic(), 1, 'invalid_code' ) );
		$active = $this->lic( [ 'plan' => 'pro', 'valid_until' => 9999 ] );
		$this->assertStringNotContainsString( 'name="code"', Box::html( $active, 1, '', 'a@x.de' ) );
	}

	public function test_plans_render_as_cards_and_every_form_has_a_nonce(): void {
		$free = Box::html( $this->lic(), 1000 );
		$this->assertSame( 3, substr_count( $free, 'class="invoqk-plan"' ) );
		$paid = $this->lic( [ 'plan' => 'pro', 'valid_until' => 99999, 'seats_used' => 1, 'seats_max' => 1 ] );
		$h    = Box::html( $paid, 1000 );
		$this->assertSame( 0, substr_count( $h, 'invoqk-plan' ) );
		$trial = Box::html( $this->lic( [ 'plan' => 'trial', 'valid_until' => 99999 ] ), 1000 );
		$this->assertSame( 3, substr_count( $trial, 'class="invoqk-plan"' ) );
		foreach ( [ $free, $h, $trial ] as $html ) {
			$this->assertSame( substr_count( $html, '<form' ), substr_count( $html, 'name="_wpnonce"' ) );
		}
	}
}
