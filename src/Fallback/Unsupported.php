<?php
namespace Invoqk\Fallback;

/** An order the fallback refuses to turn into an e-invoice; $reason is a fixed code, message() the translated text for the order box. */
final class Unsupported extends \RuntimeException {
	public const REASONS = [ 'country', 'currency', 'tax_rate', 'refund', 'total', 'totals', 'buyer_address', 'buyer_email', 'line_name', 'settings', 'profile', 'discount' ];

	public function __construct( public readonly string $reason ) {
		parent::__construct( $reason );
	}

	public function message(): string {
		return match ( $this->reason ) {
			'country'       => __( 'The billing address is not in Germany. The fallback e-invoice is for domestic orders only.', 'invoqk' ),
			'currency'      => __( 'The order is not in euros. The fallback e-invoice supports EUR only.', 'invoqk' ),
			'tax_rate'      => __( 'The order has a tax rate other than 19 % or 7 %. Use an invoice plugin for this order.', 'invoqk' ),
			'refund'        => __( 'The order has a refund. Create the invoice with an invoice plugin.', 'invoqk' ),
			'total'         => __( 'The order total is zero or negative.', 'invoqk' ),
			'totals'        => __( 'The line amounts do not add up to the order total to the cent.', 'invoqk' ),
			'buyer_address' => __( 'The billing address is incomplete (name, street, postcode, city and country are needed).', 'invoqk' ),
			'buyer_email'   => __( 'An XRechnung needs the customer email address, and it is missing.', 'invoqk' ),
			'line_name'     => __( 'An order line has no name.', 'invoqk' ),
			'settings'      => __( 'The order has lines with 0 % tax. This only works when “Nullsatz” is turned on in the settings.', 'invoqk' ),
			'profile'       => __( 'The selected e-invoice profile is not supported.', 'invoqk' ),
			'discount'      => __( 'Orders with discount or negative lines are not supported. Use an invoice plugin.', 'invoqk' ),
			default         => __( 'The fallback e-invoice does not support this order.', 'invoqk' ),
		};
	}
}
