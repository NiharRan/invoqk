<?php
namespace Invoqk;

final class Plugin {
	public static function boot(): void {
		Jobs\Scheduler::boot();
		Onboarding\FirstReport::boot();
		Guard\Guard::boot();
		Archive\Capture::boot();
		if ( is_admin() ) {
			Admin\OrderColumn::boot();
			Admin\OrderBox::boot();
			Admin\Revalidate::boot();
			Admin\Override::boot();
			Guard\Actions::boot();
			Admin\SettingsPage::boot();
			Onboarding\Wizard::boot();
			add_action( 'admin_init', [ Archive\WpdbIndex::class, 'ensure' ] );
		}
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'invoqk', false, dirname( plugin_basename( INVOQK_FILE ) ) . '/languages' );
			}
		);
	}
}
