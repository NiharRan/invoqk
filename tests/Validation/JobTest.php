<?php
use Invoqk\Jobs\Scheduler;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\SourceAdapter;
use Invoqk\Validation\Job;
use Invoqk\Validation\Record;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase {
	private int $calls = 0;
	public int $reads = 0;
	public bool $available = true;
	public bool $throws = false;
	private ?string $bytes = '<Invoice>INVOICEBYTES</Invoice>';
	private array $res = [ 200, [ 'verdict' => 'pass' ], '' ];

	protected function setUp(): void {
		$GLOBALS['invoqk_as'] = [];
		$GLOBALS['invoqk_orders'] = [];
		$GLOBALS['invoqk_options'] = [ 'invoqk_api_key' => 'SECRETKEY123' ];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_log'] = [];
		$this->calls = 0;
		$this->reads = 0;
		$this->available = true;
		$this->throws = false;
		$this->bytes = '<Invoice>INVOICEBYTES</Invoice>';
		$this->res   = [ 200, [ 'verdict' => 'pass' ], '' ];
		$self = $this;
		add_filter( 'invoqk_source_adapters', static fn( $l ) => [ new class( $self ) implements SourceAdapter {
			public function __construct( private JobTest $t ) {}
			public function id(): string { return 'rd'; }
			public function label(): string { return 'RD'; }
			public function isAvailable(): bool { return $this->t->available; }
			public function find( \WC_Order $o ): ?InvoiceFile { return null; }
			public function read( \WC_Order $o, InvoiceFile $f ): ?string { ++$this->t->reads; if ( $this->t->throws ) { throw new \Error( 'LEAKY' ); } return $this->t->bytes(); }
			public function findCreditNotes( \WC_Order $o ): array { return []; }
		} ] );
	}

	public function bytes(): ?string { return $this->bytes; }

	private function order( int $id = 1, string $source = 'rd' ): WC_Order {
		$o = $GLOBALS['invoqk_orders'][ $id ] = new WC_Order( $id );
		$o->update_meta_data( '_invoqk_source', $source );
		$o->update_meta_data( '_invoqk_file', [ 'reference' => 5, 'mime' => 'application/xml', 'format' => 'ubl' ] );
		return $o;
	}

	private function go( int $id = 1, int $attempt = 1, bool $force = false ): void {
		Job::handle( $id, $attempt, $force, function ( $url, $args ) {
			++$this->calls;
			return [ 'response' => [ 'code' => $this->res[0] ], 'body' => json_encode( $this->res[1] ), 'headers' => [ 'retry-after' => $this->res[2] ] ];
		} );
	}

	private function code( WC_Order $o ): ?string { return Record::get( $o )['error']['code'] ?? null; }

	public function test_finish_fires_validated_action(): void {
		$o    = $this->order();
		$seen = [];
		$GLOBALS['invoqk_actions']['invoqk_validated'] = [ [ function ( $id, $rec ) use ( &$seen ) { $seen = [ $id, $rec['status'] ]; }, 2 ] ];
		$this->go();
		unset( $GLOBALS['invoqk_actions']['invoqk_validated'] );
		$this->assertSame( [ 1, 'passed' ], $seen );
	}

	public function test_trashed_order_exits_quietly(): void {
		$o = $this->order();
		$o->status = 'trash';
		$this->go();
		$this->assertSame( 0, $this->calls );
		$this->assertNull( Record::get( $o ) );
	}

	public function test_missing_order_exits_quietly(): void {
		$this->go( 99 );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_source_none_exits_without_record(): void {
		$o = $this->order( 1, 'none' );
		$this->go();
		$this->assertSame( 0, $this->calls );
		$this->assertNull( Record::get( $o ) );
	}

	public function test_no_key_writes_error_and_no_http(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = '';
		$o = $this->order();
		$this->go();
		$this->assertSame( 'no_key', $this->code( $o ) );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_unreadable_when_adapter_returns_null(): void {
		$this->bytes = null;
		$o = $this->order();
		$this->go();
		$this->assertSame( 'unreadable', $this->code( $o ) );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_key_checked_before_adapter_read(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = '';
		$this->reads = 0;
		$this->order();
		$this->go();
		$this->assertSame( 0, $this->reads );
	}

	public function test_unavailable_adapter_is_unreadable_without_http_or_retry(): void {
		$this->available = false;
		$o = $this->order();
		$this->go();
		$this->assertSame( 'unreadable', $this->code( $o ) );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_throwing_adapter_is_unreadable_and_log_has_no_message(): void {
		$this->throws = true;
		$o = $this->order();
		$this->go();
		$this->assertSame( 'unreadable', $this->code( $o ) );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		$this->assertStringNotContainsString( 'LEAKY', implode( "\n", $GLOBALS['invoqk_log'] ) );
	}

	public function test_same_hash_with_changed_reference_updates_ref_without_api_call(): void {
		$o = $this->order();
		$this->go();
		$o->update_meta_data( '_invoqk_file', [ 'reference' => 9, 'mime' => 'application/xml', 'format' => 'ubl' ] );
		$this->go();
		$this->assertSame( 1, $this->calls );
		$this->assertSame( '9', Record::get( $o )['ref'] );
		$this->assertSame( 'passed', Record::get( $o )['status'] );
	}

	public function test_pass_writes_passed_record_with_hash_and_ref(): void {
		$o = $this->order();
		$this->go();
		$r = Record::get( $o );
		$this->assertSame( 'passed', $r['status'] );
		$this->assertSame( hash( 'sha256', $this->bytes ), $r['hash'] );
		$this->assertSame( '5', $r['ref'] );
		$this->assertSame( 0, $o->saves );
	}

	public function test_fail_writes_failed_record(): void {
		$this->res = [ 200, [ 'verdict' => 'fail', 'issues' => [ [ 'id' => 'x', 'message' => 'm' ] ] ], '' ];
		$o = $this->order();
		$this->go();
		$this->assertSame( 'failed', Record::get( $o )['status'] );
	}

	public function test_same_hash_skips_api_call(): void {
		$this->order();
		$this->go();
		$this->go();
		$this->assertSame( 1, $this->calls );
	}

	public function test_force_calls_api_again_with_same_hash(): void {
		$this->order();
		$this->go();
		$this->go( 1, 1, true );
		$this->assertSame( 2, $this->calls );
	}

	public function test_changed_bytes_validate_again(): void {
		$this->order();
		$this->go();
		$this->bytes = '<Invoice>other</Invoice>';
		$this->go();
		$this->assertSame( 2, $this->calls );
	}

	public function test_503_schedules_retry_with_attempt_plus_one_at_60s_and_keeps_status_error(): void {
		$this->res = [ 503, [], '' ];
		$o = $this->order();
		$this->go();
		$this->assertSame( 'error', Record::get( $o )['status'] );
		$a = $GLOBALS['invoqk_as'][0];
		$this->assertSame( Scheduler::VALIDATE, $a['hook'] );
		$this->assertSame( [ 1, 2, false ], $a['args'] );
		$this->assertEqualsWithDelta( time() + 60, $a['when'], 2 );
	}

	public function test_attempt_4_503_schedules_nothing(): void {
		$this->res = [ 503, [], '' ];
		$this->order();
		$this->go( 1, 4 );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_429_schedules_one_retry_at_retry_after(): void {
		$this->res = [ 429, [], '120' ];
		$this->order();
		$this->go();
		$this->assertEqualsWithDelta( time() + 120, $GLOBALS['invoqk_as'][0]['when'], 2 );
		$GLOBALS['invoqk_as'] = [];
		$this->go( 1, 2 );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_401_403_422_schedule_no_retry(): void {
		$o = $this->order();
		foreach ( [ 401 => 'unauthorized', 403 => 'wrong_site', 422 => 'rejected' ] as $c => $code ) {
			$this->res = [ $c, [], '' ];
			$this->go();
			$this->assertSame( 'error', Record::get( $o )['status'] );
			$this->assertSame( $code, $this->code( $o ) );
			$this->assertSame( [], $GLOBALS['invoqk_as'], (string) $c );
		}
	}

	public function test_retry_after_outage_retries(): void {
		$this->res = [ 503, [], '' ];
		$o = $this->order();
		$this->go();
		$this->go( 1, 2 );
		$this->assertSame( 'error', Record::get( $o )['status'] );
		$this->assertSame( 2, $this->calls );
	}

	public function test_log_never_contains_bytes_or_key(): void {
		$this->res = [ 503, [], '' ];
		$this->order();
		$this->go();
		$log = implode( "\n", $GLOBALS['invoqk_log'] );
		$this->assertNotSame( '', $log );
		$this->assertStringNotContainsString( 'INVOICEBYTES', $log );
		$this->assertStringNotContainsString( 'SECRETKEY123', $log );
	}

	public function test_not_required_order_is_not_validated_and_takes_no_slot(): void {
		$o          = $this->order();
		$o->company = '';
		$this->go();
		$this->assertSame( 0, $this->calls );
		$this->assertSame( 0, $this->reads );
		$this->assertNull( Record::get( $o ) );
		$this->assertSame( [], (array) get_option( 'invoqk_free_slots', [] ) );
	}

	public function test_override_to_required_validates_a_b2c_order(): void {
		$o          = $this->order();
		$o->company = '';
		$o->update_meta_data( \Invoqk\Classification\Classifier::OVERRIDE, [ 'required' => true, 'by' => 1, 'at' => 1 ] );
		$this->go();
		$this->assertSame( 1, $this->calls );
	}
}
