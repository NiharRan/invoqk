<?php
namespace Invoqk\Admin;

final class SettingsPage {
	public static function boot(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'Invoqk', 'Invoqk', 'manage_woocommerce', 'invoqk', [ self::class, 'render' ] );
		} );
	}

	public static function render(): void {
		echo '<div class="wrap"><h1>Invoqk</h1><div class="notice notice-info inline"><p>'
			. esc_html__( 'Invoqk is a technical check and not legal or tax advice. Ask your tax advisor what applies to your business.', 'invoqk' )
			. '</p></div></div>';
	}
}
