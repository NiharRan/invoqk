<?php
namespace Invoqk\Archive;

/** Archive files under a base directory that the web server must never serve. */
final class LocalStorage implements Storage {
	private const KEY = '#^\d{4}/\d{2}/[a-f0-9]{32}\.(xml|pdf)$#';

	public function __construct( private string $base ) {}

	public function put( string $sourcePath, string $ext, int $time ): string {
		if ( ! in_array( $ext, [ 'xml', 'pdf' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported archive extension.' );
		}
		$this->ready();
		$key    = gmdate( 'Y/m', $time ) . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$target = $this->base . '/' . $key;
		$this->mkdir( dirname( $target ) );
		if ( ! @copy( $sourcePath, $target ) ) {
			throw new \RuntimeException( 'Could not copy file into archive.' );
		}
		return $key;
	}

	public function path( string $key ): ?string {
		if ( ! preg_match( self::KEY, $key ) ) {
			return null;
		}
		$path = $this->base . '/' . $key;
		return is_file( $path ) ? $path : null;
	}

	public function delete( string $key ): void {
		$path = $this->path( $key );
		if ( $path !== null && ! unlink( $path ) ) {
			throw new \RuntimeException( 'Could not delete archive file.' );
		}
	}

	public function staging(): string {
		$this->ready();
		return $this->base . '/staging';
	}

	/** Creates the base and staging dirs and their protection files on first use. */
	private function ready(): void {
		foreach ( [ $this->base, $this->base . '/staging' ] as $dir ) {
			$this->mkdir( $dir );
			if ( ! is_file( "$dir/.htaccess" ) ) {
				file_put_contents( "$dir/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>Deny from all</IfModule>\n" );
			}
			if ( ! is_file( "$dir/index.php" ) ) {
				file_put_contents( "$dir/index.php", '' );
			}
		}
	}

	private function mkdir( string $dir ): void {
		if ( is_dir( $dir ) ) {
			return;
		}
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} else {
			@mkdir( $dir, 0755, true );
		}
		if ( ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create archive directory.' );
		}
	}
}
