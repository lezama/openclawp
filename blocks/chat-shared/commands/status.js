/**
 * `/status` — snapshot of the chat surface's runtime context.
 *
 * Fully offline: reads everything from `window.openclaWPConfig`, `window.location`,
 * and the agents array we already have in memory.
 */

export default {
	name: '/status',
	description: 'Show site, plugin, and agent context.',
	offline: true,
	handler: async ( args, ctx ) => {
		const config = ( typeof window !== 'undefined' && window.openclaWPConfig ) || {};
		const siteUrl =
			config.siteUrl ||
			( typeof window !== 'undefined' && window.location ? window.location.origin : 'unknown' );
		const version = config.version || 'unknown';
		const agentCount = Array.isArray( ctx.agents ) ? ctx.agents.length : 0;
		const agentId = ctx.agentId || 'none';

		const lines = [
			`- **Site:** ${ siteUrl }`,
			`- **Plugin version:** ${ version }`,
			`- **Registered agents:** ${ agentCount }`,
			`- **Current agent:** \`${ agentId }\``,
		];

		return {
			type: 'card',
			kind: 'info',
			title: 'openclaWP status',
			body: lines.join( '\n' ),
		};
	},
};
