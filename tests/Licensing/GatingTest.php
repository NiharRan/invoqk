<?php
namespace Invoqk\Tests\Licensing;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Capture;
use Invoqk\Archive\LocalStorage;
use Invoqk\Guard\Guard;
use Invoqk\Licensing\License;
use PHPUnit\Framework\TestCase;

final class GatingTest extends TestCase {
	private string $base;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_actions'] = [];
		Guard::reset();
		Capture::reset();
		Archive::reset();
		$this->base       = sys_get_temp_dir() . '/invoqk-gate-' . bin2hex( random_bytes( 4 ) );
		Archive::$storage = new LocalStorage( $this->base );
	}

	protected function tearDown(): void {
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function license( string $plan, int $validUntil, bool $failed = false ): void {
		License::save( [ 'plan' => $plan, 'valid_until' => $validUntil, 'check_failed' => $failed ] + License::get() );
	}

	/** Runs one customer email through Capture and says whether the invoice got staged for the archive. */
	private function archived(): bool {
		$o = $GLOBALS['invoqk_orders'][5] = new \WC_Order( 5 );
		@mkdir( $this->base, 0777, true );
		$pdf = "{$this->base}/a.pdf";
		file_put_contents( $pdf, 'x' );
		Capture::record( $pdf, 'invoice', (object) [ 'order' => $o ], 'pdf' );
		$email = new class() {
			public function is_customer_email(): bool { return true; }
		};
		Capture::stage( [ $pdf ], 'x', $o, $email );
		return count( glob( Archive::storage()->staging() . '/*', GLOB_ONLYDIR ) ?: [] ) > 0;
	}

	public function test_free_site_archives_nothing(): void {
		$this->assertFalse( $this->archived() );
	}

	public function test_active_trial_and_paid_archive(): void {
		foreach ( [ 'trial', 'pro', 'pro3', 'agency' ] as $plan ) {
			$this->setUp();
			$this->license( $plan, time() + 3600 );
			$this->assertTrue( $this->archived(), $plan );
		}
	}

	public function test_expired_license_stops_new_archiving(): void {
		$this->license( 'pro', time() - 10 );
		$this->assertFalse( $this->archived() );
	}

	public function test_offline_grace_keeps_archiving(): void {
		$this->license( 'pro', time() - 10, true );
		$this->assertTrue( $this->archived() );
	}

	/** Page.php is left out: its only plan check is the upgrade prompt on an empty archive (Page.php:191). */
	public function test_archive_read_and_export_code_never_checks_the_plan(): void {
		foreach ( [ 'Export', 'ExportFormat', 'Actions', 'Verify', 'WpdbIndex', 'LocalStorage' ] as $class ) {
			$src = (string) file_get_contents( dirname( __DIR__, 2 ) . "/src/Archive/$class.php" );
			$this->assertDoesNotMatchRegularExpression( '/Limit::isPro|invoqk_is_pro|License::/', $src, $class );
		}
	}
}
