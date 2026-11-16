<?php
use Invoqk\Fallback\Actions;
use Invoqk\Fallback\Seller;
use Invoqk\Fallback\Store;
use Invoqk\Sources\FallbackAdapter;
use PHPUnit\Framework\TestCase;

final class FallbackActionsTest extends TestCase {
	private WC_Order $o;
	private array $sent = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options']    = [];
		$GLOBALS['invoqk_filters']    = [];
		$GLOBALS['invoqk_transients'] = [];
		$GLOBALS['invoqk_can']        = true;
		$GLOBALS['invoqk_nonce_ok']   = true;
		$GLOBALS['invoqk_uploads']    = sys_get_temp_dir() . '/invoqk-act-' . bin2hex( random_bytes( 4 ) );
		add_filter( 'invoqk_is_pro', fn() => true );
		add_filter( 'invoqk_source_adapters', fn() => [ new FallbackAdapter() ] );
		update_option( Seller::OPTION, Seller::sanitize( [ 'name' => 'Shop GmbH', 'street' => 'Weg 1', 'postcode' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789',
			'contact_name' => 'Ann', 'contact_phone' => '030 123', 'contact_email' => 'a@example.com', 'iban' => 'DE89370400440532013000' ] ) );
		$this->o                  = new WC_Order( 5 );
		$GLOBALS['invoqk_orders'] = [ 5 => $this->o ];
		$_REQUEST['order_id']     = '5';
		$this->sent               = [];
		Actions::$sender          = function ( string $number, string $xml ): void {
			$this->sent[] = [ $number, $xml ];
		};
	}

	protected function tearDown(): void {
		Actions::$sender = null;
		unset( $GLOBALS['invoqk_can'], $GLOBALS['invoqk_nonce_ok'], $_REQUEST['order_id'] );
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['invoqk_uploads'] ) );
	}

	public function test_box_offers_link_with_nonce_and_limits_notice(): void {
		$h = Actions::box( $this->o );
		$this->assertStringContainsString( 'action=invoqk_fallback_generate', $h );
		$this->assertStringContainsString( 'order_id=5', $h );
		$this->assertStringContainsString( '_wpnonce', $h );
		$this->assertStringContainsString( 'For complex tax cases', $h );
	}

	public function test_box_hidden_when_plugin_invoice_exists(): void {
		$this->o->update_meta_data( '_invoqk_source', 'germanized_pro' );
		$this->assertSame( '', Actions::box( $this->o ) );
	}

	public function test_box_hidden_on_free_without_invoice(): void {
		add_filter( 'invoqk_is_pro', fn() => false );
		$this->assertSame( '', Actions::box( $this->o ) );
	}

	public function test_box_lists_missing_seller_fields(): void {
		update_option( Seller::OPTION, [] );
		$h = Actions::box( $this->o );
		$this->assertStringNotContainsString( 'action=invoqk_fallback_generate', $h );
		$this->assertStringContainsString( 'page=invoqk', $h );
		$this->assertStringContainsString( esc_html( Seller::label( Seller::missing()[0] ) ), $h );
	}

	public function test_box_shows_number_and_download_after_generation(): void {
		Store::save( $this->o, '<x/>', 'RE-2026-00001', 'xrechnung' );
		$h = Actions::box( $this->o );
		$this->assertStringContainsString( 'RE-2026-00001', $h );
		$this->assertStringContainsString( 'xrechnung', $h );
		$this->assertStringContainsString( 'action=invoqk_fallback_download', $h );
		$this->assertStringNotContainsString( 'action=invoqk_fallback_generate', $h );
	}

	public function test_box_shows_refusal_once(): void {
		set_transient( 'invoqk_fallback_msg_5', 'Nicht <möglich>', 60 );
		$h = Actions::box( $this->o );
		$this->assertStringContainsString( 'Nicht &lt;möglich&gt;', $h );
		$this->assertStringNotContainsString( 'Nicht &lt;möglich&gt;', Actions::box( $this->o ) );
	}

	public function test_generate_callback_rejects_without_capability_or_nonce(): void {
		$GLOBALS['invoqk_can'] = false;
		try {
			Actions::generateCallback();
			$this->fail( 'expected die' );
		} catch ( InvoqkTestDie $e ) {
			$this->assertNull( Store::get( $this->o ) );
		}
		$GLOBALS['invoqk_can']      = true;
		$GLOBALS['invoqk_nonce_ok'] = false;
		$this->expectException( InvoqkTestDie::class );
		Actions::generateCallback();
	}

	public function test_download_rejects_without_capability_or_nonce(): void {
		Store::save( $this->o, '<x/>', 'RE-1', 'xrechnung' );
		$GLOBALS['invoqk_nonce_ok'] = false;
		try {
			Actions::downloadCallback();
			$this->fail( 'expected die' );
		} catch ( InvoqkTestDie $e ) {
			$this->assertSame( [], $this->sent );
		}
	}

	public function test_download_streams_only_files_inside_store_dir(): void {
		Store::save( $this->o, '<x/>', 'RE/2026 "1"', 'xrechnung' );
		Actions::downloadCallback();
		$this->assertSame( [ [ 'RE_2026__1_', '<x/>' ] ], $this->sent );

		$this->sent = [];
		$this->o->update_meta_data( Store::META, [ 'file' => '../../etc/passwd' ] + Store::get( $this->o ) );
		try {
			Actions::downloadCallback();
		} catch ( InvoqkTestDie $e ) {
		}
		$this->assertSame( [], $this->sent );
	}

	public function test_download_still_works_on_free(): void {
		Store::save( $this->o, '<x/>', 'RE-1', 'xrechnung' );
		add_filter( 'invoqk_is_pro', fn() => false );
		$this->assertStringContainsString( 'action=invoqk_fallback_download', Actions::box( $this->o ) );
		Actions::downloadCallback();
		$this->assertSame( [ [ 'RE-1', '<x/>' ] ], $this->sent );
	}
}
