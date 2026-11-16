<?php
namespace Invoqk\Fallback;

/** Order box section and admin-post actions for the fallback e-invoice: generate once, download later. */
final class Actions {
	public const GENERATE = 'invoqk_fallback_generate';
	public const DOWNLOAD = 'invoqk_fallback_download';

	/** @var ?callable Test seam: replaces headers, output and exit in the download. Receives the safe number and the XML. */
	public static $sender = null;

	public static function boot(): void {
		add_action( 'admin_post_' . self::GENERATE, [ self::class, 'generateCallback' ] );
		add_action( 'admin_post_' . self::DOWNLOAD, [ self::class, 'downloadCallback' ] );
	}

	public static function generateCallback(): void {
		$id = self::authorized( self::GENERATE );
		$r  = Generate::run( $id );
		if ( ! $r['ok'] ) {
			set_transient( self::msgKey( $id ), $r['message'], 60 );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public static function downloadCallback(): void {
		$id    = self::authorized( self::DOWNLOAD );
		$order = wc_get_order( $id );
		$path  = $order instanceof \WC_Order ? Store::path( $order ) : null; // never a path from the request
		$xml   = null === $path ? false : file_get_contents( $path );
		if ( false === $xml ) {
			wp_die( esc_html__( 'Not allowed.', 'invoqk' ), '', [ 'response' => 404 ] );
		}
		$number = preg_replace( '/[^A-Za-z0-9._-]/', '_', (string) ( Store::get( $order )['number'] ?? '' ) );
		if ( self::$sender ) {
			( self::$sender )( $number, $xml );
			return;
		}
		header( 'Content-Type: application/xml' );
		header( 'Content-Disposition: attachment; filename="' . $number . '.xml"' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- XML file download, not HTML
		exit;
	}

	private static function authorized( string $action ): int {
		$id = absint( wp_unslash( $_REQUEST['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( $action . '_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'invoqk' ), '', [ 'response' => 403 ] );
		}
		return $id;
	}

	private static function msgKey( int $id ): string {
		return 'invoqk_fallback_msg_' . $id;
	}

	/** Order box section; links, not forms (a nested form would break the order edit form). */
	public static function box( \WC_Order $o ): string {
		$id   = $o->get_id();
		$link = static fn( string $action ) => esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&order_id=' . $id ), $action . '_' . $id ) );
		$out  = '';
		$msg  = get_transient( self::msgKey( $id ) );
		if ( is_string( $msg ) && '' !== $msg ) {
			delete_transient( self::msgKey( $id ) );
			$out .= '<p>' . esc_html( $msg ) . '</p>';
		}
		$inv = Store::get( $o );
		if ( $inv ) {
			/* translators: 1: invoice number, 2: profile */
			return $out . '<p>' . esc_html( sprintf( __( 'Basic e-invoice %1$s (%2$s)', 'invoqk' ), (string) ( $inv['number'] ?? '' ), (string) ( $inv['profile'] ?? '' ) ) ) . ' | '
				. '<a href="' . $link( self::DOWNLOAD ) . '">' . esc_html__( 'Download XML', 'invoqk' ) . '</a></p>';
		}
		if ( ! Offer::available( $o ) ) {
			return $out;
		}
		$missing = Seller::missing();
		if ( $missing ) {
			return $out . '<p>' . esc_html__( 'Complete these seller details to create a basic e-invoice:', 'invoqk' ) . ' '
				. esc_html( implode( ', ', array_map( [ Seller::class, 'label' ], $missing ) ) ) . ' '
				. '<a href="' . esc_url( admin_url( 'admin.php?page=invoqk' ) ) . '">' . esc_html__( 'Open settings', 'invoqk' ) . '</a></p>';
		}
		return $out . '<p><a href="' . $link( self::GENERATE ) . '">' . esc_html__( 'Create basic e-invoice', 'invoqk' ) . '</a></p><p>'
			. esc_html__( 'Basic e-invoice for standard domestic orders. For complex tax cases, discounts or other currencies, use an invoice plugin.', 'invoqk' ) . '</p>';
	}
}
