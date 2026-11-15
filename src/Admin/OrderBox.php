<?php
namespace Invoqk\Admin;

use Invoqk\Classification\Classifier;
use Invoqk\Detection\Result;
use Invoqk\Guard\Actions;

final class OrderBox {
	public static function boot(): void {
		add_action( 'add_meta_boxes', static function () {
			foreach ( [ 'woocommerce_page_wc-orders', 'shop_order' ] as $screen ) {
				add_meta_box( 'invoqk-einvoice', esc_html__( 'E-Rechnung (Invoqk)', 'invoqk' ), [ self::class, 'show' ], $screen, 'normal' );
			}
		} );
	}

	/** @param \WP_Post|\WC_Order $post */
	public static function show( $post ): void {
		$order = $post instanceof \WC_Order ? $post : wc_get_order( $post->ID );
		if ( ! $order ) {
			return;
		}
		echo self::classification( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in classification()
		if ( ! Classifier::isRequired( $order ) ) {
			echo Actions::box( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in box(); a held order reclassified later still needs its links
			return; // no validation report, source or format for orders that need no e-invoice
		}
		echo Report::html( $order, get_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Report
		echo Actions::box( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in box()
		$r = Result::fromOrder( $order );
		if ( $r ) {
			echo '<p>' . esc_html( $r->label ?? $r->source ) . '</p>';
		}
		if ( $r && $r->file ) {
			printf( '<p>%s: %s</p>', esc_html__( 'Format', 'invoqk' ), esc_html( $r->file->format ) );
		}
		if ( $r ) {
			printf( '<p>%s: %s</p>', esc_html__( 'Checked', 'invoqk' ), esc_html( wp_date( 'Y-m-d H:i', $r->detectedAt ) ) );
		}
	}

	/** Required yes/no, the reason, and the override links (links: a nested form would break the order edit form). */
	public static function classification( \WC_Order $order ): string {
		$auto = Classifier::auto( $order );
		$ov   = Classifier::override( $order );
		$req  = Classifier::isRequired( $order );
		$out  = '<p><strong>' . esc_html( $req ? __( 'E-invoice required', 'invoqk' ) : __( 'E-invoice not required', 'invoqk' ) ) . '</strong></p>';
		$text = Classifier::reasonText( (string) $auto['reason'], Classifier::threshold( $order ) );
		if ( $ov ) {
			$u    = ! empty( $ov['by'] ) && function_exists( 'get_userdata' ) ? get_userdata( (int) $ov['by'] ) : false;
			$out .= '<p>' . esc_html( sprintf(
				/* translators: 1: user name, 2: date */
				__( 'Set manually by %1$s on %2$s.', 'invoqk' ),
				$u ? $u->display_name : '?',
				wp_date( 'Y-m-d H:i', (int) ( $ov['at'] ?? 0 ) )
			) ) . ' ' . esc_html( sprintf( __( 'Automatic result: %s', 'invoqk' ), $auto['required'] ? __( 'required', 'invoqk' ) : __( 'not required', 'invoqk' ) ) ) . '</p>';
		} elseif ( '' !== $text ) {
			$out .= '<p>' . esc_html( $text ) . '</p>';
		}
		$hint = Classifier::hintText( $auto['hint'] ?? null );
		if ( '' !== $hint ) {
			$out .= '<p>' . esc_html( $hint ) . '</p>';
		}
		$id    = $order->get_id();
		$state = $ov ? ( $ov['required'] ? 'required' : 'not_required' ) : 'auto';
		$opts  = [ 'auto' => __( 'Automatic', 'invoqk' ), 'required' => __( 'Required', 'invoqk' ), 'not_required' => __( 'Not required', 'invoqk' ) ];
		$links = [];
		foreach ( $opts as $value => $label ) {
			if ( $value === $state ) {
				$links[] = '<strong>' . esc_html( $label ) . '</strong>';
				continue;
			}
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=' . Override::ACTION . '&order_id=' . $id . '&value=' . $value ), 'invoqk_override_' . $id );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return $out . '<p>' . esc_html__( 'Override', 'invoqk' ) . ': ' . implode( ' | ', $links ) . '</p>';
	}
}
