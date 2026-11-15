<?php
use Invoqk\Archive\Hooks;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_actions'] = [];
		$GLOBALS['invoqk_filters'] = [];
	}

	public function test_registers_hooks(): void {
		Hooks::boot();

		foreach ( [ 'wpo_wcpdf_email_attachment', 'woocommerce_email_sent', 'invoqk_archive_entry', 'invoqk_archive_verify', 'invoqk_validated', 'invoqk_sweep', 'admin_init' ] as $tag ) {
			$this->assertNotEmpty( $GLOBALS['invoqk_actions'][ $tag ] ?? [], "action $tag" );
		}
		$this->assertNotEmpty( $GLOBALS['invoqk_filters']['woocommerce_email_attachments'] ?? [], 'filter woocommerce_email_attachments' );
	}
}
