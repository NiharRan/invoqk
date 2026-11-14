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

	public function test_not_required_order_is_not_flagged_even_with_a_file(): void {
		$o          = $this->order( 'germanized_pro' );
		$o->company = '';
		$this->assertSame( 'not_required', Flag::forOrder( $o ) );
	}

	public function test_override_to_required_flags_missing_invoice(): void {
		$o          = $this->order( 'none' );
		$o->company = '';
		$o->update_meta_data( \Invoqk\Classification\Classifier::OVERRIDE, [ 'required' => true, 'by' => 1, 'at' => 1 ] );
		$this->assertSame( 'missing', Flag::forOrder( $o ) );
	}

	public function test_filter_gets_the_classified_value_first(): void {
		$seen = null;
		add_filter( 'invoqk_order_requires_einvoice', function ( $v ) use ( &$seen ) { $seen = $v; return $v; } );
		$o          = $this->order( 'none' );
		$o->company = '';
		Flag::forOrder( $o );
		$this->assertFalse( $seen );
	}
}
