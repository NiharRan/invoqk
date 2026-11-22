( function () {
	const c = window.invoqkWizard;
	const bar = document.getElementById( 'invoqk-bar' );
	if ( ! c || ! bar ) {
		return;
	}
	const count = document.getElementById( 'invoqk-count' );
	let waitFrom = 0; // start of the 90 s fallback
	const go = () => { window.location.href = c.dashboardUrl; };
	const tick = async () => {
		try {
			const r = await fetch( c.ajaxUrl + '&nonce=' + encodeURIComponent( c.nonce ), { credentials: 'same-origin' } );
			if ( r.status === 403 ) {
				return; // nonce expired or logged out: polling cannot recover
			}
			if ( ! r.ok ) {
				throw new Error( 'status ' + r.status ); // falls through to the reschedule below
			}
			const s = await r.json();
			if ( s.total ) {
				bar.value = Math.round( 100 * s.done / s.total );
				count.textContent = c.checked.replace( '%1$d', s.done ).replace( '%2$d', s.total );
			} else if ( s.state === 'running' ) {
				bar.removeAttribute( 'value' ); // indeterminate: the scan has not picked invoices yet
				count.textContent = c.scanning;
			}
			// 90 s fallback starts at the first result, for checks that never complete
			if ( s.done > 0 && ! waitFrom ) {
				waitFrom = Date.now();
			}
			// total 0 while still running means the job has not saved its ids yet
			if ( ( s.total === 0 && s.state !== 'running' ) || ( s.total > 0 && s.done >= s.total ) || ( waitFrom && Date.now() - waitFrom >= 90000 ) ) {
				go();
				return;
			}
		} catch ( e ) { /* retry on next tick */ }
		setTimeout( tick, 3000 );
	};
	tick();
}() );
