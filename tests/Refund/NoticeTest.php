<?php
use Invoqk\Refund\Notice;
use PHPUnit\Framework\TestCase;

final class NoticeTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [ 5 => new WC_Order( 5 ) ];
	}

	public function test_one_batched_notice_for_many_orders(): void {
		update_option( 'invoqk_refund_open_orders', [ 5, 6, 7 ] );
		$h = Notice::html();
		$this->assertSame( 1, substr_count( $h, '<div' ) );
		$this->assertStringContainsString( '3', $h );
		$this->assertStringContainsString( 'id=5', $h );
	}

	public function test_empty_when_none(): void {
		$this->assertSame( '', Notice::html() );
	}
}
