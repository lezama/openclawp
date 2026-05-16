/**
 * Minimal ESLint config for the openclaWP chat block.
 *
 * Enforces `@wordpress/use-recommended-components` (introduced in
 * `@wordpress/eslint-plugin@25.1.0`, adopted monorepo-wide in Jetpack
 * PR #48487). The rule flags deprecated `@wordpress/components` exports
 * (`__experimentalHStack`, `__experimentalVStack`, `__experimentalText`,
 * `Card`, `VisuallyHidden`, `ExternalLink`, …) and recommends the stable
 * `@wordpress/ui` replacements. The chat surface itself stays on
 * `@automattic/agenttic-ui` — this rule covers the wp-admin chrome around
 * it (`SelectControl`, etc.) so we don't drift back onto `__experimental*`
 * exports as the React component grows.
 */

import wpPlugin from '@wordpress/eslint-plugin';

export default [
	{
		ignores: [
			'blocks/chat/build/**',
			'node_modules/**',
		],
	},
	{
		files: [ 'blocks/**/*.{js,jsx,ts,tsx}' ],
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		plugins: { '@wordpress': wpPlugin },
		rules: {
			'@wordpress/use-recommended-components': 'error',
		},
	},
];
