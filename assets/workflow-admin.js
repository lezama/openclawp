/* global wp, openclaWPWorkflows */
( function () {
	'use strict';

	function api( path, opts ) {
		return wp.apiFetch(
			Object.assign(
				{
					path: '/' + openclaWPWorkflows.restNamespace + path,
					headers: { 'X-WP-Nonce': openclaWPWorkflows.nonce },
				},
				opts || {}
			)
		);
	}

	function escape( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function fmtAgo( ts ) {
		if ( ! ts ) return '—';
		const d = Math.max( 0, Math.floor( Date.now() / 1000 ) - ts );
		if ( d < 60 ) return d + 's ago';
		if ( d < 3600 ) return Math.floor( d / 60 ) + 'm ago';
		if ( d < 86400 ) return Math.floor( d / 3600 ) + 'h ago';
		return Math.floor( d / 86400 ) + 'd ago';
	}

	function statusBadge( status ) {
		const cls = {
			succeeded: 'is-succeeded',
			failed: 'is-failed',
			running: 'is-running',
			pending: 'is-pending',
			skipped: 'is-skipped',
		}[ status ] || 'is-unknown';
		return '<span class="openclawp-status ' + cls + '">' + escape( status || 'unknown' ) + '</span>';
	}

	// ─── List view ────────────────────────────────────────────────────

	async function renderList() {
		const root = document.getElementById( 'openclawp-workflow-list' );
		if ( ! root ) return;
		try {
			const data = await api( '/workflows' );
			if ( ! data.workflows.length ) {
				root.innerHTML =
					'<div class="notice notice-info inline"><p>' +
					'No workflows registered yet. Plugins register workflows via <code>wp_register_workflow()</code>; once registered they appear here.' +
					'</p></div>';
				return;
			}
			let html = '<table class="widefat striped openclawp-workflow-table"><thead><tr>' +
				'<th>ID</th><th>Source</th><th>Version</th><th>Steps</th><th>Inputs</th><th></th>' +
				'</tr></thead><tbody>';
			data.workflows.forEach( function ( w ) {
				const detailUrl = openclaWPWorkflows.listUrl + '&workflow=' + encodeURIComponent( w.id );
				html += '<tr>' +
					'<td><a href="' + escape( detailUrl ) + '"><strong>' + escape( w.id ) + '</strong></a></td>' +
					'<td>' + escape( w.source ) + '</td>' +
					'<td>' + escape( w.version ) + '</td>' +
					'<td>' + escape( w.steps ) + '</td>' +
					'<td>' + ( w.inputs.length ? escape( w.inputs.join( ', ' ) ) : '<em>none</em>' ) + '</td>' +
					'<td><a class="button" href="' + escape( detailUrl ) + '">Open</a></td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			root.innerHTML = html;
		} catch ( e ) {
			root.innerHTML = '<div class="notice notice-error inline"><p>' + escape( e.message || 'failed to load workflows' ) + '</p></div>';
		}
	}

	// ─── Detail view ──────────────────────────────────────────────────

	async function renderDetail() {
		const root = document.getElementById( 'openclawp-workflow-detail' );
		if ( ! root ) return;

		const id = root.dataset.workflowId;
		try {
			const [ spec, runs ] = await Promise.all( [
				api( '/workflow?id=' + encodeURIComponent( id ) ),
				api( '/workflow-runs?workflow_id=' + encodeURIComponent( id ) + '&limit=10' ),
			] );

			root.innerHTML = renderDetailBody( spec, runs.runs );
			wireDetailEvents( root, id );
		} catch ( e ) {
			if ( e.code === 'unknown_workflow' ) {
				root.innerHTML = '<div class="notice notice-error inline"><p>Workflow not found.</p></div>';
				return;
			}
			root.innerHTML = '<div class="notice notice-error inline"><p>' + escape( e.message || 'failed to load workflow' ) + '</p></div>';
		}
	}

	function renderDetailBody( spec, runs ) {
		const inputsHtml = renderInputsForm( spec );

		const runsHtml = runs.length
			? '<table class="widefat striped"><thead><tr>' +
				'<th>Status</th><th>Started</th><th>Run ID</th><th></th>' +
				'</tr></thead><tbody>' +
				runs.map( function ( r ) {
					return '<tr>' +
						'<td>' + statusBadge( r.status ) + '</td>' +
						'<td>' + escape( fmtAgo( r.started_at ) ) + '</td>' +
						'<td><code>' + escape( r.run_id ) + '</code></td>' +
						'<td><button type="button" class="button-link openclawp-show-run" data-run-id="' + escape( r.run_id ) + '">view</button></td>' +
						'</tr>';
				} ).join( '' ) +
				'</tbody></table>'
			: '<p><em>No runs yet.</em></p>';

		return '<div class="openclawp-workflow-detail__columns">' +
				'<section class="card">' +
					'<h2>Run now</h2>' +
					'<form id="openclawp-workflow-run-form">' +
						inputsHtml +
						'<p class="submit">' +
							'<button type="submit" class="button button-primary">Run workflow</button>' +
							'<span class="openclawp-workflow-run-status" aria-live="polite"></span>' +
						'</p>' +
					'</form>' +
				'</section>' +
				'<section class="card">' +
					'<h2>Spec</h2>' +
					'<pre class="openclawp-spec-pre">' + escape( JSON.stringify( spec.spec, null, 2 ) ) + '</pre>' +
				'</section>' +
			'</div>' +
			'<section class="card">' +
				'<h2>Recent runs</h2>' +
				runsHtml +
				'<div id="openclawp-run-detail" class="openclawp-run-detail"></div>' +
			'</section>';
	}

	function renderInputsForm( spec ) {
		const inputs = spec.inputs || {};
		const names = Object.keys( inputs );
		if ( ! names.length ) {
			return '<p><em>No inputs.</em></p>';
		}
		return names.map( function ( name ) {
			const schema = inputs[ name ] || {};
			const required = schema.required ? ' <span class="required">*</span>' : '';
			return '<p><label for="wf-input-' + escape( name ) + '"><strong>' + escape( name ) + '</strong>' + required + '</label><br/>' +
				'<input type="text" id="wf-input-' + escape( name ) + '" name="' + escape( name ) + '" class="regular-text"' +
				( schema.required ? ' required' : '' ) + ' />' +
				( schema.description ? '<br/><span class="description">' + escape( schema.description ) + '</span>' : '' ) +
				'</p>';
		} ).join( '' );
	}

	function wireDetailEvents( root, workflowId ) {
		const form = root.querySelector( '#openclawp-workflow-run-form' );
		if ( form ) {
			form.addEventListener( 'submit', async function ( ev ) {
				ev.preventDefault();
				const status = form.querySelector( '.openclawp-workflow-run-status' );
				const inputs = {};
				form.querySelectorAll( 'input[name]' ).forEach( function ( el ) {
					inputs[ el.name ] = el.value;
				} );
				status.textContent = 'Running…';
				try {
					const res = await api( '/workflow/run?id=' + encodeURIComponent( workflowId ), {
						method: 'POST',
						data: { inputs: inputs },
					} );
					status.textContent = res.status === 'succeeded'
						? '✓ ' + res.run_id + ' (' + res.status + ')'
						: '✗ ' + res.run_id + ' (' + res.status + ')';
					// Re-render after a run completes.
					setTimeout( renderDetail, 500 );
				} catch ( e ) {
					status.textContent = '✗ ' + ( e.message || 'failed' );
				}
			} );
		}

		root.querySelectorAll( '.openclawp-show-run' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', async function () {
				const runId = btn.dataset.runId;
				const detail = root.querySelector( '#openclawp-run-detail' );
				if ( ! detail ) return;
				detail.innerHTML = '<p>Loading run…</p>';
				try {
					const run = await api( '/workflow-run?run_id=' + encodeURIComponent( runId ) );
					detail.innerHTML = renderRunDetail( run );
				} catch ( e ) {
					detail.innerHTML = '<div class="notice notice-error inline"><p>' + escape( e.message || 'failed' ) + '</p></div>';
				}
			} );
		} );
	}

	function renderRunDetail( run ) {
		const stepsHtml = ( run.steps || [] ).map( function ( s ) {
			return '<details class="openclawp-step">' +
				'<summary>' + escape( s.id ) + ' — ' + statusBadge( s.status ) + ' (' + escape( s.type ) + ')</summary>' +
				'<pre>' + escape( JSON.stringify( { output: s.output, error: s.error || null }, null, 2 ) ) + '</pre>' +
				'</details>';
		} ).join( '' );

		return '<h3>Run <code>' + escape( run.run_id ) + '</code> — ' + statusBadge( run.status ) + '</h3>' +
			( run.error && run.error.code
				? '<div class="notice notice-error inline"><p><strong>' + escape( run.error.code ) + ':</strong> ' + escape( run.error.message || '' ) + '</p></div>'
				: '' ) +
			'<h4>Inputs</h4>' +
			'<pre>' + escape( JSON.stringify( run.inputs || {}, null, 2 ) ) + '</pre>' +
			'<h4>Steps</h4>' +
			( stepsHtml || '<p><em>No steps recorded.</em></p>' ) +
			'<h4>Output</h4>' +
			'<pre>' + escape( JSON.stringify( run.output || {}, null, 2 ) ) + '</pre>';
	}

	// ─── Create-with-AI view ─────────────────────────────────────────

	async function renderCreate() {
		const root = document.getElementById( 'openclawp-workflow-create' );
		if ( ! root ) return;

		root.innerHTML =
			'<section class="card">' +
				'<h2>Describe what you want the workflow to do</h2>' +
				'<p class="description">' +
					'One paragraph in plain English. Mention <em>when</em> it should fire (a WordPress action like <code>comment_post</code>, a schedule, or on-demand) and <em>what</em> it should do (which abilities or agents to involve). The drafter knows your registered abilities and agents and will pick real slugs.' +
				'</p>' +
				'<textarea id="openclawp-create-prompt" class="large-text" rows="6" placeholder="e.g. Every time a new comment is posted, classify it as spam or ham and write the verdict to the workflow log."></textarea>' +
				'<p class="submit">' +
					'<button type="button" class="button button-primary" id="openclawp-draft-btn">Draft with AI</button>' +
					'<span id="openclawp-draft-status" class="openclawp-workflow-run-status" aria-live="polite"></span>' +
				'</p>' +
			'</section>' +
			'<div id="openclawp-draft-result"></div>';

		document.getElementById( 'openclawp-draft-btn' ).addEventListener( 'click', onDraftClick );
	}

	async function onDraftClick() {
		const promptEl = document.getElementById( 'openclawp-create-prompt' );
		const status   = document.getElementById( 'openclawp-draft-status' );
		const result   = document.getElementById( 'openclawp-draft-result' );
		const btn      = document.getElementById( 'openclawp-draft-btn' );

		const prompt = ( promptEl.value || '' ).trim();
		if ( ! prompt ) {
			status.textContent = '✗ Empty prompt.';
			return;
		}

		btn.disabled = true;
		status.textContent = 'Drafting… (~5–15s)';
		result.innerHTML = '';

		try {
			const draft = await api( '/workflow/draft', {
				method: 'POST',
				data: { prompt: prompt },
			} );
			status.textContent = '✓ Drafted.';
			result.innerHTML = renderDraftResult( draft );
			wireSaveButton( draft.spec );
		} catch ( e ) {
			let msg = e.message || 'Draft failed';
			if ( e.data && e.data.errors ) {
				msg += ' — ' + e.data.errors.map( function ( er ) { return er.message; } ).join( '; ' );
			}
			status.textContent = '✗ ' + msg;
		} finally {
			btn.disabled = false;
		}
	}

	function renderDraftResult( draft ) {
		return '<section class="card">' +
				'<h2>Drafted spec</h2>' +
				( draft.explanation
					? '<p>' + escape( draft.explanation ) + '</p>'
					: '' ) +
				'<pre class="openclawp-spec-pre">' + escape( JSON.stringify( draft.spec, null, 2 ) ) + '</pre>' +
				'<p class="submit">' +
					'<button type="button" class="button button-primary" id="openclawp-save-btn">Save & enable</button>' +
					'<span id="openclawp-save-status" class="openclawp-workflow-run-status" aria-live="polite"></span>' +
				'</p>' +
			'</section>';
	}

	function wireSaveButton( spec ) {
		const btn    = document.getElementById( 'openclawp-save-btn' );
		const status = document.getElementById( 'openclawp-save-status' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', async function () {
			btn.disabled = true;
			status.textContent = 'Saving…';
			try {
				const res = await api( '/workflow', {
					method: 'POST',
					data: { spec: spec },
				} );
				status.textContent = '✓ Saved as ' + res.id + ' — opening detail…';
				setTimeout( function () {
					window.location.href = openclaWPWorkflows.listUrl + '&workflow=' + encodeURIComponent( res.id );
				}, 600 );
			} catch ( e ) {
				status.textContent = '✗ ' + ( e.message || 'Save failed' );
				btn.disabled = false;
			}
		} );
	}

	// ─── Boot ──────────────────────────────────────────────────────────

	function start() {
		if ( 'new' === openclaWPWorkflows.action ) {
			renderCreate();
		} else if ( openclaWPWorkflows.activeId ) {
			renderDetail();
		} else {
			renderList();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
