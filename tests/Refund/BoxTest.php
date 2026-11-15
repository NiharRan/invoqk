<?php
use Invoqk\Refund\Box;
use Invoqk\Refund\Open;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase {
	public function test_lists_open_refunds_with_dismiss_link(): void {
		$o            = new WC_Order( 5 );
		$o->refunds   = [ new class { public function get_id() { return 11; } public function get_amount() { return '12.5'; } }, new class { public function get_id() { return 12; } public function get_amount() { return '1'; } } ];
		$o->update_meta_data( Open::META, [ 11 ] );
		$h = Box::html( $o );
		$this->assertStringContainsString( '#11', $h );
		$this->assertStringContainsString( '12.50', $h );
		$this->assertStringNotContainsString( '#12', $h );
		$this->assertStringContainsString( 'action=invoqk_refund_dismiss&amp;order_id=5&amp;refund_id=11', $h );
		$this->assertStringContainsString( 'invoqk_refund_dismiss_5_11', $h );
	}

	public function test_empty_when_none(): void {
		$this->assertSame( '', Box::html( new WC_Order( 5 ) ) );
	}
}
