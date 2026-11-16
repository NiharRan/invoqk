<?php
use Invoqk\Fallback\Lock;
use Invoqk\Fallback\Numbers;
use Invoqk\Tests\Fallback\LockDb;
use PHPUnit\Framework\TestCase;

final class NumbersTest extends TestCase {
	private LockDb $db;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['wpdb']           = $this->db = new LockDb();
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
		$this->assertSame( [], $this->db->held, 'lock released' );
		$this->assertSame( "SELECT GET_LOCK( '" . Lock::name( 'fb_num' ) . "', 0 )", $this->db->queries[0] );
	}

	public function test_next_refuses_while_another_connection_holds_the_lock(): void {
		$this->db->held[ Lock::name( 'fb_num' ) ]             = 'other';
		$GLOBALS['invoqk_options']['invoqk_fallback_counter'] = 4;
		try {
			Numbers::next( 2027 );
			$this->fail( 'expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 4, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
			$this->assertSame( [ Lock::name( 'fb_num' ) => 'other' ], $this->db->held, 'foreign lock stays' );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['invoqk_fail_update'], $GLOBALS['wpdb'] );
	}

	public function test_build_failure_leaves_counter_and_releases_lock(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_counter'] = 4;
		$GLOBALS['invoqk_options']['invoqk_fallback_seller']  = [ 'number_format' => new \stdClass() ];
		try {
			Numbers::next( 2027 );
			$this->fail( 'expected Throwable' );
		} catch ( \Throwable $e ) {
			$this->assertSame( 4, $GLOBALS['invoqk_options']['invoqk_fallback_counter'] );
			$this->assertSame( [], $this->db->held, 'lock released' );
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
			$this->assertSame( [], $this->db->held, 'lock released' );
		}
	}
}
