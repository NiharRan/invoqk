<?php
use Invoqk\Refund\Box;
use Invoqk\Refund\Open;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [ 'invoqk_is_pro' => [ fn() => true ] ];
	}

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

	public function test_empty_when_not_pro(): void {
		$GLOBALS['invoqk_filters'] = [];
		$o = new WC_Order( 5 );
		$o->refunds = [ new class { public function get_id() { return 11; } public function get_amount() { return '1'; } } ];
		$o->update_meta_data( Open::META, [ 11 ] );
		$this->assertSame( '', Box::html( $o ) );
	}

	public function test_empty_when_the_flagged_refund_no_longer_exists(): void {
		$o = new WC_Order( 5 );
		$o->update_meta_data( Open::META, [ 11 ] );
		$this->assertSame( '', Box::html( $o ) );
	}
}
