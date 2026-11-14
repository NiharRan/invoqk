<?php
use Invoqk\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $g ): void { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
	function submit_button(): void { echo '<button type="submit">save</button>'; }
}

final class SettingsPageTest extends TestCase {
	public function test_form_uses_options_flow_and_never_echoes_key(): void {
		$GLOBALS['invoqk_options']['invoqk_api_key'] = 'sk_live_SECRET123';
		ob_start();
		SettingsPage::render();
		$h = ob_get_clean();
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
		ob_start();
		SettingsPage::render();
		$this->assertStringNotContainsString( 'placeholder=', ob_get_clean() );
	}

	public function test_untouched_form_keeps_existing_key(): void {
		$this->assertSame( 'old', SettingsPage::keep( '', 'old' ) );
		$this->assertSame( 'old', SettingsPage::keep( '••••••••', 'old' ) );
		$this->assertSame( 'new', SettingsPage::keep( 'new', 'old' ) );
		$this->assertSame( '', SettingsPage::keep( '', '' ) );
	}
}
