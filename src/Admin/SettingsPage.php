<?php
namespace Invoqk\Admin;

use Invoqk\Archive\Actions;
use Invoqk\B2g\Field;
use Invoqk\Onboarding\State;
use Invoqk\Onboarding\Wizard;
use Invoqk\Guard\Hold;
use Invoqk\Validation\Client;
use Invoqk\Validation\Limit;

final class SettingsPage {
	private const MASK = '••••••••';

	public static function boot(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'Invoqk', 'Invoqk', 'manage_woocommerce', 'invoqk', [ self::class, 'render' ] );
		} );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_init', static function () {
			register_setting( 'invoqk', 'invoqk_api_key', [ 'sanitize_callback' => 'sanitize_text_field', 'autoload' => false ] );
			register_setting( 'invoqk', Actions::RETENTION_OPTION, [ 'sanitize_callback' => [ Actions::class, 'sanitizeYears' ] ] );
			add_option( Actions::RETENTION_OPTION, 8, '', false ); // register_setting has no autoload argument; no-op when it exists
			if ( Limit::isPro() ) {
				register_setting( 'invoqk', 'invoqk_guard_mode', [ 'sanitize_callback' => [ Hold::class, 'sanitizeMode' ], 'autoload' => false ] );
				register_setting( 'invoqk', Field::OPTION, [ 'sanitize_callback' => [ Field::class, 'sanitizeMode' ] ] ); // autoloaded: read on every front-end request
			}
		} );
		// options.php checks the capability via this filter; the key is never echoed, so an empty or masked submit keeps the stored one.
		add_filter( 'option_page_capability_invoqk', static fn() => 'manage_woocommerce' );
		$stamp = static fn() => update_option( 'invoqk_key_changed_at', time() ); // lets the sweep retry 401/403 records
		add_action( 'update_option_invoqk_api_key', $stamp );
		add_action( 'add_option_invoqk_api_key', $stamp );
		add_filter( 'pre_update_option_invoqk_api_key', [ self::class, 'keep' ], 10, 2 );
	}

	/** shortcut: the key cannot be cleared from the form, only replaced; delete the option to remove it. */
	public static function keep( $new, $old ) {
		return ( '' === $new || self::MASK === $new ) ? $old : $new;
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_invoqk' === $hook ) {
			wp_enqueue_style( 'invoqk-admin', plugins_url( 'assets/invoqk-admin.css', INVOQK_FILE ), [], INVOQK_VERSION );
		}
	}

	public static function render(): void {
		$set   = '' !== (string) get_option( 'invoqk_api_key', '' );
		$state = State::get();
		echo '<div class="wrap"><h1>Invoqk</h1>';
		settings_errors();
		if ( 'new' === $state || isset( $_GET['invoqk_wizard'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing
			$step = absint( $_GET['step'] ?? 1 ); // phpcs:ignore WordPress.Security.NonceVerification
			echo Wizard::render( 2 === $step ? 2 : 1 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Wizard
		} elseif ( 'running' === $state ) {
			echo Wizard::progress(); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo Dashboard::html( Readiness::get( get_locale() ), State::turnover(), '' !== Client::apiKey() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		\Invoqk\Licensing\Actions::refreshIfStale( time() );
		echo \Invoqk\Licensing\Box::html( \Invoqk\Licensing\License::get(), time(), sanitize_key( (string) ( $_GET['invoqk_license'] ?? '' ) ), (string) get_option( \Invoqk\Licensing\Actions::TRIAL_PENDING, '' ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only flash code; escaped in Box
		echo '<div class="invoqk-box invoqk-settings"><h2>' . esc_html__( 'Settings', 'invoqk' ) . '</h2>';
		echo '<div class="notice notice-info inline"><p>'
			. esc_html__( 'Invoqk is a technical check and not legal or tax advice. Ask your tax advisor what applies to your business.', 'invoqk' )
			. '</p></div>';
		$pro = Limit::isPro();
		if ( $pro && ! get_option( 'invoqk_guard_intro_seen' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Invoices with errors are now held until you fix or release them.', 'invoqk' ) . '</p></div>';
			update_option( 'invoqk_guard_intro_seen', time(), false );
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'invoqk' );
		echo '<table class="form-table"><tr><th scope="row"><label for="invoqk_api_key">' . esc_html__( 'API key', 'invoqk' ) . '</label></th>'
			. '<td><input type="password" id="invoqk_api_key" name="invoqk_api_key" value="" autocomplete="off" class="regular-text"'
			. ( $set ? ' placeholder="' . esc_attr( self::MASK ) . '"' : '' ) . '></td></tr>';
		if ( $pro ) {
			$mode = Hold::mode();
			echo '<tr><th scope="row">' . esc_html__( 'Invoices with errors', 'invoqk' ) . '</th><td>';
			foreach ( [ 'block' => __( 'Hold invoices with errors', 'invoqk' ), 'warn' => __( 'Only warn me', 'invoqk' ) ] as $v => $label ) {
				echo '<label><input type="radio" name="invoqk_guard_mode" value="' . esc_attr( $v ) . '"' . ( $v === $mode ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
			}
			echo '</td></tr>';
			$lw = Field::mode();
			echo '<tr><th scope="row">' . esc_html__( 'Ask for Leitweg-ID', 'invoqk' ) . '</th><td>';
			foreach ( [ 'off' => __( 'Off', 'invoqk' ), 'always' => __( 'Always', 'invoqk' ), 'business' => __( 'Only for business customers (company name or VAT ID filled in)', 'invoqk' ) ] as $v => $label ) {
				echo '<label><input type="radio" name="' . esc_attr( Field::OPTION ) . '" value="' . esc_attr( $v ) . '"' . ( $v === $lw ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
			}
			echo '<p class="description">' . esc_html__( 'The block checkout always shows the field. Only the classic checkout can limit it to business customers.', 'invoqk' ) . '</p></td></tr>';
		}
		echo '<tr><th scope="row"><label for="invoqk_archive_retention_years">' . esc_html__( 'Archive retention (years)', 'invoqk' ) . '</label></th>'
			. '<td><input type="number" min="1" max="30" id="invoqk_archive_retention_years" name="invoqk_archive_retention_years" value="' . esc_attr( (string) Actions::retentionYears() ) . '" class="small-text">'
			. '<p class="description">' . esc_html__( 'This is not tax advice. Check the retention period with your tax advisor.', 'invoqk' ) . '</p></td></tr>';
		echo '</table>';
		if ( false !== stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'nginx' ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Your server ignores .htaccess. Add a rule that denies web access to uploads/invoqk-archive/, or set INVOQK_ARCHIVE_DIR outside the web root.', 'invoqk' )
				. '</p></div>';
		}
		submit_button();
		echo '</form></div></div>';
	}
}
