<?php
use Invoqk\Validation\FixMap;
use PHPUnit\Framework\TestCase;

final class FixMapTest extends TestCase {
	public function test_unknown_or_null_key_returns_null(): void {
		$o = new WC_Order( 7 );
		$this->assertNull( FixMap::link( null, $o ) );
		$this->assertNull( FixMap::link( 'nope', $o ) );
	}

	public function test_every_fixture_key_has_an_entry_and_vice_versa(): void {
		$keys = json_decode( file_get_contents( __DIR__ . '/fixtures/e1-s9-fix-keys.json' ), true );
		$a = array_keys( FixMap::KEYS ); sort( $a ); sort( $keys );
		$this->assertSame( $keys, $a );
	}

	public function test_link_urls_are_admin_urls_and_labels_non_empty(): void {
		foreach ( array_keys( FixMap::KEYS ) as $k ) {
			$l = FixMap::link( $k, new WC_Order( 7 ) );
			$this->assertNotEmpty( $l['label'], $k );
			$this->assertMatchesRegularExpression( '#wp-admin/|/order/7#', $l['url'], $k );
		}
	}

	public function test_source_plugin_entry_uses_order_source(): void {
		$o = new WC_Order( 7 );
		$o->meta['_invoqk_source'] = 'wp_overnight';
		$a = FixMap::link( 'seller_vat_id', $o )['url'];
		$o->meta['_invoqk_source'] = 'germanized_pro';
		$b = FixMap::link( 'seller_vat_id', $o )['url'];
		$this->assertNotSame( $a, $b );
		$o->meta['_invoqk_source'] = 'unknown_plugin';
		$this->assertStringContainsString( 'wc-settings', FixMap::link( 'seller_vat_id', $o )['url'] );
	}
}
