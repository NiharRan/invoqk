<?php
use Invoqk\CreditNote\Detect;
use Invoqk\CreditNote\Store;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\SourceAdapter;
use Invoqk\Validation\CreditNoteJob;
use PHPUnit\Framework\TestCase;

final class CreditNoteJobTest extends TestCase {
	private int $calls = 0;
	public int $reads = 0;
	public bool $throws = false;
	private ?string $bytes = '<CreditNote>NOTEBYTES</CreditNote>';
	private array $res = [ 200, [ 'verdict' => 'pass' ], '' ];

	protected function setUp(): void {
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => 'SECRETKEY123' ];
		$GLOBALS['invoqk_filters'] = [ 'invoqk_is_pro' => [ fn() => true ] ];
		$GLOBALS['invoqk_log']     = [];
		$this->calls  = 0;
		$this->reads  = 0;
		$this->throws = false;
		$this->bytes  = '<CreditNote>NOTEBYTES</CreditNote>';
		$this->res    = [ 200, [ 'verdict' => 'pass' ], '' ];
		$self = $this;
		add_filter( 'invoqk_source_adapters', static fn( $l ) => [ new class( $self ) implements SourceAdapter {
			public function __construct( private CreditNoteJobTest $t ) {}
			public function id(): string { return 'rd'; }
			public function label(): string { return 'RD'; }
			public function isAvailable(): bool { return true; }
			public function find( \WC_Order $o ): ?InvoiceFile { return null; }
			public function read( \WC_Order $o, InvoiceFile $f ): ?string { ++$this->t->reads; if ( $this->t->throws ) { throw new \Error( 'LEAKY' ); } return $this->t->bytes(); }
			public function findCreditNotes( \WC_Order $o ): array { return []; }
		} ] );
	}

	protected function tearDown(): void {
		$GLOBALS['invoqk_filters'] = [];
		unset( $GLOBALS['invoqk_actions']['invoqk_credit_note_validated'] );
	}

	public function bytes(): ?string { return $this->bytes; }

	private function order(): WC_Order {
		$o = $GLOBALS['invoqk_orders'][1] = new WC_Order( 1 );
		Store::put( $o, 11, [ 'source' => 'rd', 'file' => [ 'reference' => 5, 'mime' => 'application/xml', 'format' => 'ubl', 'onDemand' => false ], 'detectedAt' => 1, 'validation' => null, 'archived' => null ] );
		return $o;
	}

	private function go( int $attempt = 1 ): void {
		CreditNoteJob::handle( 1, 11, $attempt, function () {
			++$this->calls;
			return [ 'response' => [ 'code' => $this->res[0] ], 'body' => json_encode( $this->res[1] ), 'headers' => [ 'retry-after' => $this->res[2] ] ];
		} );
	}

	private function v( WC_Order $o ): ?array { return Store::get( $o )[11]['validation'] ?? null; }

	public function test_pass_is_stored(): void {
		$o = $this->order();
		$this->go();
		$this->assertSame( 'passed', $this->v( $o )['status'] );
		$this->assertSame( hash( 'sha256', $this->bytes ), $this->v( $o )['hash'] );
	}

	public function test_fail_is_stored_with_issues(): void {
		$this->res = [ 200, [ 'verdict' => 'fail', 'issues' => [ [ 'id' => 'x', 'message' => 'm' ] ] ], '' ];
		$o = $this->order();
		$this->go();
		$this->assertSame( 'failed', $this->v( $o )['status'] );
		$this->assertNotEmpty( $this->v( $o )['issues'] );
	}

	public function test_unreadable_file_stores_error_and_schedules_retry(): void {
		$this->bytes = null;
		$o = $this->order();
		$this->go();
		$this->assertSame( 'unreadable', $this->v( $o )['error']['code'] );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( [], $GLOBALS['invoqk_as'] ); // Mapper::retryDelay gives unreadable no retry, same as Job
	}

	public function test_503_schedules_credit_note_retry_with_next_attempt(): void {
		$this->res = [ 503, [], '' ];
		$o = $this->order();
		$this->go();
		$this->assertSame( 'error', $this->v( $o )['status'] );
		$this->assertSame( Detect::VALIDATE, $GLOBALS['invoqk_as'][0]['hook'] );
		$this->assertSame( [ 1, 11, 2 ], $GLOBALS['invoqk_as'][0]['args'] );
	}

	public function test_throwing_adapter_does_not_crash_and_logs_class_only(): void {
		$this->throws = true;
		$o = $this->order();
		$this->go();
		$this->assertSame( 'unreadable', $this->v( $o )['error']['code'] );
		$this->assertStringNotContainsString( 'LEAKY', implode( "\n", $GLOBALS['invoqk_log'] ) );
		$this->assertStringContainsString( 'Error', implode( "\n", $GLOBALS['invoqk_log'] ) );
	}

	public function test_same_hash_is_not_posted_again(): void {
		$this->order();
		$this->go();
		$this->go();
		$this->assertSame( 1, $this->calls );
	}

	public function test_no_api_key_stores_no_key_error_before_reading(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = '';
		$o = $this->order();
		$this->go();
		$this->assertSame( 'no_key', $this->v( $o )['error']['code'] );
		$this->assertSame( 0, $this->reads );
		$this->assertSame( 0, $this->calls );
	}

	public function test_free_install_does_nothing(): void {
		$GLOBALS['invoqk_filters'] = [];
		$o = $this->order();
		$this->go();
		$this->assertNull( $this->v( $o ) );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( 0, $this->reads );
	}

	public function test_fires_validated_hook_once(): void {
		$this->order();
		$seen = [];
		$GLOBALS['invoqk_actions']['invoqk_credit_note_validated'] = [ [ function ( $o, $r, $rec ) use ( &$seen ) { $seen[] = [ $o, $r, $rec['status'] ]; }, 3 ] ];
		$this->go();
		$this->assertSame( [ [ 1, 11, 'passed' ] ], $seen );
	}
}
