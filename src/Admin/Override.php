<?php
namespace Invoqk\Admin;

use Invoqk\Classification\Classifier;
use Invoqk\Jobs\Scheduler;

/** Per-order manual override of the B2B/B2C classification. Links, not a form: the box sits inside the order edit form. */
final class Override {
	public const ACTION = 'invoqk_override';
	public const VALUES = [ 'required', 'not_required', 'auto' ];

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'callback' ] );
	}

	public static function callback(): void {
		$id    = absint( wp_unslash( $_REQUEST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below
		$value = sanitize_key( wp_unslash( $_REQUEST['value'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$user  = wp_get_current_user();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'invoqk_override_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'invoqk' ), '', [ 'response' => 403 ] );
		}
		self::handle( $id, $value, true, true, (int) $user->ID, (string) $user->display_name );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** Returns true when something changed. Unknown value, bad nonce or missing capability change nothing. */
	public static function handle( int $orderId, string $value, bool $allowed, bool $nonceOk, int $userId, string $userName ): bool {
		if ( ! $allowed || ! $nonceOk || $orderId <= 0 || ! in_array( $value, self::VALUES, true ) ) {
			return false;
		}
		$order = wc_get_order( $orderId );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$current = Classifier::override( $order );
		if ( 'auto' === $value ) {
			if ( ! $current ) {
				return false;
			}
			$order->delete_meta_data( Classifier::OVERRIDE );
			$note = sprintf(
				/* translators: %s: automatic result, "required" or "not required" */
				__( 'Invoqk: override removed, automatic result: %s.', 'invoqk' ),
				Classifier::auto( $order )['required'] ? __( 'required', 'invoqk' ) : __( 'not required', 'invoqk' )
			);
		} else {
			$required = 'required' === $value;
			if ( $current && (bool) $current['required'] === $required ) {
				return false;
			}
			$order->update_meta_data( Classifier::OVERRIDE, [ 'required' => $required, 'by' => $userId, 'at' => time() ] );
			$note = sprintf(
				/* translators: 1: "required" or "not required", 2: user name */
				__( 'Invoqk: e-invoice set to %1$s by %2$s.', 'invoqk' ),
				$required ? __( 'required', 'invoqk' ) : __( 'not required', 'invoqk' ),
				$userName
			);
		}
		$order->save_meta_data();
		Readiness::flush();
		$order->add_order_note( $note );
		if ( Classifier::isRequired( $order ) ) {
			Scheduler::enqueue( $orderId ); // detect, which queues validation when a file exists
		}
		return true;
	}
}
