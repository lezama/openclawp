/* global wp, openclaWPConfig */
function openclaWPInitChat() {
	'use strict';

	const config = window.openclaWPConfig || { restNamespace: 'openclawp/v1', nonce: '' };
	const apiFetch = window.wp && window.wp.apiFetch;
	if ( ! apiFetch ) {
		return;
	}
	if ( config.nonce && ! apiFetch._openclawpNonceAttached ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
		apiFetch._openclawpNonceAttached = true;
	}

	const SESSION_KEY = 'openclawp:active-session';

	const els = {
		agent:      document.getElementById( 'openclawp-agent' ),
		newSession: document.getElementById( 'openclawp-new-session' ),
		sessionId:  document.getElementById( 'openclawp-session-id' ),
		transcript: document.getElementById( 'openclawp-transcript' ),
		form:       document.getElementById( 'openclawp-form' ),
		input:      document.getElementById( 'openclawp-input' ),
	};

	if ( ! els.form || ! els.transcript ) {
		// Block markup not on this page (e.g. plugin loaded but block not rendered).
		return;
	}

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
			const data = { agent, message };
			if ( sessionId ) {
				data.session_id = sessionId;
			}
			const result = await apiFetch( {
				path: '/' + config.restNamespace + '/chat',
				method: 'POST',
				data,
			} );
			setSessionId( result.session_id );
			appendTurn( 'assistant', result.reply || '(no reply)' );
		} catch ( err ) {
			appendTurn( 'system', 'Chat failed: ' + err.message );
		}
	} );

	rehydrateSession();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', openclaWPInitChat );
} else {
	openclaWPInitChat();
}
