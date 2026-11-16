<?php
use Invoqk\Fallback\Offer;
use Invoqk\Fallback\Store;
use Invoqk\Sources\FallbackAdapter;
use PHPUnit\Framework\TestCase;

final class OfferTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_filters'] = [];
		$GLOBALS['invoqk_orders']  = [];
		$GLOBALS['invoqk_uploads'] = sys_get_temp_dir() . '/invoqk-offer-' . bin2hex( random_bytes( 4 ) );
		add_filter( 'invoqk_is_pro', fn() => true );
		add_filter( 'invoqk_source_adapters', fn() => [ new FallbackAdapter() ] ); // other suites leave a wcpdf stub behind
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $GLOBALS['invoqk_uploads'] ) );
	}

	public static function cases(): array {
		return [
			'ok'             => [ fn( $o ) => null, true ],
			'free'           => [ fn( $o ) => add_filter( 'invoqk_is_pro', fn() => false ), false ],
			'not required'   => [ fn( $o ) => $o->country = 'FR', false ],
			'status pending' => [ fn( $o ) => $o->status = 'pending', false ],
			'completed'      => [ fn( $o ) => $o->status = 'completed', true ],
			'plugin source'  => [ fn( $o ) => $o->update_meta_data( '_invoqk_source', 'germanized_pro' ), false ],
			'already made'   => [ fn( $o ) => Store::save( $o, '<x/>', 'RE-1', 'xrechnung' ), false ],
		];
	}

	/** @dataProvider cases */
	public function test_matrix( callable $setup, bool $expected ): void {
		$o = new WC_Order( 5 );
		$GLOBALS['invoqk_orders'][5] = $o;
		$setup( $o );
		$this->assertSame( $expected, Offer::available( $o ) );
	}
}
