/**
 * `/clear` — drop the active conversation.
 *
 * Purely client-side: we wipe the localStorage session key that
 * `useAgentChat` uses to persist the session id (one key per agent), then
 * tell the parent surface to re-mount the chat hook so it starts a fresh
 * session on the next message.
 */

export default {
	name: '/clear',
	description: 'Clear the active conversation.',
	offline: true,
	handler: async ( args, ctx ) => {
		if ( typeof ctx.clearLocalSession === 'function' ) {
			ctx.clearLocalSession();
		}
		return {
			type: 'card',
			kind: 'success',
			title: 'Conversation cleared.',
			body: 'Starting a fresh session on your next message.',
		};
	},
};
