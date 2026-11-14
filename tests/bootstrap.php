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
		public int $saves = 0;
		public int $metaSaves = 0;
		public function save(): int { ++$this->saves; return $this->id; }
		public function save_meta_data(): void { ++$this->metaSaves; }
		public string $status = 'processing';
		public function get_status(): string { return $this->status; }
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
		return new class {
			public function error( $m, $c = [] ): void { $GLOBALS['invoqk_log'][] = (string) $m; }
			public function info( $m, $c = [] ): void { $GLOBALS['invoqk_log'][] = (string) $m; }
		};
	}
}

// --- WooCommerce / Action Scheduler stand-ins for unit tests ---
$GLOBALS['invoqk_orders']  = [];
$GLOBALS['invoqk_as']      = [];
$GLOBALS['invoqk_actions'] = [];
$GLOBALS['invoqk_files']   = [];
$GLOBALS['invoqk_orders_args'] = null;
$GLOBALS['invoqk_orders_result'] = [];

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) { return $GLOBALS['invoqk_orders'][ $id ] ?? false; }
	function wc_get_orders( array $args ) {
		$GLOBALS['invoqk_orders_args'] = $args;
		return ( $args['paged'] ?? 1 ) > 1 ? [] : $GLOBALS['invoqk_orders_result'];
	}
	function get_attached_file( int $id ) { return $GLOBALS['invoqk_files'][ $id ] ?? false; }
	function add_action( string $tag, callable $cb, int $prio = 10, int $args = 1 ): void { $GLOBALS['invoqk_actions'][ $tag ][] = [ $cb, $args ]; }
	function do_action( string $tag, ...$args ): void {
		foreach ( $GLOBALS['invoqk_actions'][ $tag ] ?? [] as [ $cb, $n ] ) { $cb( ...array_slice( $args, 0, $n ) ); }
	}
	function as_has_scheduled_action( string $hook, array $args = [], string $group = '' ): bool {
		foreach ( $GLOBALS['invoqk_as'] as $a ) {
			if ( $a['hook'] === $hook && ( $args === [] || $a['args'] === $args ) ) { return true; }
		}
		return false;
	}
	function as_enqueue_async_action( string $hook, array $args = [], string $group = '' ) { $GLOBALS['invoqk_as'][] = compact( 'hook', 'args', 'group' ) + [ 'when' => 0, 'every' => 0 ]; return 1; }
	function as_schedule_single_action( int $when, string $hook, array $args = [], string $group = '' ) { $GLOBALS['invoqk_as'][] = compact( 'hook', 'args', 'group', 'when' ) + [ 'every' => 0 ]; return 1; }
	function as_schedule_recurring_action( int $when, int $every, string $hook, array $args = [], string $group = '' ) { $GLOBALS['invoqk_as'][] = compact( 'hook', 'args', 'group', 'when', 'every' ); return 1; }
	function as_unschedule_all_actions( string $hook = '', array $args = [], string $group = '' ): void {
		$GLOBALS['invoqk_as'] = array_values( array_filter( $GLOBALS['invoqk_as'], fn( $a ) => $group !== '' && $a['group'] !== $group ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ): string { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_html__( string $t, string $d = 'default' ): string { return esc_html( $t ); }
}

if ( ! defined( 'INVOQK_VERSION' ) ) { define( 'INVOQK_VERSION', '0.1.0' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
$GLOBALS['invoqk_options'] = [];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $k, $d = false ) { return $GLOBALS['invoqk_options'][ $k ] ?? $d; }
	function update_option( string $k, $v ): bool { $GLOBALS['invoqk_options'][ $k ] = $v; return true; }
	function delete_option( string $k ): bool { unset( $GLOBALS['invoqk_options'][ $k ] ); return true; }
}

if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function wp_remote_post( string $url, array $args = [] ) { return new WP_Error(); }
	function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? ''; }
	function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
	function wp_remote_retrieve_header( $r, string $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; }
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string { return '/wp-admin/' . $path; }
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $f, ?int $ts = null ): string { return gmdate( $f, $ts ?? 0 ); }
	function get_locale(): string { return 'de_DE'; }
	function esc_attr( $s ): string { return esc_html( $s ); }
	function esc_url( $s ): string { return esc_html( $s ); }
	function _n( string $s, string $p, int $n, string $d = 'default' ): string { return 1 === $n ? $s : $p; }
	function current_user_can( string $c ): bool { return true; }
	function check_admin_referer( $a ) { return true; }
	function wp_safe_redirect( string $u ): bool { return true; }
	function wp_get_referer() { return false; }
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { return '<input type="hidden" name="' . $n . '" value="nonce">'; }
}
