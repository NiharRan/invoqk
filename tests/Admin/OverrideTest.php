<?php
use Invoqk\Admin\Override;
use Invoqk\Admin\OrderBox;
use Invoqk\Classification\Classifier;
use PHPUnit\Framework\TestCase;

final class OverrideTest extends TestCase {
	private WC_Order $o;

	protected function setUp(): void {
		$GLOBALS['invoqk_as']     = [];
		$GLOBALS['invoqk_filters'] = [];
		$this->o                  = new WC_Order( 5 );
		$this->o->company         = '';
		$GLOBALS['invoqk_orders'] = [ 5 => $this->o ];
	}

	public function test_requires_capability_and_nonce_and_known_value(): void {
		$this->assertFalse( Override::handle( 5, 'required', false, true, 1, 'A' ) );
		$this->assertFalse( Override::handle( 5, 'required', true, false, 1, 'A' ) );
		$this->assertFalse( Override::handle( 5, 'bogus', true, true, 1, 'A' ) );
		$this->assertFalse( Classifier::isOverridden( $this->o ) );
		$this->assertSame( [], $this->o->notes );
	}

	public function test_set_required_writes_override_note_and_queues_detect(): void {
		$this->assertTrue( Override::handle( 5, 'required', true, true, 7, 'Erika' ) );
		$this->assertTrue( Classifier::isRequired( $this->o ) );
		$ov = Classifier::override( $this->o );
		$this->assertSame( 7, $ov['by'] );
		$this->assertCount( 1, $this->o->notes );
		$this->assertStringContainsString( 'Erika', $this->o->notes[0] );
		$this->assertSame( 'invoqk_detect_order', $GLOBALS['invoqk_as'][0]['hook'] );
	}

	public function test_set_not_required_queues_nothing(): void {
		$this->o->company = 'Acme';
		Override::handle( 5, 'not_required', true, true, 7, 'Erika' );
		$this->assertFalse( Classifier::isRequired( $this->o ) );
		$this->assertSame( [], $GLOBALS['invoqk_as'] );
	}

	public function test_same_value_twice_is_a_noop(): void {
		Override::handle( 5, 'required', true, true, 7, 'Erika' );
		$this->assertFalse( Override::handle( 5, 'required', true, true, 7, 'Erika' ) );
		$this->assertCount( 1, $this->o->notes );
	}

	public function test_auto_clears_override_and_notes_it(): void {
		Override::handle( 5, 'required', true, true, 7, 'Erika' );
		$this->assertTrue( Override::handle( 5, 'auto', true, true, 7, 'Erika' ) );
		$this->assertFalse( Classifier::isOverridden( $this->o ) );
		$this->assertFalse( Classifier::isRequired( $this->o ) );
		$this->assertCount( 2, $this->o->notes );
		$this->assertFalse( Override::handle( 5, 'auto', true, true, 7, 'Erika' ), 'nothing to clear' );
	}

	public function test_box_uses_links_not_a_form_and_escapes_company_free_reason(): void {
		$h = OrderBox::classification( $this->o );
		$this->assertStringNotContainsString( '<form', $h );
		$this->assertStringContainsString( 'action=invoqk_override', $h );
		$this->assertStringContainsString( 'value=required', $h );
		$this->assertStringContainsString( 'No VAT ID, no company name.', $h );
	}

	public function test_box_marks_override_and_hostile_vat_not_printed(): void {
		$this->o->update_meta_data( '_billing_vat_id', '<script>x</script>' );
		$this->o->update_meta_data( Classifier::OVERRIDE, [ 'required' => false, 'by' => 0, 'at' => 0 ] );
		$h = OrderBox::classification( $this->o );
		$this->assertStringContainsString( 'Set manually', $h );
		$this->assertStringNotContainsString( '<script>', $h );
	}
}
