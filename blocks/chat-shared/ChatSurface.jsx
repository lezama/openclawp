/**
 * Shared chat surface for openclaWP.
 *
 * Extracts the inner conversation UI used by both the openclawp/chat block
 * (rendered on the wp-admin → openclaWP → Chat page and embeddable as a
 * block) and the floating admin-bar panel that lives across every wp-admin
 * screen.
 *
 * Wraps `@automattic/agenttic-ui`'s `<AgentUI>` driven by `useAgentChat`
 * from `@automattic/agenttic-client`, talking to the openclaWP agenttic
 * JSON-RPC bridge at `/wp-json/openclawp/v1/agenttic/<agent>`. After every
 * assistant message, polls `/decisions/pending/<session_id>` so the agent
 * can ask for permission to invoke a gated tool — answering the prompt
 * POSTs the decision and triggers a follow-up turn.
 *
 * This component owns no chrome of its own — its callers (block view,
 * panel drawer) supply the surrounding shell.
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { SelectControl, Button, Notice } from '@wordpress/components';
import { useAgentChat } from '@automattic/agenttic-client';
import { AgentUI } from '@automattic/agenttic-ui';
import '@automattic/agenttic-ui/index.css';

const SESSION_KEY = 'openclawp:active-session';

function ConfirmationCard( { pending, restNamespace, nonce, onResolved } ) {
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( null );

	const submit = useCallback(
		async ( action ) => {
			setIsSubmitting( true );
			setError( null );
			try {
				const url = `/wp-json/${ restNamespace }/decisions/${ pending.decision_id }`;
				const response = await fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( { action } ),
				} );
				if ( ! response.ok ) {
					const body = await response.json().catch( () => ( {} ) );
					throw new Error( body.message || `HTTP ${ response.status }` );
				}
				onResolved && onResolved();
			} catch ( e ) {
				setError( e.message );
			} finally {
				setIsSubmitting( false );
			}
		},
		[ pending.decision_id, restNamespace, nonce, onResolved ]
	);

	return (
		<div className="openclawp-confirmation-card" role="alertdialog" aria-live="polite">
			<h3 className="openclawp-confirmation-card__title">
				Permission needed
			</h3>
			<p className="openclawp-confirmation-card__body">
				The agent wants to run <code>{ pending.ability }</code> (
				<code>{ pending.effect }</code> effect).
			</p>
			{ pending.parameters && Object.keys( pending.parameters ).length > 0 && (
				<details className="openclawp-confirmation-card__details">
					<summary>Parameters</summary>
					<pre>{ JSON.stringify( pending.parameters, null, 2 ) }</pre>
				</details>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="openclawp-confirmation-card__actions">
				<Button
					variant="primary"
					isBusy={ isSubmitting }
					disabled={ isSubmitting }
					onClick={ () => submit( 'allow' ) }
				>
					Allow once
				</Button>
				<Button
					variant="secondary"
					disabled={ isSubmitting }
					onClick={ () => submit( 'always' ) }
				>
					Always allow this tool
				</Button>
				<Button
					variant="tertiary"
					isDestructive
					disabled={ isSubmitting }
					onClick={ () => submit( 'deny' ) }
				>
					Deny
				</Button>
			</div>
		</div>
	);
}

function useLatestSessionId( sessionIdStorageKey ) {
	// useAgentChat persists the session id to localStorage under the
	// provided key. We can't read it via the hook's return shape (v0.1.x
	// doesn't expose it), so we read it directly and re-check whenever the
	// caller bumps `tick`.
	const [ tick, setTick ] = useState( 0 );
	const refresh = useCallback( () => setTick( ( t ) => t + 1 ), [] );
	const sessionId = useMemo( () => {
		// Depends on `tick` for re-evaluation, but tick is purposefully not
		// referenced inside the body — bumping it triggers re-read via the
		// dep array, which is the entire mechanism.
		void tick;
		try {
			return window.localStorage.getItem( sessionIdStorageKey ) || '';
		} catch ( e ) {
			return '';
		}
	}, [ sessionIdStorageKey, tick ] );
	return [ sessionId, refresh ];
}

export default function ChatSurface( { agents, defaultAgent, bridgeUrl, nonce, restNamespace } ) {
	const [ agentId, setAgentId ] = useState(
		defaultAgent || agents[ 0 ]?.slug || ''
	);

	const authProvider = useCallback(
		async () => ( { 'X-WP-Nonce': nonce } ),
		[ nonce ]
	);

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

	const [ sessionId, refreshSession ] = useLatestSessionId( sessionIdStorageKey );
	const [ pending, setPending ] = useState( null );

	// Poll for pending tool-call decisions after the assistant finishes
	// processing. One immediate check + a short follow-up; we deliberately
	// don't long-poll — decisions are bursty (one per gated tool call) and
	// the user typically resolves them within seconds.
	useEffect( () => {
		if ( isProcessing ) {
			return undefined;
		}
		refreshSession();
		if ( ! sessionId ) {
			setPending( null );
			return undefined;
		}
		let cancelled = false;
		const fetchPending = async () => {
			try {
				const response = await fetch(
					`/wp-json/${ restNamespace }/decisions/pending/${ sessionId }`,
					{
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': nonce },
					}
				);
				if ( ! response.ok ) {
					return;
				}
				const data = await response.json();
				if ( ! cancelled ) {
					setPending( data.pending || null );
				}
			} catch ( e ) {
				// Swallow — pending card is a nice-to-have, not load-bearing.
			}
		};
		fetchPending();
		return () => {
			cancelled = true;
		};
	}, [ isProcessing, sessionId, restNamespace, nonce, refreshSession, messages.length ] );

	const handleResolved = useCallback( () => {
		setPending( null );
		// The decision REST endpoint already ran the follow-up turn; the
		// chat UI hook is unaware of it, so trigger a refetch by reloading
		// the persisted session id (which the hook syncs from on next render).
		refreshSession();
		// Force the UI to fetch fresh messages by toggling the session
		// id — react to it on the next event loop tick.
		setTimeout( () => refreshSession(), 250 );
	}, [ refreshSession ] );

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

			{ pending && (
				<ConfirmationCard
					pending={ pending }
					restNamespace={ restNamespace }
					nonce={ nonce }
					onResolved={ handleResolved }
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
