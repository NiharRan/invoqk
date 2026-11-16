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

	public function test_credit_notes_use_the_stored_source_first(): void {
		$a = new FakeAdapter( 'a', true, null, false, [ 7 => $this->file( 7 ) ] );
		$b = new FakeAdapter( 'b', true, null, false, [ 8 => $this->file( 8 ) ] );
		$order = new WC_Order();
		$order->meta['_invoqk_source'] = 'b';
		$hit = ( new Registry( $a, $b ) )->creditNotes( $order );
		$this->assertSame( 'b', $hit['adapter']->id() );
		$this->assertSame( [ 8 ], array_keys( $hit['files'] ) );
		$this->assertSame( 'a', ( new Registry( $a, $b ) )->creditNotes( new WC_Order() )['adapter']->id() );
	}

	public function test_credit_notes_skip_a_throwing_adapter(): void {
		$a = new FakeAdapter( 'a', true, null, true );
		$b = new FakeAdapter( 'b', true, null, false, [ 8 => $this->file( 8 ) ] );
		$GLOBALS['invoqk_log'] = [];
		$this->assertSame( 'b', ( new Registry( $a, $b ) )->creditNotes( new WC_Order() )['adapter']->id() );
		$this->assertStringContainsString( 'RuntimeException', implode( ' ', $GLOBALS['invoqk_log'] ) );
		$this->assertStringNotContainsString( 'boom', implode( ' ', $GLOBALS['invoqk_log'] ), 'class only, never the message' );
	}

	public function test_credit_notes_returns_null_when_none_found(): void {
		$this->assertNull( ( new Registry( new FakeAdapter( 'a' ) ) )->creditNotes( new WC_Order() ) );
	}

	public function test_plugin_adapter_beats_fallback(): void {
		$GLOBALS['invoqk_options']['invoqk_fallback_used'] = 1;
		$o = new WC_Order( 41 );
		$GLOBALS['invoqk_uploads'] = sys_get_temp_dir() . '/invoqk-reg-' . bin2hex( random_bytes( 4 ) );
		\Invoqk\Fallback\Store::save( $o, '<x/>', 'RE-1', 'xrechnung' );
		$hit = ( new Registry( new FakeAdapter( 'plugin', true, $this->file( 9 ) ), new \Invoqk\Sources\FallbackAdapter() ) )->detect( $o );
		$this->assertSame( 'plugin', $hit['adapter']->id() );
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['invoqk_uploads'] ) );
	}
}
