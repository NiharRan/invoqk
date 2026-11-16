<?php
namespace Invoqk\Licensing;

/** The licensing box on the settings page. Pure: takes the stored license and the time, returns escaped HTML. Never prints the key. */
final class Box {
	public static function html( array $l, int $now, string $flash = '', string $pendingEmail = '' ): string {
		$active = License::activeAt( $l, $now );
		$h      = '<div class="invoqk-box invoqk-license"><h2>' . esc_html__( 'License', 'invoqk' ) . '</h2>' . self::flash( $flash )
			. '<p class="invoqk-hero__verdict">' . esc_html( self::status( $l, $now, $active ) ) . '</p>';
		if ( $active ) {
			$h .= 'trial' === $l['plan'] ? '' : self::form( 'portal', __( 'Manage billing', 'invoqk' ) );
			$h .= self::form( 'deactivate', __( 'Deactivate this site', 'invoqk' ) );
		} else {
			if ( '' !== $pendingEmail ) {
				/* translators: %s: email address */
				$h .= '<p>' . esc_html( sprintf( __( 'We sent a 6-digit code to %s. Enter it to start your trial.', 'invoqk' ), $pendingEmail ) ) . '</p>'
					. self::form( 'trial_confirm', __( 'Confirm trial', 'invoqk' ), '<label>' . esc_html__( 'Code', 'invoqk' ) . ' <input name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" class="small-text"></label> ' );
			}
			if ( ! $l['trial_used'] ) {
				$h .= self::form( 'trial', __( 'Start 14-day trial', 'invoqk' ), '<label>' . esc_html__( 'Email', 'invoqk' ) . ' <input name="email" type="email" required autocomplete="email" class="regular-text"></label> ' );
			}
			$h .= self::form( 'activate', __( 'Activate', 'invoqk' ), '<label>' . esc_html__( 'License key (API key from your account)', 'invoqk' ) . ' <input type="text" name="key" value="" autocomplete="off" class="regular-text"></label> ' );
		}
		if ( ! $active || 'trial' === $l['plan'] ) {
			$h .= self::plans();
		}
		return $h . '</div>';
	}

	private static function status( array $l, int $now, bool $active ): string {
		if ( 'free' === $l['plan'] ) {
			return __( 'Free plan', 'invoqk' );
		}
		$date = wp_date( 'Y-m-d', $l['valid_until'] );
		if ( ! $active ) {
			/* translators: %s: date */
			return sprintf( __( 'Your Pro license ended on %s. Archive and export still work; guard and new archiving are paused.', 'invoqk' ), $date );
		}
		if ( 'trial' === $l['plan'] ) {
			$days = License::daysLeft( $now, $l );
			/* translators: 1: number of days, 2: date */
			return sprintf( _n( 'Pro trial, %1$d day left (ends %2$s)', 'Pro trial, %1$d days left (ends %2$s)', $days, 'invoqk' ), $days, $date );
		}
		$name = [ 'pro' => 'Pro', 'pro3' => 'Pro 3', 'agency' => 'Agency' ][ $l['plan'] ] ?? 'Pro';
		/* translators: 1: plan name, 2: shops used, 3: shops allowed, 4: renewal date */
		return sprintf( __( '%1$s, %2$d of %3$d shops, renews %4$s', 'invoqk' ), $name, $l['seats_used'], $l['seats_max'], $date );
	}

	private static function plans(): string {
		$rows = [
			'pro'    => [ 'Pro', '€79', _n( '%d shop', '%d shops', 1, 'invoqk' ), 1 ],
			'pro3'   => [ 'Pro 3', '€149', _n( '%d shop', '%d shops', 3, 'invoqk' ), 3 ],
			'agency' => [ 'Agency', '€399', _n( '%d shop', '%d shops', 25, 'invoqk' ), 25 ],
		];
		$h = '<div class="invoqk-pricing">';
		foreach ( $rows as $plan => [ $name, $price, $shops, $n ] ) {
			$h .= '<div class="invoqk-plan"><span class="invoqk-plan__name">' . esc_html( $name ) . '</span><span class="invoqk-plan__price">' . esc_html( $price )
				. ' <small class="invoqk-muted">/ ' . esc_html__( 'year', 'invoqk' ) . '</small></span><span class="invoqk-muted">' . esc_html( sprintf( $shops, $n ) ) . '</span>'
				. self::form( 'checkout', __( 'Buy', 'invoqk' ), '<input type="hidden" name="plan" value="' . esc_attr( $plan ) . '">', true ) . '</div>';
		}
		return $h . '</div>';
	}

	private static function form( string $action, string $button, string $extra = '', bool $primary = false ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="invoqk_license">'
			. wp_nonce_field( 'invoqk_license', '_wpnonce', true, false ) . $extra
			. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '" name="license_action" value="' . esc_attr( $action ) . '">' . esc_html( $button ) . '</button></form>';
	}

	private static function flash( string $code ): string {
		if ( '' === $code || 'ok' === $code ) {
			return '';
		}
		if ( 'check_email' === $code ) {
			return '<div class="notice notice-info inline"><p>' . esc_html__( 'Check your email for the trial code.', 'invoqk' ) . '</p></div>';
		}
		$texts = [
			'invalid_key' => __( 'This license key was not found.', 'invoqk' ),
			'seats_full'  => __( 'All seats of this license are in use. Deactivate another site first.', 'invoqk' ),
			'trial_used'  => __( 'The trial was already used for this site.', 'invoqk' ),
			'unreachable' => __( 'The Invoqk server could not be reached. Try again later.', 'invoqk' ),
			'no_key'      => __( 'Enter your API key in the settings below first.', 'invoqk' ),
			'invalid_code' => __( 'That code is not right or has expired. Request a new one.', 'invoqk' ),
			'no_pending'  => __( 'Enter your email first to get a code.', 'invoqk' ),
			'invalid_email' => __( 'Enter a valid email address.', 'invoqk' ),
			'account_exists' => __( 'An invoqk.com account with this email exists. Create an API key in your account and enter it below.', 'invoqk' ),
			'no_license'  => __( 'The key is saved, but this account has no active plan. Buy a plan below.', 'invoqk' ),
			'already_subscribed' => __( 'This account already has an Invoqk plugin license. Manage it with the link in your Lemon Squeezy receipt email.', 'invoqk' ),
			'not_connected' => __( 'This key is not connected to this shop yet. Enter it in "License key" above and click Activate first.', 'invoqk' ),
			'wrong_site'  => __( 'This key is already connected to another shop. Deactivate it there first.', 'invoqk' ),
			'site_taken'  => __( 'Another key of your account is already connected to this shop.', 'invoqk' ),
		];
		return '<div class="notice notice-error inline"><p>' . esc_html( $texts[ $code ] ?? __( 'Something went wrong. Try again later.', 'invoqk' ) ) . '</p></div>';
	}
}
