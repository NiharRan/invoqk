<?php
use Invoqk\Sources\InvoiceFile;
use Invoqk\Sources\Registry;
use Invoqk\Tests\Sources\FakeAdapter;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase {
	private function file( int $id = 5 ): InvoiceFile {
		return new InvoiceFile( $id, 'application/xml', 'ubl' );
	}

	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [];
	}

	public function test_first_available_adapter_with_file_wins(): void {
		$a = new FakeAdapter( 'a', true, $this->file( 1 ) );
		$b = new FakeAdapter( 'b', true, $this->file( 2 ) );
		$hit = ( new Registry( $a, $b ) )->detect( new WC_Order() );
		$this->assertSame( 'a', $hit['adapter']->id() );
		$this->assertSame( 1, $hit['file']->reference );
		$this->assertSame( 0, $b->findCalls );
	}

	public function test_unavailable_adapter_is_skipped_and_find_not_called(): void {
		$a = new FakeAdapter( 'a', false, $this->file( 1 ) );
		$b = new FakeAdapter( 'b', true, $this->file( 2 ) );
		$hit = ( new Registry( $a, $b ) )->detect( new WC_Order() );
		$this->assertSame( 'b', $hit['adapter']->id() );
		$this->assertSame( 0, $a->findCalls );
	}

	public function test_returns_null_when_no_adapter_finds_file(): void {
		$this->assertNull( ( new Registry( new FakeAdapter( 'a' ) ) )->detect( new WC_Order() ) );
	}

	public function test_adapter_throwing_is_skipped_not_fatal(): void {
		$a = new FakeAdapter( 'a', true, null, true );
		$b = new FakeAdapter( 'b', true, $this->file( 2 ) );
		$hit = ( new Registry( $a, $b ) )->detect( new WC_Order() );
		$this->assertSame( 'b', $hit['adapter']->id() );
	}

	public function test_filter_can_add_adapter(): void {
		add_filter( 'invoqk_source_adapters', fn( array $list ) => [ ...$list, new FakeAdapter( 'extra' ) ] );
		$ids = array_map( fn( $a ) => $a->id(), Registry::default()->all() );
		$this->assertContains( 'extra', $ids );
	}

	public function test_get_by_id(): void {
		$r = new Registry( new FakeAdapter( 'a' ), new FakeAdapter( 'b' ) );
		$this->assertSame( 'b', $r->get( 'b' )->id() );
		$this->assertNull( $r->get( 'zzz' ) );
	}
}
