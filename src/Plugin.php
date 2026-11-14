<?php
namespace Invoqk;

final class Plugin {
	public static function boot(): void {
		Jobs\Scheduler::boot();
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'invoqk', false, dirname( plugin_basename( INVOQK_FILE ) ) . '/languages' );
			}
		);
	}
}
