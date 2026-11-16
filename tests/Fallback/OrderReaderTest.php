<?php
use Invoqk\B2g\Reference;
use Invoqk\Fallback\OrderReader;
use Invoqk\Fallback\Unsupported;
use PHPUnit\Framework\TestCase;

final class OrderReaderTest extends TestCase {
	/** 100.00 net product at 19, 10.00 shipping at 19, 5.00 fee at 19: 115 net, 21.85 tax. */
	private function order( array $over = [] ): WC_Order {
		$o            = new WC_Order( 42 );
		$o->number    = '1042';
		$o->company   = 'Kunde AG';
		$o->first     = 'Max';
		$o->last      = 'Kunde';
		$o->street    = 'Hauptstr. 5';
		$o->postcode  = '80331';
		$o->city      = 'München';
		$o->email     = 'einkauf@kunde.example';
		$o->total     = '136.85';
		$o->tax       = '21.85';
		$o->created   = strtotime( '2026-11-01 12:00:00 UTC' );
		$o->items     = [
			new WC_Order_Item_Product( 'Widget', '100.00', '19.00', 2 ),
			new WC_Order_Item_Stub( 'Versand', '10.00', '1.90', 'shipping' ),
			new WC_Order_Item_Stub( 'Gebühr', '5.00', '0.95', 'fee' ),
		];
		foreach ( $over as $k => $v ) {
			$o->$k = $v;
		}
		return $o;
	}

	private function reason( WC_Order $o, bool $nullsatz = false ): string {
		try {
			OrderReader::read( $o, $nullsatz );
		} catch ( Unsupported $e ) {
			return $e->reason;
		}
		return '';
	}

	public function test_standard_order_maps_lines_shipping_and_fee(): void {
		$o            = $this->order();
		$o->meta['_billing_vat_id'] = 'DE987654321';
		$o->completed = new DateTimeImmutable( '2026-11-10' );
		$i            = OrderReader::read( $o, false );
		$this->assertSame( '1042', $i->orderNumber );
		$this->assertSame( '2026-11-10', $i->deliveryDate );
		$this->assertSame( gmdate( 'Y-m-d', 0 ), $i->issueDate );
		$this->assertCount( 3, $i->lines );
		$this->assertSame( [ 'Widget', '2', 10000, 19 ], [ $i->lines[0]->name, $i->lines[0]->quantity, $i->lines[0]->netCents, $i->lines[0]->ratePercent ] );
		$this->assertSame( [ 'Versand', '1', 1000, 19 ], [ $i->lines[1]->name, $i->lines[1]->quantity, $i->lines[1]->netCents, $i->lines[1]->ratePercent ] );
		$this->assertSame( 500, $i->lines[2]->netCents );
		$this->assertSame( 'Kunde AG', $i->buyer['name'] );
		$this->assertSame( 'DE987654321', $i->buyer['vat_id'] );
		$this->assertSame( 'München', $i->buyer['city'] );
	}

	public function test_buyer_name_falls_back_to_person_and_delivery_date_to_paid_then_created(): void {
		$o = $this->order( [ 'company' => '' ] );
		$i = OrderReader::read( $o, false );
		$this->assertSame( 'Max Kunde', $i->buyer['name'] );
		$this->assertSame( '2026-11-01', $i->deliveryDate );
		$o->paid = new DateTimeImmutable( '2026-11-05' );
		$this->assertSame( '2026-11-05', OrderReader::read( $o, false )->deliveryDate );
	}

	public function test_refuses_foreign_country(): void {
		$this->assertSame( 'country', $this->reason( $this->order( [ 'country' => 'AT' ] ) ) );
	}

	public function test_refuses_non_eur(): void {
		$this->assertSame( 'currency', $this->reason( $this->order( [ 'currency' => 'USD' ] ) ) );
	}

	public function test_untaxed_line_becomes_rate_zero(): void {
		$o = $this->order( [ 'items' => [ new WC_Order_Item_Product( 'Export', '100.00', '0.00' ) ], 'total' => '100.00', 'tax' => '0.00' ] );
		$this->assertSame( 0, OrderReader::read( $o, true )->lines[0]->ratePercent );
	}

	public function test_refuses_odd_tax_rate(): void {
		$o = $this->order( [ 'items' => [ new WC_Order_Item_Product( 'Widget', '100.00', '16.00' ) ], 'total' => '116.00', 'tax' => '16.00' ] );
		$this->assertSame( 'tax_rate', $this->reason( $o ) );
	}

