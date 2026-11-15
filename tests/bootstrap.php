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
		public array $refunds = [];
		public function get_refunds(): array { return $this->refunds; }
		public int $saves = 0;
		public int $metaSaves = 0;
		public function save(): int { ++$this->saves; return $this->id; }
		public function save_meta_data(): void { ++$this->metaSaves; }
		public int $created = 0;
		public function get_date_created(): ?DateTimeImmutable { return $this->created ? new DateTimeImmutable( '@' . $this->created ) : null; }
		public string $status = 'processing';
		public function get_status(): string { return $this->status; }
		// Defaults describe a domestic B2B order over the threshold, so older tests keep "required" orders.
		public string $country = 'DE';
		public string $shipCountry = '';
		public string $company = 'Acme GmbH';
		public string $total = '500.00';
		public array $notes = [];
		public function get_billing_country(): string { return $this->country; }
		public function get_shipping_country(): string { return $this->shipCountry; }
		public function get_billing_company(): string { return $this->company; }
		public function get_total(): string { return $this->total; }
		public string $tax = '0.00';
		public string $currency = 'EUR';
		public string $first = '';
		public string $last = '';
		public function get_total_tax(): string { return $this->tax; }
		public function get_currency(): string { return $this->currency; }
		public function get_billing_first_name(): string { return $this->first; }
		public function get_billing_last_name(): string { return $this->last; }
		public function get_edit_order_url(): string { return admin_url( "admin.php?page=wc-orders&action=edit&id={$this->id}" ); }
		public function delete_meta_data( string $k ): void { unset( $this->meta[ $k ] ); }
		public function add_order_note( string $n ): int { $this->notes[] = $n; return count( $this->notes ); }
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

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
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
	function remove_action( string $tag, callable $cb, int $prio = 10 ): bool {
		$GLOBALS['invoqk_actions'][ $tag ] = array_values( array_filter( $GLOBALS['invoqk_actions'][ $tag ] ?? [], fn( $a ) => $a[0] !== $cb ) );
		return true;
	}
	function as_has_scheduled_action( string $hook, array $args = [], string $group = '' ): bool {
		foreach ( $GLOBALS['invoqk_as'] as $a ) {
			if ( $a['hook'] === $hook && ( $args === [] || $a['args'] === $args ) ) { return true; }
		}
		return false;
	}
	function as_enqueue_async_action( string $hook, array $args = [], string $group = '' ) { $GLOBALS['invoqk_as_opt'][] = $GLOBALS['invoqk_options']['invoqk_first_report'] ?? null; $GLOBALS['invoqk_as'][] = compact( 'hook', 'args', 'group' ) + [ 'when' => 0, 'every' => 0 ]; return 1; }
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
if ( ! defined( 'INVOQK_FILE' ) ) { define( 'INVOQK_FILE', dirname( __DIR__ ) . '/invoqk.php' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
$GLOBALS['invoqk_options'] = [];
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $k, $d = false ) { return $GLOBALS['invoqk_options'][ $k ] ?? $d; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['invoqk_options'][ $k ] = $v; return true; }
	function add_option( string $k, $v = '', $dep = '', $autoload = null ): bool { if ( isset( $GLOBALS['invoqk_options'][ $k ] ) ) { return false; } $GLOBALS['invoqk_options'][ $k ] = $v; return true; }
	function delete_option( string $k ): bool { unset( $GLOBALS['invoqk_options'][ $k ] ); return true; }
}

if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function wp_remote_post( string $url, array $args = [] ) { $GLOBALS['invoqk_http'][] = $url; return new WP_Error(); }
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
	function get_transient( string $k ) { return $GLOBALS['invoqk_transients'][ $k ] ?? false; }
	function set_transient( string $k, $v, int $ttl = 0 ): bool { $GLOBALS['invoqk_transients'][ $k ] = $v; return true; }
	function delete_transient( string $k ): bool { unset( $GLOBALS['invoqk_transients'][ $k ] ); return true; }
	function esc_attr( $s ): string { return esc_html( $s ); }
	function esc_url( $s ): string { return esc_html( $s ); }
	function _n( string $s, string $p, int $n, string $d = 'default' ): string { return 1 === $n ? $s : $p; }
	function absint( $v ): int { return abs( (int) $v ); }
	function wp_unslash( $v ) { return $v; }
	function current_user_can( string $c ): bool { return $GLOBALS['invoqk_can'] ?? true; }
	function check_admin_referer( $a, $q = '_wpnonce' ) { $GLOBALS['invoqk_nonce_args'][ $a ] = $q; return $GLOBALS['invoqk_nonce_ok'] ?? true; }
	function wp_safe_redirect( string $u ): bool { return true; }
	function wp_get_referer() { return false; }
	function wc_price( $n ): string { return '<span>' . number_format( (float) $n, 2 ) . '&euro;</span>'; }
	function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); }
	function wp_nonce_url( string $u, $a = -1 ): string { return $u . '&_wpnonce=nonce-' . $a; }
	function settings_errors(): void { $GLOBALS['invoqk_settings_errors'] = ( $GLOBALS['invoqk_settings_errors'] ?? 0 ) + 1; }
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { return '<input type="hidden" name="' . $n . '" value="nonce" data-action="' . $a . '">'; }
}

