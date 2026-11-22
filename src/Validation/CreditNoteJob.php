<?php
namespace Invoqk\Validation;

use Invoqk\CreditNote\Detect;
use Invoqk\CreditNote\Store;
use Invoqk\Jobs\Scheduler;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;

/** Background validation of one credit note (Pro only). Logs order/refund id, status and HTTP code only. */
final class CreditNoteJob {
	public static function handle( int $orderId, int $refundId, int $attempt = 1, ?callable $transport = null ): void {
		if ( ! Limit::isPro() ) {
			return;
		}
		$order = wc_get_order( $orderId );
		$entry = $order instanceof \WC_Order && 'trash' !== $order->get_status() ? ( Store::get( $order )[ $refundId ] ?? null ) : null;
		if ( ! $entry ) {
			return;
		}
		$f    = $entry['file'];
		$file = new InvoiceFile( $f['reference'], $f['mime'], $f['format'], (bool) ( $f['onDemand'] ?? false ) );
		$ref  = (string) $file->reference;
		if ( '' === Client::apiKey() ) { // before read(): reading may build the XML, wasted without a key
			self::finish( $order, $refundId, $entry, Mapper::error( 'no_key', 0, '', $ref ), $attempt );
			return;
		}
		$adapter = Registry::default()->get( (string) $entry['source'] );
		try {
			$bytes = $adapter && $adapter->isAvailable() ? $adapter->read( $order, $file ) : null;
		} catch ( \Throwable $e ) {
			$bytes = null; // class only: the message may carry credit note data
			wc_get_logger()->error( "credit note #{$orderId}/{$refundId} read failed: " . get_class( $e ), [ 'source' => 'invoqk' ] );
		}
		if ( null === $bytes || '' === $bytes ) {
			self::finish( $order, $refundId, $entry, Mapper::error( 'unreadable', 0, '', $ref ), $attempt );
			return;
		}
		$hash = hash( 'sha256', $bytes );
		$old  = $entry['validation'] ?? null;
		if ( $old && ( $old['hash'] ?? '' ) === $hash && in_array( $old['status'] ?? '', [ 'passed', 'failed' ], true ) ) {
			if ( empty( $entry['archived'] ) ) { // checked but not archived yet (archive failed): retry archiving only
				do_action( 'invoqk_credit_note_validated', $orderId, $refundId, $old );
			}
			return;
		}
		$res = Client::post( $bytes, $file->mime, $transport );
		self::finish( $order, $refundId, $entry, Mapper::map( $res['http'], $res['body'], $res['retryAfter'], $hash, $ref ), $attempt, $res['http'] );
	}

	private static function finish( \WC_Order $order, int $refundId, array $entry, array $record, int $attempt, int $http = 0 ): void {
		$record['attempt']   = $attempt;
		$entry['validation'] = $record;
		Store::put( $order, $refundId, $entry );
		$id = $order->get_id();
		wc_get_logger()->info( "credit note #{$id}/{$refundId} status {$record['status']} http {$http} attempt {$attempt}", [ 'source' => 'invoqk' ] );
		if ( 'error' === $record['status'] ) {
			$delay = Mapper::retryDelay( $record, $attempt );
			if ( null !== $delay ) {
				as_schedule_single_action( time() + $delay, Detect::VALIDATE, [ $id, $refundId, $attempt + 1 ], Scheduler::GROUP );
			}
		}
		do_action( 'invoqk_credit_note_validated', $id, $refundId, $record );
	}
}
