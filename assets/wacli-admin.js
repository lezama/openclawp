/* global QRCode, wp, openclaWPWacli */
( function () {
	'use strict';

	const state = {
		root: null,
		qrInstance: null,
		timer: null,
	};

	function api( path, opts ) {
		const init = Object.assign(
			{
				path: '/' + openclaWPWacli.restNamespace + path,
				headers: { 'X-WP-Nonce': openclaWPWacli.nonce },
			},
			opts || {}
		);
		return wp.apiFetch( init );
	}

	function fmtAgo( ts ) {
		if ( ! ts ) return '—';
		const d = Math.max( 0, Math.floor( Date.now() / 1000 ) - ts );
		if ( d < 60 ) return d + 's ago';
		if ( d < 3600 ) return Math.floor( d / 60 ) + 'm ago';
		return Math.floor( d / 3600 ) + 'h ago';
	}

	function render( s ) {
		if ( ! state.root ) return;
		const root = state.root;

		if ( s.mode === 'idle' ) {
			root.innerHTML =
				'<div class="card"><h2>WhatsApp not connected</h2>' +
				'<p>Pair this site as a WhatsApp linked device. The agent will reply on your behalf to incoming messages from allowlisted chats.</p>' +
				'<button class="button button-primary button-hero" id="openclawp-wacli-connect">Connect WhatsApp</button></div>';
			document
				.getElementById( 'openclawp-wacli-connect' )
				.addEventListener( 'click', onConnect );
			return;
		}

		if ( s.mode === 'pairing' ) {
			root.innerHTML =
				'<div class="card"><h2>Scan to pair</h2>' +
				'<p>Open WhatsApp on your phone → Settings → Linked Devices → Link a Device, and scan:</p>' +
				'<div id="openclawp-wacli-qr"></div>' +
				'<p class="description">QR last refreshed ' +
				fmtAgo( s.qr_seen_at ) +
				'. wacli rotates the code automatically; this view follows.</p>' +
				'<button class="button" id="openclawp-wacli-cancel">Cancel</button></div>';

			const target = document.getElementById( 'openclawp-wacli-qr' );
			if ( s.qr_payload ) {
				target.innerHTML = '';
				new QRCode( target, {
					text: s.qr_payload,
					width: 280,
					height: 280,
					correctLevel: QRCode.CorrectLevel.M,
				} );
			} else {
				target.textContent = 'Waiting for wacli to emit the first QR…';
			}
			document
				.getElementById( 'openclawp-wacli-cancel' )
				.addEventListener( 'click', onDisconnect );
			return;
		}

		if ( s.mode === 'syncing' ) {
			root.innerHTML =
				'<div class="card"><h2>Connected ✅</h2>' +
				'<p><strong>Paired as:</strong> <code>' +
				( s.paired_jid || '(unknown)' ) +
				'</code></p>' +
				'<p><strong>Last event:</strong> ' +
				( s.last_event || '(none)' ) +
				' · ' +
				fmtAgo( s.last_event_at ) +
				'</p>' +
				'<p>Incoming WhatsApp messages from allowlisted chats are forwarded to <code>' +
				( openclaWPWacli.agent || '(no agent set)' ) +
				'</code>.</p>' +
				'<button class="button" id="openclawp-wacli-disconnect">Disconnect</button></div>';
			document
				.getElementById( 'openclawp-wacli-disconnect' )
				.addEventListener( 'click', onDisconnect );
			return;
		}

		// failed
		root.innerHTML =
			'<div class="card error"><h2>Connection failed</h2>' +
			'<p>' +
			( s.error || 'Unknown error' ) +
			'</p>' +
			'<button class="button button-primary" id="openclawp-wacli-retry">Try again</button></div>';
		document
			.getElementById( 'openclawp-wacli-retry' )
			.addEventListener( 'click', onConnect );
	}

	async function refresh() {
		try {
			const s = await api( '/wacli/state' );
			render( s );
		} catch ( e ) {
			console.error( '[openclawp-wacli] state fetch failed', e );
		}
	}

	async function onConnect() {
		try {
			const s = await api( '/wacli/connect', { method: 'POST' } );
			render( s );
		} catch ( e ) {
			alert( 'Connect failed: ' + ( e && e.message ? e.message : e ) );
		}
	}

	async function onDisconnect() {
		try {
			const s = await api( '/wacli/disconnect', { method: 'POST' } );
			render( s );
		} catch ( e ) {
			alert( 'Disconnect failed: ' + ( e && e.message ? e.message : e ) );
		}
	}

	async function loadSettings() {
		const form = document.getElementById( 'openclawp-wacli-settings-form' );
		if ( ! form ) return;

		try {
			const s = await api( '/wacli/settings' );
			const select = form.querySelector( '#openclawp-wacli-agent' );
			select.innerHTML = '<option value="">— select an agent —</option>';
			( s.available_agents || [] ).forEach( function ( slug ) {
				const opt = document.createElement( 'option' );
				opt.value = slug;
				opt.textContent = slug;
				if ( slug === s.agent ) opt.selected = true;
				select.appendChild( opt );
			} );
			form.querySelector( '#openclawp-wacli-allowed' ).value = ( s.allowed_jids || '' ).split( ',' ).filter( Boolean ).join( '\n' );
			form.querySelector( '#openclawp-wacli-binary' ).value = s.binary || '';
			openclaWPWacli.agent = s.agent;
		} catch ( e ) {
			console.error( '[openclawp-wacli] settings load failed', e );
		}

		form.addEventListener( 'submit', onSaveSettings );
	}

	async function onSaveSettings( ev ) {
		ev.preventDefault();
		const form = ev.currentTarget;
		const indicator = form.querySelector( '.openclawp-wacli-save-indicator' );
		indicator.textContent = 'Saving…';
		indicator.classList.remove( 'is-error' );

		const data = new FormData( form );
		try {
			const s = await api( '/wacli/settings', {
				method: 'POST',
				data: {
					agent: data.get( 'agent' ) || '',
					allowed_jids: data.get( 'allowed_jids' ) || '',
					binary: data.get( 'binary' ) || '',
				},
			} );
			indicator.textContent = '✓ Saved';
			openclaWPWacli.agent = s.agent;
			setTimeout( function () {
				indicator.textContent = '';
			}, 2000 );
			refresh();
		} catch ( e ) {
			indicator.textContent = '✗ ' + ( ( e && e.message ) || 'Save failed' );
			indicator.classList.add( 'is-error' );
		}
	}

	function start() {
		state.root = document.getElementById( 'openclawp-wacli-state' );
		if ( state.root ) {
			refresh();
			state.timer = setInterval( refresh, openclaWPWacli.pollInterval || 2000 );
		}
		loadSettings();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
