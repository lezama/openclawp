<?php
/**
 * Plugin Name: openclaWP demo runner
 * Description: Canned-response runner for the WordPress Playground demo. Hooks openclawp_pre_chat_turn to short-circuit the loop before it reaches wp_ai_client_prompt() (no AI provider is installed in Playground). Real abilities are invoked for matched questions, so the demo shows live WordPress data.
 * Version: 0.1.0
 * Author: openclaWP
 * License: GPL-2.0-or-later
 *
 * @package OpenclaWP_Demo
 */

defined( 'ABSPATH' ) || exit;

// Register the site-introspection agent + its read-only abilities.
add_filter( 'openclawp_register_site_introspection', '__return_true' );

// Open the REST + ability gates so anon visitors hitting an embedded chat
// block also work. Playground auto-logs admin, but published-post embeds
// can be hit unauthenticated.
add_filter( 'openclawp_rest_permission_callback', '__return_true' );
add_filter( 'openclawp_chat_ability_permission', '__return_true' );

/**
 * Canned preflight. Returning an array short-circuits the loop, so the
 * runner never reaches wp_ai_client_prompt() — which would error out in
 * Playground because no provider is installed.
 */
add_filter(
	'openclawp_pre_chat_turn',
	function ( $maybe_result, array $context ) {
		if ( null !== $maybe_result ) {
			return $maybe_result;
		}

		$message = strtolower( trim( (string) ( $context['message'] ?? '' ) ) );
		if ( '' === $message ) {
			return null;
		}

		$reply = openclawp_demo_canned_reply( $message );
		if ( null === $reply ) {
			$reply = "I'm running in demo mode — drop in a WP AI Client provider plugin (Ollama for free local inference, or Anthropic / OpenAI / Gemini) to talk to a real model. See the README for the recipe. Meanwhile, try **what's my latest post?**, **who am I?**, **how many pending comments?**, or **what plugins are active?** to see the agent call real WordPress abilities.";
		}

		return array(
			'reply'     => $reply,
			'completed' => true,
		);
	},
	10,
	2
);

function openclawp_demo_canned_reply( string $message ): ?string {
	// Recent / latest posts.
	if ( false !== strpos( $message, 'latest post' ) || false !== strpos( $message, 'recent post' ) || false !== strpos( $message, 'last post' ) ) {
		$ability = wp_get_ability( 'openclawp/get-recent-posts' );
		if ( null === $ability ) {
			return null;
		}
		$result = $ability->execute( array( 'limit' => 1 ) );
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['posts'] ) ) {
			return "I couldn't find any published posts yet.";
		}
		$post = $result['posts'][0];
		return sprintf(
			'Your latest post is **%s** (id %d), published %s.',
			(string) ( $post['title'] ?? '(no title)' ),
			(int) ( $post['id'] ?? 0 ),
			(string) ( $post['published_at'] ?? '?' )
		);
	}

	// Comment counts.
	if ( false !== strpos( $message, 'comment' ) ) {
		$ability = wp_get_ability( 'openclawp/count-comments' );
		if ( null === $ability ) {
			return null;
		}
		$result = $ability->execute( array() );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}
		return sprintf(
			'Comments — approved: %d, pending: %d, spam: %d, trash: %d (total: %d).',
			(int) ( $result['approved'] ?? 0 ),
			(int) ( $result['pending'] ?? 0 ),
			(int) ( $result['spam'] ?? 0 ),
			(int) ( $result['trash'] ?? 0 ),
			(int) ( $result['total'] ?? 0 )
		);
	}

	// Current user.
	if ( false !== strpos( $message, 'who am i' ) || false !== strpos( $message, 'current user' ) || false !== strpos( $message, 'my email' ) ) {
		$ability = wp_get_ability( 'openclawp/get-current-user' );
		if ( null === $ability ) {
			return null;
		}
		$result = $ability->execute( array() );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}
		$roles = (array) ( $result['roles'] ?? array() );
		return sprintf(
			'You are **%s** (id %d, login `%s`, email %s, role%s: %s).',
			(string) ( $result['display_name'] ?? $result['login'] ?? 'unknown' ),
			(int) ( $result['id'] ?? 0 ),
			(string) ( $result['login'] ?? '' ),
			(string) ( $result['email'] ?? '' ),
			count( $roles ) === 1 ? '' : 's',
			implode( ', ', array_map( 'strval', $roles ) )
		);
	}

	// Active plugins.
	if ( false !== strpos( $message, 'plugin' ) ) {
		$ability = wp_get_ability( 'openclawp/get-active-plugins' );
		if ( null === $ability ) {
			return null;
		}
		$result = $ability->execute( array() );
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['plugins'] ) ) {
			return null;
		}
		$names = array_map(
			static function ( $p ): string {
				if ( is_array( $p ) ) {
					return (string) ( $p['name'] ?? $p['slug'] ?? '?' );
				}
				return (string) $p;
			},
			$result['plugins']
		);
		return 'Active plugins: ' . implode( ', ', $names ) . '.';
	}

	// Greeting.
	if ( in_array( $message, array( 'hi', 'hello', 'hey', 'yo', 'sup' ), true ) ) {
		return "Hello! I'm running in **demo mode** — try _what's my latest post?_, _who am I?_, _how many pending comments?_, or _what plugins are active?_ to see the agent call real WordPress abilities.";
	}

	return null;
}

/**
 * Demo banner on the openclawp admin page.
 */
add_action(
	'admin_notices',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || false === strpos( (string) $screen->id, 'openclawp' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo '<strong>Demo mode.</strong> ';
		echo 'Responses come from a canned runner — no LLM is installed in Playground. ';
		echo 'Try <code>what&rsquo;s my latest post?</code>, <code>who am I?</code>, <code>how many pending comments?</code>, or <code>what plugins are active?</code> to see real abilities run. ';
		echo 'For a real model, see <a href="https://github.com/lezama/openclawp#readme">Path A / B in the README</a>.';
		echo '</p></div>';
	}
);
