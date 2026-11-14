<?php
namespace Invoqk\Validation;

use Invoqk\Detection\Result;
use Invoqk\Jobs\Scheduler;
use Invoqk\Sources\Registry;

/** Background validation of one order's e-invoice. Logs order id, status and HTTP code only. */
final class Job {
	public static function handle( int $orderId, int $attempt = 1, bool $force = false, ?callable $transport = null ): void {
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || 'trash' === $order->get_status() ) {
			return;
		}
		$found = Result::fromOrder( $order );
		if ( ! $found || 'none' === $found->source || ! $found->file ) {
			return;
		}
		$file    = $found->file;
		$ref     = (string) $file->reference;
		$adapter = Registry::default()->get( $found->source );
		if ( '' === Client::apiKey() ) { // before read(): reading may build the XML, wasted without a key
			self::finish( $order, Mapper::error( 'no_key', 0, '', $ref ), $attempt );
			return;
		}
		try {
			$bytes = $adapter && $adapter->isAvailable() ? $adapter->read( $order, $file ) : null;
		} catch ( \Throwable $e ) {
			$bytes = null; // class only: the message may carry invoice data
			wc_get_logger()->error( "validate #{$orderId} read failed: " . get_class( $e ), [ 'source' => 'invoqk' ] );
		}
		if ( null === $bytes || '' === $bytes ) {
			self::finish( $order, Mapper::error( 'unreadable', 0, '', $ref ), $attempt );
			return;
		}
		$hash = hash( 'sha256', $bytes );
		$old  = Record::get( $order );
		if ( ! $force && $old && ( $old['hash'] ?? '' ) === $hash && in_array( $old['status'] ?? '', [ 'passed', 'failed' ], true ) ) {
			if ( ( $old['ref'] ?? '' ) !== $ref ) { // keep ref current so the Detector stops re-queuing this order
				Record::put( $order, array_merge( $old, [ 'ref' => $ref ] ) );
			}
			return;
		}
		if ( ! Limit::allow( $orderId ) ) {
			$r           = Mapper::error( 'limit', 0, $hash, $ref );
			$r['status'] = 'limit';
			$r['error']  = null;
			self::finish( $order, $r, $attempt );
			return;
		}
		$res = Client::post( $bytes, $file->mime, $transport );
		self::finish( $order, Mapper::map( $res['http'], $res['body'], $res['retryAfter'], $hash, $ref ), $attempt, $res['http'] );
	}

	private static function finish( \WC_Order $order, array $record, int $attempt, int $http = 0 ): void {
		$record['attempt'] = $attempt;
		Record::put( $order, $record );
		wc_get_logger()->info( "validate #{$order->get_id()} status {$record['status']} http {$http} attempt {$attempt}", [ 'source' => 'invoqk' ] );
		if ( 'error' === $record['status'] ) {
			$delay = Mapper::retryDelay( $record, $attempt );
			if ( null !== $delay ) {
				as_schedule_single_action( time() + $delay, Scheduler::VALIDATE, [ $order->get_id(), $attempt + 1, false ], Scheduler::GROUP );
			}
		}
	}
}
