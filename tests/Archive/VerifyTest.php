<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Chain;
use Invoqk\Archive\Storage;
use Invoqk\Archive\Verify;
use PHPUnit\Framework\TestCase;

final class VerifyTest extends TestCase {
	private string $dir;
	private MemoryIndex $ix;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_as']      = [];
		$this->dir = sys_get_temp_dir() . '/invoqk-verify-' . uniqid();
		mkdir( $this->dir );
		Archive::$storage = new class( $this->dir ) implements Storage {
			public function __construct( private string $d ) {}
			public function put( string $s, string $e, int $t ): string { return basename( $s ); }
			public function path( string $k ): ?string { return is_file( $this->d . '/' . $k ) ? $this->d . '/' . $k : null; }
			public function delete( string $k ): void { @unlink( $this->d . '/' . $k ); }
			public function staging(): string { return $this->d; }
			public function exports(): string { return $this->d; }
		};
		$this->ix = Archive::$index = new MemoryIndex();
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
		Archive::reset();
	}

	private function add( int $n, bool $files = true ): array {
		$extra = [];
		if ( $files ) {
			file_put_contents( "{$this->dir}/x$n.xml", "xml$n" );
			file_put_contents( "{$this->dir}/p$n.pdf", "pdf$n" );
			$extra = [ 'xml_key' => "x$n.xml", 'xml_hash' => hash( 'sha256', "xml$n" ), 'pdf_key' => "p$n.pdf", 'pdf_hash' => hash( 'sha256', "pdf$n" ) ];
		}
		return $this->ix->append( $extra + [ 'order_id' => $n, 'version' => 1, 'invoice_number' => "RE-$n", 'invoice_date' => '2026-11-01', 'gross' => '119.00', 'vat' => '19.00', 'sent_at' => 100 + $n ], [ Chain::class, 'hash' ] );
	}

	private function drain(): int {
		$runs = 0;
		Verify::start();
		while ( true ) {
			$i = null;
			foreach ( $GLOBALS['invoqk_as'] as $k => $a ) {
				if ( Verify::HOOK === $a['hook'] ) { $i = $k; break; }
			}
			if ( null === $i ) { return $runs; }
			$args = $GLOBALS['invoqk_as'][ $i ]['args'];
			array_splice( $GLOBALS['invoqk_as'], $i, 1 );
			Verify::run( ...$args );
			++$runs;
		}
	}

	private function three(): void {
		$this->add( 1 ); $this->add( 2 ); $this->add( 3 );
	}

	public function test_clean_chain_ok(): void {
		$this->three();
		$this->drain();
		$r = Verify::result();
		$this->assertSame( 'ok', $r['state'] );
		$this->assertSame( 3, $r['checked'] );
	}

	public function test_edited_field_is_row(): void {
		$this->three();
		$this->ix->rows[2]['gross'] = '1.00';
		$this->drain();
		$this->assertSame( [ 'broken', 2, 'row' ], [ Verify::result()['state'], Verify::result()['brokenId'], Verify::result()['kind'] ] );
	}

	public function test_removed_middle_row_is_link(): void {
		$this->three();
		unset( $this->ix->rows[2] );
		$this->drain();
		$this->assertSame( [ 'broken', 3, 'link' ], [ Verify::result()['state'], Verify::result()['brokenId'], Verify::result()['kind'] ] );
	}

	public function test_removed_newest_row_is_head(): void {
		$this->three();
		unset( $this->ix->rows[3] );
		$this->drain();
		$this->assertSame( 'head', Verify::result()['kind'] );
	}

	public function test_altered_file_is_file(): void {
		$this->three();
		file_put_contents( "{$this->dir}/p2.pdf", 'tampered' );
		$this->drain();
		$this->assertSame( [ 2, 'file' ], [ Verify::result()['brokenId'], Verify::result()['kind'] ] );
	}

	public function test_missing_file_is_missing(): void {
		$this->three();
		unlink( "{$this->dir}/x3.xml" );
		$this->drain();
		$this->assertSame( [ 3, 'missing' ], [ Verify::result()['brokenId'], Verify::result()['kind'] ] );
	}

	public function test_hash_without_key_is_missing(): void {
		$this->add( 1 );
		$this->ix->append( [ 'order_id' => 2, 'version' => 1, 'pdf_hash' => hash( 'sha256', 'p' ), 'sent_at' => 102 ], [ Chain::class, 'hash' ] );
		$this->drain();
		$this->assertSame( [ 2, 'missing' ], [ Verify::result()['brokenId'], Verify::result()['kind'] ] );
	}

	public function test_tombstone_without_files_ok(): void {
		$this->three();
		unlink( "{$this->dir}/x2.xml" );
		$this->ix->tombstone( 2, 1, 'r', 500 );
		$this->drain();
		$this->assertSame( 'ok', Verify::result()['state'] );
	}

	public function test_batches_of_200(): void {
		for ( $i = 1; $i <= 450; $i++ ) { $this->add( $i, false ); }
		$this->assertSame( 3, $this->drain() );
		$this->assertSame( 450, Verify::result()['checked'] );
		$this->assertSame( 'ok', Verify::result()['state'] );
	}
}
