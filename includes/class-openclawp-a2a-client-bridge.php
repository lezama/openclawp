<?php
/**
 * Bridge remote A2A peer agents into the local ability registry.
 *
 * The A2A-client counterpart to {@see OpenclaWP_Mcp_Client_Bridge}. For every
 * configured peer it registers a WP ability named `a2a/<peer-slug>` whose
 * `execute_callback` opens an outbound `message/send` to that peer's bridge
 * endpoint (via {@see OpenclaWP_A2a_Client_Transport}) and returns the peer's
 * reply. That turns "ask another WordPress agent" into a tool any local agent
 * can call — the same shape as a bridged MCP tool or a subagent delegate.
 *
 * Peers are configured through the `openclawp_a2a_peers` filter rather than a
 * CPT/admin UI — this is the Phase 2 (code-first) substrate for the local
 * native-PHP mesh demo (two Studio sites delegating to each other). A peer
 * config is:
 *
 *     add_filter( 'openclawp_a2a_peers', function ( $peers ) {
 *         $peers['site-b'] = array(
 *             'label'       => 'Client Site B',
 *             'endpoint'    => 'https://site-b.test/wp-json/openclawp/v1/agenttic/openclawp-site-introspection',
 *             'headers'     => array( 'Authorization' => 'Bearer …' ), // optional
 *             'local_agent' => 'openclawp-coordinator',                 // optional; caller-context attribution
 *         );
 *         return $peers;
 *     } );
 *
 * @package OpenclaWP
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_A2a_Client_Bridge {

	public const ABILITY_PREFIX   = 'a2a/';
	public const ABILITY_CATEGORY = 'openclawp-a2a-peers';

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_bridged_abilities' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::ABILITY_CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::ABILITY_CATEGORY,
			array(
				'label'       => __( 'openclaWP A2A peers', 'openclawp' ),
				'description' => __( 'Remote agents reachable over the A2A protocol, registered as delegate tools.', 'openclawp' ),
			)
		);
	}

	/**
	 * Configured peers, keyed by slug. Read from the `openclawp_a2a_peers`
	 * filter. Each value is an array with at least an `endpoint`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function peers(): array {
		/**
		 * Filters the registered A2A peer agents.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string,array<string,mixed>> $peers Peer configs keyed by slug.
		 */
		/** @var mixed $peers A filter can return anything; normalise to an array. */
		$peers = apply_filters( 'openclawp_a2a_peers', array() );
		return is_array( $peers ) ? $peers : array();
	}

	public static function register_bridged_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$plan = self::plan_peer_list( self::peers() );
		foreach ( $plan as $entry ) {
			self::register_peer_ability( $entry );
		}
	}

	/**
	 * Reduce the raw peer config map into a validated list of ability plans.
	 * Pure-PHP — no registration, no HTTP — so tests can assert the prefix
	 * wiring and validation without standing up WordPress.
	 *
	 * Peers missing a slug or a usable endpoint URL are dropped.
	 *
	 * @param array<string,array<string,mixed>> $peers Raw peer config map.
	 *
	 * @return array<int,array{ability_name:string,slug:string,label:string,endpoint:string,headers:array<string,string>,local_agent:string}>
	 */
	public static function plan_peer_list( array $peers ): array {
		$plan = array();
		foreach ( $peers as $raw_slug => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$slug = sanitize_title( (string) $raw_slug );
			if ( '' === $slug ) {
				continue;
			}

			$endpoint = isset( $config['endpoint'] ) ? esc_url_raw( (string) $config['endpoint'] ) : '';
			if ( '' === $endpoint ) {
				continue;
			}

			$headers = array();
			if ( isset( $config['headers'] ) && is_array( $config['headers'] ) ) {
				foreach ( $config['headers'] as $name => $value ) {
					$headers[ (string) $name ] = (string) $value;
				}
			}

			$plan[] = array(
				'ability_name' => self::ABILITY_PREFIX . $slug,
				'slug'         => $slug,
				'label'        => isset( $config['label'] ) && '' !== (string) $config['label'] ? (string) $config['label'] : $slug,
				'endpoint'     => $endpoint,
				'headers'      => $headers,
				'local_agent'  => isset( $config['local_agent'] ) ? sanitize_title( (string) $config['local_agent'] ) : '',
			);
		}
		return $plan;
	}

	/**
	 * Register one peer as an `a2a/<slug>` ability.
	 *
	 * @param array{ability_name:string,slug:string,label:string,endpoint:string,headers:array<string,string>,local_agent:string} $entry Plan entry.
	 */
	private static function register_peer_ability( array $entry ): void {
		$ability_name = $entry['ability_name'];
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
			return;
		}

		$endpoint    = $entry['endpoint'];
		$headers     = $entry['headers'];
		$local_agent = $entry['local_agent'];
		$label       = $entry['label'];

		wp_register_ability(
			$ability_name,
			array(
				'label'               => sprintf(
					/* translators: %s: peer agent label. */
					__( 'Delegate to %s (A2A)', 'openclawp' ),
					$label
				),
				'description'         => sprintf(
					/* translators: %s: peer agent label. */
					__( 'Send a message to the remote agent "%s" over A2A and return its reply. Include all the context the remote agent needs in the prompt — it does not see this conversation.', 'openclawp' ),
					$label
				),
				'category'            => self::ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'prompt' ),
					'properties' => array(
						'prompt'     => array(
							'type'        => 'string',
							'description' => 'The message to send to the remote agent.',
						),
						'session_id' => array(
							'type'        => 'string',
							'description' => 'Optional remote session id to continue a prior exchange with this peer.',
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => static function ( array $args = array() ) use ( $endpoint, $headers, $local_agent ) {
					return self::execute_peer_call( $endpoint, $headers, $local_agent, $args );
				},
				'permission_callback' => static function (): bool {
					/**
					 * Filters whether the current user may invoke A2A peer tools.
					 *
					 * @since 0.7.0
					 *
					 * @param bool $allowed Default: manage_options.
					 */
					return (bool) apply_filters( 'openclawp_a2a_client_permission', current_user_can( 'manage_options' ) );
				},
				'meta'                => array(
					'effect' => class_exists( 'OpenclaWP_Tool_Effects' ) ? OpenclaWP_Tool_Effects::EFFECT_EXTERNAL : 'external',
				),
			)
		);
	}

	/**
	 * Execute a single peer call: forward the prompt over A2A and surface the
	 * reply. Errors come back as WP_Errors so the loop's tool-call mediator can
	 * degrade gracefully ("the remote agent is unavailable…").
	 *
	 * @param string               $endpoint    Peer JSON-RPC endpoint.
	 * @param array<string,string> $headers     Auth headers for the peer.
	 * @param string               $local_agent Caller agent slug for caller-context attribution.
	 * @param array<string,mixed>  $args        Tool-call arguments (`prompt`, optional `session_id`).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute_peer_call( string $endpoint, array $headers, string $local_agent, array $args ) {
		$prompt = isset( $args['prompt'] ) ? (string) $args['prompt'] : '';
		if ( '' === trim( $prompt ) ) {
			return new \WP_Error( 'a2a_client_empty_prompt', 'prompt is required' );
		}
		$session_id = isset( $args['session_id'] ) && '' !== (string) $args['session_id'] ? (string) $args['session_id'] : null;

		$caller = array(
			'agent'   => $local_agent,
			'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		);

		return OpenclaWP_A2a_Client_Transport::send_message( $endpoint, $prompt, $session_id, $caller, $headers );
	}
}
