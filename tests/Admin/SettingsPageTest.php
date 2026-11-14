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
