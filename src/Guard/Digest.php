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
		update_option( self::OUTBOX, [], false );
		if ( $lines ) {
			$mail ??= 'wp_mail';
			$mail( get_option( 'admin_email' ), sprintf( __( 'Invoqk: %d invoices need attention', 'invoqk' ), count( $lines ) ), implode( "\n", $lines ) );
		}
	}
}
