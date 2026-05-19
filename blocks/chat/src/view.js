/**
 * View entry for the openclawp/chat block.
 *
 * Hydrates the shared `<ChatSurface>` (see `blocks/chat-shared/ChatSurface.jsx`)
 * onto the `#openclawp-chat-root` node emitted by `render.php`. The same
 * surface is mounted by the floating admin-bar panel, so this entry is
 * intentionally a thin mounter — UI behaviour, tool-call confirmation, and
 * session handling all live in the shared component.
 */

import { createRoot } from 'react-dom/client';
import ChatSurface from '../../chat-shared/ChatSurface.jsx';
import './view.css';

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
	const restNamespace = config.restNamespace || 'openclawp/v1';

	if ( agents.length === 0 ) {
		// render.php already drew the "no agents registered" empty state — leave it.
		return;
	}

	createRoot( root ).render(
		<ChatSurface
			agents={ agents }
			defaultAgent={ defaultAgent }
			bridgeUrl={ bridgeUrl }
			nonce={ nonce }
			restNamespace={ restNamespace }
		/>
	);
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
