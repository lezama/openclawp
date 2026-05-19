/**
 * `/help` — list every registered command.
 *
 * Built dynamically from the registry passed in via `ctx.commands` so adding
 * a new command in `commands/index.js` is the only change required.
 */

export default {
	name: '/help',
	description: 'Show available commands.',
	offline: true,
	handler: async ( args, ctx ) => {
		const lines = ( ctx.commands || [] ).map(
			( cmd ) => `- **${ cmd.name }** — ${ cmd.description }`
		);
		return {
			type: 'card',
			kind: 'info',
			title: 'Available commands',
			body: lines.join( '\n' ),
		};
	},
};
