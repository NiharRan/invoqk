<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Chain;
use PHPUnit\Framework\TestCase;

final class ChainTest extends TestCase {
	public function test_hash_is_stable_and_field_sensitive(): void {
		$r = [ 'prev_hash' => str_repeat( '0', 64 ), 'xml_hash' => 'x', 'pdf_hash' => 'p', 'order_id' => 5, 'version' => 1, 'invoice_number' => 'RE-1', 'invoice_date' => '2026-11-01', 'gross' => '119.00', 'vat' => '19.00', 'sent_at' => null ];
		$h = Chain::hash( $r );
		$this->assertSame( $h, Chain::hash( $r ) );
		$this->assertNotSame( $h, Chain::hash( [ 'gross' => '119.01' ] + $r ) );
		$this->assertSame( $h, Chain::hash( [ 'validation_status' => 'ok', 'deleted_at' => 5 ] + $r ) );
		$this->assertSame( $h, Chain::hash( [ 'gross' => 119 ] + $r ) );
	}

	private const GOLDEN = '8936e8a09e9c90c1d1f16c18ce7a13643c5fbf3b61d411c31085e8148666ad9d';

	private function invoiceRow(): array {
		return [ 'prev_hash' => str_repeat( '0', 64 ), 'xml_hash' => 'x', 'pdf_hash' => 'p', 'order_id' => 5, 'version' => 1, 'invoice_number' => 'RE-1', 'invoice_date' => '2026-11-01', 'gross' => '119.00', 'vat' => '19.00', 'sent_at' => null ];
	}

	/** Golden value captured from Chain::hash before kind existed: shipped rows must keep verifying. */
	public function test_invoice_row_hash_is_unchanged(): void {
		$this->assertSame( self::GOLDEN, Chain::hash( $this->invoiceRow() ) );
		$this->assertSame( self::GOLDEN, Chain::hash( [ 'kind' => 'invoice' ] + $this->invoiceRow() ) );
	}

	public function test_credit_note_hash_differs_from_same_row_as_invoice(): void {
		$this->assertNotSame( self::GOLDEN, Chain::hash( [ 'kind' => 'credit_note' ] + $this->invoiceRow() ) );
	}

	public function test_credit_note_hash_covers_parent_id(): void {
		$cn = [ 'kind' => 'credit_note' ] + $this->invoiceRow();
		$h5 = Chain::hash( [ 'parent_id' => 5 ] + $cn );
		$this->assertNotSame( $h5, Chain::hash( [ 'parent_id' => 6 ] + $cn ) );
		$this->assertSame( $h5, Chain::hash( [ 'parent_id' => 5 ] + $cn ) );
		$none = Chain::hash( $cn );
		$this->assertSame( $none, Chain::hash( [ 'parent_id' => null ] + $cn ) );
		$this->assertNotSame( $none, $h5 );
	}

	public function test_parent_id_does_not_change_invoice_hash(): void {
		$this->assertSame( self::GOLDEN, Chain::hash( [ 'parent_id' => 5 ] + $this->invoiceRow() ) );
		$this->assertSame( self::GOLDEN, Chain::hash( [ 'kind' => 'invoice', 'parent_id' => 5 ] + $this->invoiceRow() ) );
	}

	public function test_empty_or_null_kind_hashes_as_invoice(): void {
		$this->assertSame( self::GOLDEN, Chain::hash( [ 'kind' => '' ] + $this->invoiceRow() ) );
		$this->assertSame( self::GOLDEN, Chain::hash( [ 'kind' => null ] + $this->invoiceRow() ) );
	}
}
