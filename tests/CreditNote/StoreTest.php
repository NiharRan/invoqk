<?php
use Invoqk\CreditNote\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase {
	public function test_get_returns_empty_for_new_order(): void {
		$this->assertSame( [], Store::get( new WC_Order( 1 ) ) );
	}

	public function test_put_get_roundtrip_keyed_by_refund(): void {
		$o = new WC_Order( 1 );
		$e = [ 'source' => 'a', 'file' => [ 'reference' => 5, 'mime' => 'application/xml', 'format' => 'ubl', 'onDemand' => false ], 'detectedAt' => 1, 'validation' => null, 'archived' => null ];
		Store::put( $o, 11, $e );
		Store::put( $o, 12, [ 'source' => 'b' ] + $e );
		$this->assertSame( [ 11, 12 ], array_keys( Store::get( $o ) ) );
		$this->assertSame( $e, Store::get( $o )[11] );
		$this->assertSame( 2, $o->metaSaves );
		$this->assertSame( 0, $o->saves );
	}
}