// --- Wizard / admin endpoint stand-ins ---
if ( ! class_exists( 'InvoqkTestDie' ) ) {
	class InvoqkTestDie extends Exception {}
	class InvoqkTestJson extends Exception { public function __construct( public $payload, int $code = 200 ) { parent::__construct( 'json', $code ); } }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $m = '', $t = '', $args = [] ) { throw new InvoqkTestDie( (string) $m, (int) ( $args['response'] ?? 500 ) ); }
	function wp_send_json( $data, $code = 200 ) { throw new InvoqkTestJson( $data, (int) $code ); }
	function check_ajax_referer( $a = -1, $q = false, $die = true ) { return $GLOBALS['invoqk_nonce_ok'] ?? true; }
	function wp_verify_nonce( $n, $a = -1 ) { return ( $GLOBALS['invoqk_nonce_ok'] ?? true ) ? 1 : false; }
	function wp_create_nonce( $a = -1 ): string { return 'nonce-' . $a; }
	function is_network_admin(): bool { return $GLOBALS['invoqk_network'] ?? false; }
	function wp_doing_ajax(): bool { return $GLOBALS['invoqk_ajax'] ?? false; }
	function plugins_url( string $p = '', string $f = '' ): string { return '/plugins/' . $p; }
	function wp_enqueue_script( string $h, string $src = '', array $deps = [], $ver = false, $foot = false ): void { $GLOBALS['invoqk_scripts'][ $h ] = $src; }
	function wp_enqueue_style( string $h, string $src = '', array $deps = [], $ver = false ): void { $GLOBALS['invoqk_styles'][ $h ] = $src; }
	function wp_localize_script( string $h, string $n, array $d ): bool { $GLOBALS['invoqk_localized'][ $h ] = [ $n, $d ]; return true; }
	function sanitize_text_field( $s ): string { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $s ) ); }
	function get_userdata( $id ) { return (object) [ 'ID' => $id, 'display_name' => $GLOBALS['invoqk_user_name'] ?? 'T', 'user_email' => $GLOBALS['invoqk_user_email'] ?? '' ]; }
	function esc_js( $s ): string { return str_replace( "'", "\\'", (string) $s ); }
	function wp_get_current_user() { return (object) [ 'ID' => 1, 'display_name' => 'T' ]; }
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $n ): string { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $n ); }
	function nocache_headers(): void {}
}

// --- WP Overnight stand-ins for the guard ---
if ( ! function_exists( 'wpo_ips_edi_is_available' ) ) {
	function wpo_ips_edi_is_available(): bool { return $GLOBALS['invoqk_edi'] ?? true; }
	function wpo_ips_edi_send_attachments(): bool { return $GLOBALS['invoqk_edi_send'] ?? true; }
	function WC() {
		return new class {
			public function mailer() {
				return (object) [ 'emails' => [ 'WC_Email_Customer_Invoice' => new class {
						public $id = 'customer_invoice';
						// Like WC_Email::send: a mailer returning false stands for wp_mail failing.
						public function trigger( $id ) { do_action( 'woocommerce_email_sent', false !== ( $GLOBALS['invoqk_mailer'] ?? static fn() => null )( $id ), $this->id, $this ); }
					} ] ];
			}
		};
	}
	function WPO_WCPDF() {
		return new class {
			public $main;
			public function __construct() {
				$this->main = new class {
					public function get_documents_for_email( $emailId, $order ) { return $GLOBALS['invoqk_wpo_email_docs'][ $emailId ] ?? []; }
				};
			}
		};
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $d ): bool { return is_dir( $d ) || mkdir( $d, 0777, true ); }
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string { return 'https://Shop.Example' . $path; }
}
