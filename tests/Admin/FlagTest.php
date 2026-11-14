<?php
use Invoqk\Admin\Flag;
use Invoqk\Detection\Result;
use PHPUnit\Framework\TestCase;

final class FlagTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_filters'] = [];
	}

	private function order( ?string $source ): WC_Order {
		$o = new WC_Order();
		if ( null !== $source ) {
			$o->update_meta_data( Result::SOURCE, $source );
			$o->update_meta_data( '_invoqk_label', 'Germanized Pro' );
		}
		return $o;
	}

	public function test_found_returns_source(): void {
		$this->assertSame( 'source', Flag::forOrder( $this->order( 'germanized_pro' ) ) );
	}

	public function test_none_returns_missing(): void {
		$this->assertSame( 'missing', Flag::forOrder( $this->order( 'none' ) ) );
	}

	public function test_none_with_filter_false_returns_not_required(): void {
		add_filter( 'invoqk_order_requires_einvoice', fn() => false );
		$this->assertSame( 'not_required', Flag::forOrder( $this->order( 'none' ) ) );
	}

	public function test_unchecked_returns_pending(): void {
		$this->assertSame( 'pending', Flag::forOrder( $this->order( null ) ) );
	}
}
