/**
 * View entry for the openclaWP floating admin-bar chat panel.
 *
 * Mounts a slide-in drawer pinned to the right edge of the viewport on every
 * wp-admin screen. The drawer hosts the shared `<ChatSurface>` so the
 * conversation looks and behaves the same as the wp-admin → openclaWP → Chat
 * page.
 *
 * Open/close state:
 *  - Toggled from the `openclawp-chat-toggle` admin bar item, which
 *    dispatches a `openclawp:panel:toggle` event on `document` (the toolbar
 *    wiring is emitted by `OpenclaWP_Admin_Bar_Panel::print_toggle_script()`).
 *  - Persisted to `localStorage` under `openclawp:panel:open` so the panel
 *    re-opens on page nav.
 *  - `Esc` closes the panel when it's focused.
 */

import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { close as closeIcon } from '@wordpress/icons';
import ChatSurface from '../../chat-shared/ChatSurface.jsx';
import './view.css';

const STORAGE_KEY = 'openclawp:panel:open';
const TOGGLE_EVENT = 'openclawp:panel:toggle';

function readInitialOpen() {
	try {
		return window.localStorage.getItem( STORAGE_KEY ) === '1';
	} catch ( e ) {
		return false;
	}
}

function persistOpen( open ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, open ? '1' : '0' );
	} catch ( e ) {
		// localStorage may be disabled — panel still works, just won't persist.
	}
}

function Panel( { agents, defaultAgent, bridgeUrl, nonce, restNamespace } ) {
	const [ isOpen, setIsOpen ] = useState( () => readInitialOpen() );

	const close = useCallback( () => {
		setIsOpen( false );
		persistOpen( false );
	}, [] );

	const toggle = useCallback( () => {
		setIsOpen( ( prev ) => {
			const next = ! prev;
			persistOpen( next );
			return next;
		} );
	}, [] );

	useEffect( () => {
		const onToggle = () => toggle();
		document.addEventListener( TOGGLE_EVENT, onToggle );
		return () => document.removeEventListener( TOGGLE_EVENT, onToggle );
	}, [ toggle ] );

	useEffect( () => {
		if ( ! isOpen ) {
			return undefined;
		}
		const onKey = ( event ) => {
			if ( event.key === 'Escape' ) {
				close();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ isOpen, close ] );

	return (
		<div
			className={
				'openclawp-panel' + ( isOpen ? ' is-open' : '' )
			}
			role="dialog"
			aria-modal="false"
			aria-hidden={ isOpen ? 'false' : 'true' }
			aria-label="openclaWP chat"
		>
			<header className="openclawp-panel__header">
				<h2 className="openclawp-panel__title">openclaWP</h2>
				<Button
					icon={ closeIcon }
					label="Close panel"
					onClick={ close }
					size="small"
				/>
			</header>
			<div className="openclawp-panel__body">
				{ agents.length === 0 ? (
					<p className="openclawp-panel__empty">
						No conversational agents are registered. Register a
						general-purpose agent on the
						<code> wp_agents_api_init </code>
						hook to chat here.
					</p>
				) : (
					<ChatSurface
						agents={ agents }
						defaultAgent={ defaultAgent }
						bridgeUrl={ bridgeUrl }
						nonce={ nonce }
						restNamespace={ restNamespace }
					/>
				) }
			</div>
		</div>
	);
}

function mount() {
	const root = document.getElementById( 'openclawp-admin-bar-panel-root' );
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
	const restNamespace = root.dataset.restNamespace || 'openclawp/v1';
	const config = window.openclaWPConfig || {};
	const bridgeUrl =
		config.bridgeUrl ||
		'/wp-json/' + restNamespace + '/agenttic';
	const nonce = root.dataset.nonce || config.nonce || '';

	createRoot( root ).render(
		<Panel
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