	public function test_refuses_any_refund(): void {
		$this->assertSame( 'refund', $this->reason( $this->order( [ 'refunds' => [ new WC_Order_Refund( 5, 42 ) ] ] ) ) );
	}

	public function test_refuses_zero_total(): void {
		$o = $this->order( [ 'total' => '0.00', 'tax' => '0.00', 'items' => [ new WC_Order_Item_Product( 'Gratis', '0.00', '0.00' ) ] ] );
		$this->assertSame( 'total', $this->reason( $o, true ) );
	}

	public function test_refuses_missing_buyer_street_postcode_or_city(): void {
		foreach ( [ 'street', 'postcode', 'city' ] as $f ) {
			$this->assertSame( 'buyer_address', $this->reason( $this->order( [ $f => ' ' ] ) ), $f );
		}
	}

	public function test_refuses_empty_line_name(): void {
		$o = $this->order( [ 'items' => [ new WC_Order_Item_Product( ' ', '100.00', '19.00' ) ], 'total' => '119.00', 'tax' => '19.00' ] );
		$this->assertSame( 'line_name', $this->reason( $o ) );
	}

	public function test_refuses_when_lines_do_not_reconcile(): void {
		$this->assertSame( 'totals', $this->reason( $this->order( [ 'total' => '137.85' ] ) ) );
	}

	public function test_three_decimal_prices_reconcile_or_refuse(): void {
		$items = [ new WC_Order_Item_Product( 'A', '0.333', '0.063' ), new WC_Order_Item_Product( 'B', '0.333', '0.063' ), new WC_Order_Item_Product( 'C', '0.333', '0.063' ) ];
		$this->assertSame( '', $this->reason( $this->order( [ 'items' => $items, 'total' => '1.19', 'tax' => '0.19' ] ) ) );
		$this->assertSame( 'totals', $this->reason( $this->order( [ 'items' => $items, 'total' => '1.30', 'tax' => '0.19' ] ) ) );
	}

	public function test_buyer_reference_from_b2g_when_set(): void {
		$o = $this->order();
		$this->assertSame( '', OrderReader::read( $o, false )->buyer['reference'] );
		$o->meta[ Reference::META ] = '991-12345-67';
		$this->assertSame( '991-12345-67', OrderReader::read( $o, false )->buyer['reference'] );
	}

	public function test_shipping_item_without_get_quantity_reads_fine(): void {
		$this->assertFalse( method_exists( new WC_Order_Item_Stub( 'x', '1', '0', 'shipping' ), 'get_quantity' ) );
		$this->assertSame( '1', OrderReader::read( $this->order(), false )->lines[1]->quantity );
	}

	public function test_mixed_taxed_and_untaxed_lines_refused(): void {
		$o = $this->order( [ 'items' => [ new WC_Order_Item_Product( 'Widget', '100.00', '19.00' ), new WC_Order_Item_Stub( 'Versand', '10.00', '0.00', 'shipping' ) ], 'total' => '129.00', 'tax' => '19.00' ] );
		$this->assertSame( 'tax_rate', $this->reason( $o, true ) );
	}

	public function test_all_untaxed_lines_get_rate_zero(): void {
		$o = $this->order( [ 'items' => [ new WC_Order_Item_Product( 'Widget', '100.00', '0.00' ), new WC_Order_Item_Stub( 'Versand', '10.00', '0.00', 'shipping' ) ], 'total' => '110.00', 'tax' => '0.00' ] );
		$this->assertSame( [ 0, 0 ], array_map( fn( $l ) => $l->ratePercent, OrderReader::read( $o, true )->lines ) );
	}

	public function test_free_shipping_on_taxed_order_is_fine(): void {
		$o = $this->order( [ 'items' => [ new WC_Order_Item_Product( 'Widget', '100.00', '19.00' ), new WC_Order_Item_Stub( 'Versand', '0.00', '0.00', 'shipping' ) ], 'total' => '119.00', 'tax' => '19.00' ] );
		$this->assertSame( [ 19, 0 ], array_map( fn( $l ) => $l->ratePercent, OrderReader::read( $o, false )->lines ) );
	}

	public function test_non_numeric_amount_refused(): void {
		$this->assertSame( 'totals', $this->reason( $this->order( [ 'total' => 'abc' ] ) ) );
	}

	public function test_empty_buyer_name_refused(): void {
		$this->assertSame( 'buyer_address', $this->reason( $this->order( [ 'company' => ' ', 'first' => '', 'last' => ' ' ] ) ) );
	}
}
