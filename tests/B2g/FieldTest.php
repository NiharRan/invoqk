<?php
use Invoqk\B2g\Field;
use Invoqk\B2g\LeitwegId;
use Invoqk\B2g\Reference;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
	function woocommerce_register_additional_checkout_field( array $options ): void { $GLOBALS['invoqk_block_fields'][ $options['id'] ] = $options; }
}

final class FieldTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters']      = [];
		$GLOBALS['invoqk_actions']      = [];
		$GLOBALS['invoqk_options']      = [];
		$GLOBALS['invoqk_block_fields'] = [];
		$_POST                          = [];
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	protected function tearDown(): void {
		$_POST = [];
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	private function boot( string $mode, bool $pro = true ): void {
		$GLOBALS['invoqk_options'][ Field::OPTION ] = $mode;
		if ( $pro ) {
			add_filter( 'invoqk_is_pro', fn() => true );
		}
		Field::boot();
		do_action( 'woocommerce_init' );
	}

	private function billing(): array {
		return apply_filters( 'woocommerce_checkout_fields', [ 'billing' => [ 'billing_company' => [] ] ] )['billing'];
	}

	public function test_off_registers_no_field(): void {
		$this->boot( 'off' );
		$this->assertSame( [], $GLOBALS['invoqk_actions'] );
		$this->assertArrayNotHasKey( 'invoqk_leitweg_id', $this->billing() );
		$this->assertSame( [], $GLOBALS['invoqk_block_fields'] );
	}

	public function test_free_install_registers_no_field(): void {
		$this->boot( 'always', false );
		$this->assertSame( [], $GLOBALS['invoqk_actions'] );
		$this->assertArrayNotHasKey( 'invoqk_leitweg_id', $this->billing() );
		$this->assertSame( [], $GLOBALS['invoqk_block_fields'] );
	}

	public function test_classic_field_added_when_always(): void {
		$this->boot( 'always' );
		$f = $this->billing()['invoqk_leitweg_id'];
		$this->assertSame( 'Leitweg-ID / Buyer reference', $f['label'] );
		$this->assertFalse( $f['required'] );
		$this->assertContains( 'invoqk-leitweg', $f['class'] );
		$_SERVER['REQUEST_METHOD'] = 'POST'; // no company posted: always mode does not care
		$this->assertArrayHasKey( 'invoqk_leitweg_id', $this->billing() );

		$b = $GLOBALS['invoqk_block_fields']['invoqk/leitweg_id'];
		$this->assertSame( 'order', $b['location'] ); // stored as _wc_other/invoqk/leitweg_id
		$this->assertSame( 'text', $b['type'] );
		$this->assertFalse( $b['required'] );
		$this->assertSame( '04011000-12345-67', $b['sanitize_callback']( ' 04011000 - 12345 - 67 ', $b ) );
	}

	public function test_business_mode_adds_field_only_with_company_posted(): void {
		$this->boot( 'business' );
		$this->assertArrayHasKey( 'invoqk_leitweg_id', $this->billing() ); // page render: the company is not known yet
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['billing_company']  = '  ';
		$this->assertArrayNotHasKey( 'invoqk_leitweg_id', $this->billing() );
		$_POST['billing_company'] = 'Stadt Musterhausen';
		$this->assertArrayHasKey( 'invoqk_leitweg_id', $this->billing() );
		$this->assertArrayHasKey( 'invoqk/leitweg_id', $GLOBALS['invoqk_block_fields'] ); // block API cannot hide by company on WC 9.0
	}

	private function placeOrder(): WC_Order {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$data                      = array_intersect_key( $_POST, $this->billing() ); // as WC_Checkout::get_posted_data
		$e                         = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', $data, $e );
		$this->assertFalse( $e->has_errors() );
		$o = new WC_Order( 0 );
		do_action( 'woocommerce_checkout_create_order', $o, $data );
		return $o;
	}

	public function test_business_mode_vat_only_buyer_is_saved(): void {
		$this->boot( 'business' );
		$_POST = [ 'billing_company' => '', 'billing_vat_number' => 'DE123456789', 'invoqk_leitweg_id' => '04011000-12345-67' ];
		$this->assertSame( '04011000-12345-67', Reference::get( $this->placeOrder() ) );
	}

	public function test_business_mode_without_company_or_vat_is_not_saved(): void {
		$this->boot( 'business' );
		$_POST = [ 'billing_company' => ' ', 'billing_vat_id' => ' ', 'invoqk_leitweg_id' => '04011000-12345-67' ];
		$this->assertSame( '', Reference::get( $this->placeOrder() ) );
	}

	public function test_business_mode_honours_custom_vat_key(): void {
		$this->boot( 'business' );
		$_POST = [ 'my_vat' => 'ATU12345678', 'invoqk_leitweg_id' => '04011000-12345-67' ];
		$this->assertSame( '', Reference::get( $this->placeOrder() ) );
		add_filter( 'invoqk_vat_id_meta_keys', fn( $k ) => array_merge( $k, [ '_my_vat' ] ) );
		$this->assertSame( '04011000-12345-67', Reference::get( $this->placeOrder() ) );
	}

	public function test_bad_id_blocks_checkout_with_german_message(): void {
		$this->boot( 'always' );
		$e = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', [ 'invoqk_leitweg_id' => '0401_1000-67' ], $e );
		$this->assertSame( [ LeitwegId::message( 'chars' ) ], $e->get_error_messages() );

		$b   = $GLOBALS['invoqk_block_fields']['invoqk/leitweg_id'];
		$res = $b['validate_callback']( '04011000-12345', $b );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( [ LeitwegId::message( 'parts' ) ], $res->get_error_messages() );
	}

	public function test_valid_id_is_saved_on_the_order(): void {
		$this->boot( 'always' );
		$e = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', [ 'invoqk_leitweg_id' => '04011000-12345-67' ], $e );
		$this->assertFalse( $e->has_errors() );
		$b = $GLOBALS['invoqk_block_fields']['invoqk/leitweg_id'];
		$this->assertTrue( $b['validate_callback']( '04011000-12345-67', $b ) );

		$o = new WC_Order( 0 );
		do_action( 'woocommerce_checkout_create_order', $o, [ 'invoqk_leitweg_id' => '04011000 12345 67' ] );
		$this->assertSame( '04011000-12345-67', Reference::get( $o ) );
	}

	public function test_empty_id_is_accepted(): void {
		$this->boot( 'business' );
		$e = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', [ 'invoqk_leitweg_id' => '' ], $e );
		do_action( 'woocommerce_after_checkout_validation', [], $e ); // business mode without company: field not posted
		$this->assertFalse( $e->has_errors() );
		$b = $GLOBALS['invoqk_block_fields']['invoqk/leitweg_id'];
		$this->assertTrue( $b['validate_callback']( '', $b ) );

		$o = new WC_Order( 0 );
		$o->meta[ Reference::META ] = 'stale';
		do_action( 'woocommerce_checkout_create_order', $o, [] ); // not posted: leave the order alone
		$this->assertSame( 'stale', $o->meta[ Reference::META ] );
		do_action( 'woocommerce_checkout_create_order', $o, [ 'invoqk_leitweg_id' => '' ] );
		$this->assertArrayNotHasKey( Reference::META, $o->meta );
	}

	public function test_sanitize_mode_falls_back_to_off(): void {
		$this->assertSame( 'always', Field::sanitizeMode( 'always' ) );
		$this->assertSame( 'business', Field::sanitizeMode( 'business' ) );
		foreach ( [ 'off', 'ALWAYS', '', null, 1, [ 'always' ] ] as $v ) {
			$this->assertSame( 'off', Field::sanitizeMode( $v ) );
		}
		$this->assertSame( 'off', Field::mode() );
	}
}
