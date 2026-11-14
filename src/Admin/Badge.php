<?php
namespace Invoqk\Admin;

/** Validation status badge. Static text only; nothing remote is printed here. */
final class Badge {
	public static function html( string $status ): string {
		$map = [
			'passed'  => [ 'status-completed', __( 'Valid', 'invoqk' ) ],
			'failed'  => [ 'status-failed', __( 'Invalid', 'invoqk' ) ],
			'error'   => [ 'status-on-hold', __( 'Could not check', 'invoqk' ) ],
			'limit'   => [ 'status-on-hold', __( 'Limit reached', 'invoqk' ) ],
			'pending' => [ 'status-pending', __( 'Checking', 'invoqk' ) ],
		];
		if ( ! isset( $map[ $status ] ) ) {
			return '';
		}
		return '<mark class="order-status ' . esc_attr( $map[ $status ][0] ) . '"><span>' . esc_html( $map[ $status ][1] ) . '</span></mark>';
	}
}
