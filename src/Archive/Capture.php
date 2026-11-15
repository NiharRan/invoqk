<?php
namespace Invoqk\Archive;

use Invoqk\Guard\Guard;
use Invoqk\Jobs\Scheduler;
use Invoqk\Validation\Limit;

/** Copies the invoice files a customer email carries into staging; queues them once the mail went out. */
final class Capture {
	public const ENTRY = 'invoqk_archive_entry';

	/** @var array<int, array<int, array{path: string, format: string}>> order id => recorded files */
	private static array $recorded = [];

	/** @var array<int, string> spl_object_id( $email ) => set id */
	private static array $sets = [];

	public static function boot(): void {
		add_action( 'wpo_wcpdf_email_attachment', [ self::class, 'record' ], 10, 4 );
		add_filter( 'woocommerce_email_attachments', [ self::class, 'stage' ], 1000, 4 );
		add_action( 'woocommerce_email_sent', [ self::class, 'sent' ], 10, 3 );
	}

	public static function reset(): void {
		self::$recorded = [];
		self::$sets     = [];
	}

	// Hook callbacks take untyped parameters: another plugin on these shared hooks may pass anything.
	public static function record( $path, $documentType, $document, $format = '' ): void {
		$order = is_object( $document ) ? ( $document->order ?? null ) : null;
		if ( ! is_string( $path ) || 'invoice' !== $documentType || ! $order instanceof \WC_Order ) {
			return;
		}
		$id = $order->get_id();
		if ( ! Limit::isPro() && ! Guard::sending( $id ) ) {
			return;
		}
		$format = in_array( $format, [ 'pdf', 'xml' ], true ) ? $format : strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $format, [ 'pdf', 'xml' ], true ) ) {
			self::$recorded[ $id ][ $path ] = [ 'path' => $path, 'format' => $format ];
		}
	}

	public static function stage( $attachments, $emailId, $object, $email = null ) {
		if ( ! is_array( $attachments ) || ! $object instanceof \WC_Order || ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) ) {
			return $attachments;
		}
		$id = $object->get_id();
		if ( ! $email->is_customer_email() ) {
			unset( self::$recorded[ $id ] ); // same as Guard: admin mail must not leak paths into the next customer mail
			return $attachments;
		}
		$files = array_values( array_filter( self::$recorded[ $id ] ?? [], static fn( $r ) => in_array( $r['path'], $attachments, true ) ) );
		unset( self::$recorded[ $id ] );
		if ( ! $files ) {
			return $attachments;
		}
		$dir = '';
		try {
			$setId = bin2hex( random_bytes( 16 ) );
			$dir   = Archive::storage()->staging() . '/' . $setId;
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new \RuntimeException( 'mkdir' );
			}
			$list = [];
			foreach ( $files as $i => $r ) {
				$name = "invoice-{$i}.{$r['format']}";
				if ( ! @copy( $r['path'], "$dir/$name" ) ) {
					throw new \RuntimeException( 'copy' );
				}
				$list[] = [ 'format' => $r['format'], 'file' => $name ];
			}
			$manifest = [ 'order_id' => $id, 'email_id' => (string) $emailId, 'files' => $list, 'created' => time(), 'sent' => null, 'sent_at' => null ];
			$json     = function_exists( 'wp_json_encode' ) ? wp_json_encode( $manifest ) : json_encode( $manifest );
			if ( false === file_put_contents( "$dir/manifest.json", $json ) ) {
				throw new \RuntimeException( 'manifest' );
			}
			self::$sets[ spl_object_id( $email ) ] = $setId;
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( "archive capture #{$id} failed: " . get_class( $e ), [ 'source' => 'invoqk' ] );
			if ( '' !== $dir ) {
				self::remove( $dir );
			}
		}
		return $attachments;
	}

	public static function sent( $return, $emailId = '', $email = null ): void {
		if ( ! is_object( $email ) || ! isset( self::$sets[ spl_object_id( $email ) ] ) ) {
			return;
		}
		$setId = self::$sets[ spl_object_id( $email ) ];
		unset( self::$sets[ spl_object_id( $email ) ] );
		try { // the mail already left: a throw here must not read as a send failure
			$dir = Archive::storage()->staging() . '/' . $setId;
			if ( ! $return ) {
				self::remove( $dir );
				return;
			}
			$m = json_decode( (string) @file_get_contents( "$dir/manifest.json" ), true );
			if ( ! is_array( $m ) ) {
				return;
			}
			$m['sent']    = true;
			$m['sent_at'] = time();
			$json         = function_exists( 'wp_json_encode' ) ? wp_json_encode( $m ) : json_encode( $m );
			if ( false === @file_put_contents( "$dir/manifest.json", $json ) ) {
				wc_get_logger()->error( "archive capture #{$m['order_id']} manifest write failed", [ 'source' => 'invoqk' ] );
			}
			// Queue even if the rewrite failed: the entry job treats an unconfirmed set as sent_confirmed 0, better than a gap.
			as_enqueue_async_action( self::ENTRY, [ $setId ], Scheduler::GROUP );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( 'archive capture sent failed: ' . get_class( $e ), [ 'source' => 'invoqk' ] );
		}
	}

	private static function remove( string $dir ): void {
		foreach ( glob( "$dir/*" ) ?: [] as $f ) {
			@unlink( $f );
		}
		@rmdir( $dir );
	}
}
