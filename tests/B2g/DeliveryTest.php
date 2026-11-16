<?php
use Invoqk\B2g\Delivery;
use Invoqk\B2g\Reference;
use Invoqk\Sources\BuyerReferenceAware;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\SourceAdapter;
use Invoqk\Tests\Sources\FakeAdapter;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_edi']     = false; // the built-in WP Overnight adapter stays unavailable unless a test wants it
		add_filter( 'invoqk_is_pro', fn() => true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['invoqk_edi'] );
	}

	private function order( string $source = 'fake', string $label = 'Fake Invoices' ): WC_Order {
		$o = new WC_Order();
		$o->meta[ Reference::META ] = '04011000-12345-67';
		$o->meta['_invoqk_source']  = $source;
		$o->meta['_invoqk_label']   = $label;
		return $o;
	}

	private function register( SourceAdapter $a ): void {
		add_filter( 'invoqk_source_adapters', fn( array $l ) => [ ...$l, $a ] );
	}

	private function aware( bool $supports, bool $available = true ): SourceAdapter {
		return new class( $supports, $available ) implements SourceAdapter, BuyerReferenceAware {
			public function __construct( private bool $supports, private bool $available ) {}
			public function id(): string { return 'fake'; }
			public function label(): string { return 'Fake Invoices'; }
			public function isAvailable(): bool { return $this->available; }
			public function find( \WC_Order $order ): ?InvoiceFile { return null; }
			public function read( \WC_Order $order, InvoiceFile $file ): ?string { return null; }
			public function findCreditNotes( \WC_Order $order ): array { return []; }
			public function supportsBuyerReference(): bool { return $this->supports; }
		};
	}

	public function test_none_without_reference(): void {
		$this->register( $this->aware( true ) );
		$o = $this->order();
		unset( $o->meta[ Reference::META ] );
		$this->assertSame( 'none', Delivery::status( $o ) );
		$this->assertSame( '', Delivery::notice( $o ) );
	}

	public function test_delivered_for_supporting_adapter(): void {
		$this->register( $this->aware( true ) );
		$this->assertSame( 'delivered', Delivery::status( $this->order() ) );
	}

	public function test_unsupported_for_adapter_without_interface(): void {
		$this->register( new FakeAdapter( 'fake' ) );
		$this->assertSame( 'unsupported', Delivery::status( $this->order() ) );
	}

	public function test_unsupported_when_the_adapter_declines_or_is_missing(): void {
		$this->register( $this->aware( false ) );
		$this->assertSame( 'unsupported', Delivery::status( $this->order() ) );
		$this->assertSame( 'unsupported', Delivery::status( $this->order( 'gone' ) ) );
		$o = $this->order();
		unset( $o->meta['_invoqk_source'] );
		$this->assertSame( 'unsupported', Delivery::status( $o ) );
	}

	public function test_notice_names_the_source_plugin(): void {
		$this->register( new FakeAdapter( 'fake' ) );
		$n = Delivery::notice( $this->order( 'fake', 'Fake <b>Invoices</b>' ) );
		$this->assertStringContainsString( 'Fake &lt;b&gt;Invoices&lt;/b&gt;', $n );
		$this->assertStringContainsString( 'must be entered', $n );
		$this->assertStringNotContainsString( '<b>', $n );
	}

	public function test_notice_without_a_detected_plugin(): void {
		$this->assertStringContainsString( 'your invoice plugin', Delivery::notice( $this->order( 'none', '' ) ) );
	}

	public function test_no_notice_when_delivered(): void {
		$this->register( $this->aware( true ) );
		$this->assertSame( '', Delivery::notice( $this->order() ) );
	}

	public function test_no_notice_without_pro(): void {
		$GLOBALS['invoqk_filters'] = [];
		add_filter( 'invoqk_is_pro', fn() => false );
		$this->register( new FakeAdapter( 'fake' ) );
		$this->assertSame( '', Delivery::notice( $this->order() ) );
	}

	private function fresh(): WC_Order {
		$o = new WC_Order();
		$o->meta[ Reference::META ] = '04011000-12345-67'; // not detected yet: no _invoqk_source
		return $o;
	}

	public function test_declining_aware_adapter_points_to_the_format_not_a_manual_entry(): void {
		$this->register( $this->aware( false ) );
		$n = Delivery::notice( $this->order() );
		$this->assertStringContainsString( 'cannot write the Leitweg-ID into its current e-invoice format', $n );
		$this->assertStringContainsString( 'UBL or PEPPOL BIS', $n );
		$this->assertStringNotContainsString( 'must be entered', $n );
	}

	public function test_fresh_order_uses_an_available_supporting_adapter(): void {
		$this->register( new FakeAdapter( 'other' ) );
		$this->register( $this->aware( true ) );
		$this->assertSame( 'delivered', Delivery::status( $this->fresh() ) );
		$this->assertSame( '', Delivery::notice( $this->fresh() ) );
	}

	public function test_fresh_order_ignores_an_unavailable_adapter(): void {
		$this->register( $this->aware( true, false ) );
		$this->assertSame( 'unsupported', Delivery::status( $this->fresh() ) );
		$this->assertStringContainsString( 'No invoice plugin', Delivery::notice( $this->fresh() ) );
	}

	public function test_fresh_order_with_an_available_plain_adapter_names_it(): void {
		$this->register( new FakeAdapter( 'other' ) );
		$n = Delivery::notice( $this->fresh() );
		$this->assertStringNotContainsString( 'No invoice plugin', $n );
		$this->assertStringContainsString( 'must be entered', $n );
	}

	public function test_pro_with_only_the_fallback_available_has_no_plugin(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_used'] = 1;
		$this->assertSame( 'unsupported', Delivery::status( $this->fresh() ) );
		$this->assertStringContainsString( 'No invoice plugin', Delivery::notice( $this->fresh() ) );
	}
}
