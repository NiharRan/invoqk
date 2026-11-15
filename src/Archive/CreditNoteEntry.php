<?php
namespace Invoqk\Archive;

use Invoqk\CreditNote\Store;
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Limit;

/** Archives a validated credit note as one hash-chained row (kind credit_note, order_id = refund id). */
final class CreditNoteEntry {
	public static function boot(): void {
		add_action( 'invoqk_credit_note_validated', static fn( $orderId, $refundId, $record = [] ) => self::handle( (int) $orderId, (int) $refundId, (array) $record ), 10, 3 );
	}

	public static function handle( int $orderId, int $refundId, array $record, ?callable $meta = null ): ?array {
		$status = $record['status'] ?? '';
		if ( ! Limit::isPro() || ! in_array( $status, [ 'passed', 'failed' ], true ) ) {
			return null;
		}
		$order = wc_get_order( $orderId );
		$entry = $order instanceof \WC_Order ? ( Store::get( $order )[ $refundId ] ?? null ) : null;
		if ( ! $entry || ! empty( $entry['archived'] ) ) {
			return null;
		}
		$stage = 'read';
		$tmp   = null;
		try {
			$f       = $entry['file'];
			$file    = new InvoiceFile( $f['reference'], $f['mime'], $f['format'], (bool) ( $f['onDemand'] ?? false ) );
			$adapter = Registry::default()->get( (string) $entry['source'] );
			$bytes   = $adapter ? $adapter->read( $order, $file ) : null;
			$fmt     = 'application/pdf' === $file->mime ? 'pdf' : 'xml';
			if ( null === $bytes || '' === $bytes ) {
				throw new \RuntimeException( 'read' );
			}
			$stage  = 'meta';
			$info   = ( $meta ?? [ self::class, 'defaultMeta' ] )( $order, $refundId );
			$refund = null;
			foreach ( $order->get_refunds() as $r ) {
				if ( (int) $r->get_id() === $refundId ) {
					$refund = $r;
				}
			}
			$gross = $refund ? (float) $refund->get_total() : 0.0; // refund totals are negative: keep the sign
			$vat   = $refund ? (float) $refund->get_total_tax() : 0.0;
			$index = Archive::index();
			$row   = [
				'order_id'          => $refundId,
				'version'           => 1,
				'kind'              => 'credit_note',
				'parent_id'         => $index->latestForOrder( $orderId )['id'] ?? null,
				'invoice_number'    => mb_substr( (string) ( $info['invoice_number'] ?? '' ), 0, 64 ),
				'invoice_date'      => '' !== (string) ( $info['invoice_date'] ?? '' ) ? (string) $info['invoice_date'] : null,
				'customer'          => mb_substr( '' !== (string) $order->get_billing_company() ? (string) $order->get_billing_company() : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 0, 255 ),
				'currency'          => (string) $order->get_currency(),
				'validation_status' => $status,
				'email_id'          => '',
				'sent_at'           => time(),
				'sent_confirmed'    => 0,
				'gross'             => $gross,
				'vat'               => $vat,
				'net'               => $gross - $vat,
			];
			$stage = 'file';
			$dir   = Archive::storage()->staging();
			$tmp = tempnam( $dir, 'cn' );
			if ( false === $tmp || false === file_put_contents( $tmp, $bytes ) ) {
				throw new \RuntimeException( 'tmp' );
			}
			$hash                = hash( 'sha256', $bytes );
			$row[ "{$fmt}_hash" ] = $hash;
			$row[ "{$fmt}_size" ] = strlen( $bytes );
			$row[ "{$fmt}_key" ]  = $index->keyForHash( $hash ) ?? Archive::storage()->put( $tmp, $fmt, time() );
			$stage = 'append';
			if ( $index->maxVersion( $refundId ) > 0 ) { // a row exists (any kind, tombstoned too) but `archived` was never stored
				return null;
			}
			// shortcut: no lock between this check and append, so two overlapping runs can still double-append; upgrade with a unique key on (order_id, kind, version).
			$saved = $index->append( $row, [ Chain::class, 'hash' ] );
			$stage = 'store';
			$entry['archived'] = (int) $saved['id'];
			Store::put( $order, $refundId, $entry );
			return $saved;
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( "credit note archive #{$orderId}/{$refundId} failed at {$stage}: " . get_class( $e ), [ 'source' => 'invoqk' ] ); // never the message: may carry invoice data
			return null;
		} finally {
			if ( $tmp ) {
				@unlink( $tmp );
			}
		}
	}

	/** @return array{invoice_number: string, invoice_date: string} */
	public static function defaultMeta( \WC_Order $order, int $refundId ): array {
		$out = [ 'invoice_number' => '', 'invoice_date' => '' ];
		try {
			foreach ( $order->get_refunds() as $refund ) {
				if ( (int) $refund->get_id() === $refundId && function_exists( 'wcpdf_get_document' ) && ( $doc = wcpdf_get_document( 'credit-note', $refund, false ) ) ) {
					$out['invoice_number'] = (string) ( $doc->get_number()?->get_formatted() ?? '' );
					$out['invoice_date']   = (string) ( $doc->get_date()?->date( 'Y-m-d' ) ?? '' );
				}
			}
		} catch ( \Throwable $e ) {
		}
		return $out;
	}
}
