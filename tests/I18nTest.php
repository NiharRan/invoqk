<?php
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase {
	public function test_german_po_translates_requirement_notice(): void {
		$po = file_get_contents( dirname( __DIR__ ) . '/languages/invoqk-de_DE.po' );
		$this->assertMatchesRegularExpression( '/msgid "Invoqk needs PHP %s or newer\."\nmsgstr "[^"]+"/', $po );
		$this->assertFileExists( dirname( __DIR__ ) . '/languages/invoqk-de_DE.mo' );
	}

	public function test_every_pot_string_has_a_german_translation(): void {
		$po = file_get_contents( dirname( __DIR__ ) . '/languages/invoqk-de_DE.po' );
		$this->assertDoesNotMatchRegularExpression( '/msgid "[^"]+"\nmsgstr ""\n/', $po );
	}
}
