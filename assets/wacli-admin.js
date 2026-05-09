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

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'className' ) {
					node.className = attrs[ k ];
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( typeof c === 'string' ) {
				node.appendChild( document.createTextNode( c ) );
			} else if ( c ) {
				node.appendChild( c );
			}
		} );
		return node;
	}

	function render( s ) {
		if ( ! state.root ) return;
		const root = state.root;
		root.textContent = '';

		if ( s.mode === 'idle' ) {
			const btn = el( 'button', { className: 'button button-primary button-hero', id: 'openclawp-wacli-connect' }, [ 'Connect WhatsApp' ] );
			root.appendChild( el( 'div', { className: 'card' }, [
				el( 'h2', null, [ 'WhatsApp not connected' ] ),
				el( 'p', null, [ 'Pair this site as a WhatsApp linked device. The agent will reply on your behalf to incoming messages from allowlisted chats.' ] ),
				btn,
			] ) );
			btn.addEventListener( 'click', onConnect );
			return;
		}

		if ( s.mode === 'pairing' ) {
			const qrDiv = el( 'div', { id: 'openclawp-wacli-qr' } );
			const cancelBtn = el( 'button', { className: 'button', id: 'openclawp-wacli-cancel' }, [ 'Cancel' ] );
			root.appendChild( el( 'div', { className: 'card' }, [
				el( 'h2', null, [ 'Scan to pair' ] ),
				el( 'p', null, [ 'Open WhatsApp on your phone → Settings → Linked Devices → Link a Device, and scan:' ] ),
				qrDiv,
				el( 'p', { className: 'description' }, [ 'QR last refreshed ' + fmtAgo( s.qr_seen_at ) + '. wacli rotates the code automatically; this view follows.' ] ),
				cancelBtn,
			] ) );

			if ( s.qr_payload ) {
				new QRCode( qrDiv, {
					text: s.qr_payload,
					width: 280,
					height: 280,
					correctLevel: QRCode.CorrectLevel.M,
				} );
			} else {
				qrDiv.textContent = 'Waiting for wacli to emit the first QR…';
			}
			cancelBtn.addEventListener( 'click', onDisconnect );
			return;
		}

		if ( s.mode === 'syncing' ) {
			const disconnectBtn = el( 'button', { className: 'button', id: 'openclawp-wacli-disconnect' }, [ 'Disconnect' ] );
			root.appendChild( el( 'div', { className: 'card' }, [
				el( 'h2', null, [ 'Connected ✅' ] ),
				el( 'p', null, [
					el( 'strong', null, [ 'Paired as:' ] ),
					' ',
					el( 'code', null, [ s.paired_jid || '(unknown)' ] ),
				] ),
				el( 'p', null, [
					el( 'strong', null, [ 'Last event:' ] ),
					' ',
					el( 'span', null, [ s.last_event || '(none)' ] ),
					' · ' + fmtAgo( s.last_event_at ),
				] ),
				el( 'p', null, [
					'Incoming WhatsApp messages from allowlisted chats are forwarded to ',
					el( 'code', null, [ openclaWPWacli.agent || '(no agent set)' ] ),
					'.',
				] ),
				disconnectBtn,
			] ) );
			disconnectBtn.addEventListener( 'click', onDisconnect );
			return;
		}

		// failed
		const retryBtn = el( 'button', { className: 'button button-primary', id: 'openclawp-wacli-retry' }, [ 'Try again' ] );
		root.appendChild( el( 'div', { className: 'card error' }, [
			el( 'h2', null, [ 'Connection failed' ] ),
			el( 'p', null, [ s.error || 'Unknown error' ] ),
			retryBtn,
		] ) );
		retryBtn.addEventListener( 'click', onConnect );
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
			const modeSelect = form.querySelector( '#openclawp-wacli-self-mode' );
			if ( modeSelect && s.self_message_mode ) {
				modeSelect.value = s.self_message_mode;
			}
			const wfSelect = form.querySelector( '#openclawp-wacli-workflow' );
			if ( wfSelect ) {
				wfSelect.innerHTML = '<option value="">— route to the configured agent (default) —</option>';
				( s.available_workflows || [] ).forEach( function ( id ) {
					const opt = document.createElement( 'option' );
					opt.value = id;
					opt.textContent = id;
					if ( id === s.workflow_id ) opt.selected = true;
					wfSelect.appendChild( opt );
				} );
			}
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
					self_message_mode: data.get( 'self_message_mode' ) || '',
					workflow_id: data.get( 'workflow_id' ) || '',
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
