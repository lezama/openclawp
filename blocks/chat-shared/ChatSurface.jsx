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
import Card from './Card.jsx';
import { COMMANDS, parseInput } from './commands/index.js';
import { useSetupWizard } from './setup/index.js';

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

	// Bump to force `useAgentChat` to remount with a fresh session — used by
	// `/clear` and `/reset` so the next user message starts a new server-side
	// conversation rather than continuing the previous one.
	const [ resetNonce, setResetNonce ] = useState( 0 );

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
		// Include the reset nonce in the storage key so a `/clear` invocation
		// causes the hook to read an empty value on the next render and start
		// a fresh session.
		sessionIdStorageKey: sessionIdStorageKey + ':' + resetNonce,
		authProvider: nonce ? authProvider : undefined,
		credentials: 'same-origin',
	} );

	const [ sessionId, refreshSession ] = useLatestSessionId( sessionIdStorageKey );
	const [ pending, setPending ] = useState( null );
	const [ cardStack, setCardStack ] = useState( [] );

	const pushCard = useCallback( ( card ) => {
		if ( ! card ) {
			return;
		}
		// Stamp a stable key so React can keep dismiss buttons paired with
		// their cards across re-renders.
		const stamped = { ...card, __key: Date.now() + ':' + Math.random() };
		setCardStack( ( stack ) => [ stamped, ...stack ] );
	}, [] );

	const dismissCard = useCallback( ( key ) => {
		setCardStack( ( stack ) => stack.filter( ( c ) => c.__key !== key ) );
	}, [] );

	const clearLocalSession = useCallback( () => {
		try {
			window.localStorage.removeItem( sessionIdStorageKey );
			// Also remove the current effective key — the hook reads from
			// `sessionIdStorageKey + ':' + resetNonce`, and the prior session
			// may have written under that exact key.
			window.localStorage.removeItem( sessionIdStorageKey + ':' + resetNonce );
		} catch ( e ) {
			// localStorage may be disabled — bumping the reset nonce below is
			// still enough to start the next message in a fresh session.
		}
		setResetNonce( ( n ) => n + 1 );
		refreshSession();
	}, [ sessionIdStorageKey, resetNonce, refreshSession ] );

	const runCommand = useCallback(
		async ( commandText ) => {
			const parsed = parseInput( commandText );
			if ( ! parsed ) {
				return;
			}
			const ctx = {
				agents,
				agentId,
				restNamespace,
				nonce,
				commands: COMMANDS,
				clearLocalSession,
			};
			if ( parsed.command ) {
				try {
					const card = await parsed.command.handler( parsed.args, ctx );
					pushCard( card );
				} catch ( e ) {
					pushCard( {
						type: 'card',
						kind: 'warning',
						title: 'Command failed',
						body: e && e.message ? e.message : 'Unknown error.',
					} );
				}
				return;
			}
			// Unknown command — surface the same help payload as `/help`,
			// flagged as a warning so it's visually distinct.
			const helpLines = COMMANDS.map(
				( cmd ) => `- **${ cmd.name }** — ${ cmd.description }`
			);
			pushCard( {
				type: 'card',
				kind: 'warning',
				title: `Unknown command: ${ parsed.name }`,
				body:
					'Try one of the bundled commands:\n\n' + helpLines.join( '\n' ),
			} );
		},
		[ agents, agentId, restNamespace, nonce, clearLocalSession, pushCard ]
	);

	const handleSubmit = useCallback(
		async ( message, options ) => {
			const parsed = parseInput( message );
			if ( parsed ) {
				await runCommand( message );
				return;
			}
			return onSubmit( message, options );
		},
		[ onSubmit, runCommand ]
	);

	const handleCardAction = useCallback(
		( action ) => {
			if ( ! action ) {
				return;
			}
			// Wizard cards attach an inline `onClick`; fall through to the
			// command dispatch path otherwise so existing slash-command cards
			// keep working untouched.
			if ( typeof action.onClick === 'function' ) {
				action.onClick();
				return;
			}
			if ( action.command ) {
				runCommand( action.command );
			}
		},
		[ runCommand ]
	);

	// First-run setup wizard. Renders a single card above the regular card
	// stack until the user finishes or dismisses it. `openclaWPConfig`
	// carries the persisted `openclawp_setup_completed` flag plus the
	// `/setup` REST surface used to advance through the steps.
	const wpConfig =
		typeof window !== 'undefined' && window.openclaWPConfig
			? window.openclaWPConfig
			: {};
	const setupEnabled = wpConfig.setupCompleted === false;
	const setup = useSetupWizard( {
		enabled: setupEnabled,
		restNamespace,
		nonce,
	} );

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

			{ ( setup.card || cardStack.length > 0 ) && (
				<div className="openclawp-card-stack">
					{ setup.card && (
						<Card
							card={ setup.card }
							onDismiss={ setup.dismiss }
							onAction={ handleCardAction }
						/>
					) }
					{ cardStack.map( ( card ) => (
						<Card
							key={ card.__key }
							card={ card }
							onDismiss={ () => dismissCard( card.__key ) }
							onAction={ handleCardAction }
						/>
					) ) }
				</div>
			) }

			<AgentUI
				variant="embedded"
				messages={ messages }
				isProcessing={ isProcessing }
				error={ error }
				onSubmit={ handleSubmit }
				onStop={ abortCurrentRequest }
				suggestions={ suggestions }
				clearSuggestions={ clearSuggestions }
				placeholder="Type a message or /help…"
			/>
		</section>
	);
}
