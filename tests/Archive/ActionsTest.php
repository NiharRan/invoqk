<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Actions;
use Invoqk\Archive\Archive;
use Invoqk\Archive\Chain;
use Invoqk\Archive\LocalStorage;
use Invoqk\Archive\Verify;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase {
	private const NOW = 4000000000;
	private string $base;
	private MemoryIndex $ix;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_as']      = [];
		$GLOBALS['invoqk_log']     = [];
		unset( $GLOBALS['invoqk_can'], $GLOBALS['invoqk_nonce_ok'] );
		$this->base       = sys_get_temp_dir() . '/invoqk-actions-' . uniqid();
		Archive::$storage = new LocalStorage( $this->base );
		$this->ix         = Archive::$index = new MemoryIndex();
	}

	protected function tearDown(): void {
		$_REQUEST = $_POST = [];
		Archive::reset();
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	private function add( int $n, int $sentAt, ?string $shared = null ): array {
		$src = sys_get_temp_dir() . "/invoqk-a-$n.xml";
		file_put_contents( $src, "xml$n" );
		$key = $shared ?? Archive::storage()->put( $src, 'xml', 1700000000 );
		unlink( $src );
		return $this->ix->append( [ 'order_id' => $n, 'version' => 1, 'invoice_number' => "RE-$n", 'invoice_date' => '2020-01-01', 'gross' => '119.00', 'vat' => '19.00', 'sent_at' => $sentAt, 'xml_key' => $key, 'xml_hash' => hash( 'sha256', "xml$n" ) ], [ Chain::class, 'hash' ] );
	}

	private function old(): int {
		return self::NOW - 9 * 365 * DAY_IN_SECONDS;
	}

	public function test_delete_after_retention(): void {
		$r = $this->add( 1, $this->old() );
		$this->assertSame( [ 'deleted' => 1, 'refused' => 0 ], Actions::delete( [ $r['id'] ], 7, '', '', self::NOW ) );
		$this->assertNull( Archive::storage()->path( $r['xml_key'] ) );
		$this->assertSame( 7, $this->ix->find( $r['id'] )['deleted_by'] );
		$this->assertContains( "archive delete #{$r['id']} by user 7", $GLOBALS['invoqk_log'] );
		$this->assertSame( [ 'deleted' => 0, 'refused' => 0 ], Actions::delete( [ $r['id'], 99 ], 7, '', '', self::NOW ), 'tombstones and unknown ids skipped' );
	}

	public function test_delete_before_retention_needs_word_and_reason(): void {
		$r = $this->add( 1, self::NOW - DAY_IN_SECONDS );
		$this->assertSame( 1, Actions::delete( [ $r['id'] ], 7, '', 'why', self::NOW )['refused'] );
		$this->assertSame( 1, Actions::delete( [ $r['id'] ], 7, 'DELETE', '  ', self::NOW )['refused'] );
		$this->assertNotNull( Archive::storage()->path( $r['xml_key'] ) );
		$this->assertSame( 1, Actions::delete( [ $r['id'] ], 7, 'DELETE', 'test data', self::NOW )['deleted'] );
		$this->assertSame( 'test data', $this->ix->find( $r['id'] )['delete_reason'] );
	}

	public function test_shared_file_kept_until_last_live_row(): void {
		$a = $this->add( 1, $this->old() );
		$b = $this->add( 2, $this->old(), $a['xml_key'] );
		Actions::delete( [ $a['id'] ], 7, '', '', self::NOW );
		$this->assertNotNull( Archive::storage()->path( $a['xml_key'] ) );
		Actions::delete( [ $b['id'] ], 7, '', '', self::NOW );
		$this->assertNull( Archive::storage()->path( $a['xml_key'] ) );
	}

	public function test_chain_still_verifies_after_delete(): void {
		$this->add( 1, $this->old() );
		$b = $this->add( 2, $this->old() );
		$this->add( 3, $this->old() );
		Actions::delete( [ $b['id'] ], 7, '', '', self::NOW );
		Verify::start();
		while ( $GLOBALS['invoqk_as'] ) {
			$a = array_shift( $GLOBALS['invoqk_as'] );
			if ( Verify::HOOK === $a['hook'] ) {
				Verify::run( ...$a['args'] );
			}
		}
		$this->assertSame( 'ok', Verify::result()['state'] );
	}

	public function test_no_cap_is_403_and_nothing_changes(): void {
		$r                    = $this->add( 1, $this->old() );
		$GLOBALS['invoqk_can'] = false;
		$_POST                = $_REQUEST = [ 'ids' => [ $r['id'] ] ];
		try {
			Actions::callback();
			$this->fail( 'expected die' );
		} catch ( \InvoqkTestDie $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertEmpty( $this->ix->find( $r['id'] )['deleted_at'] ?? null );
	}

	public function test_bad_nonce_nothing_changes(): void {
		$r                         = $this->add( 1, $this->old() );
		$GLOBALS['invoqk_nonce_ok'] = false;
		$_POST                     = [ 'ids' => [ $r['id'] ] ];
		try {
			Actions::callback();
			$this->fail( 'expected die' );
		} catch ( \InvoqkTestDie $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertEmpty( $this->ix->find( $r['id'] )['deleted_at'] ?? null );
	}

	public function test_sanitize_years(): void {
		$this->assertSame( 1, Actions::sanitizeYears( 0 ) );
		$this->assertSame( 30, Actions::sanitizeYears( 99 ) );
		$this->assertSame( 8, Actions::sanitizeYears( 'x' ) );
		$this->assertSame( 10, Actions::sanitizeYears( '10' ) );
		$this->assertSame( 8, Actions::retentionYears() );
	}

	public function test_boot_registers_endpoints(): void {
		$GLOBALS['invoqk_actions'] = [];
		Actions::boot();
		$this->assertNotEmpty( $GLOBALS['invoqk_actions']['admin_post_invoqk_archive_delete'] ?? [] );
		$this->assertCount( 3, $GLOBALS['invoqk_actions'] );
	}

	public function test_unlink_failure_still_tombstones_and_continues(): void {
		$a = $this->add( 1, $this->old() );
		$b = $this->add( 2, $this->old() );
		Archive::$storage = new class( Archive::storage() ) implements \Invoqk\Archive\Storage {
			public function __construct( private \Invoqk\Archive\Storage $s ) {}
			public function put( string $p, string $e, int $t ): string { return $this->s->put( $p, $e, $t ); }
			public function path( string $k ): ?string { return $this->s->path( $k ); }
			public function delete( string $k ): void { throw new \RuntimeException( 'x' ); }
			public function staging(): string { return $this->s->staging(); }
		};
		$this->assertSame( 2, Actions::delete( [ $a['id'], $b['id'] ], 7, '', '', self::NOW )['deleted'] );
		$this->assertNotEmpty( $this->ix->find( $a['id'] )['deleted_at'] );
		$this->assertNotEmpty( $this->ix->find( $b['id'] )['deleted_at'] );
		$this->assertContains( "archive delete #{$a['id']} file kept: RuntimeException", $GLOBALS['invoqk_log'] );
	}

	public function test_shared_key_both_ids_one_call(): void {
		$a = $this->add( 1, $this->old() );
		$b = $this->add( 2, $this->old(), $a['xml_key'] );
		$this->assertSame( 2, Actions::delete( [ $a['id'], $b['id'] ], 7, '', '', self::NOW )['deleted'] );
		$this->assertNull( Archive::storage()->path( $a['xml_key'] ) );
	}

	public function test_download_refuses_tombstone_and_bad_type_and_traversal(): void {
		$r = $this->add( 1, $this->old() );
		$this->assertNull( Actions::download( $r['id'], 'pdf' ), 'no pdf key' );
		$this->assertNull( Actions::download( $r['id'], '../xml' ) );
		$this->assertNull( Actions::download( $r['id'], 'exe' ) );
		$this->assertNull( Actions::download( 999, 'xml' ) );
		$this->assertNotNull( Actions::download( $r['id'], 'xml' ) );
		Actions::delete( [ $r['id'] ], 7, '', '', self::NOW );
		$this->assertNull( Actions::download( $r['id'], 'xml' ) );
	}

	public function test_download_name_sanitized(): void {
		$r = $this->add( 1, $this->old() );
		$d = Actions::download( $r['id'], 'xml' );
		$this->assertSame( 'RE-1.xml', $d['name'] );
		$this->assertSame( 'application/xml', $d['mime'] );
		$this->assertSame( Archive::storage()->path( $r['xml_key'] ), $d['path'] );
	}

	public function test_verify_endpoint_403_without_cap(): void {
		$GLOBALS['invoqk_can'] = false;
		try {
			Actions::verifyCallback();
			$this->fail( 'expected die' );
		} catch ( \InvoqkTestDie $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_verify_not_restarted_while_running(): void {
		Verify::start();
		Verify::start();
		$this->assertCount( 1, $GLOBALS['invoqk_as'] );
	}

	public function test_download_endpoint_403_without_cap_or_nonce(): void {
		foreach ( [ 'invoqk_can', 'invoqk_nonce_ok' ] as $k ) {
			unset( $GLOBALS['invoqk_can'], $GLOBALS['invoqk_nonce_ok'] );
			$GLOBALS[ $k ] = false;
			try {
				Actions::downloadCallback();
				$this->fail( "expected die for $k" );
			} catch ( \InvoqkTestDie $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}
	}

	public function test_delete_callback_uses_own_nonce_field(): void {
		$GLOBALS['invoqk_nonce_args'] = [];
		$_POST = [];
		try {
			Actions::callback();
		} catch ( \Throwable $e ) { // exit ends the callback; only the nonce args matter
		}
		$this->assertSame( 'invoqk_delete_nonce', $GLOBALS['invoqk_nonce_args']['invoqk_archive_delete'] ?? null );
	}
}
