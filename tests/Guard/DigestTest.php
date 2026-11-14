<?php
use Invoqk\Guard\Digest;
use PHPUnit\Framework\TestCase;

final class DigestTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [ 'admin_email' => 'shop@example.test' ];
		$GLOBALS['invoqk_orders']  = [ 1 => new \WC_Order( 1 ), 2 => new \WC_Order( 2 ) ];
		$GLOBALS['invoqk_as']      = [];
	}

	public function test_add_dedupes_and_schedules_once(): void {
		$before = time();
		Digest::add( 1, 'held' );
		Digest::add( 1, 'held' );
		Digest::add( 2, 'warn' );
		$this->assertCount( 2, get_option( 'invoqk_guard_outbox' ) );
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
		$this->assertSame( Digest::HOOK, $GLOBALS['invoqk_as'][0]['hook'] );
		$this->assertGreaterThanOrEqual( $before + 900, $GLOBALS['invoqk_as'][0]['when'] );
		$this->assertLessThanOrEqual( time() + 900, $GLOBALS['invoqk_as'][0]['when'] );
	}

	public function test_run_sends_one_mail_and_clears(): void {
		Digest::add( 1, 'held' );
		Digest::add( 2, 'no_einvoice' );
		$calls = [];
		Digest::run( function ( ...$a ) use ( &$calls ) { $calls[] = $a; return true; } );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'shop@example.test', $calls[0][0] );
		$this->assertSame( 'Invoqk: 2 invoices need attention', $calls[0][1] );
		$this->assertStringContainsString( 'https://shop.test/order/1', $calls[0][2] );
		$this->assertStringContainsString( 'https://shop.test/order/2', $calls[0][2] );
		$this->assertSame( [], get_option( 'invoqk_guard_outbox' ) );
	}

	public function test_run_empty_sends_nothing(): void {
		$n = 0;
		Digest::run( function () use ( &$n ) { ++$n; } );
		$this->assertSame( 0, $n );
	}

	public function test_run_skips_deleted_orders(): void {
		Digest::add( 1, 'held' );
		Digest::add( 99, 'held' );
		$body = '';
		Digest::run( function ( $to, $s, $b ) use ( &$body ) { $body = $b; } );
		$this->assertStringContainsString( '/order/1', $body );
		$this->assertStringNotContainsString( '/order/99', $body );
	}
}
