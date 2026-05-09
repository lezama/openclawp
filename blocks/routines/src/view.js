/**
 * Routines DataView entry.
 *
 * Mounted onto `#openclawp-routines-root` rendered by
 * `OpenclaWP_Routines_Admin::render_page()`. Reads `/openclawp/v1/routines`
 * (one row per registered routine, including its agent, schedule, next
 * wake, and last completed wake) and renders a `<DataViews>` table.
 *
 * v0 is read-only. Run-now / pause / resume row actions land alongside
 * matching REST endpoints.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { dateI18n } from '@wordpress/date';
import { createRoot } from 'react-dom/client';

function formatTrigger( trigger ) {
	if ( ! trigger ) {
		return '—';
	}
	if ( trigger.type === 'interval' ) {
		const s = Number( trigger.value ) || 0;
		if ( s % 3600 === 0 && s >= 3600 ) {
			return `every ${ s / 3600 }h`;
		}
		if ( s % 60 === 0 && s >= 60 ) {
			return `every ${ s / 60 }m`;
		}
		return `every ${ s }s`;
	}
	if ( trigger.type === 'expression' ) {
		return trigger.value;
	}
	return '—';
}

function formatTime( iso, ts ) {
	if ( ! ts ) {
		return '—';
	}
	return dateI18n( 'M j, Y H:i', iso );
}

const fields = [
	{
		id: 'id',
		label: 'Routine',
		enableSorting: true,
		enableGlobalSearch: true,
		render: ( { item } ) => item.label || item.id,
	},
	{
		id: 'agent',
		label: 'Agent',
		enableSorting: true,
		enableGlobalSearch: true,
	},
	{
		id: 'trigger',
		label: 'Schedule',
		enableSorting: false,
		render: ( { item } ) => formatTrigger( item.trigger ),
	},
	{
		id: 'next_wake_at',
		label: 'Next wake',
		enableSorting: true,
		render: ( { item } ) => formatTime( item.next_wake_iso, item.next_wake_at ),
	},
	{
		id: 'last_run',
		label: 'Last run',
		enableSorting: false,
		render: ( { item } ) => {
			if ( ! item.last_run ) {
				return '—';
			}
			const status = item.last_run.status === 'failed' ? '⚠ failed' : '✓ ok';
			return `${ status } · ${ formatTime( item.last_run.at_iso, item.last_run.at ) }`;
		},
	},
	{
		id: 'session_id',
		label: 'Session',
		enableSorting: false,
		enableGlobalSearch: false,
		render: ( { item } ) => item.session_id,
	},
	{
		id: 'prompt',
		label: 'Wake prompt',
		enableSorting: false,
		enableGlobalSearch: true,
		render: ( { item } ) => item.prompt || '—',
	},
];

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'next_wake_at', direction: 'asc' },
	fields: [ 'id', 'agent', 'trigger', 'next_wake_at', 'last_run', 'session_id' ],
};

function RoutinesApp() {
	const [ rows, setRows ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ asAvailable, setAsAvailable ] = useState( true );
	const [ substrateAvailable, setSubstrateAvailable ] = useState( true );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const pollInterval =
		( typeof window !== 'undefined' && window.openclaWPRoutines?.pollInterval ) || 5000;

	useEffect( () => {
		let cancelled = false;

		async function load() {
			try {
				const res = await apiFetch( { path: '/openclawp/v1/routines' } );
				if ( cancelled ) {
					return;
				}
				setRows( Array.isArray( res?.routines ) ? res.routines : [] );
				setAsAvailable( !! res?.action_scheduler );
				setSubstrateAvailable( ! res?.message );
			} catch ( err ) {
				if ( ! cancelled ) {
					setRows( [] );
				}
			} finally {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			}
		}

		load();
		const handle = window.setInterval( load, pollInterval );
		return () => {
			cancelled = true;
			window.clearInterval( handle );
		};
	}, [ pollInterval ] );

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view ]
	);

	if ( ! substrateAvailable ) {
		return (
			<div className="notice notice-warning">
				<p>
					Routines substrate isn&apos;t loaded. Update <code>agents-api</code> to a
					release that ships <code>WP_Agent_Routine</code> and{ ' ' }
					<code>wp_register_routine()</code>.
				</p>
			</div>
		);
	}

	if ( ! asAvailable ) {
		return (
			<div className="notice notice-warning">
				<p>
					Action Scheduler isn&apos;t loaded. Routines can be registered, but
					they won&apos;t fire on their schedule until AS is installed
					(<code>woocommerce/action-scheduler</code> via composer or any plugin
					that ships it).
				</p>
			</div>
		);
	}

	if ( ! isLoading && rows.length === 0 ) {
		return (
			<div className="notice notice-info">
				<p>
					No routines registered. Drop a{ ' ' }
					<code>wp_register_routine( 'my-routine', [ 'agent' =&gt; 'foo', 'interval' =&gt; 3600, 'prompt' =&gt; '...' ] )</code>{ ' ' }
					call into a plugin to see one show up here.
				</p>
			</div>
		);
	}

	return (
		<DataViews
			data={ data }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			paginationInfo={ paginationInfo }
			isLoading={ isLoading }
			defaultLayouts={ { table: {} } }
			search
			searchLabel="Search routines"
		/>
	);
}

function mount() {
	const root = document.getElementById( 'openclawp-routines-root' );
	if ( ! root ) {
		return;
	}

	const config = ( typeof window !== 'undefined' && window.openclaWPRoutines ) || {};
	if ( config.nonce && ! apiFetch._openclawpNonceAttached ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
		apiFetch._openclawpNonceAttached = true;
	}

	createRoot( root ).render( <RoutinesApp /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
