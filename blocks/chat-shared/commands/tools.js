/**
 * `/tools` — inspect tools available to the current agent.
 *
 * There is no dedicated `GET /agents/<slug>/tools` endpoint yet, so this
 * command falls back to a static informational card pointing users at the
 * Tool activity surface in wp-admin. When the endpoint lands, swap the body
 * for the fetched data.
 */

export default {
	name: '/tools',
	description: 'List tools available to the current agent.',
	offline: true,
	handler: async ( args, ctx ) => {
		const agentLabel = ctx.agentId ? `\`${ ctx.agentId }\`` : 'the current agent';
		return {
			type: 'card',
			kind: 'info',
			title: 'Tool inspection coming soon',
			body:
				`Per-agent tool inspection isn't wired up yet for ${ agentLabel }. ` +
				'In the meantime, see **Tool activity** in wp-admin for a log of recent tool calls.',
		};
	},
};
