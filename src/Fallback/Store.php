<?php
namespace Invoqk\Fallback;

/** Keeps generated invoices under uploads, behind a random file name, and records them in order meta. */
final class Store {
	public const META   = '_invoqk_fallback';
	public const OPTION = 'invoqk_fallback_used';
	private const FILE  = '#^[a-f0-9]{32}\.xml$#D';

	/** Where the files live; creates nothing, so reads stay side-effect free. */
	private static function base(): string {
		return rtrim( (string) wp_upload_dir()['basedir'], '/' ) . '/invoqk-fallback';
	}

	/** Creates and protects the directory on first write. */
	public static function dir(): string {
		$dir = self::base();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create fallback directory.' );
		}
		$htaccess = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		foreach ( [ 'index.php' => '', '.htaccess' => $htaccess ] as $name => $body ) {
			if ( ! is_file( "$dir/$name" ) && false === file_put_contents( "$dir/$name", $body ) ) {
				throw new \RuntimeException( 'Could not write fallback protection file.' );
			}
		}
		return $dir;
	}

	/** @throws \RuntimeException file first, meta last: a failure removes the file and leaves no meta. */
	public static function save( \WC_Order $o, string $xml, string $number, string $profile ): void {
		$path = self::dir() . '/' . bin2hex( random_bytes( 16 ) ) . '.xml';
		if ( false === file_put_contents( $path, $xml ) ) {
			throw new \RuntimeException( 'Could not write fallback invoice.' );
		}
		try {
			if ( ! get_option( self::OPTION ) ) {
				update_option( self::OPTION, 1, true );
			}
			$o->update_meta_data( self::META, [
				'number'  => $number,
				'date'    => gmdate( 'Y-m-d' ),
				'profile' => $profile,
				'file'    => basename( $path ),
				'hash'    => hash( 'sha256', $xml ),
			] );
			$o->save_meta_data(); // not save(): that fires woocommerce_update_order and order.updated webhooks
		} catch ( \Throwable $e ) {
			@unlink( $path );
			throw $e;
		}
	}

	public static function get( \WC_Order $o ): ?array {
		$m = $o->get_meta( self::META );
		return is_array( $m ) && $m ? $m : null;
	}

	public static function path( \WC_Order $o ): ?string {
		$file = self::get( $o )['file'] ?? '';
		if ( ! is_string( $file ) || ! preg_match( self::FILE, $file ) ) {
			return null;
		}
		$path = self::base() . '/' . $file;
		return is_file( $path ) ? $path : null;
	}
}
