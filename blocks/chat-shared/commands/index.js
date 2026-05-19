/**
 * Slash command registry for openclaWP's chat surface.
 *
 * Each command is a small module exporting `{ name, description, offline,
 * handler }`. The handler returns a Card (see `Card.jsx`) or null. Detection
 * happens in `ChatSurface.jsx` before the message reaches `useAgentChat`, so
 * offline commands never round-trip to the LLM.
 *
 * Add a command by:
 *   1. Drop a new file in this directory exporting the shape above.
 *   2. Import + push it into the array below.
 */

import help from './help.js';
import clear from './clear.js';
import reset from './reset.js';
import status from './status.js';
import tools from './tools.js';

export const COMMANDS = [ help, clear, reset, status, tools ];

/**
 * Look up a command by its leading token, case-insensitive.
 *
 * The argument may carry a leading slash or not — both `find('/help')` and
 * `find('help')` resolve.
 *
 * @param {string} token First whitespace-delimited token of the user input.
 * @return {object|null} Matching command, or null when nothing matches.
 */
export function findCommand( token ) {
	if ( ! token ) {
		return null;
	}
	const normalized = token.startsWith( '/' )
		? token.toLowerCase()
		: '/' + token.toLowerCase();
	return COMMANDS.find( ( cmd ) => cmd.name.toLowerCase() === normalized ) || null;
}

/**
 * Parse a raw chat input into a `{ command, args }` pair.
 *
 * Returns null when the input isn't a slash command (no leading `/` or
 * only whitespace after it).
 *
 * @param {string} input Raw user input.
 * @return {{ command: object|null, name: string, args: string }|null}
 */
export function parseInput( input ) {
	if ( typeof input !== 'string' ) {
		return null;
	}
	const trimmed = input.trim();
	if ( ! trimmed.startsWith( '/' ) ) {
		return null;
	}
	const firstSpace = trimmed.indexOf( ' ' );
	const name = firstSpace === -1 ? trimmed : trimmed.slice( 0, firstSpace );
	const args = firstSpace === -1 ? '' : trimmed.slice( firstSpace + 1 ).trim();
	return {
		command: findCommand( name ),
		name,
		args,
	};
}
