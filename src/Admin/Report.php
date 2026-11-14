<?php
namespace Invoqk\Admin;

use Invoqk\Validation\FixMap;
use Invoqk\Validation\IssueText;
use Invoqk\Validation\Limit;
use Invoqk\Validation\Mapper;
use Invoqk\Validation\Record;

/** Meta box body. Cached meta only; every remote value is escaped. */
final class Report {
	public static function html( \WC_Order $o, string $locale ): string {
		$status = Record::status( $o );
		$rec    = Record::get( $o ) ?? [];
		if ( 'none' === $status ) {
			return '';
		}
		$out = '<p>' . Badge::html( $status ) . '</p>';
		if ( $rec ) {
			$out .= '<p>' . esc_html__( 'Validated', 'invoqk' ) . ': ' . esc_html( wp_date( 'Y-m-d H:i', (int) ( $rec['checkedAt'] ?? 0 ) ) ) . '</p>';
			$rs   = $rec['ruleSet'] ?? [];
			if ( ! empty( $rs['name'] ) ) {
				$out .= '<p>' . esc_html__( 'Rule set', 'invoqk' ) . ': ' . esc_html( sprintf( '%s %s (%s)', $rs['name'], $rs['version'] ?? '', $rs['date'] ?? '' ) ) . '</p>';
			}
		}
		if ( 'error' === $status ) {
			$out .= '<p>' . esc_html( self::errorText( $rec, (int) ( $rec['attempt'] ?? 1 ) ) ) . '</p>';
		}
		if ( 'limit' === $status ) {
			$out .= '<p>' . esc_html( sprintf( __( 'Free includes %d invoices. With Pro you check them all.', 'invoqk' ), Limit::MAX ) )
				. ' <a href="' . esc_url( Limit::upgradeUrl() ) . '">' . esc_html__( 'Upgrade', 'invoqk' ) . '</a></p>';
		}
		if ( in_array( $status, [ 'passed', 'failed' ], true ) ) {
			$out .= self::issues( $o, $rec, $locale );
		}
		return $out . self::form( $o );
	}

	/** Retry wording follows the job's own policy (Mapper::retryDelay), so it never promises a retry nobody queued. */
	private static function errorText( array $rec, int $attempt ): string {
		$retry = null !== Mapper::retryDelay( $rec, $attempt );
		switch ( $rec['error']['code'] ?? '' ) {
			case 'no_key':
				return __( 'No API key is set yet. Enter it under WooCommerce > Invoqk.', 'invoqk' );
			case 'unauthorized':
				return __( 'The API key is invalid or was revoked.', 'invoqk' );
			case 'wrong_site':
				return __( 'This API key belongs to a different domain.', 'invoqk' );
			case 'quota_exceeded':
				return $retry ? __( 'The check quota is used up. We will try again later.', 'invoqk' ) : __( 'The check quota is used up. Please click “Validate again” later.', 'invoqk' );
			case 'unreadable':
				return __( 'The invoice file could not be read.', 'invoqk' );
			case 'rejected':
				return __( 'The file is not a supported e-invoice format.', 'invoqk' );
			default:
				return $retry ? __( 'The validation service is not reachable right now. We will try again.', 'invoqk' ) : __( 'The validation service is not reachable right now. Please click “Validate again” later.', 'invoqk' );
		}
	}

	private static function issues( \WC_Order $o, array $rec, string $locale ): string {
		$issues = is_array( $rec['issues'] ?? null ) ? $rec['issues'] : [];
		$rows   = [ 'error' => [], 'warning' => [] ];
		foreach ( $issues as $i ) {
			if ( is_array( $i ) ) {
				$t = IssueText::for( $i, $locale );
				$rows[ $t['severity'] ][] = [ $t, $i ];
			}
		}
		$out = '';
		if ( $rows['warning'] && ! $rows['error'] ) {
			$out .= '<p>' . esc_html( sprintf( _n( '%d note', '%d notes', count( $rows['warning'] ), 'invoqk' ), count( $rows['warning'] ) ) ) . '</p>';
		}
		$out .= '<ul>';
		foreach ( array_merge( $rows['error'], $rows['warning'] ) as [ $t, $i ] ) {
			$sev  = 'warning' === $t['severity'] ? __( 'Warning', 'invoqk' ) : __( 'Error', 'invoqk' );
			$out .= '<li><strong>' . esc_html( $sev ) . '</strong> ' . esc_html( $t['code'] ) . ' ' . esc_html( $t['text'] );
			if ( $t['raw'] ) {
				$out .= ' <em>(' . esc_html__( 'Original text (English)', 'invoqk' ) . ')</em>';
			}
			$fix = FixMap::link( is_string( $i['fix'] ?? null ) ? $i['fix'] : null, $o );
			if ( $fix ) {
				$out .= ' <a href="' . esc_url( $fix['url'] ) . '">' . esc_html( $fix['label'] ) . '</a>';
			}
			if ( is_string( $i['xpath'] ?? null ) && '' !== $i['xpath'] ) {
				$out .= '<details><summary>XPath</summary><code>' . esc_html( $i['xpath'] ) . '</code></details>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		$more = (int) ( $rec['issueCount'] ?? 0 ) - count( $issues );
		if ( $more > 0 ) {
			$out .= '<p>' . esc_html( sprintf( __( 'and %d more', 'invoqk' ), $more ) ) . '</p>';
		}
		return $out;
	}

	private static function form( \WC_Order $o ): string {
		$id = $o->get_id();
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( Revalidate::ACTION ) . '">'
			. '<input type="hidden" name="order_id" value="' . esc_attr( (string) $id ) . '">'
			. wp_nonce_field( 'invoqk_revalidate_' . $id, '_wpnonce', true, false )
			. '<button type="submit" class="button">' . esc_html__( 'Validate again', 'invoqk' ) . '</button></form>';
	}
}
