<?php
namespace Invoqk\Admin;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Usage;
use Invoqk\Validation\Limit;

/** Readiness dashboard markup. Pure: takes Readiness::get() data, returns escaped HTML. */
final class Dashboard {
	public static function html( array $d, ?string $turnover, bool $hasKey ): string {
		$h = '<div class="invoqk-dash">';
		$h .= '<a class="invoqk-head" href="' . esc_url( admin_url( 'admin.php?page=invoqk&invoqk_wizard=1' ) ) . '" title="' . esc_attr( __( 'Edit setup', 'invoqk' ) ) . '" aria-label="' . esc_attr( __( 'Edit setup', 'invoqk' ) ) . '">'
			. '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/></svg><span>' . esc_html__( 'Edit setup', 'invoqk' ) . '</span></a>';
		if ( ! $hasKey ) {
			$h .= '<div class="notice notice-warning inline invoqk-nokey"><p>'
				. esc_html__( 'No API key is set yet.', 'invoqk' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=invoqk&tab=settings' ) ) . '#invoqk_api_key">' . esc_html__( 'Enter it in the settings.', 'invoqk' ) . '</a></p></div>';
		}
		$h .= '<div class="invoqk-grid">' . self::readiness( $d ) . self::deadline( $turnover ) . '</div>';
		$h .= self::top( $d['top'] );
		if ( Limit::isPro() ) {
			$h .= '<p class="invoqk-muted">' . esc_html( sprintf( __( 'Archive: %s', 'invoqk' ), Usage::label( Usage::used() ) ) ) . '</p>';
		}
		return $h . self::pro() . self::footer() . '</div>';
	}

	/** @return array{0:string,1:string,2:?array{label:string,url:string}} state, sentence, action */
	private static function verdict( array $d ): array {
		$setup = [ 'label' => __( 'Open setup', 'invoqk' ), 'url' => admin_url( 'admin.php?page=invoqk&invoqk_wizard=1' ) ];
		if ( 0 === (int) $d['required'] ) {
			return [ 'empty', __( 'No B2B invoices found yet', 'invoqk' ), $setup ];
		}
		$fix = $d['top'][0]['fix'] ?? null;
		if ( (int) $d['failed'] > 0 ) {
			/* translators: %d: number of faulty e-invoices */
			return [ 'bad', sprintf( _n( '%d e-invoice needs fixing', '%d e-invoices need fixing', (int) $d['failed'], 'invoqk' ), (int) $d['failed'] ), $fix ? [ 'label' => __( 'Fix errors', 'invoqk' ), 'url' => $fix['url'] ] : null ];
		}
		if ( (int) $d['missing'] > 0 ) {
			/* translators: %d: number of B2B orders without an e-invoice */
			return [ 'warn', sprintf( _n( '%d B2B order has no e-invoice yet.', '%d B2B orders have no e-invoice yet.', (int) $d['missing'], 'invoqk' ), (int) $d['missing'] ), null ];
		}
		if ( (int) $d['unchecked'] > 0 ) { // not yet checked is not the same as valid
			/* translators: %d: number of B2B orders not checked yet */
			return [ 'warn', sprintf( _n( '%d B2B order is not checked yet.', '%d B2B orders are not checked yet.', (int) $d['unchecked'], 'invoqk' ), (int) $d['unchecked'] ), null ];
		}
		return [ 'good', __( 'All your B2B e-invoices are valid.', 'invoqk' ), null ];
	}

	private static function readiness( array $d ): string {
		$req                    = (int) $d['required'];
		[ $state, $text, $act ] = self::verdict( $d );
		$h                      = '<div class="invoqk-box invoqk-hero is-' . $state . ( 'empty' === $state ? ' invoqk-empty' : '' ) . '">';
		if ( 'empty' !== $state ) {
			$h .= '<div class="invoqk-card-head"><span class="invoqk-eyebrow">' . esc_html__( 'B2B orders with a valid e-invoice · last 30 days', 'invoqk' ) . '</span>'
				. '<span class="invoqk-tag">' . esc_html__( 'EN 16931 checked', 'invoqk' ) . '</span></div>';
		}
		$h .= '<div class="invoqk-hero__main">';
		if ( 'empty' !== $state ) {
			$pct = null === $d['score'] ? 0 : max( 0, min( 100, (int) $d['score'] ) );
			/* translators: 1: valid e-invoices, 2: B2B orders that need one */
			$of = sprintf( __( '%1$d of %2$d', 'invoqk' ), (int) $d['passed'], $req );
			$h .= '<div class="invoqk-score">'
				. '<svg class="invoqk-ring" viewBox="0 0 120 120" aria-hidden="true"><circle class="invoqk-ring__track" cx="60" cy="60" r="52"/>'
				. '<circle class="invoqk-ring__fill" cx="60" cy="60" r="52" stroke-dasharray="326.7" stroke-dashoffset="' . esc_attr( (string) round( 326.7 * ( 1 - $pct / 100 ), 1 ) ) . '"/></svg>'
				. '<div class="invoqk-ring__text"><span class="invoqk-big">' . ( null === $d['score'] ? '–' : esc_html( (int) $d['score'] . '%' ) ) . '</span><span class="invoqk-sub">' . esc_html( $of ) . '</span></div></div>';
		}
		$h .= '<div class="invoqk-hero__body"><p class="invoqk-hero__verdict">' . esc_html( $text ) . '</p>';
		if ( 'empty' === $state ) {
			$h .= '<p class="invoqk-muted">' . esc_html__( 'We only check orders that need an e-invoice: customer in Germany with a VAT ID or company name, over €250.', 'invoqk' )
				. '</p><ul class="invoqk-muted"><li>' . esc_html__( 'Does your invoice plugin already create e-invoices? Open its settings.', 'invoqk' )
				. '</li><li>' . esc_html__( 'Is an order B2B? Mark it as "e-invoice required" in the order.', 'invoqk' ) . '</li></ul>';
		} else {
			$h .= '<div class="invoqk-bar">';
			foreach ( [ 'passed' => 'ok', 'failed' => 'bad', 'missing' => 'warn', 'unchecked' => 'na' ] as $k => $c ) {
				$h .= '<span class="' . $c . '" style="width:' . (int) round( 100 * $d[ $k ] / $req ) . '%"></span>';
			}
			$h .= '</div><div class="invoqk-legend">';
			foreach ( [
				'ok'   => [ 'passed', __( 'valid', 'invoqk' ) ],
				'bad'  => [ 'failed', __( 'faulty', 'invoqk' ) ],
				'warn' => [ 'missing', __( 'missing', 'invoqk' ) ],
				'na'   => [ 'unchecked', __( 'unchecked', 'invoqk' ) ],
			] as $c => [ $k, $l ] ) {
				$h .= '<span class="is-' . $c . '"><i class="invoqk-dot ' . $c . '"></i>' . (int) $d[ $k ] . ' ' . esc_html( $l ) . '</span>';
			}
			if ( (int) $d['notRequired'] > 0 ) {
				$h .= '<span class="is-ex">' . (int) $d['notRequired'] . ' ' . esc_html__( 'exempt (B2C)', 'invoqk' ) . '</span>';
			}
			$h .= '</div>';
		}
		if ( $d['capped'] ) {
			$h .= '<p class="invoqk-muted">' . esc_html__( 'Basis: newest 2,000 orders', 'invoqk' ) . '</p>';
		}
		$h .= '</div></div>';
		if ( $act ) {
			$h .= '<div class="invoqk-foot"><span class="invoqk-muted">' . ( 'bad' === $state ? esc_html__( 'Requires immediate merchant verification', 'invoqk' ) : '' ) . '</span>'
				. '<a class="button button-primary" href="' . esc_url( $act['url'] ) . '">' . esc_html( $act['label'] ) . '</a></div>';
		}
		return $h . '</div>';
	}

	private static function deadline( ?string $turnover ): string {
		$date = null === $turnover ? '1.1.2027 / 1.1.2028' : ( 'over' === $turnover ? '1.1.2027' : '1.1.2028' );
		$h    = '<div class="invoqk-box invoqk-deadline"><div class="invoqk-card-head"><span class="invoqk-eyebrow">' . esc_html__( 'Your obligation starts', 'invoqk' ) . '</span>'
			. '<span class="invoqk-tag is-blue">' . esc_html__( 'DE compliance', 'invoqk' ) . '</span></div>'
			. '<div class="invoqk-date-row"><span class="invoqk-date">' . esc_html( $date ) . '</span><span class="invoqk-mand">' . esc_html__( 'B2B mandatory', 'invoqk' ) . '</span></div>'
			/* translators: %s: name of the German law */
			. '<p class="invoqk-desc">' . sprintf( esc_html__( 'Mandatory e-invoicing for domestic B2B transactions under the German Growth Opportunities Act (%s).', 'invoqk' ), '<em>Wachstumschancengesetz</em>' ) . '</p>';
		// Saves on change; the Update button keeps it usable without JS.
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="invoqk-turnover">'
			. wp_nonce_field( 'invoqk_wizard', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="invoqk_turnover">'
			. '<div class="invoqk-eyebrow">' . esc_html__( 'Previous calendar year revenue', 'invoqk' ) . '</div>'
			. '<div class="invoqk-seg" role="radiogroup" aria-label="' . esc_attr( __( 'Turnover last year', 'invoqk' ) ) . '">';
		foreach ( [ 'over' => __( 'Over €800,000', 'invoqk' ), 'under' => __( 'Up to €800,000', 'invoqk' ) ] as $v => $l ) {
			$h .= '<label><input type="radio" name="band" value="' . $v . '"' . ( $turnover === $v ? ' checked' : '' ) . ' onchange="this.form.submit()"><span>' . esc_html( $l ) . '</span></label>';
		}
		return $h . '</div><div class="invoqk-foot"><span class="invoqk-muted">' . esc_html__( 'Standard regulatory guidance only.', 'invoqk' )
			. '</span><button type="submit" class="invoqk-upd">' . esc_html__( 'Update', 'invoqk' ) . '</button></div></form></div>';
	}

	private static function top( array $top ): string {
		if ( ! $top ) {
			return '';
		}
		/* translators: %d: number of error types */
		$n = sprintf( _n( '%d issue pending', '%d issues pending', count( $top ), 'invoqk' ), count( $top ) );
		$h = '<div class="invoqk-box invoqk-errors"><div class="invoqk-errors__head"><strong>' . esc_html__( 'Most common errors', 'invoqk' ) . '</strong><span class="invoqk-pill">' . esc_html( $n ) . '</span></div>'
			. '<p class="invoqk-muted">' . esc_html__( 'Frequent validation failures in your recent WooCommerce orders', 'invoqk' ) . '</p>';
		foreach ( $top as $r ) {
			$h .= '<div class="invoqk-err"><span class="invoqk-err__n">' . (int) $r['count'] . '×</span><code>' . esc_html( $r['code'] ) . '</code><span class="invoqk-err__t">' . esc_html( $r['text'] ) . '</span>'
				. ( $r['fix'] ? '<a href="' . esc_url( $r['fix']['url'] ) . '" title="' . esc_attr( $r['fix']['label'] ) . '">' . esc_html__( 'Fix in order', 'invoqk' ) . ' →</a>' : '' ) . '</div>';
		}
		return $h . '</div>';
	}

	private static function pro(): string {
		if ( Limit::isPro() || ! Limit::proUi() ) {
			return '';
		}
		$lock = '<span class="invoqk-chip"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
		return '<div class="invoqk-box invoqk-pro"><div class="invoqk-pro__body"><strong>' . esc_html__( 'Stop errors before the invoice goes out', 'invoqk' ) . '</strong>'
			. '<p class="invoqk-muted">' . esc_html__( 'Block non-compliant orders before the invoice goes out and keep an audit-proof invoice archive.', 'invoqk' ) . '</p>'
			. '<div class="invoqk-chips">' . $lock . esc_html__( 'Dispatch guard', 'invoqk' ) . '</span>' . $lock . esc_html__( 'Archive', 'invoqk' ) . '</span></div></div>'
			. '<a class="button button-primary" href="' . esc_url( Limit::upgradeUrl() ) . '">' . esc_html__( 'Try Pro for 14 days', 'invoqk' ) . '</a></div>';
	}

	private static function footer(): string {
		return '<p class="invoqk-footer">' . sprintf( esc_html__( 'EN 16931 · German Growth Opportunities Act (%1$s) · v%2$s', 'invoqk' ), '<em>Wachstumschancengesetz</em>', esc_html( INVOQK_VERSION ) ) . '</p>';
	}
}
