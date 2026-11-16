<?php
namespace Invoqk\Fallback;

/** An order the fallback refuses to turn into an e-invoice; $reason is a fixed code, message() the German text for the order box. */
final class Unsupported extends \RuntimeException {
	public const MESSAGES = [
		'country'       => 'Die Rechnungsadresse liegt nicht in Deutschland. Der Fallback erstellt nur Inlandsrechnungen.',
		'currency'      => 'Die Bestellung ist nicht in Euro. Der Fallback erstellt nur Rechnungen in EUR.',
		'tax_rate'      => 'Die Bestellung enthält einen Steuersatz außer 19 % oder 7 %. Bitte ein Rechnungs-Plugin verwenden.',
		'refund'        => 'Die Bestellung hat eine Erstattung. Bitte die Rechnung mit einem Rechnungs-Plugin erstellen.',
		'total'         => 'Der Bestellbetrag ist null oder negativ.',
		'totals'        => 'Die Positionsbeträge ergeben nicht centgenau die Bestellsumme.',
		'buyer_address' => 'Die Rechnungsadresse ist unvollständig (Name, Straße, PLZ und Ort werden benötigt).',
		'buyer_email'   => 'Für eine XRechnung fehlt die E-Mail-Adresse des Kunden.',
		'line_name'     => 'Eine Position hat keine Bezeichnung.',
		'settings'      => 'Die Bestellung enthält Positionen mit 0 % Steuer. Das geht nur, wenn in den Einstellungen „Nullsatz“ aktiviert ist.',
	];

	public function __construct( public readonly string $reason ) {
		parent::__construct( $reason );
	}

	public function message(): string {
		return self::MESSAGES[ $this->reason ] ?? 'Diese Bestellung wird vom Fallback nicht unterstützt.';
	}
}
