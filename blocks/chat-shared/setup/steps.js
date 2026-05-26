/**
 * First-run setup wizard — step definitions.
 *
 * Each step describes one screen of the wizard:
 *
 *   {
 *     id: string,
 *     title: string,
 *     buildBody:    (state) => string,                     // light markdown
 *     buildActions: (state, helpers) => Array<CardAction>, // see below
 *     kindFor?:     (state) => 'info' | 'success' | 'warning',
 *   }
 *
 * Card actions in the wizard use an `onClick` property that
 * `ChatSurface.handleCardAction` dispatches when present. This is in addition
 * to (not a replacement for) the `command` / `href` properties supported by
 * the existing Card primitive — wizard cards just never use those.
 *
 * `helpers` is the bag passed by `buildSetupCardStack`:
 *
 *   - `advance( id )`           — replace the wizard card with the next step.
 *   - `refresh()`               — re-fetch `/setup/state` and re-render.
 *   - `finish()`                — POST `/setup/complete` and close the wizard.
 *   - `setExampleAgent( bool )` — POST `/setup/enable-example-agent`.
 *
 * Step IDs map 1:1 to the PHP wizard's `?step=` values so the two surfaces
 * stay easy to reason about together.
 */

export const STEP_WELCOME = 'welcome';
export const STEP_PROVIDER = 'provider';
export const STEP_AGENT = 'agent';

export const FIRST_STEP_ID = STEP_WELCOME;

/**
 * Format the providers list as a markdown body. Installed providers are
 * tagged inline so the warning vs. success case stays visually clear.
 *
 * @param {Array<{slug:string,label:string,installed:boolean}>} providers Providers list.
 * @return {string} Markdown body lines.
 */
function renderProviderList( providers ) {
	if ( ! providers || providers.length === 0 ) {
		return '_No AI providers detected yet._';
	}
	return providers
		.map( ( p ) =>
			p.installed
				? `- **${ p.label }** — installed`
				: `- ${ p.label }`
		)
		.join( '\n' );
}

export const STEPS = [
	{
		id: STEP_WELCOME,
		title: 'Welcome to openclaWP',
		buildBody: () =>
			'openclaWP turns your WordPress site into a place where an agent lives, ' +
			'reads your content, talks back, and can be reached from outside ' +
			'WordPress through pluggable connectors. This 3-step wizard gets you ' +
			'to a working chat in under a minute.',
		buildActions: ( state, helpers ) => [
			{
				label: 'Get started',
				variant: 'primary',
				onClick: () => helpers.advance( STEP_PROVIDER ),
			},
			{
				label: 'Skip setup',
				variant: 'tertiary',
				onClick: () => helpers.finish(),
			},
		],
	},
	{
		id: STEP_PROVIDER,
		title: 'Pick an AI provider',
		buildBody: ( state ) => {
			const providers = Array.isArray( state.providers ) ? state.providers : [];
			const anyInstalled = providers.some( ( p ) => p.installed );
			const intro =
				"openclaWP works with any AI provider that registers with the WordPress " +
				"AI client. Pick one to continue — if you don't have one installed yet, " +
				'the easiest path is Ollama (runs locally, no API key).';
			const list = renderProviderList( providers );
			const footer = anyInstalled
				? ''
				: '\n\n_No AI provider plugin detected yet. Install one and click **Recheck**._';
			return intro + '\n\n' + list + footer;
		},
		buildActions: ( state, helpers ) => {
			const providers = Array.isArray( state.providers ) ? state.providers : [];
			const anyInstalled = providers.some( ( p ) => p.installed );

			const actions = [];
			if ( anyInstalled ) {
				actions.push( {
					label: 'Continue',
					variant: 'primary',
					onClick: () => helpers.advance( STEP_AGENT ),
				} );
			} else {
				// No "Continue" until a provider is installed. Surfacing only
				// "Recheck" keeps the wizard honest about the prerequisite
				// without us needing a disabled-button affordance.
				actions.push( {
					label: 'Recheck',
					variant: 'primary',
					onClick: () => helpers.refresh(),
				} );
			}
			actions.push( {
				label: 'Skip setup',
				variant: 'tertiary',
				onClick: () => helpers.finish(),
			} );
			return actions;
		},
		kindFor: ( state ) => {
			const providers = Array.isArray( state.providers ) ? state.providers : [];
			return providers.some( ( p ) => p.installed ) ? 'info' : 'warning';
		},
	},
	{
		id: STEP_AGENT,
		title: 'Enable the example agent',
		buildBody: () =>
			"Turn on the bundled example agent so there's something to chat with " +
			'right away. You can register your own agents later via PHP, or via ' +
			'the Agent Files surface.',
		buildActions: ( state, helpers ) => [
			{
				label: 'Enable example agent & finish',
				variant: 'primary',
				onClick: async () => {
					await helpers.setExampleAgent( true );
					await helpers.finish();
				},
			},
			{
				label: 'Finish without example agent',
				variant: 'secondary',
				onClick: async () => {
					await helpers.setExampleAgent( false );
					await helpers.finish();
				},
			},
		],
	},
];

/**
 * Look up a step by id.
 *
 * @param {string} id Step id.
 * @return {object|null}
 */
export function findStep( id ) {
	return STEPS.find( ( s ) => s.id === id ) || null;
}
