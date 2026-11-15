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

	/** Minimal .mo reader: msgid => msgstr (plural forms joined by NUL, as stored). */
	private function mo(): array {
		$d = file_get_contents( dirname( __DIR__ ) . '/languages/invoqk-de_DE.mo' );
		$u = unpack( 'V', substr( $d, 0, 4 ) )[1] === 0x950412de ? 'V' : 'N';
		$n = unpack( "{$u}n", substr( $d, 8, 4 ) )[ 'n' ];
		$o = unpack( "{$u}o", substr( $d, 12, 4 ) )[ 'o' ];
		$t = unpack( "{$u}t", substr( $d, 16, 4 ) )[ 't' ];
		$out = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$a = unpack( "{$u}l/{$u}p", substr( $d, $o + 8 * $i, 8 ) );
			$b = unpack( "{$u}l/{$u}p", substr( $d, $t + 8 * $i, 8 ) );
			$out[ substr( $d, $a['p'], $a['l'] ) ] = substr( $d, $b['p'], $b['l'] );
		}
		return $out;
	}

	public function test_mo_has_german_for_e3_strings(): void {
		$mo  = $this->mo();
		$exp = [
			'No API key is set yet. Enter it under WooCommerce > Invoqk.' => 'Es ist noch kein API-Schlüssel hinterlegt. Trage ihn unter WooCommerce → Invoqk ein.',
			'The API key is invalid or was revoked.'                      => 'Der API-Schlüssel ist ungültig oder wurde widerrufen.',
			'This API key belongs to a different domain.'                 => 'Dieser API-Schlüssel gehört zu einer anderen Domain.',
			'The check quota is used up. We will try again later.'        => 'Das Prüfkontingent ist aufgebraucht. Wir versuchen es später erneut.',
			'The invoice file could not be read.'                         => 'Die Rechnungsdatei konnte nicht gelesen werden.',
			'The file is not a supported e-invoice format.'               => 'Die Datei ist kein unterstütztes E-Rechnungsformat.',
			'The validation service is not reachable right now. We will try again.' => 'Der Prüfdienst ist gerade nicht erreichbar. Wir versuchen es erneut.',
			'The check quota is used up. Please click “Validate again” later.' => 'Das Prüfkontingent ist aufgebraucht. Bitte später auf „Erneut prüfen“ klicken.',
			'The validation service is not reachable right now. Please click “Validate again” later.' => 'Der Prüfdienst ist gerade nicht erreichbar. Bitte später auf „Erneut prüfen“ klicken.',
			'Valid'                => 'Gültig',
			'Invalid'              => 'Fehlerhaft',
			'Could not check'      => 'Nicht geprüft',
			'Limit reached'        => 'Limit erreicht',
			'Checking'             => 'Wird geprüft',
			'Original text (English)' => 'Originaltext (Englisch)',
			'Error'                => 'Fehler',
			'Warning'              => 'Warnung',
			'Validate again'       => 'Erneut prüfen',
			'Free includes %d invoices. With Pro you check them all.' => 'Free enthält %d Rechnungen. Mit Pro prüfst du alle.',
			'API key'              => 'API-Schlüssel',
		];
		foreach ( $exp as $id => $de ) {
			$this->assertSame( $de, $mo[ $id ] ?? null, $id );
		}
		$e4 = [
			'No VAT ID, no company name.'    => 'Keine USt-IdNr., kein Firmenname.',
			'Billing country is not Germany.' => 'Rechnungsland ist nicht Deutschland.',
			'Order total is 0 €.'            => 'Bestellwert 0 €.',
			'Not required'                   => 'Nicht erforderlich',
			'E-invoice required'             => 'E-Rechnung erforderlich',
		];
		foreach ( $e4 as $id => $de ) {
			$this->assertSame( $de, $mo[ $id ] ?? null, $id );
		}
		$this->assertSame( "%d Hinweis\0%d Hinweise", $mo["%d note\0%d notes"] ?? null );
		$this->assertSame( "%d Rechnung von Invoqk zurückgehalten\0%d Rechnungen von Invoqk zurückgehalten", $mo["%d invoice held by Invoqk\0%d invoices held by Invoqk"] ?? null );
	}

	public function test_mo_has_german_for_e5_strings(): void {
		$mo = $this->mo();
		foreach ( [
			'No legal or tax advice.' => 'Keine Rechts- oder Steuerberatung.',
			'Set up later'            => 'Später einrichten',
			'Start first check'       => 'Erste Prüfung starten',
			'Try Pro for 14 days'     => 'Pro 14 Tage testen',
			'Go to dashboard'         => 'Zum Dashboard',
			'No API key is set yet.'  => 'Es ist noch kein API-Schlüssel hinterlegt.',
			'Enter it in the settings below.' => 'Geben Sie ihn unten in den Einstellungen ein.',
		] as $id => $de ) {
			$this->assertSame( $de, $mo[ $id ] ?? null, $id );
		}
	}

	public function test_mo_has_german_for_e6_strings(): void {
		$mo = $this->mo();
		foreach ( [
			'Your invoice will follow in a separate email.' => 'Ihre Rechnung folgt in einer separaten E-Mail.',
			'Held by Invoqk'                                 => 'Von Invoqk zurückgehalten',
			'Fix and resend'                                 => 'Korrigieren und senden',
			'Send anyway…'                                   => 'Trotzdem senden…',
			'Check skipped – invoice sent without validation' => 'Prüfung übersprungen – Rechnung ohne Prüfung gesendet',
		] as $id => $de ) {
			$this->assertSame( $de, $mo[ $id ] ?? null, $id );
		}
	}
}
