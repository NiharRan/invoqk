<?php
namespace Invoqk;

final class Plugin {
	public static function boot(): void {
		Jobs\Scheduler::boot();
		Onboarding\FirstReport::boot();
		Guard\Guard::boot();
		Archive\Hooks::boot();
		Refund\Open::boot();
		CreditNote\Detect::boot();
		Licensing\Actions::boot();
		B2g\Field::boot(); // checkout is front end
		Sources\WpOvernightAdapter::boot(); // WP Overnight builds EDI for emails and downloads too, not only in admin
		if ( is_admin() ) {
			Admin\OrderColumn::boot();
			Admin\OrderBox::boot();
			Admin\Revalidate::boot();
			Admin\Override::boot();
			Guard\Actions::boot();
			Admin\SettingsPage::boot();
			Licensing\Notice::boot();
			Refund\Dismiss::boot();
			Refund\Notice::boot();
			Onboarding\Wizard::boot();
		}
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'invoqk', false, dirname( plugin_basename( INVOQK_FILE ) ) . '/languages' );
			}
		);
	}
}
