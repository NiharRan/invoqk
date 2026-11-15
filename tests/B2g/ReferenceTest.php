<?php
use Invoqk\B2g\Reference;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase {
	public function test_set_normalises_and_stores(): void {
		$o = new WC_Order( 7 );
		Reference::set( $o, " 04011000 \u{2013} 12345 - 67 " );
		$this->assertSame( '04011000-12345-67', $o->meta[ Reference::META ] );
		$this->assertSame( 1, $o->metaSaves );
		$this->assertSame( 0, $o->saves );
	}

	public function test_empty_deletes_meta(): void {
		$o = new WC_Order( 7 );
		Reference::set( $o, '991-33333-06' );
		Reference::set( $o, " \u{200B} " );
		$this->assertArrayNotHasKey( Reference::META, $o->meta );
		$this->assertSame( '', Reference::get( $o ) );
	}

	public function test_unsaved_order_is_not_meta_saved(): void {
		$o = new WC_Order( 0 ); // classic checkout: the order is saved by WooCommerce after woocommerce_checkout_create_order
		Reference::set( $o, '991-33333-06' );
		$this->assertSame( '991-33333-06', $o->meta[ Reference::META ] );
		$this->assertSame( 0, $o->metaSaves );
	}

	public function test_get_falls_back_to_block_meta(): void {
		$o = new WC_Order( 7 );
		$o->meta['_wc_other/invoqk/leitweg_id'] = '991-33333-06';
		$this->assertSame( '991-33333-06', Reference::get( $o ) );
		$o->meta[ Reference::META ] = '04011000-12345-67';
		$this->assertSame( '04011000-12345-67', Reference::get( $o ) );
	}

	public function test_order_box_shows_reference_escaped(): void {
		$o          = new WC_Order( 7 );
		$o->company = '';
		ob_start();
		\Invoqk\Admin\OrderBox::show( $o );
		$this->assertStringNotContainsString( 'Leitweg-ID / Buyer reference', ob_get_clean() );
		$o->meta[ Reference::META ] = '04011000-<b>-67';
		ob_start();
		\Invoqk\Admin\OrderBox::show( $o );
		$this->assertStringContainsString( 'Leitweg-ID / Buyer reference: 04011000-&lt;b&gt;-67', ob_get_clean() );
	}
}
