<?php
use Invoqk\Fallback\Seller;
use PHPUnit\Framework\TestCase;

final class SellerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
	}

	private function store( array $raw ): void {
		update_option( Seller::OPTION, Seller::sanitize( $raw ) );
	}

	private function full(): array {
		return [ 'name' => 'Shop GmbH', 'street' => 'Weg 1', 'postcode' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789',
			'contact_name' => 'Ann', 'contact_phone' => '030 123', 'contact_email' => 'a@example.com', 'iban' => 'DE89370400440532013000' ];
	}

	public function test_missing_lists_every_empty_required_field(): void {
		$this->assertSame( [ 'name', 'street', 'postcode', 'city', 'vat_id', 'contact_name', 'contact_phone', 'contact_email', 'iban' ], Seller::missing() );
	}

	public function test_tax_number_alone_satisfies_vat_requirement(): void {
		$raw = $this->full();
		unset( $raw['vat_id'] );
		$this->store( $raw + [ 'tax_number' => '12/345/67890' ] );
		$this->assertSame( [], Seller::missing() );
		$this->store( $raw );
		$this->assertSame( [ 'vat_id' ], Seller::missing() );
	}

	public function test_iban_shape_checked(): void {
		$this->store( [ 'iban' => 'de89 3704 0044 0532 0130 00' ] );
		$this->assertSame( 'DE89370400440532013000', Seller::get()['iban'] );
		$this->assertNotContains( 'iban', Seller::missing() );
		$this->store( [ 'iban' => 'DE12' ] );
		$this->assertContains( 'iban', Seller::missing() );
	}

	public function test_vat_id_shape_checked(): void {
		$this->store( [ 'vat_id' => 'DE123456789' ] );
		$this->assertNotContains( 'vat_id', Seller::missing() );
		$this->store( [ 'vat_id' => 'DE12345' ] );
		$this->assertContains( 'vat_id', Seller::missing() );
	}

	public function test_sanitize_drops_unknown_keys_and_clamps_profile(): void {
		$s = Seller::sanitize( [ 'profile' => 'bogus', 'due_days' => 999, 'evil' => 'x', 'nullsatz' => '1' ] );
		$this->assertArrayNotHasKey( 'evil', $s );
		$this->assertSame( 'xrechnung', $s['profile'] );
		$this->assertSame( 365, $s['due_days'] );
		$this->assertTrue( $s['nullsatz'] );
		$this->assertSame( 0, Seller::sanitize( [ 'due_days' => -5 ] )['due_days'] );
		$this->assertSame( 'en16931', Seller::sanitize( [ 'profile' => 'en16931' ] )['profile'] );
	}

	public function test_number_format_must_contain_counter_token(): void {
		$this->assertSame( 'RE-{YYYY}-{00001}', Seller::sanitize( [ 'number_format' => 'INV-2026' ] )['number_format'] );
		$this->assertSame( 'X{000}', Seller::sanitize( [ 'number_format' => 'X{000}' ] )['number_format'] );
		foreach ( [ 'RE-{0001}', 'RE-{YYYY}-{00001}' ] as $f ) {
			$this->assertSame( $f, Seller::sanitize( [ 'number_format' => $f ] )['number_format'] );
		}
	}

	public function test_invalid_email_flagged(): void {
		$this->store( [ 'contact_email' => 'not an email' ] );
		$this->assertContains( 'contact_email', Seller::missing() );
		$this->store( [ 'contact_email' => 'a@example.com' ] );
		$this->assertNotContains( 'contact_email', Seller::missing() );
	}

	public function test_defaults(): void {
		$g = Seller::get();
		$this->assertSame( [ 'xrechnung', 14, 'RE-{YYYY}-{00001}', false ], [ $g['profile'], $g['due_days'], $g['number_format'], $g['nullsatz'] ] );
		$this->assertNotSame( '', Seller::label( 'iban' ) );
	}

	public function test_needed_lists_nine_required_keys(): void {
		$this->assertCount( 9, Seller::needed() );
		$this->assertContains( 'iban', Seller::needed() );
	}
}
