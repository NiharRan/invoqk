<?php
namespace Invoqk\Archive;

use Invoqk\Validation\Limit;

/** Archive admin page: menu, render, and the pure helpers that carry the logic. */
final class Page {
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
		$raw    = (string) ( $row['validation_status'] ?? '' );
		$tone   = [ 'passed' => 'ok', 'failed' => 'bad' ][ strtolower( $raw ) ] ?? 'na';
		$status = '' === $raw ? '' : '<span class="invoqk-pill-status ' . $tone . '">' . esc_html( $raw ) . '</span>';
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
		$cn    = 'credit_note' === ( $row['kind'] ?? '' );
		$money = static fn( $k ) => isset( $row[ $k ] ) ? esc_html( number_format( (float) $row[ $k ], 2, ',', '.' ) ) : '';
		return [
			'cb'       => self::checkbox( $row ),
			'number'   => self::number( $row ),
			'date'     => ( $dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date ) ) ? esc_html( $dt->format( 'd.m.Y' ) ) : '',
			'customer' => esc_html( (string) ( $row['customer'] ?? '' ) ),
			'net'      => $money( 'net' ),
			'vat'      => $money( 'vat' ),
			'gross'    => $money( 'gross' ),
			'status'   => $status,
			'files'    => $files,
			'version'  => $cn ? '' : esc_html( (string) ( $row['version'] ?? '' ) ),
		];
	}

	/** Number cell; a credit note row stores the refund id, so its order link goes through the refund's parent. */
	private static function number( array $row ): string {
		$cn  = 'credit_note' === ( $row['kind'] ?? '' );
		$o   = wc_get_order( (int) ( $row['order_id'] ?? 0 ) );
		$o   = $cn && $o && method_exists( $o, 'get_parent_id' ) ? wc_get_order( (int) $o->get_parent_id() ) : $o;
		$num = esc_html( (string) ( $row['invoice_number'] ?? '' ) );
		if ( $o instanceof \WC_Order ) {
			$num = '<a href="' . esc_url( $o->get_edit_order_url() ) . '">' . $num . '</a>';
		}
		return $cn ? $num . ' <em>' . esc_html__( 'Credit note', 'invoqk' ) . '</em>' : $num;
	}

	public static function header( array $verify, int $used, int $stuck = 0 ): string {
		$state = (string) ( $verify['state'] ?? 'none' );
		$at    = wp_date( 'd.m.Y', (int) ( $verify['at'] ?? 0 ) );
		$msg   = [
			'running' => __( 'Check running', 'invoqk' ),
			/* translators: %s: date */
			'ok'      => sprintf( __( 'Last check: %s, no changes detected', 'invoqk' ), $at ),
			/* translators: 1: date, 2: entry id, 3: kind of change */
			'broken'  => sprintf( __( 'Last check: %1$s, changes detected at entry #%2$d (%3$s)', 'invoqk' ), $at, (int) ( $verify['brokenId'] ?? 0 ), (string) ( $verify['kind'] ?? '' ) ),
		][ $state ] ?? __( 'No check run yet', 'invoqk' );
		$tone  = [ 'ok' => 'is-ok', 'broken' => 'is-bad' ][ $state ] ?? 'is-idle';
		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="invoqk_archive_verify">'
			. wp_nonce_field( 'invoqk_archive_verify', '_wpnonce', true, false )
			. '<button class="button">' . esc_html__( 'Check archive', 'invoqk' ) . '</button></form>';
		$h     = '<div class="invoqk-statusbar invoqk-archive-head">'
			. '<div class="invoqk-stat"><span class="invoqk-stat__label">' . esc_html__( 'Storage', 'invoqk' ) . '</span><span class="invoqk-stat__value">' . esc_html( Usage::label( $used ) ) . '</span></div>';
		if ( $stuck > 0 ) {
			/* translators: %d: count */
			$h .= '<div class="invoqk-stat"><span class="invoqk-stat__label">' . esc_html__( 'Queue', 'invoqk' ) . '</span><span class="invoqk-stat__value">'
				. esc_html( sprintf( _n( '%d invoice waiting to be archived', '%d invoices waiting to be archived', $stuck, 'invoqk' ), $stuck ) ) . '</span></div>';
		}
		return $h . '<div class="invoqk-stat invoqk-state ' . $tone . '"><span class="invoqk-stat__label">' . esc_html__( 'Integrity check', 'invoqk' ) . '</span><span class="invoqk-stat__value">'
			. esc_html( $msg ) . '</span>' . $form . '</div></div>';
	}

	public static function upgradeCard(): string {
		return '<div class="invoqk-card"><h2 class="invoqk-card__title">' . esc_html__( 'Invoqk Archive', 'invoqk' ) . '</h2><p class="invoqk-card__hint">'
			. esc_html__( 'The archive stores every e-invoice you send. Available in Invoqk Pro.', 'invoqk' ) . '</p><p><a class="button button-primary" href="'
			. esc_url( Limit::upgradeUrl() ) . '">' . esc_html__( 'Upgrade to Pro', 'invoqk' ) . '</a></p></div>';
	}

	public static function checkbox( array $row ): string {
		return empty( $row['deleted_at'] ) ? '<input type="checkbox" name="ids[]" value="' . (int) $row['id'] . '">' : '';
	}

	public static function deleteControls(): string {
		$word = __( 'DELETE', 'invoqk' );
		return '<details class="invoqk-delete"><summary>' . esc_html__( 'Delete old entries', 'invoqk' ) . '</summary><input type="hidden" name="action" value="invoqk_archive_delete">' . wp_nonce_field( 'invoqk_archive_delete', 'invoqk_delete_nonce', false, false )
			. '<p><input type="text" name="confirm" placeholder="' . esc_attr( $word ) . '"> '
			. '<input type="text" name="reason" placeholder="' . esc_attr( __( 'Reason', 'invoqk' ) ) . '"> '
			. '<button class="button" type="submit" onclick="return confirm(\'' . esc_attr( esc_js( __( 'Delete the selected archive entries? This cannot be undone.', 'invoqk' ) ) ) . '\')">' . esc_html__( 'Delete selected', 'invoqk' ) . '</button></p>'
			/* translators: %s: confirm word */
			. '<p class="description">' . esc_html( sprintf( __( 'Entries before the retention date are only deleted when you type %s and give a reason.', 'invoqk' ), $word ) ) . '</p></details>';
	}

	public static function notice( array $get ): string {
		$d = absint( $get['deleted'] ?? 0 );
		$r = absint( $get['refused'] ?? 0 );
		$o = '';
		if ( isset( $get['deleted'] ) ) {
			/* translators: %d: count */
			$o .= '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d entry deleted.', '%d entries deleted.', $d, 'invoqk' ), $d ) ) . '</p></div>';
		}
		if ( $r > 0 ) {
			/* translators: %d: count */
			$o .= '<div class="notice notice-warning"><p>' . esc_html( sprintf( _n( '%d entry kept: before the retention date it needs the confirm word and a reason.', '%d entries kept: before the retention date they need the confirm word and a reason.', $r, 'invoqk' ), $r ) ) . '</p></div>';
		}
		$e = [
			'started' => [ 'success', __( 'Export started. It runs in the background.', 'invoqk' ) ],
			'busy'    => [ 'warning', __( 'An export is already running.', 'invoqk' ) ],
			'range'   => [ 'warning', __( 'Choose a valid date range.', 'invoqk' ) ],
			'nozip'   => [ 'warning', __( 'The PHP zip extension is missing.', 'invoqk' ) ],
		][ (string) ( $get['export'] ?? '' ) ] ?? null;
		if ( $e ) {
			$o .= '<div class="notice notice-' . $e[0] . '"><p>' . esc_html( $e[1] ) . '</p></div>';
		}
		return $o;
	}

	/** Steuerberater export box: range form plus the state of the last export. */
	public static function exportBox( array $state, bool $zip, int $now ): string {
		$h = '<div class="invoqk-card invoqk-export invoqk-archive-export"><h2 class="invoqk-card__title">' . esc_html__( 'Export for Steuerberater', 'invoqk' ) . '</h2>';
		if ( ! $zip ) {
			return $h . '<p>' . esc_html__( 'The PHP zip extension is missing. Ask your host to enable it.', 'invoqk' ) . '</p></div>';
		}
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="invoqk_archive_export">'
			. wp_nonce_field( 'invoqk_archive_export', '_wpnonce', true, false )
			. '<input type="date" name="from"> <input type="date" name="to"> '
			. '<button class="button" type="submit">' . esc_html__( 'Export range', 'invoqk' ) . '</button> '
			. '<button class="button" type="submit" name="range" value="this_month">' . esc_html__( 'This month', 'invoqk' ) . '</button> '
			. '<button class="button" type="submit" name="range" value="last_month">' . esc_html__( 'Last month', 'invoqk' ) . '</button></form>';
		$st = $state['state'] ?? 'none';
		if ( 'running' === $st && (int) ( $state['at'] ?? 0 ) < $now - HOUR_IN_SECONDS ) {
			$st = 'failed'; // a crashed job never writes its failure
		}
		switch ( $st ) {
			case 'running':
				$h .= '<p>' . esc_html__( 'Export running. Reload this page later.', 'invoqk' ) . '</p>';
				break;
			case 'ready':
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=invoqk_archive_export_download' ), 'invoqk_archive_export_download' );
				$n   = (int) ( $state['count'] ?? 0 );
				$fmt = static fn( $d ) => ( $dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $d ) ) ? $dt->format( 'd.m.Y' ) : (string) $d;
				/* translators: 1: invoice count, 2: first date, 3: last date */
				$txt = sprintf( _n( '%1$d invoice, %2$s to %3$s', '%1$d invoices, %2$s to %3$s', $n, 'invoqk' ), $n, $fmt( $state['from'] ?? '' ), $fmt( $state['to'] ?? '' ) );
				$h  .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $txt ) . '</a></p>';
				break;
			case 'failed':
				$h .= '<p>' . esc_html__( 'Export failed. Check the archive check, then try again.', 'invoqk' ) . '</p>';
				break;
		}
		return $h . '</div>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}
		$now  = time();
		$ix   = Archive::index();
		$f    = self::filters( $_GET, $now ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters
		$page = max( 1, absint( $_GET['paged'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$res  = $ix->search( $f, $page, 20 );
		if ( ! Limit::isPro() && 0 === $ix->count() ) {
			echo self::upgradeCard(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in upgradeCard()
			return;
		}
		echo self::notice( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput -- counts only, escaped in notice()
		echo self::header( Verify::result(), Usage::used(), Entry::stuck() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in header()
		echo self::exportBox( Export::state(), Export::available(), $now ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in exportBox()
		echo '<form method="get" class="invoqk-toolbar"><input type="hidden" name="page" value="invoqk"><input type="hidden" name="tab" value="archive">'
			. '<input type="search" name="s" placeholder="' . esc_attr( __( 'Search number or customer', 'invoqk' ) ) . '" value="' . esc_attr( $f['q'] ?? '' ) . '"> '
			. '<input type="date" name="from" value="' . esc_attr( $f['from'] ?? '' ) . '"> <input type="date" name="to" value="' . esc_attr( $f['to'] ?? '' ) . '"> '
			. '<label><input type="checkbox" name="deletable" value="1"' . ( empty( $f['deletable'] ) ? '' : ' checked' ) . '> ' . esc_html__( 'Only deletable', 'invoqk' ) . '</label> '
			. '<button class="button">' . esc_html__( 'Filter', 'invoqk' ) . '</button></form>';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new class( $res['rows'], $res['total'], $page, $now ) extends \WP_List_Table {
			public function __construct( private array $data, private int $total, private int $pg, private int $now ) {
				parent::__construct( [ 'singular' => 'invoice', 'plural' => 'invoices' ] );
			}
			public function get_columns(): array {
				return [
					'cb'       => '<input type="checkbox" />',
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
			protected function column_cb( $item ) {
				return $item['cb'];
			}
			protected function column_default( $item, $col ) {
				return $item[ $col ] ?? ''; // already escaped by Page::cells
			}
		};
		$table->prepare_items();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . self::deleteControls(); // phpcs:ignore WordPress.Security.EscapeOutput
		$table->display();
		echo '</form>';
	}
}
