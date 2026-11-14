<?php
namespace Invoqk\Admin;

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
			if ( Limit::isPro() ) {
				register_setting( 'invoqk', 'invoqk_guard_mode', [ 'sanitize_callback' => [ Hold::class, 'sanitizeMode' ], 'autoload' => false ] );
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
		}
		echo '</table>';
		submit_button();
		echo '</form></div></div>';
	}
}
