<?php
namespace Invoqk\Tests\Archive;

use Invoqk\Archive\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase {
	private const NOW = 4000000000;

	protected function setUp(): void {
		$GLOBALS['invoqk_options'] = [];
		$GLOBALS['invoqk_user_name'] = 'Anna';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['invoqk_user_name'] );
	}

	private function row( array $o = [] ): array {
		return $o + [ 'id' => 5, 'invoice_number' => 'RE-5', 'invoice_date' => '2020-01-01', 'customer' => 'Acme', 'net' => '100.00', 'vat' => '19.00', 'gross' => '1119.00', 'validation_status' => 'valid', 'version' => 2, 'sent_at' => self::NOW - 100, 'sent_confirmed' => 1, 'xml_key' => 'k', 'pdf_key' => null, 'deleted_at' => null ];
	}

	public function test_filters_drop_bad_dates(): void {
		$f = Page::filters( [ 's' => ' <b>RE</b> ', 'from' => '2020-01-02', 'to' => '31.12.2020' ], self::NOW );
		$this->assertSame( [ 'q' => 'RE', 'from' => '2020-01-02' ], $f );
		$f = Page::filters( [ 'from' => '2020-02-30', 'deletable' => '1' ], self::NOW );
		$this->assertSame( [ 'deletable' => true, 'before' => self::NOW - 8 * 365 * DAY_IN_SECONDS ], $f );
	}

	public function test_cells_escape_customer(): void {
		$c = Page::cells( $this->row( [ 'customer' => '<b>x</b>' ] ), self::NOW );
		$this->assertStringNotContainsString( '<b>', $c['customer'] );
		$this->assertSame( '1.119,00', $c['gross'] );
		$this->assertSame( '01.01.2020', $c['date'] );
		$this->assertStringContainsString( 'invoqk_archive_download_5', $c['files'] );
		$this->assertStringContainsString( 'file=xml', $c['files'] );
		$this->assertStringNotContainsString( 'file=pdf', $c['files'] );
	}

	public function test_cells_tombstone_and_unconfirmed(): void {
		$c = Page::cells( $this->row( [ 'deleted_at' => 86400, 'deleted_by' => 3 ] ), self::NOW );
		$this->assertSame( 'Deleted on 02.01.1970 by Anna', $c['files'] );
		$c = Page::cells( $this->row( [ 'sent_confirmed' => 0 ] ), self::NOW );
		$this->assertStringContainsString( 'Delivery unconfirmed', $c['status'] );
		$this->assertStringNotContainsString( 'deletable', $c['status'] );
		$c = Page::cells( $this->row( [ 'sent_at' => 1 ] ), self::NOW );
		$this->assertStringContainsString( 'deletable', $c['status'] );
	}

	public function test_header_meter_and_verify_form(): void {
		$h = Page::header( [ 'state' => 'ok', 'checked' => 3, 'at' => 0 ], (int) ( 1.2 * 1024 ** 3 ) );
		$this->assertStringContainsString( '1,2 GB of 5 GB', $h );
		$this->assertStringContainsString( 'invoqk_archive_verify', $h );
	}

	public function test_delete_controls(): void {
		$h = Page::deleteControls();
		$this->assertStringContainsString( 'name="invoqk_delete_nonce"', $h );
		$this->assertStringContainsString( 'name="action" value="invoqk_archive_delete"', $h );
		$this->assertStringContainsString( 'name="confirm"', $h );
		$this->assertStringContainsString( 'name="reason"', $h );
		$this->assertStringContainsString( 'type DELETE and give a reason', $h );
	}

	public function test_notice_counts_and_escapes(): void {
		$this->assertSame( '', Page::notice( [] ) );
		$n = Page::notice( [ 'deleted' => '2', 'refused' => '1<b>' ] );
		$this->assertStringContainsString( '2 entries deleted.', $n );
		$this->assertStringContainsString( '1 entry kept', $n );
		$this->assertStringNotContainsString( '<b>', $n );
	}

	public function test_checkbox_only_for_live_rows(): void {
		$this->assertStringContainsString( 'name="ids[]" value="5"', Page::checkbox( $this->row() ) );
		$this->assertSame( '', Page::checkbox( $this->row( [ 'deleted_at' => 5 ] ) ) );
	}

	public function test_date_not_shifted_by_timezone_and_last_check_line(): void {
		$this->assertSame( '31.12.2019', Page::cells( $this->row( [ 'invoice_date' => '2019-12-31' ] ), self::NOW )['date'] );
		$this->assertStringContainsString( 'Last check: 01.01.1970, no changes detected', Page::header( [ 'state' => 'ok', 'at' => 0 ], 0 ) );
		$this->assertStringContainsString( 'changes detected at entry #9 (link)', Page::header( [ 'state' => 'broken', 'brokenId' => 9, 'kind' => 'link', 'at' => 0 ], 0 ) );
	}
}
