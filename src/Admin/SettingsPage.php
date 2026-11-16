<?php
namespace Invoqk\Admin;

use Invoqk\Archive\Actions;
use Invoqk\B2g\Field;
use Invoqk\Fallback\Seller;
use Invoqk\Onboarding\State;
use Invoqk\Onboarding\Wizard;
use Invoqk\Guard\Hold;
use Invoqk\Validation\Client;
use Invoqk\Validation\Limit;

final class SettingsPage {
	private const MASK = '••••••••';

	public static function boot(): void {
		add_action( 'admin_menu', static function () {
			add_submenu_page( 'woocommerce', 'Invoqk', 'Invoqk', 'manage_woocommerce', 'invoqk', [ self::class, 'render' ] );
		} );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_init', static function () {
			register_setting( 'invoqk', 'invoqk_api_key', [ 'sanitize_callback' => 'sanitize_text_field', 'autoload' => false ] );
			register_setting( 'invoqk', Actions::RETENTION_OPTION, [ 'sanitize_callback' => [ Actions::class, 'sanitizeYears' ] ] );
			add_option( Actions::RETENTION_OPTION, 8, '', false ); // register_setting has no autoload argument; no-op when it exists
			if ( Limit::isPro() ) {
				register_setting( 'invoqk', 'invoqk_guard_mode', [ 'sanitize_callback' => [ Hold::class, 'sanitizeMode' ], 'autoload' => false ] );
				register_setting( 'invoqk', Field::OPTION, [ 'sanitize_callback' => [ Field::class, 'sanitizeMode' ] ] ); // autoloaded: read on every front-end request
				register_setting( 'invoqk', Seller::OPTION, [ 'sanitize_callback' => [ Seller::class, 'sanitize' ], 'autoload' => false ] );
			}
		} );
		// options.php checks the capability via this filter; the key is never echoed, so an empty or masked submit keeps the stored one.
		add_filter( 'option_page_capability_invoqk', static fn() => 'manage_woocommerce' );
		$stamp = static fn() => update_option( 'invoqk_key_changed_at', time() ); // lets the sweep retry 401/403 records
		add_action( 'update_option_invoqk_api_key', $stamp );
		add_action( 'add_option_invoqk_api_key', $stamp );
		add_filter( 'pre_update_option_invoqk_api_key', [ self::class, 'keep' ], 10, 2 );
	}

	/** shortcut: the key cannot be cleared from the form, only replaced; delete the option to remove it. */
	public static function keep( $new, $old ) {
		return ( '' === $new || self::MASK === $new ) ? $old : $new;
	}

	public static function enqueue( string $hook ): void {
		if ( in_array( $hook, [ 'woocommerce_page_invoqk', 'woocommerce_page_invoqk-archive' ], true ) ) {
			wp_enqueue_style( 'invoqk-admin', plugins_url( 'assets/invoqk-admin.css', INVOQK_FILE ), [], INVOQK_VERSION . '.' . (int) @filemtime( dirname( INVOQK_FILE ) . '/assets/invoqk-admin.css' ) ); // file time busts caches when the CSS changes
		}
	}

	/** One selectable option card around a radio. All arguments are plain text and get escaped. */
	public static function choice( string $name, string $value, string $title, string $desc, bool $checked ): string {
		return '<label class="invoqk-choice"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $checked ? ' checked' : '' ) . '>'
			. '<span><span class="invoqk-choice__title">' . esc_html( $title ) . '</span><span class="invoqk-choice__desc">' . esc_html( $desc ) . '</span></span></label>';
	}

	/** A titled card. $body is already-escaped HTML. */
	public static function card( string $title, string $hint, string $body ): string {
		return '<section class="invoqk-card"><div class="invoqk-card__head"><h2 class="invoqk-card__title">' . esc_html( $title ) . '</h2>'
			. ( '' !== $hint ? '<p class="invoqk-card__hint">' . esc_html( $hint ) . '</p>' : '' ) . '</div>' . $body . '</section>';
	}

	private static function field( string $id, string $label, string $input, string $help = '' ): string {
		return '<div class="invoqk-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' . $input
			. ( '' !== $help ? '<p class="invoqk-help">' . esc_html( $help ) . '</p>' : '' ) . '</div>';
	}

	private static function choices( string $name, array $options, string $current ): string {
		$h = '<div class="invoqk-choices">';
		foreach ( $options as $v => [ $title, $desc ] ) {
			$h .= self::choice( $name, (string) $v, $title, $desc, (string) $v === $current );
		}
		return $h . '</div>';
	}

	private static function fallbackSection(): string {
		$s     = Seller::get();
		$sug   = Seller::suggest( $s );
		$s     = array_replace( $s, $sug ); // keeps field order; progress counts what the form shows
		$o     = Seller::OPTION;
		$total = count( Seller::needed() );
		$done  = $total - count( Seller::missing( $s ) );
		$h     = '<div class="invoqk-progress' . ( $done >= $total ? ' is-done' : '' ) . '"><div class="invoqk-progress__label">'
			/* translators: 1: filled required details, 2: total required details */
			. esc_html( sprintf( __( '%1$d of %2$d required details filled in', 'invoqk' ), $done, $total ) ) . '</div>'
			. '<div class="invoqk-progress__track"><span class="invoqk-progress__fill" style="width:' . (int) round( 100 * $done / $total ) . '%"></span></div></div>';
		if ( $sug ) {
			$h .= '<p class="invoqk-help">' . esc_html__( 'Empty fields were prefilled from WooCommerce and WordPress. Check them, then save.', 'invoqk' ) . '</p>';
		}
		$h    .= '<div class="invoqk-grid2">';
		$auto  = [ 'name' => 'organization', 'street' => 'address-line1', 'postcode' => 'postal-code', 'city' => 'address-level2', 'contact_phone' => 'tel', 'contact_email' => 'email' ];
		foreach ( array_diff( array_keys( $s ), [ 'profile', 'nullsatz' ] ) as $k ) {
			$id    = $o . '_' . $k;
			$num   = 'due_days' === $k;
			$input = '<input type="' . ( $num ? 'number' : 'text' ) . '" id="' . esc_attr( $id ) . '"' . ( $num ? ' min="0" max="365" inputmode="numeric"' : '' )
				. ( isset( $auto[ $k ] ) ? ' autocomplete="' . $auto[ $k ] . '"' : '' )
				. ' name="' . esc_attr( $o . '[' . $k . ']' ) . '" value="' . esc_attr( (string) $s[ $k ] ) . '">';
			$h    .= self::field( $id, Seller::label( $k ), $input );
		}
		$h .= '</div><h3 class="invoqk-card__title">' . esc_html( Seller::label( 'profile' ) ) . '</h3><div class="invoqk-choices">';
		foreach ( [ 'xrechnung' => 'XRechnung', 'en16931' => 'EN 16931' ] as $v => $label ) {
			$h .= self::choice( $o . '[profile]', $v, $label, '', $v === $s['profile'] );
		}
		$id = $o . '_nullsatz';
		return $h . '</div><div class="invoqk-field"><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $o . '[nullsatz]' ) . '" value="1"'
			. ( $s['nullsatz'] ? ' checked' : '' ) . '> ' . esc_html( Seller::label( 'nullsatz' ) ) . '</label><p class="invoqk-help">'
			. esc_html__( 'Small businesses under §19 UStG (Kleinunternehmer) are not supported.', 'invoqk' ) . '</p></div>';
	}

	private const TABS = [ 'overview' => 'Overview', 'settings' => 'Settings', 'license' => 'License' ];

	/** The active tab; anything unknown falls back to the overview. */
	public static function tab(): string {
		$t = sanitize_key( (string) ( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing
		return isset( self::TABS[ $t ] ) ? $t : 'overview';
	}

	public static function tabs( string $active ): string {
		$labels = [ 'overview' => __( 'Overview', 'invoqk' ), 'settings' => __( 'Settings', 'invoqk' ), 'license' => __( 'License', 'invoqk' ) ];
		$h      = '<nav class="invoqk-tabs" aria-label="Invoqk">';
		foreach ( $labels as $slug => $label ) {
			$on  = $slug === $active;
			$h  .= '<a class="invoqk-tab' . ( $on ? ' is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=invoqk&tab=' . $slug ) ) . '"'
				. ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $h . '</nav>';
	}

	public static function render(): void {
		$tab = self::tab();
		echo '<div class="wrap invoqk-app"><h1>Invoqk</h1>';
		settings_errors();
		echo self::tabs( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tabs()
		'settings' === $tab ? self::renderSettings() : ( 'license' === $tab ? self::renderLicense() : self::renderOverview() );
		echo '</div>';
	}

	private static function renderOverview(): void {
		$state = State::get();
		if ( 'new' === $state || isset( $_GET['invoqk_wizard'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing
			$step = absint( $_GET['step'] ?? 1 ); // phpcs:ignore WordPress.Security.NonceVerification
			echo Wizard::render( 2 === $step ? 2 : 1 ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Wizard
		} elseif ( 'running' === $state ) {
			echo Wizard::progress(); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo Dashboard::html( Readiness::get( get_locale() ), State::turnover(), '' !== Client::apiKey() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	private static function renderLicense(): void {
		\Invoqk\Licensing\Actions::refreshIfStale( time() );
		\Invoqk\Licensing\Actions::refreshOnOpen( time() );
		echo \Invoqk\Licensing\Box::html( \Invoqk\Licensing\License::get(), time(), sanitize_key( (string) ( $_GET['invoqk_license'] ?? '' ) ), (string) get_option( \Invoqk\Licensing\Actions::TRIAL_PENDING, '' ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- read-only flash code; escaped in Box
	}

	private static function renderSettings(): void {
		$set = '' !== (string) get_option( 'invoqk_api_key', '' );
		$pro = Limit::isPro();
		echo '<form method="post" action="options.php">';
		settings_fields( 'invoqk' );
		if ( $pro && ! get_option( 'invoqk_guard_intro_seen' ) ) {
			echo '<p class="invoqk-note">' . esc_html__( 'Invoices with errors are now held until you fix or release them.', 'invoqk' ) . '</p>';
			update_option( 'invoqk_guard_intro_seen', time(), false );
		}
		$key = self::field(
			'invoqk_api_key',
			__( 'API key', 'invoqk' ),
			'<input type="password" id="invoqk_api_key" name="invoqk_api_key" value="" autocomplete="off"' . ( $set ? ' placeholder="' . esc_attr( self::MASK ) . '"' : '' ) . '>',
			$set ? __( 'A key is saved. Enter a new one only to replace it.', 'invoqk' ) : __( 'Paste the API key from your invoqk.com account.', 'invoqk' )
		);
		echo self::card( __( 'Connection', 'invoqk' ), __( 'Links this shop to your Invoqk account so invoices can be checked.', 'invoqk' ), $key ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in card()/field()
		if ( $pro ) {
			$body  = '<h3 class="invoqk-card__title">' . esc_html__( 'Invoices with errors', 'invoqk' ) . '</h3>' . self::choices( 'invoqk_guard_mode', [
				'block' => [ __( 'Hold invoices with errors', 'invoqk' ), __( 'The invoice waits until you fix it or release it.', 'invoqk' ) ],
				'warn'  => [ __( 'Only warn me', 'invoqk' ), __( 'The invoice goes out anyway and you get a warning.', 'invoqk' ) ],
			], Hold::mode() );
			$body .= '<h3 class="invoqk-card__title">' . esc_html__( 'Ask for Leitweg-ID', 'invoqk' ) . '</h3>' . self::choices( Field::OPTION, [
				'off'      => [ __( 'Off', 'invoqk' ), __( 'Checkout does not ask for it.', 'invoqk' ) ],
				'always'   => [ __( 'Always', 'invoqk' ), __( 'Every customer sees the field.', 'invoqk' ) ],
				'business' => [ __( 'Only for business customers (company name or VAT ID filled in)', 'invoqk' ), __( 'Private customers do not see it.', 'invoqk' ) ],
			], Field::mode() )
				. '<p class="invoqk-help">' . esc_html__( 'The block checkout always shows the field. Only the classic checkout can limit it to business customers.', 'invoqk' ) . '</p>';
			echo self::card( __( 'Invoice checks', 'invoqk' ), __( 'What happens when Invoqk finds an error, and who is asked for a Leitweg-ID.', 'invoqk' ), $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$ret = self::field(
			'invoqk_archive_retention_years',
			__( 'Archive retention (years)', 'invoqk' ),
			'<input type="number" min="1" max="30" id="invoqk_archive_retention_years" name="invoqk_archive_retention_years" value="' . esc_attr( (string) Actions::retentionYears() ) . '">',
			__( 'This is not tax advice. Check the retention period with your tax advisor.', 'invoqk' )
		);
		echo self::card( __( 'Archive', 'invoqk' ), __( 'How long archived invoices are kept.', 'invoqk' ), $ret ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $pro ) {
			echo self::card( __( 'Fallback e-invoice', 'invoqk' ), __( 'Basic e-invoice for standard domestic orders. For complex tax cases, discounts or other currencies, use an invoice plugin.', 'invoqk' ), self::fallbackSection() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( false !== stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'nginx' ) ) {
			echo '<p class="invoqk-note is-warn">'
				. esc_html__( 'Your server ignores .htaccess. Add a rule that denies web access to uploads/invoqk-archive/, or set INVOQK_ARCHIVE_DIR outside the web root.', 'invoqk' )
				. '</p>';
		}
		echo '<p class="invoqk-note">' . esc_html__( 'Invoqk is a technical check and not legal or tax advice. Ask your tax advisor what applies to your business.', 'invoqk' ) . '</p>';
		echo '<div class="invoqk-savebar">';
		submit_button();
		echo '</div></form>';
	}
}
