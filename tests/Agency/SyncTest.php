<?php
use Invoqk\Agency\Sync;
use Invoqk\Licensing\License;
use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase {
	private array $calls = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options']       = [ 'invoqk_api_key' => 'sk' ];
		$GLOBALS['invoqk_transients']    = [];
		$GLOBALS['invoqk_orders']        = [];
		$GLOBALS['invoqk_orders_result'] = [];
		$this->calls = [];
		if ( ! defined( 'INVOQK_VERSION' ) ) {
			define( 'INVOQK_VERSION', '0.0.0' );
		}
	}

	private function t( int $code = 200 ): callable {
		return function ( string $url, array $args ) use ( $code ) {
			$this->calls[] = [ $url, $args ];
			return [ 'response' => [ 'code' => $code ], 'body' => '{}' ];
		};
	}

	private function plan( string $plan, int $until = 9999999 ): void {
		License::save( [ 'plan' => $plan, 'valid_until' => $until, 'seats_used' => 1, 'seats_max' => 25, 'key' => '', 'checked_at' => 0, 'check_failed' => false, 'trial_used' => false ] );
	}

	public function test_non_agency_never_sends(): void {
		$this->plan( 'pro' );
		Sync::syncIfStale( 100000, $this->t() );
		$this->assertSame( [], $this->calls );
	}

	public function test_agency_sends_once_per_window_and_only_aggregates(): void {
		$this->plan( 'agency' );
		Sync::syncIfStale( 100000, $this->t() );
		Sync::syncIfStale( 100000 + Sync::EVERY - 1, $this->t() );
		$this->assertCount( 1, $this->calls );
		$this->assertStringEndsWith( '/license/agency/sync', $this->calls[0][0] );
		$body = json_decode( $this->calls[0][1]['body'], true );
		$this->assertSame( [ 'sent_at', 'version', 'sources', 'counts', 'score', 'capped', 'top' ], array_keys( $body ) );
		Sync::syncIfStale( 100000 + Sync::EVERY, $this->t() );
		$this->assertCount( 2, $this->calls );
	}

	public function test_failed_call_retries_next_sweep(): void {
		$this->plan( 'agency' );
		Sync::syncIfStale( 100000, $this->t( 500 ) );
		Sync::syncIfStale( 100001, $this->t() );
		$this->assertCount( 2, $this->calls );
	}

	public function test_expired_agency_does_not_send(): void {
		$this->plan( 'agency', 10 );
		Sync::syncIfStale( 10 + License::GRACE + 1, $this->t() );
		$this->assertSame( [], $this->calls );
	}
}
