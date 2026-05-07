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

	function clearTranscript() {
		els.transcript.innerHTML = '';
	}

	function scrollToBottom() {
		els.transcript.scrollTop = els.transcript.scrollHeight;
	}

	function makeRow( kind ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'openclawp-turn openclawp-turn-' + kind;
		return wrap;
	}

	function appendTextTurn( role, text ) {
		const wrap = makeRow( role );
		const who = document.createElement( 'div' );
		who.className = 'openclawp-turn-role';
		who.textContent = role;
		const body = document.createElement( 'div' );
		body.className = 'openclawp-turn-content';
		body.textContent = text;
		wrap.appendChild( who );
		wrap.appendChild( body );
		els.transcript.appendChild( wrap );
	}

	function appendToolCallTurn( toolName, parameters ) {
		const wrap = makeRow( 'tool-call' );
		const summary = document.createElement( 'div' );
		summary.className = 'openclawp-turn-tool-summary';
		summary.textContent = '🔧 calling ' + toolName;
		wrap.appendChild( summary );

		const hasParams = parameters && Object.keys( parameters ).length > 0;
		if ( hasParams ) {
			const details = document.createElement( 'details' );
			const dt = document.createElement( 'summary' );
			dt.textContent = 'arguments';
			const pre = document.createElement( 'pre' );
			pre.textContent = JSON.stringify( parameters, null, 2 );
			details.appendChild( dt );
			details.appendChild( pre );
			wrap.appendChild( details );
		}

		els.transcript.appendChild( wrap );
	}

	function appendToolResultTurn( toolName, success, payload ) {
		const wrap = makeRow( 'tool-result' );
		wrap.classList.toggle( 'openclawp-turn-tool-error', success === false );

		const summary = document.createElement( 'div' );
		summary.className = 'openclawp-turn-tool-summary';
		summary.textContent = ( success === false ? '⚠ ' : '↩ ' ) + toolName;
		wrap.appendChild( summary );

		const details = document.createElement( 'details' );
		const dt = document.createElement( 'summary' );
		dt.textContent = 'result';
		const pre = document.createElement( 'pre' );
		pre.textContent = JSON.stringify( payload, null, 2 );
		details.appendChild( dt );
		details.appendChild( pre );
		wrap.appendChild( details );

		els.transcript.appendChild( wrap );
	}

	function renderMessage( msg ) {
		const type = msg.type || 'text';
		const role = msg.role || 'unknown';

		if ( type === 'tool_call' ) {
			const payload = msg.payload || {};
			appendToolCallTurn(
				payload.tool_name || 'unknown',
				payload.parameters || {}
			);
			return;
		}

		if ( type === 'tool_result' ) {
			const payload = msg.payload || {};
			appendToolResultTurn(
				payload.tool_name || 'unknown',
				payload.success !== false,
				payload.result !== undefined ? payload.result : ( typeof msg.content === 'string' ? msg.content : payload )
			);
			return;
		}

		// Default: plain text turn (user / assistant / system).
		const text = typeof msg.content === 'string' ? msg.content : JSON.stringify( msg.content );
		appendTextTurn( role, text );
	}

	function renderTranscript( messages ) {
		clearTranscript();
		if ( ! Array.isArray( messages ) ) {
			return;
		}
		for ( const m of messages ) {
			renderMessage( m );
		}
		scrollToBottom();
	}

	async function loadTranscript() {
		if ( ! sessionId ) {
			return;
		}
		try {
			const session = await apiFetch( {
				path: '/' + config.restNamespace + '/chat/' + encodeURIComponent( sessionId ),
			} );
			if ( session && Array.isArray( session.messages ) ) {
				renderTranscript( session.messages );
			}
		} catch ( err ) {
			appendTextTurn( 'system', 'Could not load session: ' + err.message );
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
		appendTextTurn( 'user', message );
		appendTextTurn( 'system', '…thinking' );
		const thinkingNode = els.transcript.lastChild;
		els.input.value = '';
		scrollToBottom();

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
			// Drop the optimistic "thinking" placeholder; reload the full transcript
			// so tool_call / tool_result turns from the loop's mediation become visible.
			thinkingNode && thinkingNode.remove();
			await loadTranscript();
		} catch ( err ) {
			thinkingNode && thinkingNode.remove();
			appendTextTurn( 'system', 'Chat failed: ' + err.message );
		}
	} );

	loadTranscript();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', openclaWPInitChat );
} else {
	openclaWPInitChat();
}
