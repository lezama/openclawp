/* global wp, openclaWPConfig */
( function () {
	'use strict';

	const config = window.openclaWPConfig || { restNamespace: 'openclawp/v1', nonce: '' };
	const apiFetch = wp && wp.apiFetch;
	if ( ! apiFetch ) {
		return;
	}
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

	const SESSION_KEY = 'openclawp:active-session';

	const els = {
		agent:      document.getElementById( 'openclawp-agent' ),
		newSession: document.getElementById( 'openclawp-new-session' ),
		sessionId:  document.getElementById( 'openclawp-session-id' ),
		transcript: document.getElementById( 'openclawp-transcript' ),
		form:       document.getElementById( 'openclawp-form' ),
		input:      document.getElementById( 'openclawp-input' ),
	};

	let sessionId = sessionStorage.getItem( SESSION_KEY ) || null;

	function setSessionId( id ) {
		sessionId = id || null;
		if ( id ) {
			sessionStorage.setItem( SESSION_KEY, id );
			els.sessionId.textContent = id;
		} else {
			sessionStorage.removeItem( SESSION_KEY );
			els.sessionId.textContent = '';
		}
	}

	function appendTurn( role, text ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'openclawp-turn openclawp-turn-' + role;
		const who = document.createElement( 'div' );
		who.className = 'openclawp-turn-role';
		who.textContent = role;
		const body = document.createElement( 'div' );
		body.className = 'openclawp-turn-content';
		body.textContent = text;
		wrap.appendChild( who );
		wrap.appendChild( body );
		els.transcript.appendChild( wrap );
		els.transcript.scrollTop = els.transcript.scrollHeight;
	}

	function clearTranscript() {
		els.transcript.innerHTML = '';
	}

	async function loadAgents() {
		try {
			const agents = await apiFetch( { path: '/' + config.restNamespace + '/agents' } );
			els.agent.innerHTML = '';
			for ( const agent of agents ) {
				const opt = document.createElement( 'option' );
				opt.value = agent.slug;
				opt.textContent = agent.label || agent.slug;
				els.agent.appendChild( opt );
			}
		} catch ( err ) {
			appendTurn( 'system', 'Failed to load agents: ' + err.message );
		}
	}

	async function rehydrateSession() {
		if ( ! sessionId ) {
			return;
		}
		els.sessionId.textContent = sessionId;
		try {
			const session = await apiFetch( {
				path: '/' + config.restNamespace + '/chat/' + encodeURIComponent( sessionId ),
			} );
			if ( session && Array.isArray( session.messages ) ) {
				clearTranscript();
				for ( const msg of session.messages ) {
					const role = msg.role || 'unknown';
					const content = typeof msg.content === 'string' ? msg.content : JSON.stringify( msg.content );
					appendTurn( role, content );
				}
			}
		} catch ( err ) {
			appendTurn( 'system', 'Could not load prior session — starting fresh. (' + err.message + ')' );
			setSessionId( null );
		}
	}

	els.newSession.addEventListener( 'click', () => {
		setSessionId( null );
		clearTranscript();
		els.input.focus();
	} );

	els.form.addEventListener( 'submit', async ( ev ) => {
		ev.preventDefault();
		const message = els.input.value.trim();
		if ( ! message ) {
			return;
		}
		const agent = els.agent.value;
		appendTurn( 'user', message );
		els.input.value = '';

		try {
			const result = await apiFetch( {
				path: '/' + config.restNamespace + '/chat',
				method: 'POST',
				data: {
					agent,
					message,
					session_id: sessionId,
				},
			} );
			setSessionId( result.session_id );
			appendTurn( 'assistant', result.reply || '(no reply)' );
		} catch ( err ) {
			appendTurn( 'system', 'Chat failed: ' + err.message );
		}
	} );

	loadAgents().then( rehydrateSession );
} )();
