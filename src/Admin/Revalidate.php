<?php
namespace Invoqk\Admin;

use Invoqk\Jobs\Scheduler;

final class Revalidate {
	public const ACTION = 'invoqk_revalidate';

	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'callback' ] );
	}

	public static function callback(): void {
		$id = self::requestedId( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below
		self::handle( $id, current_user_can( 'manage_woocommerce' ), (bool) check_admin_referer( 'invoqk_revalidate_' . $id ) );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** The button is a link (a nested form would break the order edit form), so the id arrives in the query string. */
	public static function requestedId( array $req ): int {
		return absint( wp_unslash( $req['order_id'] ?? 0 ) );
	}

	/** The Scheduler dedupes identical queued jobs, so a double click queues one. */
	public static function handle( int $orderId, bool $allowed, bool $nonceOk ): bool {
		if ( ! $allowed || ! $nonceOk || $orderId <= 0 ) {
			return false;
		}
		Scheduler::enqueueValidate( $orderId, true );
		return true;
	}
}
