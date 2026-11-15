<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\LocalStorage;
use PHPUnit\Framework\TestCase;

final class LocalStorageTest extends TestCase {
	private string $base;
	private string $src;
	private ?string $sentinel = null;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/invoqk-storage-' . uniqid();
		$this->src  = sys_get_temp_dir() . '/invoqk-src-' . uniqid() . '.pdf';
		file_put_contents( $this->src, 'pdf-bytes' );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->base );
		@unlink( $this->src );
		if ( $this->sentinel !== null ) {
			@unlink( $this->sentinel );
		}
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = "$dir/$entry";
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function test_put_copies_and_returns_key_pattern(): void {
		$store = new LocalStorage( $this->base );
		$key   = $store->put( $this->src, 'pdf', 1700000000 );

		$this->assertMatchesRegularExpression( '#^2023/11/[a-f0-9]{32}\.pdf$#', $key );
		$this->assertFileExists( $this->src, 'put must copy, not move' );
		$this->assertSame( 'pdf-bytes', file_get_contents( $store->path( $key ) ) );
	}

	public function test_protection_files_written(): void {
		$store = new LocalStorage( $this->base );
		$store->put( $this->src, 'xml', 1700000000 );

		foreach ( [ $this->base, $store->staging() ] as $dir ) {
			$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( "$dir/.htaccess" ) );
			$this->assertFileExists( "$dir/index.php" );
		}
		$this->assertDirectoryExists( $this->base . '/staging' );
	}

	public function test_path_rejects_traversal(): void {
		$store = new LocalStorage( $this->base );
		$store->put( $this->src, 'pdf', 1700000000 );

		$this->assertNull( $store->path( '../x.pdf' ) );
		$this->assertNull( $store->path( '2023/11/' . str_repeat( 'a', 32 ) . '.pdf/../../x' ) );
	}

	public function test_path_null_for_missing_file(): void {
		$store = new LocalStorage( $this->base );
		$this->assertNull( $store->path( '2023/11/' . str_repeat( 'a', 32 ) . '.pdf' ) );
	}

	public function test_delete_removes_file(): void {
		$store = new LocalStorage( $this->base );
		$key   = $store->put( $this->src, 'pdf', 1700000000 );
		$path  = $store->path( $key );

		$store->delete( $key );

		$this->assertFileDoesNotExist( $path );
		$this->assertNull( $store->path( $key ) );
	}

	public function test_month_dirs_get_index_php(): void {
		$store = new LocalStorage( $this->base );
		$store->put( $this->src, 'pdf', 1700000000 );

		foreach ( [ '2023', '2023/11', 'staging' ] as $dir ) {
			$this->assertFileExists( "{$this->base}/$dir/index.php", "$dir needs index.php" );
		}
	}

	public function test_delete_bad_key_removes_nothing(): void {
		$store    = new LocalStorage( $this->base );
		$sentinel = dirname( $this->base ) . '/invoqk-sentinel-' . uniqid() . '.pdf';
		file_put_contents( $sentinel, 'keep' );
		$this->sentinel = $sentinel;

		$store->delete( '../' . basename( $sentinel ) );

		$this->assertFileExists( $sentinel );
	}

	public function test_regex_rejects_trailing_newline(): void {
		$store = new LocalStorage( $this->base );
		$key   = $store->put( $this->src, 'pdf', 1700000000 );
		file_put_contents( $this->base . '/' . $key . "\n", 'x' );

		$this->assertNull( $store->path( $key . "\n" ) );
		$store->delete( $key . "\n" );
		$this->assertFileExists( $this->base . '/' . $key . "\n" );
	}

	public function test_put_rejects_other_extensions(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new LocalStorage( $this->base ) )->put( $this->src, 'exe', 1700000000 );
	}

	public function test_put_throws_when_copy_fails(): void {
		$this->expectException( \RuntimeException::class );
		( new LocalStorage( $this->base ) )->put( $this->base . '/missing.pdf', 'pdf', 1700000000 );
	}

	public function test_exports_dir_is_created_and_guarded(): void {
		$dir = ( new LocalStorage( $this->base ) )->exports();
		$this->assertSame( $this->base . '/exports', $dir );
		$this->assertFileExists( "$dir/.htaccess" );
		$this->assertFileExists( "$dir/index.php" );
	}
}
