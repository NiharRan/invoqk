<?php
namespace Invoqk\Tests\CreditNote;

use Invoqk\Archive\Archive;
use Invoqk\Archive\Chain;
use Invoqk\CreditNote\Box;
use Invoqk\CreditNote\Store;
use Invoqk\Tests\Archive\MemoryIndex;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase {
	private MemoryIndex $ix;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [ 'invoqk_is_pro' => [ fn() => true ] ];
		$this->ix                  = Archive::$index = new MemoryIndex();
	}

	protected function tearDown(): void {
		$GLOBALS['invoqk_filters'] = [];
		Archive::reset();
	}

	private function entry( array $o = [] ): array {
		return $o + [ 'source' => 'a', 'file' => [], 'detectedAt' => 1, 'validation' => [ 'status' => 'passed' ], 'archived' => null ];
	}

	public function test_lists_credit_note_with_original_invoice_number(): void {
		$o   = new \WC_Order( 7 );
		$inv = $this->ix->append( [ 'order_id' => 7, 'version' => 1, 'invoice_number' => 'RE-7' ], [ Chain::class, 'hash' ] );
		$cn  = $this->ix->append( [ 'order_id' => 11, 'version' => 1, 'kind' => 'credit_note', 'parent_id' => $inv['id'], 'invoice_number' => 'GS-<1>' ], [ Chain::class, 'hash' ] );
		Store::put( $o, 11, $this->entry( [ 'archived' => $cn['id'] ] ) );
		$h = Box::html( $o );
		$this->assertStringContainsString( '#11', $h );
		$this->assertStringContainsString( 'GS-&lt;1&gt;', $h );
		$this->assertStringContainsString( 'RE-7', $h );
		$this->assertStringContainsString( 'Valid', $h );
	}

	public function test_empty_without_credit_notes(): void {
		$this->assertSame( '', Box::html( new \WC_Order( 7 ) ) );
	}

	public function test_empty_when_not_pro(): void {
		$o = new \WC_Order( 7 );
		Store::put( $o, 11, $this->entry() );
		$GLOBALS['invoqk_filters'] = [];
		$this->assertSame( '', Box::html( $o ) );
	}
}
