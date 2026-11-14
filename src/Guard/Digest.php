<?php
namespace Invoqk\Guard;

use Invoqk\Jobs\Scheduler;

/** Collects guard events and mails the admin at most once per DELAY. */
final class Digest {
	public const HOOK   = 'invoqk_guard_digest';
	public const DELAY  = 900;
	public const OUTBOX = 'invoqk_guard_outbox';

	public static function add( int $id, string $kind ): void {
		$box = (array) get_option( self::OUTBOX, [] );
		foreach ( $box as $item ) {
			if ( ( $item['id'] ?? 0 ) === $id && ( $item['kind'] ?? '' ) === $kind ) {
				return;
			}
		}
		$box[] = [ 'id' => $id, 'kind' => $kind ];
		update_option( self::OUTBOX, $box, false );
		if ( ! as_has_scheduled_action( self::HOOK, [], Scheduler::GROUP ) ) {
			as_schedule_single_action( time() + self::DELAY, self::HOOK, [], Scheduler::GROUP );
		}
	}

	public static function run( ?callable $mail = null ): void {
		$box = (array) get_option( self::OUTBOX, [] );
		if ( ! $box ) {
			return;
		}
		$texts = [
			'held'        => __( 'Invoice held back because validation failed:', 'invoqk' ),
			'warn'        => __( 'Invoice sent although validation failed:', 'invoqk' ),
			'skipped'     => __( 'Invoice sent without validation (validation unavailable):', 'invoqk' ),
			'no_einvoice' => __( 'Invoice sent without an e-invoice:', 'invoqk' ),
		];
		$lines = [];
		foreach ( $box as $item ) {
			$order = wc_get_order( (int) ( $item['id'] ?? 0 ) );
			if ( $order instanceof \WC_Order ) {
				$lines[] = ( $texts[ $item['kind'] ?? '' ] ?? (string) $item['kind'] ) . ' ' . $order->get_edit_order_url();
			}
		}
		$ok = ! $lines;
		if ( $lines ) {
			try {
				$mail ??= 'wp_mail';
				$ok     = (bool) $mail( get_option( 'admin_email' ), sprintf( _n( 'Invoqk: %d invoice needs attention', 'Invoqk: %d invoices need attention', count( $lines ), 'invoqk' ), count( $lines ) ), implode( "\n", $lines ) );
			} catch ( \Throwable $e ) {
				$ok = false;
			}
		}
		if ( $ok ) {
			// Re-read: items added while mailing stay queued.
			$left = array_values( array_filter( (array) get_option( self::OUTBOX, [] ), static fn( $i ) => ! in_array( $i, $box, true ) ) );
			update_option( self::OUTBOX, $left, false );
			$retry = (bool) $left;
		} else {
			wc_get_logger()->error( 'guard digest mail failed, will retry', [ 'source' => 'invoqk' ] );
			$retry = true;
		}
		if ( $retry && ! as_has_scheduled_action( self::HOOK, [], Scheduler::GROUP ) ) {
			as_schedule_single_action( time() + self::DELAY, self::HOOK, [], Scheduler::GROUP );
		}
	}
}
