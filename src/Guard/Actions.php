<?php
namespace Invoqk\Guard;

use Invoqk\Detection\Detector;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Job;
use Invoqk\Validation\Record;

/** Admin links on a held invoice: fix and resend, or send anyway. */
final class Actions {
	public const RESEND  = 'invoqk_guard_resend';
	public const RELEASE = 'invoqk_guard_release';

	/** @var ?callable Test seam: replaces the customer mail in the callbacks. */
	public static $mailer = null;

	public static function boot(): void {
		add_action( 'admin_post_' . self::RESEND, [ self::class, 'resendCallback' ] );
		add_action( 'admin_post_' . self::RELEASE, [ self::class, 'releaseCallback' ] );
	}

	public static function resendCallback(): void {
		$id = self::authorized( self::RESEND );
		self::resend( $id, null, self::$mailer );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public static function releaseCallback(): void {
		$id = self::authorized( self::RELEASE );
		self::release( $id, (int) wp_get_current_user()->ID, self::$mailer );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	private static function authorized( string $action ): int {
		$id = absint( wp_unslash( $_REQUEST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( $action . '_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'invoqk' ), '', [ 'response' => 403 ] );
		}
		return $id;
	}

	private static function held( int $id ): ?\WC_Order {
		$order = wc_get_order( $id );
		return $order instanceof \WC_Order && 'held' === ( Hold::get( $order )['state'] ?? '' ) ? $order : null;
	}

	/** Revalidates a held order; sends when it now passes. Returns 'noop', 'sent' or the validation status. */
	public static function resend( int $id, ?callable $validate = null, ?callable $mailer = null ): string {
		$order = self::held( $id );
		if ( ! $order ) {
			return 'noop';
		}
		$validate ??= static function ( int $i ): void {
			( new Detector( Registry::default() ) )->run( $i, false );
			Job::handle( $i, 1, true );
		};
		$validate( $id );
		$order  = wc_get_order( $id );
		$status = Record::status( $order );
		if ( 'passed' === $status ) {
			return Guard::send( $id, 'sent', [], $mailer ) ? 'sent' : 'noop';
		}
		$reason = '';
		foreach ( Record::get( $order )['issues'] ?? [] as $issue ) {
			if ( 'error' === ( $issue['severity'] ?? 'error' ) ) {
				$reason = (string) ( $issue['id'] ?? '' );
				break;
			}
		}
		Hold::set( $order, 'held', [ 'reason' => $reason ] );
		return $status;
	}

	public static function release( int $id, int $userId, ?callable $mailer = null ): bool {
		$order = self::held( $id );
		if ( ! $order || ! Guard::send( $id, 'released', [ 'by' => $userId, 'at' => time() ], $mailer ) ) {
			return false;
		}
		$user = get_userdata( $userId );
		$order->add_order_note( sprintf(
			/* translators: %s: user name */
			__( 'Invoice sent despite validation errors by %s.', 'invoqk' ),
			$user ? (string) $user->display_name : (string) $userId
		) );
		return true;
	}
}
