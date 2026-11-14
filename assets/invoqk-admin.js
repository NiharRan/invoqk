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
			bar.value = s.total ? Math.round( 100 * s.done / s.total ) : 0;
			count.textContent = s.done + ' / ' + s.total;
			// first result, or job done with results that never complete
			if ( ( s.done > 0 || s.state === 'done' ) && ! waitFrom ) {
				waitFrom = Date.now();
			}
			// total 0 while still running means the job has not saved its ids yet
			if ( ( s.total === 0 && s.state !== 'running' ) || ( s.total > 0 && s.done >= s.total ) || ( s.state === 'done' && s.done > 0 ) || ( waitFrom && Date.now() - waitFrom >= 90000 ) ) {
				go();
				return;
			}
		} catch ( e ) { /* retry on next tick */ }
		setTimeout( tick, 3000 );
	};
	tick();
}() );
