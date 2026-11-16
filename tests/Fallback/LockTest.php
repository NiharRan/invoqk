<?php
use Invoqk\Fallback\Lock;
use Invoqk\Tests\Fallback\LockDb;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_name_is_per_site_and_fits_mysql_limit(): void {
		$GLOBALS['wpdb'] = new LockDb();
		$a               = Lock::name( 'fb_num' );
		$GLOBALS['wpdb']->prefix = 'wp2_';
		$this->assertNotSame( $a, Lock::name( 'fb_num' ) );
		$this->assertLessThanOrEqual( 64, strlen( Lock::name( 'fb_gen_' . PHP_INT_MAX ) ) );
		$this->assertStringStartsWith( 'invoqk_', $a );
	}

	public function test_acquire_uses_the_site_name(): void {
		$GLOBALS['wpdb'] = $db = new LockDb();
		$this->assertTrue( Lock::acquire( 'fb_num' ) );
		$this->assertSame( [ Lock::name( 'fb_num' ) => 'me' ], $db->held );
		Lock::release( 'fb_num' );
		$this->assertSame( [], $db->held );
	}
}
