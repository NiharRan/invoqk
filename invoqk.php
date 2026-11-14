<?php
/**
 * Plugin Name:       Invoqk E-Rechnung QA
 * Description:       Finds and checks the e-invoice of every WooCommerce order. Not legal or tax advice.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Text Domain:       invoqk
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'INVOQK_VERSION', '0.1.0' );
define( 'INVOQK_FILE', __FILE__ );

require_once __DIR__ . '/src/Requirements.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', INVOQK_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', INVOQK_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		global $wp_version;
		$wc       = defined( 'WC_VERSION' ) ? WC_VERSION : null;
		$problems = \Invoqk\Requirements::problems( $wp_version, PHP_VERSION, $wc );

		if ( $problems ) {
			$notice = static function () use ( $problems ) {
				foreach ( $problems as $p ) {
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $p ) );
				}
			};
			add_action( 'admin_notices', $notice );
			add_action( 'network_admin_notices', $notice );
			return;
		}

		// Own PSR-4 loader so the shipped zip needs no vendor/ directory.
		spl_autoload_register(
			static function ( string $class ) {
				if ( str_starts_with( $class, 'Invoqk\\' ) ) {
					$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, 7 ) ) . '.php';
					if ( is_readable( $file ) ) {
						require_once $file;
					}
				}
			}
		);
		\Invoqk\Plugin::boot();
	},
	20
);

register_deactivation_hook( __FILE__, static function () {
	if ( class_exists( \Invoqk\Jobs\Scheduler::class ) ) {
		\Invoqk\Jobs\Scheduler::deactivate();
	}
} );
