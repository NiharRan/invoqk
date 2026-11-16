<?php
namespace Invoqk\Fallback;

/** Keeps generated invoices under uploads, behind a random file name, and records them in order meta. */
final class Store {
	public const META   = '_invoqk_fallback';
	public const OPTION = 'invoqk_fallback_used';
	private const FILE  = '#^[a-f0-9]{32}\.xml$#D';

	public static function dir(): string {
		$dir = rtrim( (string) wp_upload_dir()['basedir'], '/' ) . '/invoqk-fallback';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Could not create fallback directory.' );
		}
		foreach ( [ 'index.php' => '', '.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>Deny from all</IfModule>\n" ] as $name => $body ) {
			if ( ! is_file( "$dir/$name" ) && false === file_put_contents( "$dir/$name", $body ) ) {
				throw new \RuntimeException( 'Could not write fallback protection file.' );
			}
		}
		return $dir;
	}

	/** @throws \RuntimeException file first, meta last: a failure leaves no meta behind. */
	public static function save( \WC_Order $o, string $xml, string $number, string $profile ): void {
		$name = bin2hex( random_bytes( 16 ) ) . '.xml';
		if ( false === file_put_contents( self::dir() . '/' . $name, $xml ) ) {
			throw new \RuntimeException( 'Could not write fallback invoice.' );
		}
		$o->update_meta_data( self::META, [
			'number'  => $number,
			'date'    => gmdate( 'Y-m-d' ),
			'profile' => $profile,
			'file'    => $name,
			'hash'    => hash( 'sha256', $xml ),
		] );
		$o->save_meta_data(); // not save(): that fires woocommerce_update_order and order.updated webhooks
		if ( ! get_option( self::OPTION ) ) {
			update_option( self::OPTION, 1, true );
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
		$path = self::dir() . '/' . $file;
		return is_file( $path ) ? $path : null;
	}
}
