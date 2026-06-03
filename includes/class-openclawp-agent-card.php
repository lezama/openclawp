<?php
/**
 * A2A Agent Card endpoint (discovery).
 *
 * Serves an A2A-shaped Agent Card for any registered agent at:
 *
 *     GET /openclawp/v1/agenttic/<agent-slug>/.well-known/agent-card.json
 *
 * The card is the discovery document a peer reads *before* opening a
 * conversation: it advertises the agent's name, the JSON-RPC endpoint the
 * {@see OpenclaWP_Agenttic_Bridge} exposes (`message/send` / `message/stream`),
 * the transport capabilities, and a list of skills derived from the agent's
 * configured tools and subagents. It is the missing half of the bridge — the
 * bridge could already *answer* A2A calls, but nothing told a peer the agent
 * existed or what it could do.
 *
 * The card mirrors the bridge's own permission gate (`manage_options`) by
 * default: an agent's description doubles as its system prompt, so a wide-open
 * card would leak the prompt and full tool inventory to anonymous callers, and
 * the card should never be more permissive than the endpoint it advertises.
 * Sites that want true public A2A discovery can opt in with the
 * `openclawp_agent_card_permission` filter (return `true`).
 *
 * Pairs with the A2A client side ({@see OpenclaWP_A2a_Client_Bridge}) which
 * reads these cards to call peers.
 *
 * @package OpenclaWP
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agent_Card {

	private const NAMESPACE = 'openclawp/v1';

	/**
	 * A2A protocol version advertised by the bridge. The agenttic-client wire
	 * (`message/send` + `message/stream`, Task envelopes) tracks A2A 0.2.x.
	 */
	private const PROTOCOL_VERSION = '0.2.5';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agenttic/(?P<agent>[A-Za-z0-9_\-]+)/\.well-known/agent-card\.json',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'serve' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'agent' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	public static function check_permission( WP_REST_Request $request ) {
		/**
		 * Filters whether the current request may read an agent card.
		 *
		 * Defaults to `manage_options`, matching the bridge endpoint the card
		 * describes — the card exposes the agent's system prompt and tool
		 * inventory. Return `true` to enable public A2A discovery.
		 *
		 * @since 0.7.0
		 *
		 * @param bool            $allowed Default: current_user_can( 'manage_options' ).
		 * @param WP_REST_Request $request Current request.
		 */
		return (bool) apply_filters( 'openclawp_agent_card_permission', current_user_can( 'manage_options' ), $request );
	}

	public static function serve( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'agent' );

		if ( ! function_exists( 'wp_get_agent' ) ) {
			return new WP_Error( 'openclawp_agents_api_missing', __( 'Agents API is not loaded.', 'openclawp' ), array( 'status' => 500 ) );
		}

		$agent = wp_get_agent( $slug );
		if ( null === $agent ) {
			return new WP_Error(
				'openclawp_agent_not_found',
				sprintf(
					/* translators: %s: agent slug. */
					__( 'No agent named "%s" is registered.', 'openclawp' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		$rpc_url  = rest_url( self::NAMESPACE . '/agenttic/' . $slug );
		$card_url = $rpc_url . '/.well-known/agent-card.json';

		$card = self::build_card_data( self::agent_to_descriptor( $agent ), $card_url, $rpc_url );

		return new WP_REST_Response( $card, 200 );
	}

	/**
	 * Flatten a WP_Agent into the plain descriptor the pure card builder
	 * consumes. Resolves each configured tool's label/description through the
	 * Abilities API and each subagent's label/description through the Agents
	 * registry so the card carries human-readable skills.
	 *
	 * @param WP_Agent $agent Registered agent.
	 *
	 * @return array{slug:string,label:string,description:string,tools:array<int,array{name:string,label:string,description:string}>,subagents:array<int,array{slug:string,label:string,description:string}>}
	 */
	private static function agent_to_descriptor( WP_Agent $agent ): array {
		$config    = method_exists( $agent, 'get_default_config' ) ? $agent->get_default_config() : array();
		$tool_names = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : array();
		$subagents  = method_exists( $agent, 'get_subagents' ) ? $agent->get_subagents() : array();

		$tools = array();
		foreach ( $tool_names as $ability_name ) {
			$ability_name = (string) $ability_name;
			$label        = $ability_name;
			$description  = '';
			if ( function_exists( 'wp_get_ability' ) ) {
				$ability = wp_get_ability( $ability_name );
				if ( null !== $ability ) {
					$label       = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $ability_name;
					$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
				}
			}
			$tools[] = array(
				'name'        => $ability_name,
				'label'       => $label,
				'description' => $description,
			);
		}

		$subagent_descriptors = array();
		foreach ( $subagents as $subagent_slug ) {
			$subagent_slug = (string) $subagent_slug;
			$label         = $subagent_slug;
			$description   = '';
			if ( function_exists( 'wp_get_agent' ) ) {
				$subagent = wp_get_agent( $subagent_slug );
				if ( null !== $subagent ) {
					$label       = method_exists( $subagent, 'get_label' ) ? (string) $subagent->get_label() : $subagent_slug;
					$description = method_exists( $subagent, 'get_description' ) ? (string) $subagent->get_description() : '';
				}
			}
			$subagent_descriptors[] = array(
				'slug'        => $subagent_slug,
				'label'       => $label,
				'description' => $description,
			);
		}

		return array(
			'slug'        => method_exists( $agent, 'get_slug' ) ? (string) $agent->get_slug() : '',
			'label'       => method_exists( $agent, 'get_label' ) ? (string) $agent->get_label() : '',
			'description' => method_exists( $agent, 'get_description' ) ? (string) $agent->get_description() : '',
			'tools'       => $tools,
			'subagents'   => $subagent_descriptors,
		);
	}

	/**
	 * Build the A2A Agent Card from a plain agent descriptor. Pure — no WP, no
	 * DB, no agent objects — so the card shape and skill derivation can be
	 * asserted without standing up WordPress.
	 *
	 * @param array<string,mixed> $agent    Agent descriptor (slug, label, description, tools[], subagents[]).
	 * @param string              $card_url Absolute URL this card is served from.
	 * @param string              $rpc_url  Absolute URL of the JSON-RPC message endpoint.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_card_data( array $agent, string $card_url, string $rpc_url ): array {
		$label = '' !== ( $agent['label'] ?? '' ) ? (string) $agent['label'] : (string) ( $agent['slug'] ?? '' );

		return array(
			'protocolVersion'    => self::PROTOCOL_VERSION,
			'name'               => $label,
			'description'        => (string) ( $agent['description'] ?? '' ),
			'url'                => $rpc_url,
			'preferredTransport' => 'JSONRPC',
			'version'            => defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : '0.0.0',
			'capabilities'       => array(
				// The bridge implements real SSE for message/stream; it does not
				// yet implement push notifications or task history (see the v0
				// scope in OpenclaWP_Agenttic_Bridge).
				'streaming'              => true,
				'pushNotifications'      => false,
				'stateTransitionHistory' => false,
			),
			'defaultInputModes'  => array( 'text/plain' ),
			'defaultOutputModes' => array( 'text/plain' ),
			'skills'             => self::derive_skills( $agent ),
			'provider'           => array(
				'organization' => 'openclaWP',
				'url'          => function_exists( 'home_url' ) ? home_url() : '',
			),
			'documentationUrl'   => $card_url,
		);
	}

	/**
	 * Derive A2A skills from an agent's tools and subagents. Every card carries
	 * at least one skill (a generic "chat" skill) so a peer always sees
	 * something actionable, even for a tool-less conversational agent.
	 *
	 * @param array<string,mixed> $agent Agent descriptor (slug, label, description, tools[], subagents[]).
	 *
	 * @return array<int,array{id:string,name:string,description:string,tags:array<int,string>}>
	 */
	private static function derive_skills( array $agent ): array {
		$skills = array();

		$tools = isset( $agent['tools'] ) && is_array( $agent['tools'] ) ? $agent['tools'] : array();
		foreach ( $tools as $tool ) {
			$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$skills[] = array(
				'id'          => $name,
				'name'        => '' !== ( $tool['label'] ?? '' ) ? (string) $tool['label'] : $name,
				'description' => (string) ( $tool['description'] ?? '' ),
				'tags'        => array( 'tool' ),
			);
		}

		$subagents = isset( $agent['subagents'] ) && is_array( $agent['subagents'] ) ? $agent['subagents'] : array();
		foreach ( $subagents as $subagent ) {
			$slug = isset( $subagent['slug'] ) ? (string) $subagent['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}
			$skills[] = array(
				'id'          => 'delegate-to-' . $slug,
				'name'        => '' !== ( $subagent['label'] ?? '' ) ? (string) $subagent['label'] : $slug,
				'description' => (string) ( $subagent['description'] ?? '' ),
				'tags'        => array( 'subagent', 'delegation' ),
			);
		}

		if ( empty( $skills ) ) {
			$skills[] = array(
				'id'          => 'chat',
				'name'        => 'Chat',
				'description' => 'Converse with this agent. No specialised tools advertised.',
				'tags'        => array( 'chat' ),
			);
		}

		return $skills;
	}
}
