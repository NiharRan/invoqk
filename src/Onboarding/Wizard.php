<?php
namespace Invoqk\Onboarding;

use Invoqk\Admin\SettingsPage;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Client;

/** Onboarding wizard: screens plus the admin-post / ajax endpoints behind them. */
final class Wizard {
	private const NONCE = 'invoqk_wizard';

	public static function boot(): void {
		add_action( 'admin_post_invoqk_wizard_finish', [ self::class, 'finishCallback' ] );
		add_action( 'admin_post_invoqk_wizard_skip', [ self::class, 'skipCallback' ] );
		add_action( 'admin_post_invoqk_turnover', [ self::class, 'turnoverCallback' ] );
		add_action( 'wp_ajax_invoqk_first_report_status', [ self::class, 'statusCallback' ] );
		add_action( 'admin_init', static function () {
			$to = self::redirectTarget();
			if ( null !== $to ) {
				wp_safe_redirect( $to );
				exit;
			}
		} );
	}

	/** One-shot: consumes the activation transient and says where to go, if anywhere. */
	public static function redirectTarget(): ?string {
		// Ajax runs admin_init too; leave the transient for the next real page load.
		if ( wp_doing_ajax() || ! get_transient( 'invoqk_activation_redirect' ) ) {
			return null;
		}
		delete_transient( 'invoqk_activation_redirect' );
		if ( isset( $_GET['activate-multi'] ) || is_network_admin() || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return null;
		}
		return admin_url( 'admin.php?page=invoqk' );
	}

	private static function guard( bool $ajax = false ): void {
		$ok = current_user_can( 'manage_woocommerce' )
			&& ( $ajax ? check_ajax_referer( self::NONCE, 'nonce', false ) : check_admin_referer( self::NONCE ) );
		if ( ! $ok ) {
			wp_die( esc_html__( 'Not allowed.', 'invoqk' ), '', [ 'response' => 403 ] );
		}
	}

