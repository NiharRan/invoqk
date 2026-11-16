<?php
use Invoqk\Fallback\Numbers;
use PHPUnit\Framework\TestCase;

final class NumbersTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
	}

	public function test_format_pads_counter_and_fills_year(): void {
		$this->assertSame( 'RE-2027-00007', Numbers::format( 'RE-{YYYY}-{00001}', 7, 2027 ) );
	}

	public function test_format_width_follows_token(): void {
		$this->assertSame( 'A-007', Numbers::format( 'A-{000}', 7, 2027 ) );
		$this->assertSame( '7', Numbers::format( '{0}', 7, 2027 ) );
		$this->assertSame( '07', Numbers::format( '{01}', 7, 2027 ) );
	}

	public function test_next_is_consecutive(): void {
		$this->assertSame( 'RE-2027-00001', Numbers::next( 2027 ) );
		$this->assertSame( 'RE-2027-00002', Numbers::next( 2027 ) );
		$this->assertSame( 'RE-2027-00003', Numbers::next( 2027 ) );
		$this->assertSame( 3, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
		$this->assertArrayNotHasKey( 'invoqk_fallback_lock', $GLOBALS['invoqk_options'] );
	}

	public function test_next_refuses_while_lock_held(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_lock']    = time();
		$GLOBALS['invoqk_options']['invoqk_fallback_counter'] = 4;
		try {
			Numbers::next( 2027 );
			$this->fail( 'expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 4, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
		}
	}

	public function test_stale_lock_is_taken_over(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_lock'] = time() - 31;
		$this->assertSame( 'RE-2027-00001', Numbers::next( 2027 ) );
		$this->assertArrayNotHasKey( 'invoqk_fallback_lock', $GLOBALS['invoqk_options'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['invoqk_fail_update'] );
	}

	public function test_build_failure_leaves_counter_and_releases_lock(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_counter'] = 4;
		$GLOBALS['invoqk_options']['invoqk_fallback_seller']  = [ 'number_format' => new \stdClass() ];
		try {
			Numbers::next( 2027 );
			$this->fail( 'expected Throwable' );
		} catch ( \Throwable $e ) {
			$this->assertSame( 4, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
			$this->assertArrayNotHasKey( 'invoqk_fallback_lock', $GLOBALS['invoqk_options'] );
		}
	}

	public function test_failed_counter_write_throws_and_releases_lock(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_counter'] = 4;
		$GLOBALS['invoqk_fail_update']                        = true;
		try {
			Numbers::next( 2027 );
			$this->fail( 'expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 4, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
			$this->assertArrayNotHasKey( 'invoqk_fallback_lock', $GLOBALS['invoqk_options'] );
		}
	}

	public function test_release_leaves_foreign_lock_alone(): void {
		// While the number is being built, "another request" replaces the lock.
		$GLOBALS['invoqk_options']['invoqk_fallback_seller'] = [ 'number_format' => new class() {
			public function __toString(): string {
				$GLOBALS['invoqk_options']['invoqk_fallback_lock'] = time() . ':foreign';
				return 'N-{1}';
			}
		} ];
		Numbers::next( 2027 );
		$this->assertStringEndsWith( ':foreign', $GLOBALS['invoqk_options']['invoqk_fallback_lock'] );
	}
}
