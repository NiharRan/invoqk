<?php
use Invoqk\Classification\Classifier;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [];
	}

	private function order( string $country, string $company, string $vat, string $total, string $ship = '', string $vatKey = '_billing_vat_id' ): WC_Order {
		$o              = new WC_Order();
		$o->country     = $country;
		$o->shipCountry = $ship;
		$o->company     = $company;
		$o->total       = $total;
		if ( '' !== $vat ) {
			$o->update_meta_data( $vatKey, $vat );
		}
		return $o;
	}

	public static function cases(): array {
		return [
			'1 private'                 => [ 'DE', '', '', '500', '', false, 'private', null ],
			'2 company only'            => [ 'DE', 'Acme GmbH', '', '500', '', true, 'company_only', 'no_vat_id' ],
			'3 vat id only'             => [ 'DE', '', 'DE123456789', '500', '', true, 'vat_id', null ],
			'4 company and vat id'      => [ 'DE', 'Acme GmbH', 'DE123456789', '500', '', true, 'vat_id', null ],
			'5 exactly 250'             => [ 'DE', 'Acme GmbH', 'DE123456789', '250.00', '', false, 'small_amount', null ],
			'6 250.01'                  => [ 'DE', 'Acme GmbH', 'DE123456789', '250.01', '', true, 'vat_id', null ],
			'7 small private'           => [ 'DE', '', '', '100', '', false, 'private', null ],
			'8 zero total'              => [ 'DE', 'Acme GmbH', 'DE123456789', '0', '', false, 'zero_total', null ],
			'9 foreign'                 => [ 'AT', 'Acme GmbH', 'ATU12345678', '500', '', false, 'foreign', null ],
			'10 bad vat format'         => [ 'DE', 'Acme GmbH', 'DE12', '500', '', true, 'vat_id', 'vat_id_format' ],
			'11 vat with spaces, lower' => [ 'DE', 'Acme GmbH', 'de 123 456 789', '500', '', true, 'vat_id', null ],
			'12 no country'             => [ '', 'Acme GmbH', 'DE123456789', '500', '', false, 'no_country', null ],
			'13 shipping country used'  => [ '', 'Acme GmbH', 'DE123456789', '500', 'DE', true, 'vat_id', null ],
			'14 blank strings'          => [ 'DE', '   ', '  ', '500', '', false, 'private', null ],
			'15 eu vat plugin key'      => [ 'DE', '', 'DE123456789', '500', '', true, 'vat_id', null ],
			'16 empty total'            => [ 'DE', 'Acme GmbH', 'DE123456789', '', '', false, 'zero_total', null ],
		];
	}

	/** @dataProvider cases */
	public function test_rules( string $country, string $company, string $vat, string $total, string $ship, bool $req, string $reason, ?string $hint ): void {
		$key = '15 eu vat plugin key' === $this->dataName() ? '_billing_eu_vat_number' : '_billing_vat_id';
		$r   = Classifier::classify( $this->order( $country, $company, $vat, $total, $ship, $key ) );
		$this->assertSame( $req, $r['required'] );
		$this->assertSame( $reason, $r['reason'] );
		$this->assertSame( $hint, $r['hint'] );
	}

	public function test_threshold_filter_moves_the_cutoff(): void {
		add_filter( 'invoqk_classifier_threshold', fn() => 100.0 );
		$this->assertTrue( Classifier::classify( $this->order( 'DE', 'Acme', 'DE123456789', '150', '' ) )['required'] );
	}

	public function test_vat_meta_key_filter_adds_a_key(): void {
		add_filter( 'invoqk_vat_id_meta_keys', fn( $k ) => array_merge( $k, [ '_my_vat' ] ) );
		$r = Classifier::classify( $this->order( 'DE', '', 'DE123456789', '500', '', '_my_vat' ) );
		$this->assertSame( 'vat_id', $r['reason'] );
	}

	public function test_vat_keys_default_and_filter(): void {
		$this->assertSame( [ '_billing_vat_id', '_billing_eu_vat_number', '_billing_vat_number', '_vat_number' ], Classifier::vatKeys() );
		add_filter( 'invoqk_vat_id_meta_keys', fn( $k ) => array_merge( $k, [ '_my_vat' ] ) );
		$this->assertContains( '_my_vat', Classifier::vatKeys() );
	}

	public function test_override_wins_and_survives_reclassification(): void {
		$o = $this->order( 'DE', '', '', '500' );
		Classifier::save( $o );
		$this->assertFalse( Classifier::isRequired( $o ) );
		$o->update_meta_data( Classifier::OVERRIDE, [ 'required' => true, 'by' => 7, 'at' => 1 ] );
		$this->assertTrue( Classifier::isRequired( $o ) );
		$this->assertTrue( Classifier::isOverridden( $o ) );
		$o->company = 'New GmbH';
		Classifier::save( $o );
		$this->assertSame( 'company_only', Classifier::stored( $o )['reason'] );
		$this->assertTrue( Classifier::isOverridden( $o ), 'override must be untouched' );
	}

	public function test_unclassified_order_is_computed_without_writing_meta(): void {
		$o = $this->order( 'DE', 'Acme', 'DE123456789', '500' );
		$this->assertTrue( Classifier::isRequired( $o ) );
		$this->assertSame( '', $o->get_meta( Classifier::CLASS_META ) );
		$this->assertSame( 0, $o->metaSaves );
	}

	public function test_save_writes_class_meta_with_its_own_meta_save(): void {
		$o = $this->order( 'DE', 'Acme', 'DE123456789', '500' );
		Classifier::save( $o );
		$this->assertSame( 1, $o->metaSaves );
		$this->assertTrue( Classifier::stored( $o )['required'] );
	}

	public function test_every_reason_and_hint_has_text(): void {
		foreach ( [ 'vat_id', 'company_only', 'private', 'foreign', 'small_amount', 'zero_total', 'no_country' ] as $c ) {
			$this->assertNotSame( '', Classifier::reasonText( $c, 250.0 ), $c );
		}
		foreach ( [ 'no_vat_id', 'vat_id_format' ] as $h ) {
			$this->assertNotSame( '', Classifier::hintText( $h ), $h );
		}
		$this->assertStringContainsString( '250', Classifier::reasonText( 'small_amount', 250.0 ) );
	}
}
