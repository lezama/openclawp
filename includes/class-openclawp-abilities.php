<?php
/**
 * openclaWP-registered abilities.
 *
 * Two abilities are registered via `wp_register_ability()`:
 *
 *   - `openclawp/echo`  — trivial smoke-test ability.
 *   - `openclawp/chat`  — the chat runner itself, exposed as a callable
 *                         primitive. Once registered, any consumer of the
 *                         WP Abilities API (MCP servers, Studio Code skills,
 *                         WP-CLI, other agents in tool-calling chains) can
 *                         drive an openclaWP chat without going through HTTP
 *                         REST. The HTTP route remains available for
 *                         browser-driven UIs.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'openclawp',
			array(
				'label'       => __( 'openclaWP', 'openclawp' ),
				'description' => __( 'Abilities exposed by the openclaWP plugin.', 'openclawp' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_echo_ability();
		self::register_chat_ability();

		/**
		 * Whether to register the bundled loop-demo fixtures
		 * (`openclawp/get-time` ability + `openclawp-loop-demo` agent).
		 *
		 * Off by default. Used by smoke tests and the local Studio site to
		 * exercise the multi-turn loop with a real tool. Production installs
		 * should leave this off.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( apply_filters( 'openclawp_register_loop_demo', false ) ) {
			self::register_get_time_ability();
		}

		/**
		 * Whether to register the bundled site-introspection demo fixtures
		 * (`openclawp/get-recent-posts`, `openclawp/count-comments`,
		 * `openclawp/get-active-plugins`, `openclawp/get-current-user`
		 * abilities + `openclawp-site-introspection` agent).
		 *
		 * Off by default. Real consumers should register their own agents and
		 * abilities; this is fixture code that demonstrates a multi-tool agent
		 * answering questions about the live site.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( apply_filters( 'openclawp_register_site_introspection', false ) ) {
			OpenclaWP_Site_Abilities::register();
		}
	}

	private static function register_get_time_ability(): void {
		wp_register_ability(
			'openclawp/get-time',
			array(
				'label'               => __( 'Get current time', 'openclawp' ),
				'description'         => __( 'Returns the current server time in ISO 8601 (UTC). Call this whenever the user asks for the time, the current date, or how long ago something happened.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type' => 'object',
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'iso8601' => array(
							'type'        => 'string',
							'description' => 'Current time as an ISO 8601 UTC string.',
						),
						'unix'    => array(
							'type'        => 'integer',
							'description' => 'Current Unix timestamp.',
						),
					),
					'required'   => array( 'iso8601', 'unix' ),
				),
				'execute_callback'    => static function (): array {
					$now = time();
					return array(
						'iso8601' => gmdate( 'c', $now ),
						'unix'    => $now,
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function register_echo_ability(): void {
		wp_register_ability(
			'openclawp/echo',
			array(
				'label'            => __( 'Echo', 'openclawp' ),
				'description'      => __( 'Echoes the input back. Smoke-test ability.', 'openclawp' ),
				'category'         => 'openclawp',
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'text' => array(
							'type'        => 'string',
							'description' => 'The string to echo.',
						),
					),
					'required'   => array( 'text' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'echoed' => array(
							'type'        => 'string',
							'description' => 'The echoed string.',
						),
					),
					'required'   => array( 'echoed' ),
				),
				'execute_callback'    => static function ( array $args ): array {
					return array( 'echoed' => (string) ( $args['text'] ?? '' ) );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function register_chat_ability(): void {
		wp_register_ability(
			'openclawp/chat',
			array(
				'label'            => __( 'Chat with an openclaWP agent', 'openclawp' ),
				'description'      => __( 'Send one message to a registered agent and return its reply. Multi-turn sessions are supported by passing the returned session_id back on subsequent calls.', 'openclawp' ),
				'category'         => 'openclawp',
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'agent'      => array(
							'type'        => 'string',
							'description' => 'Slug of a registered agent (see wp_get_agents()).',
						),
						'message'    => array(
							'type'        => 'string',
							'description' => 'User message to send.',
						),
						'session_id' => array(
							'type'        => array( 'string', 'null' ),
							'description' => 'Existing openclaWP session UUID, or null/omitted to start a new session.',
						),
					),
					'required'   => array( 'agent', 'message' ),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'session_id' => array(
							'type'        => 'string',
							'description' => 'Session UUID — pass this back to continue the conversation.',
						),
						'reply'      => array(
							'type'        => 'string',
							'description' => 'The assistant\'s text reply.',
						),
						'completed'  => array(
							'type'        => 'boolean',
							'description' => 'Whether the loop reached natural completion.',
						),
					),
					'required'   => array( 'session_id', 'reply', 'completed' ),
				),
				'execute_callback'    => static function ( array $args ) {
					$agent      = (string) ( $args['agent'] ?? '' );
					$message    = (string) ( $args['message'] ?? '' );
					$session_id = isset( $args['session_id'] ) && is_string( $args['session_id'] )
						? $args['session_id']
						: null;

					if ( '' === $agent || '' === $message ) {
						return new WP_Error(
							'openclawp_chat_invalid',
							__( 'agent and message are required.', 'openclawp' ),
							array( 'status' => 400 )
						);
					}

					$result = OpenclaWP_Runner::run_turn(
						$agent,
						$message,
						$session_id,
						get_current_user_id()
					);

					if ( ! empty( $result['error'] ) ) {
						return new WP_Error( 'openclawp_chat_failed', (string) $result['error'], array( 'status' => 400 ) );
					}

					return array(
						'session_id' => (string) $result['session_id'],
						'reply'      => (string) $result['reply'],
						'completed'  => (bool) $result['completed'],
					);
				},
				'permission_callback' => static function (): bool {
					/**
					 * Filters whether the current user may invoke openclawp/chat via abilities.
					 *
					 * @param bool $allowed Default: manage_options.
					 */
					return (bool) apply_filters( 'openclawp_chat_ability_permission', current_user_can( 'manage_options' ) );
				},
			)
		);
	}
}
