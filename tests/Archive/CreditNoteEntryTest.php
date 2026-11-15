<?php
use Invoqk\Archive\Archive;
use Invoqk\Archive\Chain;
use Invoqk\Archive\CreditNoteEntry;
use Invoqk\Archive\LocalStorage;
use Invoqk\Archive\Verify;
use Invoqk\CreditNote\Store;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\SourceAdapter;
use Invoqk\Tests\Archive\MemoryIndex;
use PHPUnit\Framework\TestCase;

final class CreditNoteEntryTest extends TestCase {
	private string $base;
	public ?string $bytes = '<CreditNote>A</CreditNote>';
	public bool $throws   = false;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_log']     = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_filters'] = [ 'invoqk_is_pro' => [ fn() => true ] ];
		Archive::reset();
		$this->base       = sys_get_temp_dir() . '/invoqk-cne-' . bin2hex( random_bytes( 4 ) );
		Archive::$storage = new LocalStorage( $this->base );
		Archive::$index   = new MemoryIndex();
		$this->bytes      = '<CreditNote>A</CreditNote>';
		$this->throws     = false;
		$self             = $this;
		add_filter( 'invoqk_source_adapters', static fn( $l ) => [ new class( $self ) implements SourceAdapter {
			public function __construct( private CreditNoteEntryTest $t ) {}
			public function id(): string { return 'rd'; }
			public function label(): string { return 'RD'; }
			public function isAvailable(): bool { return true; }
			public function find( \WC_Order $o ): ?InvoiceFile { return null; }
			public function read( \WC_Order $o, InvoiceFile $f ): ?string { if ( $this->t->throws ) { throw new \Error( 'LEAKY' ); } return $this->t->bytes; }
			public function findCreditNotes( \WC_Order $o ): array { return []; }
		} ] );
	}

	protected function tearDown(): void {
		$GLOBALS['invoqk_filters'] = [];
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function meta(): callable {
		return fn( $o, $rid ) => [ 'invoice_number' => 'CN-' . $rid, 'invoice_date' => '2026-02-03' ];
	}

	private function order( int $refundId = 11 ): \WC_Order {
		$o          = $GLOBALS['invoqk_orders'][5] = new class( 5 ) extends \WC_Order {
			public bool $failMeta = false;
			public function save_meta_data(): void { if ( $this->failMeta ) { throw new \RuntimeException( 'db' ); } parent::save_meta_data(); }
		};
		$o->total   = '119.00';
		$o->company = 'Acme';
		$o->refunds = [ new class( $refundId ) {
			public function __construct( private int $id ) {}
			public function get_id() { return $this->id; }
			public function get_total() { return '-59.50'; }
			public function get_total_tax() { return '-9.50'; }
		} ];
		Store::put( $o, $refundId, [ 'source' => 'rd', 'file' => [ 'reference' => 7, 'mime' => 'application/xml', 'format' => 'ubl', 'onDemand' => false ], 'detectedAt' => 1, 'validation' => null, 'archived' => null ] );
		return $o;
	}

	private function go( string $status = 'passed', int $refundId = 11 ): ?array {
		return CreditNoteEntry::handle( 5, $refundId, [ 'status' => $status ], $this->meta() );
	}

	public function test_credit_note_row_is_appended_with_parent_and_kind(): void {
		$o = $this->order();
		$inv = Archive::index()->append( [ 'order_id' => 5, 'version' => 1, 'kind' => 'invoice', 'invoice_number' => 'INV' ], [ Chain::class, 'hash' ] );
		$r = $this->go( 'failed' );
		$this->assertSame( 11, $r['order_id'] );
		$this->assertSame( 1, $r['version'] );
		$this->assertSame( 'credit_note', $r['kind'] );
		$this->assertSame( $inv['id'], $r['parent_id'] );
		$this->assertSame( 'CN-11', $r['invoice_number'] );
		$this->assertSame( '-59.50', $r['gross'] );
		$this->assertSame( '-9.50', $r['vat'] );
		$this->assertSame( 'failed', $r['validation_status'] );
		$this->assertSame( 0, $r['sent_confirmed'] );
		$this->assertSame( hash( 'sha256', $this->bytes ), $r['xml_hash'] );
		$this->assertNotNull( Archive::storage()->path( $r['xml_key'] ) );
		$this->assertSame( $r['id'], Store::get( $o )[11]['archived'] );
		$this->assertSame( [], glob( Archive::storage()->staging() . '/cn*' ) ?: [] );
	}

	public function test_second_call_does_not_append_again(): void {
		$this->order();
		$this->assertNotNull( $this->go() );
		$this->assertNull( $this->go() );
		$this->assertSame( 1, Archive::index()->count() );
	}

	public function test_pending_or_error_status_is_not_archived(): void {
		$this->order();
		$this->assertNull( $this->go( 'error' ) );
		$this->assertNull( $this->go( 'pending' ) );
		$this->assertSame( 0, Archive::index()->count() );
	}

	public function test_missing_original_invoice_row_sets_parent_null(): void {
		$this->order();
		$this->assertNull( $this->go()['parent_id'] );
	}

	public function test_adapter_failure_leaves_no_row_and_archived_null(): void {
		$o            = $this->order();
		$this->throws = true;
		$this->assertNull( $this->go() );
		$this->assertSame( 0, Archive::index()->count() );
		$this->assertNull( Store::get( $o )[11]['archived'] );
		$this->assertStringContainsString( 'Error', implode( ' ', $GLOBALS['invoqk_log'] ) );
		$this->assertStringNotContainsString( 'LEAKY', implode( ' ', $GLOBALS['invoqk_log'] ) );
		$this->throws = false;
		$this->assertNotNull( $this->go() );
	}

	public function test_identical_file_content_reuses_the_stored_key(): void {
		$o = $this->order();
		$e = Store::get( $o )[11];
		Store::put( $o, 12, $e );
		$o->refunds[] = new class { public function get_id() { return 12; } public function get_total() { return '-1'; } public function get_total_tax() { return '0'; } };
		$a = $this->go();
		$b = $this->go( 'passed', 12 );
		$this->assertSame( $a['xml_key'], $b['xml_key'] );
	}

	public function test_existing_row_with_null_archived_is_not_duplicated(): void {
		$this->order();
		Archive::index()->append( [ 'order_id' => 11, 'version' => 1, 'kind' => 'credit_note' ], [ Chain::class, 'hash' ] );
		$this->assertNull( $this->go() );
		$this->assertSame( 1, Archive::index()->count() );
	}

	public function test_failed_store_put_after_append_does_not_duplicate_on_retry(): void {
		$o = $this->order();
		$o->failMeta = true;
		$this->assertNull( $this->go() );
		$this->assertSame( 1, Archive::index()->count() );
		$o->failMeta = false;
		$this->assertNull( $this->go() );
		$this->assertSame( 1, Archive::index()->count() );
	}

	public function test_hash_chain_still_verifies_with_a_credit_note_row(): void {
		$this->order();
		Archive::index()->append( [ 'order_id' => 5, 'version' => 1, 'kind' => 'invoice' ], [ Chain::class, 'hash' ] );
		$this->go();
		Verify::run( 0, '' );
		$this->assertSame( 'ok', Verify::result()['state'] );
	}

	public function test_free_plan_archives_nothing(): void {
		$this->order();
		$GLOBALS['invoqk_filters'] = [ 'invoqk_source_adapters' => $GLOBALS['invoqk_filters']['invoqk_source_adapters'] ];
		$this->assertNull( $this->go() );
	}
}
