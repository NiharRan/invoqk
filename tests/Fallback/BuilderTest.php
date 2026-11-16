<?php
use Invoqk\Fallback\Builder;
use Invoqk\Fallback\Invoice;
use Invoqk\Fallback\InvoiceLine;
use Invoqk\Fallback\Seller;
use Invoqk\Fallback\Unsupported;
use PHPUnit\Framework\TestCase;

final class BuilderTest extends TestCase {
	private const XR  = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
	private const EN  = 'urn:cen.eu:en16931:2017';
	private const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [
			Seller::OPTION => [
				'name' => 'Shop GmbH', 'street' => 'Weg 1', 'postcode' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789',
				'tax_number' => '12/345/67890', 'contact_name' => 'Ann Muster', 'contact_phone' => '030 123456', 'contact_email' => 'rechnung@shop.example',
				'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'terms' => 'Zahlbar innerhalb von 14 Tagen ohne Abzug.',
			],
		];
	}

	private function buyer( array $over = [] ): array {
		return $over + [ 'name' => 'Max Kunde', 'company' => 'Kunde AG', 'street' => 'Hauptstr. 5', 'postcode' => '80331', 'city' => 'München',
			'country' => 'DE', 'email' => 'einkauf@kunde.example', 'vat_id' => 'DE987654321', 'reference' => 'LEIT-123' ];
	}

	private function invoice( array $lines, array $buyer = [], bool $nullsatz = false ): Invoice {
		return new Invoice( '1042', '2026-11-16', '2026-11-10', $lines, $this->buyer( $buyer ), $nullsatz );
	}

	private function build( Invoice $i, string $profile = 'xrechnung' ): string {
		return Builder::build( $i, Seller::get(), 'RE-2026-00001', $profile );
	}

	private function xp( string $xml ): DOMXPath {
		$d = new DOMDocument();
		$this->assertTrue( $d->loadXML( $xml ) );
		$x = new DOMXPath( $d );
		$x->registerNamespace( 'ram', self::RAM );
		$x->registerNamespace( 'rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100' );
		return $x;
	}

	private function text( string $xml, string $path ): string {
		return $this->xp( $xml )->evaluate( "string($path)" );
	}

	private function standard(): Invoice {
		return $this->invoice( [ new InvoiceLine( 'Kaffeebohnen 1 kg', '2', 10000, 19 ) ] );
	}

	private function assertGolden( string $file, string $xml ): void {
		$this->assertSame( file_get_contents( __DIR__ . '/fixtures/' . $file ), $xml );
	}

	private function assertHeader( string $xml, string $guideline ): void {
		$this->assertSame( $guideline, $this->text( $xml, '//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' ) );
		$this->assertSame( '380', $this->text( $xml, '//rsm:ExchangedDocument/ram:TypeCode' ) );
		$this->assertSame( 'EUR', $this->text( $xml, '//ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode' ) );
	}

	public function test_standard_xrechnung_matches_golden(): void {
		$xml = $this->build( $this->standard() );
		$this->assertGolden( 'standard-xrechnung.xml', $xml );
		$this->assertHeader( $xml, self::XR );
	}

	public function test_standard_en16931_matches_golden(): void {
		$xml = $this->build( $this->standard(), 'en16931' );
		$this->assertGolden( 'standard-en16931.xml', $xml );
		$this->assertHeader( $xml, self::EN );
	}

	public function test_mixed_rates_group_tax_per_rate(): void {
		$lines = [ new InvoiceLine( 'Buch', '1', 1000, 7 ), new InvoiceLine( 'Tasse', '1', 1000, 19 ) ];
		$this->assertSame( 2260, Builder::grandCents( $lines ) );
		$xml = $this->build( $this->invoice( $lines ) );
		$this->assertGolden( 'mixed-rates.xml', $xml );
		$x   = $this->xp( $xml );
		$tax = $x->query( '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax' );
		$this->assertSame( 2, $tax->length );
		$got = [];
		foreach ( $tax as $t ) {
			$got[ $x->evaluate( 'string(ram:RateApplicablePercent)', $t ) ] = $x->evaluate( 'string(ram:CalculatedAmount)', $t );
		}
		$this->assertSame( [ 19 => '1.90', 7 => '0.70' ], $got );
		$sum = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:';
		$this->assertSame( '20.00', $this->text( $xml, $sum . 'TaxBasisTotalAmount' ) );
		$this->assertSame( '2.60', $this->text( $xml, $sum . 'TaxTotalAmount' ) );
		$this->assertSame( '22.60', $this->text( $xml, $sum . 'GrandTotalAmount' ) );
		$this->assertSame( '22.60', $this->text( $xml, $sum . 'DuePayableAmount' ) );
	}

	public function test_grand_cents_rounds_half_up_on_group_basis(): void {
		// 3 lines of 50 cents at 7 percent: per-line tax would be 3 x 4 = 12, the group basis 150 gives 10.5 -> 11.
		$l = new InvoiceLine( 'x', '1', 50, 7 );
		$this->assertSame( 161, Builder::grandCents( [ $l, $l, $l ] ) );
		$this->assertSame( 0, Builder::grandCents( [] ) );
	}

	public function test_three_decimal_unit_price_uses_line_net(): void {
		$xml = $this->build( $this->invoice( [ new InvoiceLine( 'Drittel', '3', 1000, 19 ) ] ) );
		$this->assertSame( '10.00', $this->text( $xml, '//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount' ) );
		$this->assertSame( '3.3333', $this->text( $xml, '//ram:NetPriceProductTradePrice/ram:ChargeAmount' ) );
		$this->assertSame( '3', $this->text( $xml, '//ram:BilledQuantity' ) );
	}

	public function test_hostile_text_is_escaped_and_valid(): void {
		$name = 'A & B <C> "q" 😀';
		$xml  = $this->build( $this->invoice( [ new InvoiceLine( $name, '1', 100, 19 ) ], [ 'name' => $name, 'company' => "Evil\0 Co\f" ] ) );
		$this->assertSame( 0, preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $xml ) );
		$this->assertSame( $name, $this->text( $xml, '//ram:SpecifiedTradeProduct/ram:Name' ) );
		$this->assertSame( 'Evil Co', $this->text( $xml, '//ram:BuyerTradeParty/ram:Name' ) );
	}

	public function test_buyer_reference_falls_back_to_order_number(): void {
		$xml = $this->build( $this->invoice( [ new InvoiceLine( 'x', '1', 100, 19 ) ], [ 'reference' => '' ] ) );
		$this->assertSame( '1042', $this->text( $xml, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference' ) );
	}

	public function test_xrechnung_requires_buyer_email(): void {
		$i = $this->invoice( [ new InvoiceLine( 'x', '1', 100, 19 ) ], [ 'email' => '' ] );
		$this->assertStringContainsString( '<ram:BuyerTradeParty>', $this->build( $i, 'en16931' ) );
		try {
			$this->build( $i );
			$this->fail( 'expected Unsupported' );
		} catch ( Unsupported $e ) {
			$this->assertSame( 'buyer_email', $e->reason );
			$this->assertNotSame( '', $e->message() );
		}
	}

	public function test_zero_rate_needs_nullsatz(): void {
		$lines = [ new InvoiceLine( 'x', '1', 100, 0 ) ];
		try {
			$this->build( $this->invoice( $lines ) );
			$this->fail( 'expected Unsupported' );
		} catch ( Unsupported $e ) {
			$this->assertSame( 'settings', $e->reason );
		}
		$xml = $this->build( $this->invoice( $lines, [], true ) );
		$this->assertSame( 'Z', $this->text( $xml, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' ) );
		$this->assertSame( '0.00', $this->text( $xml, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount' ) );
	}

	public function test_other_rate_is_refused(): void {
		$this->expectExceptionObject( new Unsupported( 'tax_rate' ) );
		$this->build( $this->invoice( [ new InvoiceLine( 'x', '1', 100, 16 ) ] ) );
	}

	public function test_payment_means_is_58_with_iban(): void {
		$xml = $this->build( $this->standard() );
		$pm  = '//ram:SpecifiedTradeSettlementPaymentMeans/';
		$this->assertSame( '58', $this->text( $xml, $pm . 'ram:TypeCode' ) );
		$this->assertSame( 'DE89370400440532013000', $this->text( $xml, $pm . 'ram:PayeePartyCreditorFinancialAccount/ram:IBANID' ) );
		$this->assertSame( 'COBADEFFXXX', $this->text( $xml, $pm . 'ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID' ) );
		$this->assertSame( '20261130', $this->text( $xml, '//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/*' ) );
	}

	public function test_seller_with_tax_number_only_carries_it_as_bt29(): void {
		$s           = Seller::get();
		$s['vat_id'] = '';
		$x           = $this->xp( Builder::build( $this->standard(), $s, 'RE-2026-00001', 'xrechnung' ) );
		$this->assertSame( '12/345/67890', $x->evaluate( 'string(//ram:SellerTradeParty/ram:ID)' ) );
		$this->assertSame( 0, $x->query( '//ram:SellerTradeParty//ram:ID[@schemeID="VA"]' )->length );
		$this->assertSame( 0, $this->xp( $this->build( $this->standard() ) )->query( '//ram:SellerTradeParty/ram:ID' )->length );
	}

	public function test_every_reason_has_german_text(): void {
		foreach ( [ 'country', 'currency', 'tax_rate', 'refund', 'total', 'totals', 'buyer_address', 'buyer_email', 'line_name', 'settings' ] as $r ) {
			$m = ( new Unsupported( $r ) )->message();
			$this->assertNotSame( '', $m, $r );
			$this->assertNotSame( $r, $m );
		}
	}
}
