<?php
namespace Invoqk\Sources;

/**
 * Optional for a SourceAdapter: its plugin can take the buyer reference (BT-10) through a documented
 * filter or setting, which the adapter wires up. Invoqk never edits generated XML.
 */
interface BuyerReferenceAware {
	/** True when the source plugin, as currently configured, puts the stored reference into BT-10. */
	public function supportsBuyerReference(): bool;
}
