<?php
namespace Invoqk;

final class Plugin {
	public static function boot(): void {
		Jobs\Scheduler::boot();
		if ( is_admin() ) {
			Admin\OrderColumn::boot();
			Admin\OrderBox::boot();
			Admin\SettingsPage::boot();
		}
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'invoqk', false, dirname( plugin_basename( INVOQK_FILE ) ) . '/languages' );
			}
		);
	}
}
