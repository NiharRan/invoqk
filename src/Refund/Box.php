<?php
namespace Invoqk\Refund;

use Invoqk\Validation\Limit;

/** Order box section: open refunds with a dismiss link each. */
final class Box {
	public static function html( \WC_Order $o ): string {
		$open = Open::ids( $o );
		if ( ! $open || ! Limit::isPro() ) {
			return '';
		}
		$id    = $o->get_id();
		$items = '';
		foreach ( $o->get_refunds() as $r ) {
			$rid = (int) $r->get_id();
			if ( ! in_array( $rid, $open, true ) ) {
				continue;
			}
			$url    = wp_nonce_url( admin_url( 'admin-post.php?action=' . Dismiss::ACTION . '&order_id=' . $id . '&refund_id=' . $rid ), Dismiss::ACTION . "_{$id}_{$rid}" );
			$items .= '<li>' . esc_html( sprintf( '#%d: %s', $rid, html_entity_decode( wp_strip_all_tags( wc_price( (float) $r->get_amount(), [ 'currency' => $o->get_currency() ] ) ) ) ) )
				. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'No corrective e-invoice needed', 'invoqk' ) . '</a></li>';
		}
		return '<p><strong>' . esc_html__( 'Corrective e-invoice open', 'invoqk' ) . '</strong></p><ul>' . $items . '</ul>';
	}
}
