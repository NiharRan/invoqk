<?php
use Invoqk\Validation\Record;
use PHPUnit\Framework\TestCase;

final class RecordTest extends TestCase {
	public function test_status_none_when_no_source(): void {
		$o = new WC_Order();
		$this->assertSame( 'none', Record::status( $o ) );
		$o->update_meta_data( '_invoqk_source', 'none' );
		$this->assertSame( 'none', Record::status( $o ) );
	}

	public function test_status_pending_when_source_set_and_no_record(): void {
		$o = new WC_Order();
		$o->update_meta_data( '_invoqk_source', 'wpo' );
		$this->assertNull( Record::get( $o ) );
		$this->assertSame( 'pending', Record::status( $o ) );
	}

	public function test_get_put_roundtrip_uses_save_meta_data_not_save(): void {
		$o = new WC_Order();
		Record::put( $o, [ 'status' => 'passed' ] );
		$this->assertSame( [ 'status' => 'passed' ], Record::get( $o ) );
		$this->assertSame( 1, $o->metaSaves );
		$this->assertSame( 0, $o->saves );
	}

	public function test_status_returns_stored_status(): void {
		$o = new WC_Order();
		$o->update_meta_data( '_invoqk_source', 'wpo' );
		Record::put( $o, [ 'status' => 'limit' ] );
		$this->assertSame( 'limit', Record::status( $o ) );
	}
}
