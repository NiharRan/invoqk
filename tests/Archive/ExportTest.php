<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Chain;
use Invoqk\Archive\Export;
use Invoqk\Archive\LocalStorage;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase {
	private string $base;
	private MemoryIndex $ix;
	private array $mails = [];

	protected function setUp(): void {
		$GLOBALS['invoqk_options']    = [];
		$GLOBALS['invoqk_as']         = [];
		$GLOBALS['invoqk_log']        = [];
		$GLOBALS['invoqk_user_email'] = 'boss@example.com';
		$this->mails                  = [];
		$this->base                   = sys_get_temp_dir() . '/invoqk-export-' . uniqid();
		Archive::$storage             = new LocalStorage( $this->base );
		$this->ix                     = Archive::$index = new MemoryIndex();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['invoqk_user_email'] );
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function add( int $order, int $version, ?string $date, int $sentAt = 0, bool $pdf = false ): array {
		$src = sys_get_temp_dir() . "/invoqk-e-$order-$version.tmp";
		file_put_contents( $src, "x$order$version" );
		$extra = [ 'xml_key' => Archive::storage()->put( $src, 'xml', 1700000000 ) ];
		if ( $pdf ) {
			$extra['pdf_key'] = Archive::storage()->put( $src, 'pdf', 1700000000 );
		}
		unlink( $src );
		return $this->ix->append( [ 'order_id' => $order, 'version' => $version, 'invoice_number' => "RE-$order", 'invoice_date' => $date, 'gross' => '119.00', 'vat' => '19.00', 'sent_at' => $sentAt ] + $extra, [ Chain::class, 'hash' ] );
	}

	private function mail(): callable {
		return function ( string $to, string $subject, string $body ): bool {
			$this->mails[] = [ $to, $subject, $body ];
			return true;
		};
	}

	private function begin( string $from = '2024-01-01', string $to = '2024-12-31' ): string {
		$this->assertTrue( Export::start( $from, $to, 5, time() ) );
		return Export::state()['id'];
	}

	private function names( string $id ): array {
		$z = new \ZipArchive();
		$z->open( Archive::storage()->exports() . "/$id.zip" );
		$n = [];
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$n[] = $z->getNameIndex( $i );
		}
		sort( $n );
		return [ $n, $z ];
	}

	public function test_zip_contains_files_and_csv_for_range(): void {
		$this->add( 1, 1, '2024-03-01' );
		$this->add( 1, 2, '2024-03-02', 0, true );
		$this->add( 2, 1, '2023-12-31' );
		$this->add( 3, 1, '2024-12-31' );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		$s = Export::state();
		$this->assertSame( 'ready', $s['state'] );
		$this->assertSame( 3, $s['count'] );
		[ $names, $z ] = $this->names( $id );
		$this->assertSame( [ 'RE-1_v1.xml', 'RE-1_v2.pdf', 'RE-1_v2.xml', 'RE-3_v1.xml', 'index.csv' ], $names );
		$csv = $z->getFromName( 'index.csv' );
		$this->assertStringContainsString( ";1;;Rechnung;\r\n", $csv );
		$this->assertStringContainsString( ";2;ja;Rechnung;\r\n", $csv );
		$this->assertStringNotContainsString( 'RE-2', $csv );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'boss@example.com', $this->mails[0][0] );
		$this->assertFileDoesNotExist( Archive::storage()->exports() . "/$id.csv" );
	}

	public function test_zip_includes_credit_note_files_and_index_row(): void {
		$inv = $this->add( 1, 1, '2024-03-01' );
		$src = sys_get_temp_dir() . '/invoqk-e-cn.tmp';
		file_put_contents( $src, 'cn' );
		$this->ix->append( [ 'order_id' => 21, 'version' => 1, 'kind' => 'credit_note', 'parent_id' => $inv['id'], 'invoice_number' => 'GS-1', 'invoice_date' => '2024-03-05', 'gross' => '-119.00', 'sent_at' => 0, 'xml_key' => Archive::storage()->put( $src, 'xml', 1700000000 ) ], [ Chain::class, 'hash' ] );
		unlink( $src );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		[ $names, $z ] = $this->names( $id );
		$this->assertSame( [ 'GS-1_v1.xml', 'RE-1_v1.xml', 'index.csv' ], $names );
		$this->assertStringContainsString( "GS-1;05.03.2024;;;;-119,00;;;1;;Gutschrift;RE-1\r\n", $z->getFromName( 'index.csv' ) );
	}

	public function test_tombstoned_rows_are_skipped(): void {
		$r = $this->add( 1, 1, '2024-03-01' );
		$this->add( 2, 1, '2024-03-01' );
		$this->ix->tombstone( $r['id'], 7, 'why', 4000000000 );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		$this->assertSame( 1, Export::state()['count'] );
	}

	public function test_null_invoice_date_uses_sent_at(): void {
		$this->add( 1, 1, null, strtotime( '2024-05-05 UTC' ) );
		$this->add( 2, 1, null, strtotime( '2022-05-05 UTC' ) );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		[ $names ] = $this->names( $id );
		$this->assertContains( 'RE-1_v1.xml', $names );
		$this->assertNotContains( 'RE-2_v1.xml', $names );
	}

	public function test_batch_requeues_until_last(): void {
		$src = sys_get_temp_dir() . '/invoqk-e-bulk.tmp';
		file_put_contents( $src, 'x' );
		$key = Archive::storage()->put( $src, 'xml', 1700000000 );
		unlink( $src );
		$ids = [];
		for ( $i = 1; $i <= 201; $i++ ) {
			$ids[] = $this->ix->append( [ 'order_id' => $i, 'version' => 1, 'invoice_number' => "RE-$i", 'invoice_date' => '2024-03-01', 'xml_key' => $key ], [ Chain::class, 'hash' ] )['id'];
		}
		$id = $this->begin();
		$GLOBALS['invoqk_as'] = [];
		Export::run( $id, 0, $this->mail() );
		$this->assertSame( 'running', Export::state()['state'] );
		$this->assertSame( [ $id, $ids[199] ], $GLOBALS['invoqk_as'][0]['args'] );
		$this->assertSame( [], $this->mails );
		Export::run( $id, $ids[199], $this->mail() );
		$this->assertSame( 'ready', Export::state()['state'] );
		$this->assertSame( 201, Export::state()['count'] );
		$this->assertCount( 1, $this->mails );
	}

	public function test_missing_file_fails_cleanly(): void {
		$r = $this->add( 1, 1, '2024-03-01' );
		unlink( Archive::storage()->path( $r['xml_key'] ) );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		$this->assertSame( 'failed', Export::state()['state'] );
		$dir = Archive::storage()->exports();
		$this->assertFileDoesNotExist( "$dir/$id.zip" );
		$this->assertFileDoesNotExist( "$dir/$id.csv" );
		$this->assertSame( [], $this->mails );
		$this->assertContains( "archive export $id failed: RuntimeException: missing", $GLOBALS['invoqk_log'] );
	}

	public function test_foreign_id_is_ignored(): void {
		$this->add( 1, 1, '2024-03-01' );
		$this->begin();
		Export::run( str_repeat( 'a', 32 ), 0, $this->mail() );
		$this->assertSame( 'running', Export::state()['state'] );
		$this->assertSame( [], $this->mails );
	}

	public function test_start_refused_while_running(): void {
		$this->begin();
		$this->assertFalse( Export::start( '2024-01-01', '2024-12-31', 5, time() ) );
		$this->assertTrue( Export::start( '2024-01-01', '2024-12-31', 5, time() + 2 * HOUR_IN_SECONDS ) );
	}

	public function test_start_removes_previous_ready_zip(): void {
		$this->add( 1, 1, '2024-03-01' );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		$zip = Archive::storage()->exports() . "/$id.zip";
		$this->assertFileExists( $zip );
		$this->assertTrue( Export::start( '2024-01-01', '2024-12-31', 5, time() ) );
		$this->assertFileDoesNotExist( $zip );
	}

	public function test_cleanup_removes_old_zip_and_resets_state(): void {
		$this->add( 1, 1, '2024-03-01' );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		$zip = Archive::storage()->exports() . "/$id.zip";
		touch( $zip, time() - 8 * DAY_IN_SECONDS );
		Export::cleanup( time() );
		$this->assertFileDoesNotExist( $zip );
		$this->assertSame( 'none', Export::state()['state'] );
	}

	public function test_exports_dir_failure_marks_failed(): void {
		$this->add( 1, 1, '2024-03-01' );
		$id = $this->begin();
		$GLOBALS['invoqk_as'] = [];
		Archive::$storage     = new class implements \Invoqk\Archive\Storage {
			public function put( string $p, string $e, int $t ): string { return ''; }
			public function path( string $k ): ?string { return null; }
			public function delete( string $k ): void {}
			public function staging(): string { return ''; }
			public function exports(): string { throw new \RuntimeException( 'dir' ); }
		};
		Export::run( $id, 0, $this->mail() );
		$this->assertSame( 'failed', Export::state()['state'] );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		$this->assertSame( [], $this->mails );
		Export::cleanup( time() );
		$this->assertSame( 'failed', Export::state()['state'] );
	}

	private function bulk( int $n, int $skip = 0 ): array {
		$src = sys_get_temp_dir() . '/invoqk-e-bulk.tmp';
		file_put_contents( $src, 'x' );
		$key = Archive::storage()->put( $src, 'xml', 1700000000 );
		unlink( $src );
		$ids = [];
		for ( $i = 1; $i <= $n; $i++ ) {
			$ids[] = $this->ix->append( [ 'order_id' => $i, 'version' => 1, 'invoice_number' => 1 === $i ? 'RE-1' : ( $n === $i && $skip ? 're-1' : "RE-X$i" ), 'invoice_date' => '2024-03-01', 'xml_key' => $key ], [ Chain::class, 'hash' ] )['id'];
		}
		return $ids;
	}

	public function test_name_collision_across_batches_gets_id_suffix(): void {
		$ids = $this->bulk( 201, 1 );
		$id  = $this->begin();
		Export::run( $id, 0, $this->mail() );
		Export::run( $id, $ids[199], $this->mail() );
		[ $names ] = $this->names( $id );
		$this->assertContains( 'RE-1_v1.xml', $names );
		$this->assertContains( 're-1_v1-' . $ids[200] . '.xml', $names );
		$this->assertCount( 202, $names );
	}

	public function test_zero_rows_in_range_gives_header_only_zip(): void {
		$this->add( 1, 1, '2020-01-01' );
		$id = $this->begin();
		Export::run( $id, 0, $this->mail() );
		$this->assertSame( 'ready', Export::state()['state'] );
		$this->assertSame( 0, Export::state()['count'] );
		[ $names, $z ] = $this->names( $id );
		$this->assertSame( [ 'index.csv' ], $names );
		$this->assertSame( \Invoqk\Archive\ExportFormat::header(), $z->getFromName( 'index.csv' ) );
	}

	public function test_exactly_one_full_batch_finishes_with_empty_last_batch(): void {
		$ids = $this->bulk( 200 );
		$id  = $this->begin();
		$GLOBALS['invoqk_as'] = [];
		Export::run( $id, 0, $this->mail() );
		$this->assertSame( [ $id, $ids[199] ], $GLOBALS['invoqk_as'][0]['args'] );
		Export::run( $id, $ids[199], $this->mail() );
		$this->assertSame( 'ready', Export::state()['state'] );
		$this->assertSame( 200, Export::state()['count'] );
	}

	public function test_begin_rejects_bad_range(): void {
		foreach ( [ [ '2024-02-01', '2024-01-01' ], [ '', '' ], [ '2026-02-30', '2026-03-01' ] ] as [ $f, $t ] ) {
			$this->assertSame( 'range', Export::begin( [ 'from' => $f, 'to' => $t ], 5, time() ) );
		}
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
		$this->assertSame( 'none', Export::state()['state'] );
	}

	public function test_begin_this_month_and_last_month(): void {
		$now = gmmktime( 12, 0, 0, 3, 15, 2026 );
		$this->assertSame( 'started', Export::begin( [ 'range' => 'this_month' ], 5, $now ) );
		$this->assertSame( [ '2026-03-01', '2026-03-31' ], [ Export::state()['from'], Export::state()['to'] ] );
		$GLOBALS['invoqk_options'] = [];
		$this->assertSame( 'started', Export::begin( [ 'range' => 'last_month', 'from' => 'junk' ], 5, $now ) );
		$this->assertSame( [ '2026-02-01', '2026-02-28' ], [ Export::state()['from'], Export::state()['to'] ] );
	}

	public function test_second_start_is_busy_unless_stale(): void {
		$now = time();
		$this->assertSame( 'started', Export::begin( [ 'from' => '2024-01-01', 'to' => '2024-02-01' ], 5, $now ) );
		$this->assertSame( 'busy', Export::begin( [ 'from' => '2024-01-01', 'to' => '2024-02-01' ], 5, $now ) );
		$s       = Export::state();
		$s['at'] = $now - 2 * HOUR_IN_SECONDS;
		update_option( Export::OPT, $s, false );
		$this->assertSame( 'started', Export::begin( [ 'from' => '2024-01-01', 'to' => '2024-02-01' ], 5, $now ) );
	}

	public function test_callbacks_need_cap_and_nonce(): void {
		foreach ( [ 'invoqk_can', 'invoqk_nonce_ok' ] as $g ) {
			foreach ( [ 'startCallback', 'downloadCallback' ] as $cb ) {
				$GLOBALS['invoqk_can'] = $GLOBALS['invoqk_nonce_ok'] = true;
				$GLOBALS[ $g ]         = false;
				try {
					Export::$cb();
					$this->fail( 'expected die' );
				} catch ( \InvoqkTestDie $e ) {
					$this->assertSame( 403, $e->getCode() );
				}
			}
		}
		unset( $GLOBALS['invoqk_can'], $GLOBALS['invoqk_nonce_ok'] );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_download_refuses_when_not_ready(): void {
		$this->begin();
		try {
			Export::downloadCallback();
			$this->fail( 'expected die' );
		} catch ( \InvoqkTestDie $e ) {
			$this->assertSame( 404, $e->getCode() );
		}
	}
}
