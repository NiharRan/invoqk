<?php
use Invoqk\Archive\Archive;
use Invoqk\Archive\Capture;
use Invoqk\Archive\Chain;
use Invoqk\Archive\Entry;
use Invoqk\Archive\LocalStorage;
use Invoqk\Tests\Archive\MemoryIndex;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase {
	private string $base;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_actions'] = [];
		Archive::reset();
		$this->base       = sys_get_temp_dir() . '/invoqk-ent-' . bin2hex( random_bytes( 4 ) );
		Archive::$storage = new LocalStorage( $this->base );
		Archive::$index   = new MemoryIndex();
	}

	protected function tearDown(): void {
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function meta(): callable {
		return fn( $o ) => [ 'invoice_number' => 'INV-1', 'invoice_date' => '2026-01-02' ];
	}

	private function order(): \WC_Order {
		$o            = $GLOBALS['invoqk_orders'][5] = new \WC_Order( 5 );
		$o->total     = '119.00';
		$o->tax       = '19.00';
		$o->company   = '';
		$o->first     = 'Ada';
		$o->last      = 'Lovelace';
		return $o;
	}

	private function set( string $id, string $pdf = 'pdf-body', ?int $created = null, ?bool $sent = true, bool $manifest = true ): string {
		$dir = Archive::storage()->staging() . '/' . $id;
		mkdir( $dir, 0777, true );
		file_put_contents( "$dir/invoice-0.pdf", $pdf );
		file_put_contents( "$dir/invoice-1.xml", '<x/>' );
		if ( $manifest ) {
			file_put_contents( "$dir/manifest.json", json_encode( [
				'order_id' => 5, 'email_id' => 'customer_invoice', 'files' => [ [ 'format' => 'pdf', 'file' => 'invoice-0.pdf' ], [ 'format' => 'xml', 'file' => 'invoice-1.xml' ] ],
				'created' => $created ?? time(), 'sent' => $sent, 'sent_at' => $sent ? ( $created ?? time() ) + 5 : null,
			] ) );
		}
		return $dir;
	}

	public function test_archives_row_and_clears_staging(): void {
		$this->order();
		$dir = $this->set( 'aa', 'pdf-body', 1000 );
		Entry::handle( 'aa', $this->meta() );
		$r = Archive::index()->find( 1 );
		$this->assertSame( 5, $r['order_id'] );
		$this->assertSame( 1, $r['version'] );
		$this->assertSame( 'INV-1', $r['invoice_number'] );
		$this->assertSame( '2026-01-02', $r['invoice_date'] );
		$this->assertSame( 'Ada Lovelace', $r['customer'] );
		$this->assertSame( '100.00', $r['net'] );
		$this->assertSame( '19.00', $r['vat'] );
		$this->assertSame( '119.00', $r['gross'] );
		$this->assertSame( 'EUR', $r['currency'] );
		$this->assertSame( 'none', $r['validation_status'] );
		$this->assertSame( 1005, $r['sent_at'] );
		$this->assertSame( 1, $r['sent_confirmed'] );
		$this->assertSame( 'customer_invoice', $r['email_id'] );
		$this->assertSame( hash( 'sha256', 'pdf-body' ), $r['pdf_hash'] );
		$this->assertSame( hash( 'sha256', '<x/>' ), $r['xml_hash'] );
		$this->assertSame( 8, $r['pdf_size'] );
		$this->assertNotNull( Archive::storage()->path( $r['pdf_key'] ) );
		$this->assertSame( Chain::hash( $r ), $r['hash'] );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_company_wins_as_customer(): void {
		$this->order()->company = 'Acme';
		$this->set( 'aa' );
		Entry::handle( 'aa', $this->meta() );
		$this->assertSame( 'Acme', Archive::index()->find( 1 )['customer'] );
	}

	public function test_missing_order_still_archives(): void {
		$this->set( 'aa' );
		Entry::handle( 'aa', $this->meta() );
		$r = Archive::index()->find( 1 );
		$this->assertSame( '', $r['customer'] );
		$this->assertSame( '', $r['invoice_number'] );
		$this->assertSame( '0.00', $r['gross'] );
	}

	public function test_resend_same_file_reuses_key_new_version(): void {
		$this->order();
		$this->set( 'aa' );
		Entry::handle( 'aa', $this->meta() );
		$this->set( 'bb' );
		Entry::handle( 'bb', $this->meta() );
		$a = Archive::index()->find( 1 );
		$b = Archive::index()->find( 2 );
		$this->assertSame( $a['pdf_key'], $b['pdf_key'] );
		$this->assertSame( $a['xml_key'], $b['xml_key'] );
		$this->assertSame( 2, $b['version'] );
	}

	public function test_unconfirmed_set_archived_flagged(): void {
		$this->order();
		$this->set( 'aa', 'p', 1000, null );
		Entry::handle( 'aa', $this->meta() );
		$r = Archive::index()->find( 1 );
		$this->assertSame( 0, $r['sent_confirmed'] );
		$this->assertSame( 1000, $r['sent_at'] );
	}

	public function test_lock_failure_reschedules_past_backoff_cap(): void {
		$this->order();
		$dir             = $this->set( 'aa' );
		$mf              = json_decode( file_get_contents( "$dir/manifest.json" ), true );
		$mf['attempt']   = 4;
		file_put_contents( "$dir/manifest.json", json_encode( $mf ) );
		Archive::$index  = new class extends MemoryIndex {
			public function append( array $row, callable $hash ): array { throw new \RuntimeException( 'lock' ); }
		};
		Entry::handle( 'aa', $this->meta() );
		$a = end( $GLOBALS['invoqk_as'] );
		$this->assertSame( Entry::HOOK, $a['hook'] );
		$this->assertSame( [ 'aa' ], $a['args'] );
		$this->assertSame( 5, json_decode( file_get_contents( "$dir/manifest.json" ), true )['attempt'] );
		$this->assertEqualsWithDelta( time() + 3600, $a['when'], 5 );
		$this->assertDirectoryExists( $dir );
	}

	public function test_sweep_requeues_old_orphan_only(): void {
		$now = 100000;
		$this->set( 'old', 'p', $now - 7200 );
		$this->set( 'new', 'p', $now - 60 );
		$this->set( 'queued', 'p', $now - 7200 );
		$this->set( 'nom', 'p', 0, true, false );
		touch( Archive::storage()->staging() . '/nom', $now - 7200 );
		$GLOBALS['invoqk_as'][] = [ 'hook' => Entry::HOOK, 'args' => [ 'queued' ], 'group' => 'invoqk', 'when' => 0, 'every' => 0 ];
		Entry::sweep( $now );
		$queued = array_column( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => $a['hook'] === Entry::HOOK ), 'args' );
		$this->assertSame( [ [ 'queued' ], [ 'old' ] ], $queued );
		$this->assertDirectoryExists( Archive::storage()->staging() . '/nom' );
		$this->assertContains( 'archive staging nom has no manifest', $GLOBALS['invoqk_log'] );
	}

	public function test_sweep_skips_set_with_pending_retry(): void {
		$this->order();
		$this->set( 'aa' );
		Archive::$index = new class extends MemoryIndex {
			public function append( array $row, callable $hash ): array { throw new \RuntimeException( 'lock' ); }
		};
		Entry::handle( 'aa', $this->meta() );
		$n = count( $GLOBALS['invoqk_as'] );
		Entry::sweep( time() + 2 * HOUR_IN_SECONDS );
		$this->assertCount( $n, $GLOBALS['invoqk_as'] );
	}

	public function test_missing_invoice_date_stored_as_null(): void {
		$this->order();
		$this->set( 'aa' );
		Entry::handle( 'aa', fn( $o ) => [ 'invoice_number' => 'X', 'invoice_date' => '' ] );
		$r = Archive::index()->find( 1 );
		$this->assertNull( $r['invoice_date'] );
		$this->assertSame( Chain::hash( $r ), $r['hash'] );
	}

	public function test_same_content_twice_in_set_stored_once(): void {
		$this->order();
		$dir = $this->set( 'aa', '<x/>' );
		file_put_contents( "$dir/invoice-0.pdf", '<x/>' );
		$m = json_decode( file_get_contents( "$dir/manifest.json" ), true );
		$m['files'][1]['format'] = 'pdf';
		$m['files'][1]['file']   = 'invoice-0.pdf';
		file_put_contents( "$dir/manifest.json", json_encode( $m ) );
		Entry::handle( 'aa', $this->meta() );
		$this->assertCount( 1, glob( $this->base . '/*/*/*.pdf' ) ?: [] );
	}

	public function test_validated_ignores_bad_record(): void {
		$this->order();
		$this->set( 'aa' );
		Entry::handle( 'aa', $this->meta() );
		Entry::onValidated( 5, [] );
		Entry::onValidated( 5, [ 'status' => '' ] );
		Entry::onValidated( 5, [ 'status' => 3 ] );
		$this->assertSame( 'none', Archive::index()->find( 1 )['validation_status'] );
	}

	public function test_failed_encode_keeps_manifest(): void {
		$dir = $this->set( 'aa' );
		$old = file_get_contents( "$dir/manifest.json" );
		$this->assertFalse( Capture::writeManifest( $dir, [ 'bad' => "\xff" ] ) );
		$this->assertSame( $old, file_get_contents( "$dir/manifest.json" ) );
		$this->assertFileDoesNotExist( "$dir/manifest.json.tmp" );
	}

	public function test_validated_updates_status(): void {
		$this->order();
		$this->set( 'aa' );
		Entry::handle( 'aa', $this->meta() );
		Entry::boot();
		do_action( 'invoqk_validated', 5, [ 'status' => 'passed' ] );
		$this->assertSame( 'passed', Archive::index()->find( 1 )['validation_status'] );
	}
}