	private static function back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=invoqk' ) );
		exit;
	}

	public static function finishCallback(): void {
		self::guard();
		self::finish( array_map( 'wp_unslash', $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard()
		self::back();
	}

	public static function skipCallback(): void {
		self::guard();
		self::skip();
		self::back();
	}

	public static function turnoverCallback(): void {
		self::guard();
		self::saveTurnover( sanitize_key( wp_unslash( $_REQUEST['band'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::back();
	}

	public static function statusCallback(): void {
		self::guard( true );
		FirstReport::nudge();
		wp_send_json( FirstReport::status() );
	}

	public static function saveTurnover( string $band ): bool {
		return State::setTurnover( $band );
	}

	public static function skip(): void {
		State::set( 'skipped' );
	}

	/** Saves band and key, then starts the one-off report. Never replaces an existing report. */
	public static function finish( array $post ): bool {
		if ( ! State::setTurnover( sanitize_key( (string) ( $post['turnover'] ?? '' ) ) ) ) {
			return false;
		}
		// keep() with no old value drops empty and masked input, the same rule as the settings form.
		$key = SettingsPage::keep( sanitize_text_field( (string) ( $post['api_key'] ?? '' ) ), '' );
		if ( '' !== $key ) {
			update_option( 'invoqk_api_key', $key, false );
		}
		if ( ! FirstReport::start() && 'running' !== State::get() ) {
			State::set( 'done' ); // reopened after the report: only band and key change
		}
		return true;
	}

	private static function steps( int $on ): string {
		$h = '<div class="invoqk-stepper">';
		foreach ( [ 1 => __( 'Plugin', 'invoqk' ), 2 => __( 'Turnover', 'invoqk' ), 3 => __( 'Check', 'invoqk' ) ] as $n => $l ) {
			$c  = $n === $on ? 'is-on' : ( $n < $on ? 'is-done' : '' );
			$h .= '<span' . ( '' !== $c ? ' class="' . $c . '"' : '' ) . '><b>' . (int) $n . '</b>' . esc_html( $l ) . '</span>';
		}
		return $h . '</div>';
	}

	/** Decorative line-art; strokes draw in via CSS (assets/invoqk-admin.css). Own constant markup, no user data. */
	private static function icon( string $kind ): string {
		$p = [
			'invoice'  => '<rect x="22" y="14" width="56" height="72" rx="8"/><path d="M34 34h32M34 46h32M34 58h18"/><circle class="iq-badge" cx="72" cy="76" r="14"/><path class="iq-tick" d="M65 76l5 5 9-10"/>',
			'calendar' => '<rect x="18" y="24" width="64" height="58" rx="8"/><path d="M18 42h64M34 16v16M66 16v16"/><path class="iq-tick" d="M56 54a9 9 0 1 0 0 14M44 58h12M44 64h12"/>',
			'scan'     => '<rect x="22" y="14" width="56" height="72" rx="8"/><path d="M34 34h32M34 46h32M34 58h24"/><path class="iq-sweep" d="M16 50h68"/>',
		][ $kind ];
		return '<svg class="invoqk-icon is-' . esc_attr( $kind ) . '" viewBox="0 0 100 100" width="96" height="96" aria-hidden="true" focusable="false"><circle class="iq-ring" cx="50" cy="50" r="46"/>' . $p . '</svg>';
	}

	private static function skipLink(): string {
		return '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=invoqk_wizard_skip' ), self::NONCE ) ) . '" class="invoqk-skip">'
			. esc_html__( 'Set up later', 'invoqk' ) . '</a>';
	}

	public static function render( int $step ): string {
		return 2 === $step ? self::step2() : self::step1();
	}

	private static function step1(): string {
		$found = array_filter( Registry::default()->plugins(), fn( $a ) => $a->isAvailable() );
		$h     = '<div class="invoqk-wizard">' . self::steps( 1 );
		if ( $found ) {
			$h .= self::icon( 'invoice' ) . '<h2>' . esc_html__( 'We found your invoice plugin', 'invoqk' ) . '</h2>'
				. '<p class="invoqk-lead">' . esc_html__( 'Invoqk reads your e-invoices (XRechnung / ZUGFeRD) straight from it.', 'invoqk' ) . '</p>';
			foreach ( $found as $a ) {
				$h .= '<p class="invoqk-found"><span class="invoqk-found__ok">✓</span><strong>' . esc_html( $a->label() ) . '</strong></p>';
			}
			$h .= '<div class="invoqk-actions">' . self::skipLink() . '<a class="button button-primary" href="'
				. esc_url( admin_url( 'admin.php?page=invoqk&invoqk_wizard=1&step=2' ) ) . '">' . esc_html__( 'Continue', 'invoqk' ) . '</a></div>';
		} else {
			$h .= '<h2>' . esc_html__( 'No invoice plugin found', 'invoqk' ) . '</h2><p class="invoqk-lead">'
				. esc_html__( 'These plugins are supported:', 'invoqk' ) . '</p><ul><li>Germanized Pro</li><li>German Market</li><li>WP Overnight PDF Invoices</li></ul>'
				. '<div class="invoqk-actions">' . self::skipLink() . '</div>';
		}
		return $h . '</div>';
	}

	private static function step2(): string {
		$h = '<div class="invoqk-wizard">' . self::steps( 2 )
			. self::icon( 'calendar' ) . '<h2>' . esc_html__( 'What was your turnover last year?', 'invoqk' ) . '</h2>'
			. '<p class="invoqk-lead">' . esc_html__( 'This sets when e-invoices become mandatory for you.', 'invoqk' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. wp_nonce_field( self::NONCE, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="invoqk_wizard_finish">';
		$h .= '<div class="invoqk-choices">';
		foreach ( [ 'over' => [ __( 'Over €800,000', 'invoqk' ), '1.1.2027' ], 'under' => [ __( 'Up to €800,000', 'invoqk' ), '1.1.2028' ] ] as $v => [ $label, $date ] ) {
			$h .= '<label class="invoqk-choice"><input type="radio" name="turnover" value="' . esc_attr( $v ) . '" required' . ( State::turnover() === $v ? ' checked' : '' ) . '>'
				. '<span><span class="invoqk-choice__title">' . esc_html( $label ) . '</span><span class="invoqk-choice__desc">'
				/* translators: %s: date such as 1.1.2027 */
				. esc_html( sprintf( __( 'E-invoice mandatory from %s', 'invoqk' ), $date ) ) . '</span></span></label>';
		}
		$h .= '</div>';
		if ( '' === Client::apiKey() ) {
			$h .= '<div class="invoqk-field"><label for="invoqk_wiz_key">' . esc_html__( 'API key', 'invoqk' ) . '</label><input type="password" id="invoqk_wiz_key" name="api_key" value="" autocomplete="off">'
				. '<p class="invoqk-help">' . esc_html__( '(only if none is set yet)', 'invoqk' ) . '</p></div>';
		}
		return $h . '<p class="invoqk-note">' . esc_html__( 'No legal or tax advice.', 'invoqk' ) . '</p><p class="invoqk-actions">'
			. '<a href="' . esc_url( admin_url( 'admin.php?page=invoqk&invoqk_wizard=1' ) ) . '">' . esc_html__( 'Back', 'invoqk' ) . '</a>'
			. '<button type="submit" class="button button-primary">' . ( ! ( State::firstReport()['ids'] ?? [] ) ? esc_html__( 'Start first check', 'invoqk' ) : esc_html__( 'Save and continue', 'invoqk' ) ) . '</button>' . self::skipLink()
			. '</p></form></div>';
	}

	/** Step 3: progress bar, filled by assets/invoqk-admin.js. */
	public static function progress(): string {
		wp_enqueue_script( 'invoqk-admin', plugins_url( 'assets/invoqk-admin.js', INVOQK_FILE ), [], INVOQK_VERSION . '.' . (int) @filemtime( dirname( INVOQK_FILE ) . '/assets/invoqk-admin.js' ), true );
		wp_localize_script( 'invoqk-admin', 'invoqkWizard', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php?action=invoqk_first_report_status' ),
			'nonce'        => wp_create_nonce( self::NONCE ),
			'dashboardUrl' => admin_url( 'admin.php?page=invoqk' ),
			'scanning'     => __( 'Scanning your orders…', 'invoqk' ),
			/* translators: 1: invoices checked so far, 2: total invoices picked */
			'checked'      => __( '%1$d of %2$d invoices checked', 'invoqk' ),
		] );
		return '<div class="invoqk-wizard">' . self::steps( 3 )
			. self::icon( 'scan' ) . '<h2>' . esc_html__( 'We are checking your latest B2B invoices', 'invoqk' ) . '</h2>'
			. '<div id="invoqk-progress"><progress id="invoqk-bar" max="100" style="width:100%"></progress>'
			. '<p id="invoqk-count">' . esc_html__( 'Scanning your orders…', 'invoqk' ) . '</p></div><p class="description">'
			. esc_html__( 'You can leave this page. The check keeps running in the background; the dashboard opens as soon as results are in.', 'invoqk' )
			. '</p></div>';
	}
}
