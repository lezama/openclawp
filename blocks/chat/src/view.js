/**
 * View entry for the openclawp/chat block.
 *
 * Mounts `@automattic/agenttic-ui`'s `<AgentUI>` driven by
 * `useAgentChat` from `@automattic/agenttic-client`, talking to the
 * openclaWP agenttic JSON-RPC bridge at
 * `/wp-json/openclawp/v1/agenttic/<agent>`. The bridge translates the
 * JSON-RPC envelope into a canonical `agents/chat` ability call, so this
 * UI gets the same agent runtime the wp-admin chat surface uses.
 *
 * Styling: zero custom CSS. The chat surface comes from
 * `@automattic/agenttic-ui/index.css`; the agent picker is a
 * `@wordpress/components` `SelectControl` that ships with WordPress.
 *
 * v0 caveat: tool-call / tool-result turns are NOT yet surfaced here. The
 * bridge returns the final assistant text only; richer DataParts (tool
 * call lifecycle) land when there's a UI need.
 */

import { createRoot } from 'react-dom/client';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';
import { useAgentChat } from '@automattic/agenttic-client';
import { AgentUI } from '@automattic/agenttic-ui';
import '@automattic/agenttic-ui/index.css';

const SESSION_KEY = 'openclawp:active-session';

function ChatApp( { agents, defaultAgent, bridgeUrl, nonce } ) {
	const [ agentId, setAgentId ] = useState(
		defaultAgent || agents[ 0 ]?.slug || ''
	);

	// `useAgentChat` uses authProvider as a useEffect dep; if we hand it a
	// fresh function each render the effect re-fires → setState → re-render
	// → new function → … and the page locks up. Pin the identity.
	const authProvider = useCallback(
		async () => ( { 'X-WP-Nonce': nonce } ),
		[ nonce ]
	);

	// Per-agent storage key so switching the dropdown starts a fresh thread
	// for the new agent without clobbering the previous one. Memoised for
	// the same dep-array stability reason as authProvider above.
	const sessionIdStorageKey = useMemo( () => SESSION_KEY + ':' + agentId, [ agentId ] );

	const {
		messages,
		isProcessing,
		error,
		onSubmit,
		abortCurrentRequest,
		suggestions,
		clearSuggestions,
	} = useAgentChat( {
		agentId,
		agentUrl: bridgeUrl,
		sessionId: '',
		sessionIdStorageKey,
		authProvider: nonce ? authProvider : undefined,
		credentials: 'same-origin',
	} );

	return (
		<section className="agenttic">
			{ agents.length > 1 && (
				<SelectControl
					label="Agent"
					value={ agentId }
					options={ agents.map( ( a ) => ( {
						label: a.label,
						value: a.slug,
					} ) ) }
					onChange={ setAgentId }
					__next40pxDefaultSize
				/>
			) }

			<AgentUI
				variant="embedded"
				messages={ messages }
				isProcessing={ isProcessing }
				error={ error }
				onSubmit={ onSubmit }
				onStop={ abortCurrentRequest }
				suggestions={ suggestions }
				clearSuggestions={ clearSuggestions }
				placeholder="Type a message…"
			/>
		</section>
	);
}

function mount() {
	const root = document.getElementById( 'openclawp-chat-root' );
	if ( ! root ) {
		return;
	}

	let agents = [];
	try {
		agents = JSON.parse( root.dataset.agents || '[]' );
	} catch ( e ) {
		agents = [];
	}

	const defaultAgent = root.dataset.defaultAgent || '';
	const config = window.openclaWPConfig || {};
	const bridgeUrl = config.bridgeUrl || '/wp-json/openclawp/v1/agenttic';
	const nonce = config.nonce || '';

	if ( agents.length === 0 ) {
		// render.php already drew the "no agents registered" empty state — leave it.
		return;
	}

	createRoot( root ).render(
		<ChatApp
			agents={ agents }
			defaultAgent={ defaultAgent }
			bridgeUrl={ bridgeUrl }
			nonce={ nonce }
		/>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
