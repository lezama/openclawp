/**
 * Tasks DataView entry.
 *
 * Mounted onto `#openclawp-tasks-root` rendered by
 * `OpenclaWP_Tasks_Admin::render_page()`. Reads from the openclaWP
 * REST endpoint, projects each Action Scheduler row into a DataView
 * row, and lets `<DataViews>` handle filtering / sorting / pagination.
 *
 * v0 is read-only: no row actions yet (cancel / retry / run-now). They
 * land alongside the matching REST endpoints and a permission story.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { dateI18n } from '@wordpress/date';
import { createRoot } from 'react-dom/client';

const STATUS_OPTIONS = [
	{ value: 'pending', label: 'Pending' },
	{ value: 'in-progress', label: 'In progress' },
	{ value: 'complete', label: 'Complete' },
	{ value: 'failed', label: 'Failed' },
	{ value: 'canceled', label: 'Canceled' },
];

const GROUP_OPTIONS = [
	{ value: 'agents-api', label: 'agents-api' },
	{ value: 'openclawp', label: 'openclawp' },
];

const fields = [
	{
		id: 'id',
		label: 'ID',
		enableSorting: true,
		enableGlobalSearch: false,
		render: ( { item } ) => `#${ item.id }`,
	},
	{
		id: 'hook',
		label: 'Hook',
		enableSorting: true,
		enableGlobalSearch: true,
	},
	{
		id: 'group',
		label: 'Group',
		elements: GROUP_OPTIONS,
		filterBy: { operators: [ 'is', 'isAny' ] },
	},
	{
		id: 'status',
		label: 'Status',
		elements: STATUS_OPTIONS,
		filterBy: { operators: [ 'is', 'isAny' ] },
	},
	{
		id: 'scheduled_at',
		label: 'Scheduled',
		enableSorting: true,
		render: ( { item } ) => {
			if ( ! item.scheduled_at ) {
				return '—';
			}
			return dateI18n( 'M j, Y H:i', item.scheduled_iso );
		},
	},
	{
		id: 'recurring',
		label: 'Recurring',
		render: ( { item } ) =>
			item.recurring && item.interval_s
				? `every ${ item.interval_s }s`
				: '—',
	},
	{
		id: 'args',
		label: 'Args',
		enableSorting: false,
		enableGlobalSearch: false,
		render: ( { item } ) => {
			const keys = Object.keys( item.args || {} );
			if ( keys.length === 0 ) {
				return '—';
			}
			// Show the first key=value pair; full args show in a future
			// detail drawer.
			const first = keys[ 0 ];
			return `${ first }=${ String( item.args[ first ] ) }`;
		},
	},
];

const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: { field: 'scheduled_at', direction: 'desc' },
	fields: [ 'id', 'hook', 'group', 'status', 'scheduled_at', 'recurring', 'args' ],
};

function TasksApp() {
	const [ rows, setRows ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ asAvailable, setAsAvailable ] = useState( true );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const pollInterval =
		( typeof window !== 'undefined' && window.openclaWPTasks?.pollInterval ) || 5000;

	useEffect( () => {
		let cancelled = false;

		async function load() {
			try {
				const res = await apiFetch( { path: '/openclawp/v1/tasks' } );
				if ( cancelled ) {
					return;
				}
				setRows( Array.isArray( res?.tasks ) ? res.tasks : [] );
				setAsAvailable( !! res?.action_scheduler );
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

	if ( ! asAvailable ) {
		return (
			<div className="notice notice-warning">
				<p>
					Action Scheduler isn&apos;t loaded. Cron-triggered workflows and
					queued tasks need it — install it via composer
					(<code>woocommerce/action-scheduler</code>) or activate
					WooCommerce.
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
			searchLabel="Search tasks"
		/>
	);
}

function mount() {
	const root = document.getElementById( 'openclawp-tasks-root' );
	if ( ! root ) {
		return;
	}

	const config = ( typeof window !== 'undefined' && window.openclaWPTasks ) || {};
	if ( config.nonce && ! apiFetch._openclawpNonceAttached ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
		apiFetch._openclawpNonceAttached = true;
	}

	createRoot( root ).render( <TasksApp /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
