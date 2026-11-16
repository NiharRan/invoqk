<?php
namespace Invoqk\Tests\Licensing;

use Invoqk\Licensing\License;
use Invoqk\Licensing\Notice;
use PHPUnit\Framework\TestCase;

final class NoticeTest extends TestCase {
	private const NOW = 1000000;

	private function lic( string $plan, int $daysLeft ): array {
		return [ 'plan' => $plan, 'valid_until' => self::NOW + $daysLeft * 86400 - 1 ] + License::get();
	}

	public function test_trial_reminders_at_day_10_and_13(): void {
		$this->assertNull( Notice::message( $this->lic( 'trial', 5 ), self::NOW ) ); // day 9
		$this->assertStringContainsString( '4 days', (string) Notice::message( $this->lic( 'trial', 4 ), self::NOW ) ); // day 10
		$this->assertStringContainsString( '2 days', (string) Notice::message( $this->lic( 'trial', 2 ), self::NOW ) ); // day 12
		$this->assertStringContainsString( 'tomorrow', (string) Notice::message( $this->lic( 'trial', 1 ), self::NOW ) ); // day 13
	}

	public function test_paid_renewal_reminders(): void {
		$this->assertNull( Notice::message( $this->lic( 'pro', 31 ), self::NOW ) );
		$this->assertStringContainsString( '30 days', (string) Notice::message( $this->lic( 'pro', 30 ), self::NOW ) );
		$this->assertStringContainsString( 'Renew now', (string) Notice::message( $this->lic( 'pro3', 7 ), self::NOW ) );
	}

	public function test_expired_says_archive_and_export_still_work(): void {
		$l = [ 'plan' => 'pro', 'valid_until' => self::NOW - 1 ] + License::get();
		$m = (string) Notice::message( $l, self::NOW );
		$this->assertStringContainsString( 'archive', strtolower( $m ) );
		$this->assertStringContainsString( 'export', strtolower( $m ) );
	}

	public function test_free_site_gets_no_notice(): void {
		$this->assertNull( Notice::message( License::get(), self::NOW ) );
	}

	public function test_grace_period_shows_no_expired_notice(): void {
		$l = [ 'plan' => 'pro', 'valid_until' => self::NOW - 100, 'check_failed' => true ] + License::get();
		$this->assertNull( Notice::message( $l, self::NOW ) );
	}

	public function test_notice_links_to_the_license_tab(): void {
		$GLOBALS['invoqk_actions'] = [];
		$GLOBALS['invoqk_options']['invoqk_license'] = [ 'plan' => 'pro', 'valid_until' => 1 ] + License::get();
		Notice::boot();
		ob_start();
		do_action( 'admin_notices' );
		$h = ob_get_clean();
		$this->assertStringContainsString( 'page=invoqk&amp;tab=license', $h );
		unset( $GLOBALS['invoqk_options']['invoqk_license'] );
	}
}
