<?php
namespace Invoqk\Refund;

/** Marks an open refund as needing no corrective e-invoice. Links, not a form: the box sits inside the order edit form. */
final class Dismiss {
	public const ACTION = 'invoqk_refund_dismiss';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'callback' ] );
	}

	public static function callback(): void {
		$order  = absint( wp_unslash( $_REQUEST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below
		$refund = absint( wp_unslash( $_REQUEST['refund_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$user   = wp_get_current_user();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( self::ACTION . "_{$order}_{$refund}" ) ) {
			wp_die( esc_html__( 'Not allowed.', 'invoqk' ), '', [ 'response' => 403 ] );
		}
		self::handle( $order, $refund, true, true, (int) $user->ID, (string) $user->display_name );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** Returns true when a flagged refund was cleared. Bad nonce, missing capability or an unflagged refund change nothing. */
	public static function handle( int $orderId, int $refundId, bool $allowed, bool $nonceOk, int $userId, string $userName ): bool {
		if ( ! $allowed || ! $nonceOk || $orderId <= 0 || $refundId <= 0 ) {
			return false;
		}
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order || ! in_array( $refundId, Open::ids( $order ), true ) ) {
			return false;
		}
		Open::clear( $order, $refundId );
		$order->add_order_note( sprintf(
			/* translators: 1: refund ID, 2: user name */
			__( 'Invoqk: no corrective e-invoice needed for refund #%1$d, set by %2$s.', 'invoqk' ),
			$refundId,
			$userName
		) );
		return true;
	}
}
