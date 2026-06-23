/**
 * In-chat first-run setup wizard.
 *
 * Exposes a single hook, `useSetupWizard`, that `ChatSurface` mounts when the
 * localised `openclaWPConfig.setupCompleted` is false. The hook owns the
 * current step + last-known `/setup/state` snapshot, and returns:
 *
 *   - `card`     — the Card object to render at the top of the card stack.
 *   - `visible`  — false once the wizard has finished or been dismissed.
 *   - `dismiss`  — close the wizard and POST `/setup/complete`.
 *
 * The card it returns uses the standard Card shape (see `Card.jsx`) plus an
 * `onClick` handler on each action — `ChatSurface.handleCardAction` invokes it
 * when present. Because the wizard always renders exactly one card at a time
 * (we replace, not stack, on each step transition), it composes cleanly with
 * the existing `cardStack` used by slash commands.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { FIRST_STEP_ID, findStep } from './steps.js';

/**
 * Build the wizard card for a given step + state, wiring its actions to the
 * provided helpers bag.
 *
 * @param {object} step    Step definition (see `steps.js`).
 * @param {object} state   Latest `/setup/state` snapshot.
 * @param {object} helpers `{ advance, refresh, finish, setExampleAgent }`.
 * @return {object} Card object suitable for `<Card>`.
 */
function buildStepCard( step, state, helpers ) {
	const kind = step.kindFor ? step.kindFor( state ) : 'info';
	return {
		type: 'card',
		kind,
		title: step.title,
		body: step.buildBody( state ),
		actions: step.buildActions( state, helpers ),
	};
}

/**
 * POST JSON to a setup route. Returns the parsed response body, or throws
 * with the server's error message when the request fails.
 *
 * @param {string} restNamespace Plugin REST namespace, e.g. `openclawp/v1`.
 * @param {string} path          Route path under `/setup/`, e.g. `complete`.
 * @param {string} nonce         WP REST nonce.
 * @param {object} body          JSON payload.
 * @return {Promise<object>}
 */
async function postSetup( restNamespace, path, nonce, body ) {
	const url = `/wp-json/${ restNamespace }/setup/${ path }`;
	const response = await fetch( url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		body: JSON.stringify( body || {} ),
	} );
	if ( ! response.ok ) {
		const payload = await response.json().catch( () => ( {} ) );
		throw new Error( payload.message || `HTTP ${ response.status }` );
	}
	return response.json();
}

/**
 * GET the current setup state.
 *
 * @param {string} restNamespace
 * @param {string} nonce
 * @return {Promise<object>}
 */
async function fetchState( restNamespace, nonce ) {
	const url = `/wp-json/${ restNamespace }/setup/state`;
	const response = await fetch( url, {
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': nonce },
	} );
	if ( ! response.ok ) {
		throw new Error( `HTTP ${ response.status }` );
	}
	return response.json();
}

/**
 * React hook driving the in-chat setup wizard.
 *
 * @param {object}   args
 * @param {boolean}  args.enabled        When false, the hook is a no-op.
 * @param {string}   args.restNamespace  e.g. `openclawp/v1`.
 * @param {string}   args.nonce          WP REST nonce.
 * @param {Function} [args.onDone]       Called once the wizard finishes.
 * @return {{ card: object|null, visible: boolean, dismiss: Function }}
 */
export function useSetupWizard( { enabled, restNamespace, nonce, onDone } ) {
	const [ visible, setVisible ] = useState( Boolean( enabled ) );
	const [ stepId, setStepId ] = useState( FIRST_STEP_ID );
	const [ state, setState ] = useState( {
		completed: false,
		providers: [],
		exampleAgentEnabled: false,
	} );

	const onDoneRef = useRef( onDone );
	useEffect( () => {
		onDoneRef.current = onDone;
	}, [ onDone ] );

	// Initial state fetch. We render the welcome card immediately (no network
	// data needed) and refresh in the background so the provider step has
	// real data by the time the user advances.
	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		let cancelled = false;
		fetchState( restNamespace, nonce )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				if ( data && data.completed ) {
					// Server says we're already done — flip the local flag and
					// hide the wizard. This handles the case where another
					// admin completed setup in a different tab.
					setVisible( false );
					return;
				}
				setState( ( prev ) => ( { ...prev, ...data } ) );
			} )
			.catch( () => {
				// Surface nothing — the welcome card stays usable even without
				// the state snapshot. The provider step will retry on entry.
			} );
		return () => {
			cancelled = true;
		};
	}, [ enabled, restNamespace, nonce ] );

	const refresh = useCallback( async () => {
		try {
			const data = await fetchState( restNamespace, nonce );
			setState( ( prev ) => ( { ...prev, ...data } ) );
		} catch ( e ) {
			// Re-render the same step; the user can click Recheck again.
		}
	}, [ restNamespace, nonce ] );

	const advance = useCallback(
		async ( nextId ) => {
			// Re-fetch state on entry to each step so the provider list /
			// example-agent toggle is current.
			await refresh();
			setStepId( nextId );
		},
		[ refresh ]
	);

	const setExampleAgent = useCallback(
		async ( shouldEnable ) => {
			try {
				await postSetup( restNamespace, 'enable-example-agent', nonce, {
					enabled: Boolean( shouldEnable ),
				} );
				setState( ( prev ) => ( {
					...prev,
					exampleAgentEnabled: Boolean( shouldEnable ),
				} ) );
			} catch ( e ) {
				// Best-effort — finish() still flips setup_completed below so
				// the wizard doesn't get stuck if this single call fails.
			}
		},
		[ restNamespace, nonce ]
	);

	const finish = useCallback( async () => {
		try {
			await postSetup( restNamespace, 'complete', nonce, {} );
		} catch ( e ) {
			// Even if the POST fails the local flag below still hides the
			// wizard for this session; the welcome notice will reappear on
			// the next page load if the option didn't actually save.
		}
		if ( typeof window !== 'undefined' && window.openclaWPConfig ) {
			window.openclaWPConfig.setupCompleted = true;
		}
		setVisible( false );
		if ( typeof onDoneRef.current === 'function' ) {
			onDoneRef.current();
		}
	}, [ restNamespace, nonce ] );

	const helpers = useMemo(
		() => ( {
			advance,
			refresh,
			finish,
			setExampleAgent,
		} ),
		[ advance, refresh, finish, setExampleAgent ]
	);

	const card = useMemo( () => {
		if ( ! visible ) {
			return null;
		}
		const step = findStep( stepId );
		if ( ! step ) {
			return null;
		}
		return buildStepCard( step, state, helpers );
	}, [ visible, stepId, state, helpers ] );

	return { card, visible, dismiss: finish };
}
