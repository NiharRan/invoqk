<?php
namespace Invoqk\Admin;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Usage;
use Invoqk\Validation\Limit;

/** Readiness dashboard markup. Pure: takes Readiness::get() data, returns escaped HTML. */
final class Dashboard {
	public static function html( array $d, ?string $turnover, bool $hasKey ): string {
		$h = '<div class="invoqk-dash"><p class="invoqk-head"><a href="' . esc_url( admin_url( 'admin.php?page=invoqk&invoqk_wizard=1' ) ) . '">'
			. esc_html__( 'Open setup', 'invoqk' ) . '</a></p>';
		if ( ! $hasKey ) {
			$h .= '<div class="notice notice-warning inline invoqk-nokey"><p>'
				. esc_html__( 'No API key is set yet.', 'invoqk' ) . ' <a href="#invoqk_api_key">' . esc_html__( 'Enter it in the settings below.', 'invoqk' ) . '</a></p></div>';
		}
		$h .= '<div class="invoqk-grid">' . self::readiness( $d ) . self::deadline( $turnover ) . '</div>';
		$h .= 0 === (int) $d['required'] ? self::empty() : self::top( $d['top'] );
		if ( Limit::isPro() ) {
			$h .= '<p class="invoqk-muted">' . esc_html( sprintf( __( 'Archive: %s', 'invoqk' ), Usage::label( Usage::used() ) ) ) . '</p>';
		}
		return $h . self::pro() . '</div>';
	}

	private static function readiness( array $d ): string {
		$req = (int) $d['required'];
		$h   = '<div class="invoqk-box"><div class="invoqk-muted">' . esc_html__( 'B2B orders with a valid e-invoice · last 30 days', 'invoqk' ) . '</div>'
			. '<div class="invoqk-score"><span class="invoqk-big">' . ( null === $d['score'] ? '–' : esc_html( (int) $d['score'] . ' %' ) ) . '</span>'
			/* translators: 1: valid orders, 2: B2B orders checked */
			. '<span class="invoqk-muted">' . esc_html( sprintf( __( '%1$d of %2$d', 'invoqk' ), $d['passed'], $req ) ) . '</span></div>';
		if ( $req > 0 ) {
			$h .= '<div class="invoqk-bar">';
			foreach ( [ 'passed' => 'ok', 'failed' => 'bad', 'missing' => 'warn' ] as $k => $c ) {
				$h .= '<span class="' . $c . '" style="width:' . (int) round( 100 * $d[ $k ] / $req ) . '%"></span>';
			}
			$h .= '</div>';
		}
		$h .= '<div class="invoqk-pills">';
		foreach ( [
			'ok'   => [ 'passed', __( 'valid', 'invoqk' ) ],
			'bad'  => [ 'failed', __( 'faulty', 'invoqk' ) ],
			'warn' => [ 'missing', __( 'missing', 'invoqk' ) ],
			'na'   => [ 'unchecked', __( 'not checked yet', 'invoqk' ) ],
		] as $c => [ $k, $l ] ) {
			$h .= '<span><span class="invoqk-pill ' . $c . '">' . (int) $d[ $k ] . '</span> ' . esc_html( $l ) . '</span>';
		}
		$h .= '<span><span class="invoqk-pill na">' . (int) $d['notRequired'] . '</span> ' . esc_html__( 'not needed', 'invoqk' ) . '</span></div>';
		if ( $d['capped'] ) {
			$h .= '<p class="invoqk-muted">' . esc_html__( 'Basis: newest 2,000 orders', 'invoqk' ) . '</p>';
		}
		return $h . '</div>';
	}

	private static function deadline( ?string $turnover ): string {
		$h = '<div class="invoqk-box"><div class="invoqk-muted">' . esc_html__( 'Your obligation starts', 'invoqk' ) . '</div>';
		if ( null === $turnover ) {
			$h .= '<div class="invoqk-date">1.1.2027 / 1.1.2028</div>';
		} else {
			$h .= '<div class="invoqk-date">' . ( 'over' === $turnover ? '1.1.2027' : '1.1.2028' ) . '</div>';
		}
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="invoqk-turnover">'
			. wp_nonce_field( 'invoqk_wizard', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="invoqk_turnover">'
			. '<select name="band" aria-label="' . esc_attr( __( 'Turnover last year', 'invoqk' ) ) . '">';
		if ( null === $turnover ) {
			$h .= '<option value="" selected disabled>' . esc_html__( 'Select turnover', 'invoqk' ) . '</option>';
		}
		foreach ( [ 'over' => __( 'Over €800,000', 'invoqk' ), 'under' => __( 'Up to €800,000', 'invoqk' ) ] as $v => $l ) {
			$h .= '<option value="' . $v . '"' . ( $turnover === $v ? ' selected' : '' ) . '>' . esc_html( $l ) . '</option>';
		}
		return $h . '</select> <button type="submit" class="button">' . esc_html__( 'Save', 'invoqk' ) . '</button></form>'
			. '<div class="invoqk-muted invoqk-small">' . esc_html__( 'No legal or tax advice.', 'invoqk' ) . '</div></div>';
	}

	private static function top( array $top ): string {
		if ( ! $top ) {
			return '';
		}
		$h = '<div class="invoqk-box"><strong>' . esc_html__( 'Most common errors', 'invoqk' ) . '</strong><table><tr><th>#</th><th>'
			. esc_html__( 'Code', 'invoqk' ) . '</th><th>' . esc_html__( 'Error', 'invoqk' ) . '</th><th></th></tr>';
		foreach ( $top as $r ) {
			$h .= '<tr><td>' . (int) $r['count'] . '×</td><td><code>' . esc_html( $r['code'] ) . '</code></td><td>' . esc_html( $r['text'] ) . '</td><td>'
				. ( $r['fix'] ? '<a href="' . esc_url( $r['fix']['url'] ) . '">' . esc_html( $r['fix']['label'] ) . ' →</a>' : '' ) . '</td></tr>';
		}
		return $h . '</table></div>';
	}

	private static function empty(): string {
		return '<div class="invoqk-box invoqk-empty"><strong>' . esc_html__( 'No B2B invoices found yet', 'invoqk' ) . '</strong><p class="invoqk-muted">'
			. esc_html__( 'We only check orders that need an e-invoice: customer in Germany with a VAT ID or company name, over €250.', 'invoqk' )
			. '</p><ul class="invoqk-muted"><li>' . esc_html__( 'Does your invoice plugin already create e-invoices? Open its settings.', 'invoqk' )
			. '</li><li>' . esc_html__( 'Is an order B2B? Mark it as "e-invoice required" in the order.', 'invoqk' ) . '</li></ul></div>';
	}

	private static function pro(): string {
		return '<div class="invoqk-box invoqk-pro"><div><strong>' . esc_html__( 'Stop errors before the invoice goes out', 'invoqk' )
			. '</strong><br><span class="invoqk-muted">' . esc_html__( 'Dispatch guard', 'invoqk' ) . '<span class="invoqk-lock">PRO</span> · '
			. esc_html__( 'Archive', 'invoqk' ) . '<span class="invoqk-lock">PRO</span></span></div><a class="button button-primary" href="'
			. esc_url( Limit::upgradeUrl() ) . '">' . esc_html__( 'Try Pro for 14 days', 'invoqk' ) . '</a></div>';
	}
}
