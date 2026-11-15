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
}
