<?php
namespace Invoqk\Archive;

use Invoqk\Jobs\Scheduler;
use Invoqk\Validation\Record;

/** Turns a staged set of sent invoice files into one hash-chained archive row. */
final class Entry {
	public const HOOK = Capture::ENTRY;

	public static function boot(): void {
		add_action( self::HOOK, static fn( $setId ) => self::handle( (string) $setId ) );
		add_action( Scheduler::SWEEP, static fn() => self::sweep( time() ) );
		add_action( 'invoqk_validated', static fn( $id, $record = [] ) => self::onValidated( (int) $id, (array) $record ), 10, 2 );
	}

	public static function handle( string $setId, ?callable $meta = null ): void {
		if ( ! preg_match( '/^[a-f0-9]+$/', $setId ) ) {
			return;
		}
		$dir = Archive::storage()->staging() . '/' . $setId;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$m = json_decode( (string) @file_get_contents( "$dir/manifest.json" ), true );
		if ( ! is_array( $m ) ) {
			wc_get_logger()->error( "archive staging {$setId} has no manifest", [ 'source' => 'invoqk' ] );
			return;
		}
		$attempt = max( 1, (int) ( $m['attempt'] ?? 1 ) );
		try {
			$orderId = (int) ( $m['order_id'] ?? 0 );
			$order   = wc_get_order( $orderId );
			$order   = $order instanceof \WC_Order ? $order : null;
			$info    = $order ? ( $meta ?? [ self::class, 'defaultMeta' ] )( $order ) : [];
			$index   = Archive::index();
			$row     = [
				'order_id'          => $orderId,
				'invoice_number'    => (string) ( $info['invoice_number'] ?? '' ),
				'invoice_date'      => '' !== (string) ( $info['invoice_date'] ?? '' ) ? (string) $info['invoice_date'] : null, // NULL, not '': a DATE column would read '' back as 0000-00-00
				'customer'          => $order ? ( '' !== (string) $order->get_billing_company() ? (string) $order->get_billing_company() : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) : '',
				'currency'          => $order ? (string) $order->get_currency() : '',
				'validation_status' => $order ? Record::status( $order ) : 'none',
				'email_id'          => (string) ( $m['email_id'] ?? '' ),
				'sent_at'           => (int) ( $m['sent_at'] ?? $m['created'] ?? 0 ),
				'sent_confirmed'    => true === ( $m['sent'] ?? null ) ? 1 : 0,
			];
			$gross      = $order ? (float) $order->get_total() : 0.0;
			$vat        = $order ? (float) $order->get_total_tax() : 0.0;
			$row['gross'] = $gross;
			$row['vat']   = $vat;
			$row['net']   = $gross - $vat;
			$keys = [];
			foreach ( (array) ( $m['files'] ?? [] ) as $f ) {
				$fmt  = (string) ( $f['format'] ?? '' );
				$path = "$dir/" . basename( (string) ( $f['file'] ?? '' ) );
				$hash = is_file( $path ) && in_array( $fmt, [ 'pdf', 'xml' ], true ) ? hash_file( 'sha256', $path ) : false;
				if ( false === $hash ) {
					throw new \RuntimeException( 'file' );
				}
				$row[ "{$fmt}_hash" ] = $hash;
				$row[ "{$fmt}_size" ] = filesize( $path );
				$row[ "{$fmt}_key" ]  = $keys[ $hash ] ??= $index->keyForHash( $hash ) ?? Archive::storage()->put( $path, $fmt, time() );
			}
			$row['version'] = $index->maxVersion( $orderId ) + 1;
			$index->append( $row, [ Chain::class, 'hash' ] );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( "archive entry {$setId} failed: " . get_class( $e ), [ 'source' => 'invoqk' ] );
			$m['attempt'] = $attempt + 1; // args stay [ setId ] so the sweep sees the pending retry
			Capture::writeManifest( $dir, $m );
			as_schedule_single_action( time() + ( Scheduler::backoff( $attempt ) ?? HOUR_IN_SECONDS ), self::HOOK, [ $setId ], Scheduler::GROUP );
			return;
		}
		foreach ( glob( "$dir/*" ) ?: [] as $f ) {
			@unlink( $f );
		}
		@rmdir( $dir );
		if ( class_exists( Usage::class ) ) {
			try {
				Usage::check();
			} catch ( \Throwable $e ) {
				wc_get_logger()->error( 'archive usage check failed: ' . get_class( $e ), [ 'source' => 'invoqk' ] );
			}
		}
	}

	public static function sweep( int $now ): void {
		foreach ( glob( Archive::storage()->staging() . '/*', GLOB_ONLYDIR ) ?: [] as $dir ) {
			$setId = basename( $dir );
			$m     = json_decode( (string) @file_get_contents( "$dir/manifest.json" ), true );
			$when  = is_array( $m ) ? (int) ( $m['created'] ?? 0 ) : (int) filemtime( $dir );
			if ( $now - $when < HOUR_IN_SECONDS || as_has_scheduled_action( self::HOOK, [ $setId ], Scheduler::GROUP ) ) {
				continue;
			}
			if ( ! is_array( $m ) ) {
				wc_get_logger()->error( "archive staging {$setId} has no manifest", [ 'source' => 'invoqk' ] );
				continue;
			}
			as_enqueue_async_action( self::HOOK, [ $setId ], Scheduler::GROUP );
		}
	}

	public static function onValidated( int $orderId, array $record ): void {
		$status = $record['status'] ?? null;
		if ( ! is_string( $status ) || '' === $status ) {
			return;
		}
		try {
			Archive::index()->setStatus( $orderId, $status );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( "archive status #{$orderId} failed: " . get_class( $e ), [ 'source' => 'invoqk' ] );
		}
	}

	/** @return array{invoice_number: string, invoice_date: string} */
	public static function defaultMeta( \WC_Order $o ): array {
		$out = [ 'invoice_number' => '', 'invoice_date' => '' ];
		try {
			if ( function_exists( 'wcpdf_get_document' ) && ( $doc = wcpdf_get_document( 'invoice', $o, false ) ) ) {
				$out['invoice_number'] = (string) ( $doc->get_number()?->get_formatted() ?? '' );
				$out['invoice_date']   = (string) ( $doc->get_date()?->date( 'Y-m-d' ) ?? '' );
			}
		} catch ( \Throwable $e ) {
		}
		return $out;
	}
}
