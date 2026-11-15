<?php
namespace Invoqk\Archive;

final class Archive {
	public static ?Index $index = null;

	public static function index(): Index {
		return self::$index ??= new WpdbIndex();
	}

	public static ?Storage $storage = null;

	public static function storage(): Storage {
		return self::$storage ??= new LocalStorage( self::dir( defined( 'INVOQK_ARCHIVE_DIR' ) ? INVOQK_ARCHIVE_DIR : null ) ?? wp_upload_dir()['basedir'] . '/invoqk-archive' );
	}

	/** The configured directory when it is a non-empty absolute path, else null (uploads default). */
	public static function dir( $d ): ?string {
		if ( ! is_string( $d ) || '' === $d ) {
			return null;
		}
		$abs = function_exists( 'path_is_absolute' ) ? path_is_absolute( $d ) : ( str_starts_with( $d, '/' ) || 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $d ) );
		return $abs ? $d : null;
	}

	public static function reset(): void {
		self::$index   = null;
		self::$storage = null;
	}

	/** One row shape for both indexes: ints as int, money as "12.50", null kept. */
	public static function normalise( array $row ): array {
		foreach ( [ 'id', 'order_id', 'version', 'sent_at', 'sent_confirmed', 'xml_size', 'pdf_size', 'deleted_at', 'deleted_by' ] as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$row[ $k ] = (int) $row[ $k ];
			}
		}
		foreach ( [ 'net', 'vat', 'gross' ] as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$row[ $k ] = number_format( (float) $row[ $k ], 2, '.', '' );
			}
		}
		return $row;
	}
}
