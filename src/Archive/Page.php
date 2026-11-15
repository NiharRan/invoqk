<?php
namespace Invoqk\Archive;

use Invoqk\Validation\Limit;

/** Archive admin page: menu, render, and the pure helpers that carry the logic. */
final class Page {
	public static function boot(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'Invoqk Archiv', __( 'Invoqk Archive', 'invoqk' ), 'manage_woocommerce', 'invoqk-archive', [ self::class, 'render' ] );
	}

	private static function date( $v ): ?string {
		$v = (string) $v;
		$d = \DateTime::createFromFormat( '!Y-m-d', $v );
		return $d && $d->format( 'Y-m-d' ) === $v ? $v : null;
	}

	/** Index filters from raw query args; bad dates are dropped. */
	public static function filters( array $get, int $now ): array {
		$f = [];
		$q = sanitize_text_field( wp_unslash( $get['s'] ?? '' ) );
		if ( '' !== $q ) {
			$f['q'] = $q;
		}
		foreach ( [ 'from', 'to' ] as $k ) {
			if ( null !== ( $d = self::date( wp_unslash( $get[ $k ] ?? '' ) ) ) ) {
				$f[ $k ] = $d;
			}
		}
		if ( ! empty( $get['deletable'] ) ) {
			$f['deletable'] = true;
			$f['before']    = $now - Actions::retentionYears() * 365 * DAY_IN_SECONDS;
		}
		return $f;
	}

	/** @return array<string,string> Escaped HTML per column. */
	public static function cells( array $row, int $now ): array {
		$id     = (int) $row['id'];
		$status = esc_html( (string) ( $row['validation_status'] ?? '' ) );
		if ( isset( $row['sent_confirmed'] ) && 0 === (int) $row['sent_confirmed'] ) {
			$status .= ' <em>' . esc_html__( 'Delivery unconfirmed', 'invoqk' ) . '</em>';
		}
		if ( empty( $row['deleted_at'] ) && Actions::deletable( $row, $now ) ) {
			$status .= ' <em>' . esc_html__( 'deletable', 'invoqk' ) . '</em>';
		}
		if ( ! empty( $row['deleted_at'] ) ) {
			$u     = get_userdata( (int) ( $row['deleted_by'] ?? 0 ) );
			$files = esc_html(
				sprintf(
					/* translators: 1: date, 2: user name */
					__( 'Deleted on %1$s by %2$s', 'invoqk' ),
					wp_date( 'd.m.Y', (int) $row['deleted_at'] ),
					$u && '' !== $u->display_name ? $u->display_name : '#' . (int) ( $row['deleted_by'] ?? 0 )
				)
			);
		} else {
			$links = [];
			foreach ( [ 'xml', 'pdf' ] as $t ) {
				if ( ! empty( $row[ $t . '_key' ] ) ) {
					$url     = wp_nonce_url( admin_url( "admin-post.php?action=invoqk_archive_download&id={$id}&file={$t}" ), "invoqk_archive_download_{$id}" );
					$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( strtoupper( $t ) ) . '</a>';
				}
			}
			$files = implode( ' | ', $links );
		}
		$date  = (string) ( $row['invoice_date'] ?? '' );
		$money = static fn( $k ) => isset( $row[ $k ] ) ? esc_html( number_format( (float) $row[ $k ], 2, ',', '.' ) ) : '';
		return [
			'number'   => esc_html( (string) ( $row['invoice_number'] ?? '' ) ),
			'date'     => '' !== $date ? esc_html( wp_date( 'd.m.Y', (int) strtotime( $date . ' UTC' ) ) ) : '',
			'customer' => esc_html( (string) ( $row['customer'] ?? '' ) ),
			'net'      => $money( 'net' ),
			'vat'      => $money( 'vat' ),
			'gross'    => $money( 'gross' ),
			'status'   => $status,
			'files'    => $files,
			'version'  => esc_html( (string) ( $row['version'] ?? '' ) ),
		];
	}

	public static function header( array $verify, int $used ): string {
		$state = (string) ( $verify['state'] ?? 'none' );
		$msg   = [
			'running' => __( 'Check running', 'invoqk' ),
			'ok'      => __( 'Last check: no changes detected', 'invoqk' ),
			'broken'  => __( 'Last check: changes detected', 'invoqk' ),
		][ $state ] ?? __( 'No check run yet', 'invoqk' );
		$meter = sprintf( __( '%s GB of %s GB', 'invoqk' ), number_format( $used / 1024 ** 3, 1, ',', '.' ), '5' ); // Task 9: Usage::label
		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="invoqk_archive_verify">'
			. wp_nonce_field( 'invoqk_archive_verify', '_wpnonce', true, false )
			. '<button class="button">' . esc_html__( 'Check archive', 'invoqk' ) . '</button></form>';
		return '<div class="invoqk-archive-head"><p>' . esc_html( $meter ) . '</p>' . $form . '<p>' . esc_html( $msg ) . '</p></div>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Invoqk Archive', 'invoqk' ) . '</h1>';
		$now  = time();
		$ix   = Archive::index();
		$f    = self::filters( $_GET, $now ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters
		$page = max( 1, absint( $_GET['paged'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$res  = $ix->search( $f, $page, 20 );
		if ( ! Limit::isPro() && 0 === $ix->count() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The archive stores every e-invoice you send. Available in Invoqk Pro.', 'invoqk' )
				. ' <a href="' . esc_url( Limit::upgradeUrl() ) . '">' . esc_html__( 'Upgrade to Pro', 'invoqk' ) . '</a></p></div></div>';
			return;
		}
		echo self::header( Verify::result(), $ix->usage() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in header()
		echo '<form method="get"><input type="hidden" name="page" value="invoqk-archive">'
			. '<input type="search" name="s" value="' . esc_attr( $f['q'] ?? '' ) . '"> '
			. '<input type="date" name="from" value="' . esc_attr( $f['from'] ?? '' ) . '"> <input type="date" name="to" value="' . esc_attr( $f['to'] ?? '' ) . '"> '
			. '<label><input type="checkbox" name="deletable" value="1"' . ( empty( $f['deletable'] ) ? '' : ' checked' ) . '> ' . esc_html__( 'deletable', 'invoqk' ) . '</label> '
			. '<button class="button">' . esc_html__( 'Filter', 'invoqk' ) . '</button></form>';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new class( $res['rows'], $res['total'], $page, $now ) extends \WP_List_Table {
			public function __construct( private array $data, private int $total, private int $pg, private int $now ) {
				parent::__construct( [ 'singular' => 'invoice', 'plural' => 'invoices' ] );
			}
			public function get_columns(): array {
				return [
					'number'   => __( 'Number', 'invoqk' ),
					'date'     => __( 'Date', 'invoqk' ),
					'customer' => __( 'Customer', 'invoqk' ),
					'net'      => __( 'Net', 'invoqk' ),
					'vat'      => __( 'VAT', 'invoqk' ),
					'gross'    => __( 'Gross', 'invoqk' ),
					'status'   => __( 'Status', 'invoqk' ),
					'files'    => __( 'Files', 'invoqk' ),
					'version'  => __( 'Version', 'invoqk' ),
				];
			}
			public function prepare_items(): void {
				$this->_column_headers = [ $this->get_columns(), [], [] ];
				$this->items           = array_map( fn( $r ) => Page::cells( $r, $this->now ), $this->data );
				$this->set_pagination_args( [ 'total_items' => $this->total, 'per_page' => 20 ] );
			}
			protected function column_default( $item, $col ) {
				return $item[ $col ] ?? ''; // already escaped by Page::cells
			}
		};
		$table->prepare_items();
		$table->display();
		echo '</div>';
	}
}
