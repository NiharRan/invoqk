<?php
use Invoqk\Admin\OrderColumn;
use Invoqk\Detection\Result;
use PHPUnit\Framework\TestCase;

final class OrderColumnTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [];
	}

	public function test_rendering_makes_no_adapter_calls(): void {
		add_filter( 'invoqk_source_adapters', function () {
			$this->fail( 'adapters must not be loaded while rendering' );
		} );
		$o = new WC_Order();
		$o->update_meta_data( Result::SOURCE, 'germanized_pro' );
		$o->update_meta_data( '_invoqk_label', 'Germanized Pro' );
		$this->assertStringContainsString( 'Germanized Pro', OrderColumn::render( $o ) );
	}

	public function test_missing_shows_flag_text(): void {
		$o = new WC_Order();
		$o->update_meta_data( Result::SOURCE, 'none' );
		$this->assertStringContainsString( 'No e-invoice', OrderColumn::render( $o ) );
	}

	public function test_pending_shows_dash(): void {
		$this->assertSame( '&ndash;', OrderColumn::render( new WC_Order() ) );
	}

	public function test_label_is_escaped(): void {
		$o = new WC_Order();
		$o->update_meta_data( Result::SOURCE, 'x' );
		$o->update_meta_data( '_invoqk_label', '<script>' );
		$this->assertStringNotContainsString( '<script>', OrderColumn::render( $o ) );
	}

	public function test_cell_shows_label_and_badge_for_failed_and_nothing_extra_when_source_none(): void {
		$o = new WC_Order();
		$o->update_meta_data( Result::SOURCE, 'germanized_pro' );
		$o->update_meta_data( '_invoqk_label', 'Germanized Pro' );
		\Invoqk\Validation\Record::put( $o, [ 'status' => 'failed' ] );
		$h = OrderColumn::render( $o );
		$this->assertStringContainsString( 'Germanized Pro', $h );
		$this->assertStringContainsString( 'status-failed', $h );
		$n = new WC_Order();
		$n->update_meta_data( Result::SOURCE, 'none' );
		\Invoqk\Validation\Record::put( $n, [ 'status' => 'failed' ] );
		$this->assertStringNotContainsString( 'Invalid', OrderColumn::render( $n ) );
	}
}
