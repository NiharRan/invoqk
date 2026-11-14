<?php
namespace Invoqk\Admin;

final class SettingsPage {
	private const MASK = '••••••••';

	public static function boot(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'Invoqk', 'Invoqk', 'manage_woocommerce', 'invoqk', [ self::class, 'render' ] );
		} );
		add_action( 'admin_init', static function () {
			register_setting( 'invoqk', 'invoqk_api_key', [ 'sanitize_callback' => 'sanitize_text_field', 'autoload' => false ] );
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

	public static function render(): void {
		$set = '' !== (string) get_option( 'invoqk_api_key', '' );
		echo '<div class="wrap"><h1>Invoqk</h1>';
		settings_errors();
		echo '<div class="notice notice-info inline"><p>'
			. esc_html__( 'Invoqk is a technical check and not legal or tax advice. Ask your tax advisor what applies to your business.', 'invoqk' )
			. '</p></div><form method="post" action="options.php">';
		settings_fields( 'invoqk' );
		echo '<table class="form-table"><tr><th scope="row"><label for="invoqk_api_key">' . esc_html__( 'API key', 'invoqk' ) . '</label></th>'
			. '<td><input type="password" id="invoqk_api_key" name="invoqk_api_key" value="" autocomplete="off" class="regular-text"'
			. ( $set ? ' placeholder="' . esc_attr( self::MASK ) . '"' : '' ) . '></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
