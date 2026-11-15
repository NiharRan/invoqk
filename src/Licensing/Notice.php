<?php
namespace Invoqk\Licensing;

/** Trial and renewal reminders as admin notices. shortcut: notices only, no email; add server-side email if renewals lag. */
final class Notice {
	public static function boot(): void {
		add_action( 'admin_notices', static function (): void {
			$m = self::message( License::get(), time() );
			if ( null !== $m && current_user_can( 'manage_woocommerce' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $m ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=invoqk' ) ) . '">' . esc_html__( 'Open Invoqk', 'invoqk' ) . '</a></p></div>';
			}
		} );
	}

	public static function message( array $l, int $now ): ?string {
		if ( 'free' === $l['plan'] ) {
			return null;
		}
		if ( ! License::activeAt( $l, $now ) ) {
			return __( 'Your Invoqk Pro license ended. Archive and export still work; guard and new archiving are paused.', 'invoqk' );
		}
		if ( $l['valid_until'] <= $now ) {
			return null; // inside the offline grace window
		}
		$days = License::daysLeft( $now, $l );
		if ( 'trial' === $l['plan'] ) {
			if ( $days <= 1 ) {
				return __( 'Your Invoqk Pro trial ends tomorrow.', 'invoqk' );
			}
			/* translators: %d: days */
			return $days <= 4 ? sprintf( __( 'Your Invoqk Pro trial ends in %d days.', 'invoqk' ), $days ) : null;
		}
		if ( $days <= 7 ) {
			/* translators: %d: days */
			return sprintf( __( 'Your Invoqk license ends in %d days. Renew now to keep the guard and archiving running.', 'invoqk' ), $days );
		}
		/* translators: %d: days */
		return $days <= 30 ? sprintf( __( 'Your Invoqk license renews or ends in %d days.', 'invoqk' ), $days ) : null;
	}
}
