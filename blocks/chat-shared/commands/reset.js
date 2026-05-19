/**
 * `/reset` — `/clear` plus clear local one-shot UI dismissal flags.
 *
 * The Discover panel's own "I dismissed this" state lives in user-meta and is
 * managed server-side (see `includes/class-openclawp-admin.php`); this
 * command intentionally stays client-side and only clears the parallel
 * localStorage flag we use for transient panels and future intro
 * affordances. Users who want to restore the server-side Discover panel can
 * use the "Restore" link in wp-admin.
 */

const DISCOVER_DISMISSED_KEY = 'openclawp:discover-dismissed';

export default {
	name: '/reset',
	description: 'Clear the conversation and restore the Discover panel.',
	offline: true,
	handler: async ( args, ctx ) => {
		if ( typeof ctx.clearLocalSession === 'function' ) {
			ctx.clearLocalSession();
		}
		try {
			window.localStorage.removeItem( DISCOVER_DISMISSED_KEY );
		} catch ( e ) {
			// localStorage may be disabled — the conversation reset above is
			// the load-bearing part of /reset, so swallow.
		}
		return {
			type: 'card',
			kind: 'success',
			title: 'Reset done.',
			body: 'Conversation cleared. The Discover panel will reappear on the next session.',
		};
	},
};
