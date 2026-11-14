<?php
// Pure unit tests run without WordPress; define the few WP functions they touch.
require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
