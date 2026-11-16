<?php
use Invoqk\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $g ): void { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
	function submit_button(): void { echo '<button type="submit">save</button>'; }
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $g, string $name, array $args = [] ): void { $GLOBALS['invoqk_settings'][ $name ] = $args; }
}

final class SettingsPageTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = []; // other tests leave adapter filters behind
		\Invoqk\Archive\Archive::$index = new \Invoqk\Tests\Archive\MemoryIndex(); // Pro dashboard shows archive usage
	}

	protected function tearDown(): void {
		\Invoqk\Archive\Archive::reset();
	}

	public function test_form_uses_options_flow_and_never_echoes_key(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_live_SECRET123';
		$h = $this->page();
		$this->assertStringContainsString( 'name="option_page" value="invoqk"', $h );
		$this->assertStringContainsString( 'type="password"', $h );
		$this->assertStringContainsString( 'name="invoqk_api_key"', $h );
		$this->assertStringNotContainsString( 'SECRET123', $h );
		$this->assertStringContainsString( 'placeholder="••••••••"', $h );
		$this->assertStringContainsString( 'technical check and not legal or tax advice', $h );
	}

	public function test_key_change_records_timestamp_for_sweep(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_actions'] = [];
		SettingsPage::boot();
		do_action( 'update_option_invoqk_api_key', 'old', 'new' );
		$this->assertEqualsWithDelta( time(), $GLOBALS['invoqk_options']['invoqk_key_changed_at'], 2 );
		unset( $GLOBALS['invoqk_options']['invoqk_key_changed_at'] );
		do_action( 'add_option_invoqk_api_key', 'invoqk_api_key', 'new' );
		$this->assertArrayHasKey( 'invoqk_key_changed_at', $GLOBALS['invoqk_options'] );
	}

	public function test_render_shows_settings_errors(): void {
		$GLOBALS['invoqk_settings_errors'] = 0;
		ob_start();
		SettingsPage::render();
		ob_end_clean();
		$this->assertSame( 1, $GLOBALS['invoqk_settings_errors'] );
	}

	public function test_no_placeholder_when_unset(): void {
		unset( $GLOBALS['invoqk_options']['invoqk_api_key'] );
		$this->assertStringNotContainsString( 'placeholder=', $this->page() );
	}

	public function test_untouched_form_keeps_existing_key(): void {
		$this->assertSame( 'old', SettingsPage::keep( '', 'old' ) );
		$this->assertSame( 'old', SettingsPage::keep( '••••••••', 'old' ) );
		$this->assertSame( 'new', SettingsPage::keep( 'new', 'old' ) );
		$this->assertSame( '', SettingsPage::keep( '', '' ) );
	}

	private function reset( string $state ): void {
		$GLOBALS['invoqk_options'] = [ 'invoqk_onboarding' => $state ];
		$GLOBALS['invoqk_transients'] = [];
		$_GET = [];
	}

	public function test_render_routes_wizard_when_new(): void {
		$this->reset( 'new' );
		$_GET['step'] = '2';
		ob_start();
		SettingsPage::render();
		$h = ob_get_clean();
		$this->assertStringContainsString( 'invoqk_wizard_finish', $h );
		$this->assertStringNotContainsString( 'invoqk-dash', $h );
		$this->assertStringContainsString( 'name="api_key"', $h ); // the wizard has its own key field; the settings form is on its own tab
		$_GET = [];
	}

	public function test_render_routes_dashboard_when_done(): void {
		$this->reset( 'done' );
		ob_start();
		SettingsPage::render();
		$h = ob_get_clean();
		$this->assertStringContainsString( 'invoqk-dash', $h );
		$this->assertStringNotContainsString( 'name="option_page" value="invoqk"', $h ); // the form lives on the Settings tab

		$this->reset( 'done' );
		$_GET['invoqk_wizard'] = '1';
		$_GET['step'] = '9';
		ob_start();
		SettingsPage::render();
		$h = ob_get_clean(); // bad step falls back to step 1
		$this->assertStringContainsString( 'invoqk-wizard', $h );
		$this->assertStringNotContainsString( 'invoqk_wizard_finish', $h );
		$_GET = [];
	}

	public function test_render_shows_progress_when_running(): void {
		$this->reset( 'running' );
		$GLOBALS['invoqk_options']['invoqk_first_report'] = [ 'ids' => [ 1 ], 'startedAt' => time() ];
		ob_start();
		SettingsPage::render();
		$this->assertStringContainsString( 'invoqk-progress', ob_get_clean() );
	}

	public function test_css_enqueued_only_on_plugin_page(): void {
		$GLOBALS['invoqk_styles'] = [];
		SettingsPage::enqueue( 'edit.php' );
		$this->assertSame( [], $GLOBALS['invoqk_styles'] );
		SettingsPage::enqueue( 'woocommerce_page_invoqk' );
		$this->assertArrayHasKey( 'invoqk-admin', $GLOBALS['invoqk_styles'] );
	}

	private function page( string $tab = 'settings' ): string {
		$_GET['tab'] = $tab;
		ob_start();
		SettingsPage::render();
		$h = ob_get_clean();
		unset( $_GET['tab'] );
		return $h;
	}

	public function test_mode_radios_only_on_pro(): void {
		$this->reset( 'done' );
		$this->assertStringNotContainsString( 'invoqk_guard_mode', $this->page() );
		add_filter( 'invoqk_is_pro', fn() => true );
		$h = $this->page();
		$this->assertStringContainsString( 'name="invoqk_guard_mode" value="block" checked', $h );
		$this->assertStringContainsString( 'Only warn me', $h );
		$GLOBALS['invoqk_options']['invoqk_guard_mode'] = 'warn';
		$this->assertStringContainsString( 'value="warn" checked', $this->page() );
	}

	public function test_leitweg_mode_radio_shown_only_for_pro(): void {
		$this->reset( 'done' );
		$GLOBALS['invoqk_settings'] = [];
		$GLOBALS['invoqk_actions']  = [];
		SettingsPage::boot();
		do_action( 'admin_init' );
		$this->assertArrayNotHasKey( 'invoqk_leitweg_mode', $GLOBALS['invoqk_settings'] );
		$this->assertStringNotContainsString( 'invoqk_leitweg_mode', $this->page() );

		add_filter( 'invoqk_is_pro', fn() => true );
		do_action( 'admin_init' );
		$this->assertSame( [ \Invoqk\B2g\Field::class, 'sanitizeMode' ], $GLOBALS['invoqk_settings']['invoqk_leitweg_mode']['sanitize_callback'] );
		$h = $this->page();
		$this->assertStringContainsString( 'Ask for Leitweg-ID', $h );
		$this->assertStringContainsString( 'name="invoqk_leitweg_mode" value="off" checked', $h );
		$this->assertStringContainsString( 'value="business"', $h );
		$this->assertStringContainsString( 'company name or VAT ID filled in', $h );
		$this->assertStringContainsString( 'The block checkout always shows the field.', $h );
		$this->assertGreaterThan( strpos( $h, 'name="invoqk_guard_mode"' ), strpos( $h, 'name="invoqk_leitweg_mode"' ) );
		$GLOBALS['invoqk_options']['invoqk_leitweg_mode'] = 'always';
		$this->assertStringContainsString( 'name="invoqk_leitweg_mode" value="always" checked', $this->page() );
	}

	public function test_fallback_nullsatz_is_labelled_zero_rated_once_with_kleinunternehmer_note(): void {
		$this->reset( 'done' );
		add_filter( 'invoqk_is_pro', fn() => true );
		$h = $this->page();
		$this->assertSame( 1, substr_count( $h, '0 % lines are zero-rated (Nullsatz), not tax-exempt' ) );
		$this->assertStringContainsString( 'id="invoqk_fallback_seller_nullsatz" name="invoqk_fallback_seller[nullsatz]"', $h );
		$this->assertStringContainsString( 'Small businesses under §19 UStG (Kleinunternehmer) are not supported.', $h );
		$this->assertStringNotContainsString( 'Small business, no VAT charged', $h );
	}

	public function test_guard_mode_setting_registered_with_autoload_off(): void {
		$GLOBALS['invoqk_actions']  = [];
		$GLOBALS['invoqk_settings'] = [];
		add_filter( 'invoqk_is_pro', fn() => true );
		SettingsPage::boot();
		do_action( 'admin_init' );
		$this->assertFalse( $GLOBALS['invoqk_settings']['invoqk_guard_mode']['autoload'] ?? null );
	}

	public function test_intro_notice_shown_once(): void {
		$this->reset( 'done' );
		$this->assertStringNotContainsString( 'now held until', $this->page() );
		add_filter( 'invoqk_is_pro', fn() => true );
		$this->assertStringContainsString( 'Invoices with errors are now held until you fix or release them.', $this->page() );
		$this->assertStringNotContainsString( 'now held until', $this->page() );
	}

	public function test_retention_field_note_and_registration(): void {
		$this->reset( 'done' );
		$h = $this->page();
		$this->assertStringContainsString( 'name="invoqk_archive_retention_years"', $h );
		$this->assertStringContainsString( 'min="1" max="30"', $h );
		$this->assertStringContainsString( 'This is not tax advice. Check the retention period with your tax advisor.', $h );
		$this->assertStringNotContainsString( 'ignores .htaccess', $h );
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
		$this->assertStringContainsString( 'Your server ignores .htaccess.', $this->page() );
		unset( $_SERVER['SERVER_SOFTWARE'] );
		$GLOBALS['invoqk_actions'] = [];
		SettingsPage::boot();
		do_action( 'admin_init' );
		$this->assertArrayHasKey( 'invoqk_archive_retention_years', $GLOBALS['invoqk_settings'] );
		$this->assertSame( 8, $GLOBALS['invoqk_options']['invoqk_archive_retention_years'] );
		$GLOBALS['invoqk_options']['invoqk_archive_retention_years'] = 5;
		do_action( 'admin_init' );
		$this->assertSame( 5, $GLOBALS['invoqk_options']['invoqk_archive_retention_years'] );
	}

	public function test_render_shows_licensing_box_for_free_site(): void {
		$GLOBALS['invoqk_options'] = [];
		$h = $this->page( 'license' );
		$this->assertStringContainsString( 'Free plan', $h );
		$this->assertStringContainsString( 'value="checkout"', $h );
	}

	public function test_unknown_tab_falls_back_to_overview(): void {
		$this->reset( 'done' );
		$h = $this->page( '<x>' );
		$this->assertStringNotContainsString( '<x>', $h );
		$this->assertMatchesRegularExpression( '/class="invoqk-tab is-active"[^>]*aria-current="page"[^>]*>Overview/', $h );
		$this->assertStringContainsString( 'invoqk-dash', $h );
	}

	public function test_tab_bar_links_and_active_state(): void {
		$this->reset( 'done' );
		foreach ( [ 'settings' => 'Settings', 'license' => 'License' ] as $slug => $label ) {
			$h = $this->page( $slug );
			$this->assertStringContainsString( 'page=invoqk&amp;tab=' . $slug, $h );
			$this->assertMatchesRegularExpression( '/href="[^"]*tab=' . $slug . '"[^>]*class="invoqk-tab is-active"|class="invoqk-tab is-active"[^>]*href="[^"]*tab=' . $slug . '"/', $h );
			$this->assertSame( 1, substr_count( $h, 'is-active' ) );
		}
	}

	public function test_settings_tab_has_form_not_license(): void {
		$this->reset( 'done' );
		$h = $this->page( 'settings' );
		$this->assertStringContainsString( 'name="option_page" value="invoqk"', $h );
		$this->assertStringNotContainsString( 'value="checkout"', $h );
		$this->assertStringNotContainsString( 'invoqk-dash', $h );
	}

	public function test_license_tab_has_license_not_form(): void {
		$this->reset( 'done' );
		$h = $this->page( 'license' );
		$this->assertStringContainsString( 'value="checkout"', $h );
		$this->assertStringNotContainsString( 'name="option_page"', $h );
	}
}
