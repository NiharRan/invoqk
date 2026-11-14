<?php
// Pure unit tests run without WordPress; define the few WP functions they touch.
require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public array $meta = [];
		public function __construct( public int $id = 1 ) {}
		public function get_id(): int { return $this->id; }
		public function get_meta( string $k ) { return $this->meta[ $k ] ?? ''; }
		public function update_meta_data( string $k, $v ): void { $this->meta[ $k ] = $v; }
		public function save(): int { return $this->id; }
	}
}

$GLOBALS['invoqk_filters'] = [];
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $cb ): void { $GLOBALS['invoqk_filters'][ $tag ][] = $cb; }
	function apply_filters( string $tag, $value, ...$args ) {
		foreach ( $GLOBALS['invoqk_filters'][ $tag ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function wc_get_logger() {
		return new class { public function error( $m, $c = [] ): void {} };
	}
}
