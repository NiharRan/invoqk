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
		add_action( 'admin_notices', static function () {
			echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notice()
		} );
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
		$order = wc_get_order( $id );
		if ( 'sent' === ( Hold::get( $order )['state'] ?? '' ) ) {
			return 'sent'; // the invoqk_validated hook inside validation already sent it
		}
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

	/** Order box section for the guard state; links, not forms (a nested form would break the order edit form). */
	public static function box( \WC_Order $o ): string {
		$h = Hold::get( $o );
		$id = $o->get_id();
		switch ( $h['state'] ?? '' ) {
			case 'held':
				$link = static fn( string $action ) => esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&order_id=' . $id ), $action . '_' . $id ) );
				return '<p><strong>' . esc_html__( 'Held by Invoqk', 'invoqk' ) . '</strong></p><p>'
					. '<a href="' . $link( self::RESEND ) . '">' . esc_html__( 'Fix and resend', 'invoqk' ) . '</a> | '
					. '<a href="' . $link( self::RELEASE ) . '" onclick="return confirm(\'' . esc_attr( esc_js( __( 'Send this invoice although it failed validation?', 'invoqk' ) ) ) . '\')">'
					. esc_html__( 'Send anyway…', 'invoqk' ) . '</a></p>';
			case 'sent':
				/* translators: %s: date */
				return '<p>' . esc_html( sprintf( __( 'Invoice sent on %s', 'invoqk' ), wp_date( 'Y-m-d H:i', (int) ( $h['since'] ?? 0 ) ) ) ) . '</p>';
			case 'skipped':
				return '<p>' . esc_html__( 'Check skipped – invoice sent without validation', 'invoqk' ) . '</p>';
			case 'released':
				$u = get_userdata( (int) ( $h['by'] ?? 0 ) );
				/* translators: 1: user name, 2: date */
				return '<p>' . esc_html( sprintf( __( 'Sent despite errors by %1$s on %2$s', 'invoqk' ), $u ? $u->display_name : '?', wp_date( 'Y-m-d H:i', (int) ( $h['at'] ?? 0 ) ) ) ) . '</p>';
			case 'waiting':
				return '<p>' . esc_html__( 'Waiting for validation', 'invoqk' ) . '</p>';
		}
		return '';
	}

	/** Admin notice listing held orders (first 5, then a count). Shown on Free too: held orders stay visible after Pro ends. */
	public static function notice(): string {
		$ids = current_user_can( 'manage_woocommerce' ) ? Hold::heldIds() : [];
		if ( ! $ids ) {
			return '';
		}
		$links = [];
		foreach ( array_slice( $ids, 0, 5 ) as $id ) {
			$links[] = '<a href="' . esc_url( wc_get_order( $id )->get_edit_order_url() ) . '">#' . (int) $id . '</a>';
		}
		$more = count( $ids ) - 5;
		return '<div class="notice notice-warning"><p><strong>'
			/* translators: %d: number of orders */
			. esc_html( sprintf( __( '%d invoices held by Invoqk', 'invoqk' ), count( $ids ) ) ) . '</strong>: '
			. implode( ', ', $links )
			/* translators: %d: number of further orders */
			. ( $more > 0 ? ' ' . esc_html( sprintf( __( 'and %d more', 'invoqk' ), $more ) ) : '' )
			. '</p></div>';
	}
}
