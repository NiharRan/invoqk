<?php
namespace Invoqk\Archive;

final class Archive {
	public static ?Index $index = null;

	public static function index(): Index {
		return self::$index ??= new WpdbIndex();
	}

	public static ?Storage $storage = null;

	/** Task 3 makes LocalStorage the production default. */
	public static function storage(): Storage {
		return self::$storage ?? throw new \LogicException( 'Archive storage not configured.' );
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
