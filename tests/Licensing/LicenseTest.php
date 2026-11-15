<?php
namespace Invoqk\Tests\Licensing;

use Invoqk\Licensing\License;
use Invoqk\Validation\Limit;
use PHPUnit\Framework\TestCase;

final class LicenseTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [];
	}

	private function save( array $over ): void {
		License::save( $over + License::get() );
	}

	public function test_free_by_default(): void {
		$this->assertFalse( License::isActive( 1000 ) );
		$this->assertSame( 'free', License::get()['plan'] );
	}

	public function test_active_until_valid_until(): void {
		$this->save( [ 'plan' => 'pro', 'valid_until' => 2000 ] );
		$this->assertTrue( License::isActive( 1999 ) );
		$this->assertFalse( License::isActive( 2000 ) );
	}

	public function test_grace_only_when_last_check_failed(): void {
		$this->save( [ 'plan' => 'pro', 'valid_until' => 2000, 'check_failed' => true ] );
		$this->assertTrue( License::isActive( 2000 + License::GRACE - 1 ) );
		$this->assertFalse( License::isActive( 2000 + License::GRACE ) );
		$this->save( [ 'check_failed' => false ] );
		$this->assertFalse( License::isActive( 2000 ) );
	}

	public function test_free_plan_never_active_even_with_future_date(): void {
		$this->save( [ 'plan' => 'free', 'valid_until' => 9999 ] );
		$this->assertFalse( License::isActive( 1 ) );
	}

	public function test_from_response_rejects_malformed(): void {
		$this->assertNull( License::fromResponse( [], 1 ) );
		$this->assertNull( License::fromResponse( [ 'plan' => 'gold', 'valid_until' => 5 ], 1 ) );
		$this->assertNull( License::fromResponse( [ 'plan' => 'pro', 'valid_until' => 'x' ], 1 ) );
	}

	public function test_from_response_normalizes_trial(): void {
		$l = License::fromResponse( [ 'plan' => 'trial', 'valid_until' => 500, 'seats_max' => 1 ], 77 );
		$this->assertSame( 'trial', $l['plan'] );
		$this->assertTrue( $l['trial_used'] );
		$this->assertSame( 77, $l['checked_at'] );
		$this->assertFalse( $l['check_failed'] );
	}

	public function test_clear_keeps_trial_used(): void {
		$this->save( [ 'plan' => 'trial', 'valid_until' => 5, 'trial_used' => true, 'key' => 'K' ] );
		License::clear();
		$l = License::get();
		$this->assertSame( 'free', $l['plan'] );
		$this->assertSame( '', $l['key'] );
		$this->assertTrue( $l['trial_used'] );
	}

	public function test_days_left_rounds_up(): void {
		$this->save( [ 'plan' => 'pro', 'valid_until' => 1000 + 86400 * 3 - 1 ] );
		$this->assertSame( 3, License::daysLeft( 1000 ) );
		$this->assertSame( 0, License::daysLeft( 1000 + 86400 * 4 ) );
	}

	public function test_is_pro_follows_license_and_filter_overrides(): void {
		$this->assertFalse( Limit::isPro() );
		$this->save( [ 'plan' => 'pro', 'valid_until' => time() + 3600 ] );
		$this->assertTrue( Limit::isPro() );
		add_filter( 'invoqk_is_pro', static fn() => false );
		$this->assertFalse( Limit::isPro() );
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_options'] = [];
		add_filter( 'invoqk_is_pro', static fn() => true );
		$this->assertTrue( Limit::isPro() );
	}
}
