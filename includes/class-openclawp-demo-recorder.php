<?php
/**
 * Demo recording abilities and workflow.
 *
 * OpenclaWP does not run browsers or shell commands inside WordPress. The
 * plugin creates a deterministic recording plan and can hand it to a local
 * recorder service over HTTP. That keeps Atomic installs safe while letting
 * Studio/local demos record video and optional voice-over.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers demo recording abilities and a workflow wrapper.
 */
final class OpenclaWP_Demo_Recorder {

	public const CREATE_PLAN_ABILITY  = 'openclawp/create-demo-recording-plan';
	public const RECORD_VIDEO_ABILITY = 'openclawp/record-demo-video';
	public const WORKFLOW_ID          = 'openclawp/record-agency-demo';
	public const OPTION_ENDPOINT      = 'openclawp_demo_recorder_endpoint';

	/**
	 * Wire ability and workflow registration hooks.
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_workflow' ) );
	}

	/**
	 * Register deterministic demo recording abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( ! apply_filters( 'openclawp_register_demo_recorder_abilities', true ) ) {
			return;
		}

		self::register_ability(
			self::CREATE_PLAN_ABILITY,
			array(
				'label'               => __( 'Create demo recording plan', 'openclawp' ),
				'description'         => __( 'Build a storyboard, browser action plan, captions, and voice-over script for an agency automation demo.', 'openclawp' ),
				'input_schema'        => self::plan_input_schema(),
				'execute_callback'    => static fn ( array $args ): array => self::create_plan( $args ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);

		self::register_ability(
			self::RECORD_VIDEO_ABILITY,
			array(
				'label'               => __( 'Record demo video', 'openclawp' ),
				'description'         => __( 'Send a demo recording plan to a configured local recorder endpoint. The recorder may create video, captions, and voice-over artifacts.', 'openclawp' ),
				'input_schema'        => self::record_input_schema(),
				'execute_callback'    => static fn ( array $args ) => self::record_video( $args ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_EXTERNAL ),
			)
		);
	}

	/**
	 * Register the on-demand workflow that creates and records a demo plan.
	 */
	public static function maybe_register_workflow(): void {
		if ( ! apply_filters( 'openclawp_register_demo_recorder_workflow', true ) ) {
			return;
		}
		if ( ! function_exists( 'wp_register_workflow' ) ) {
			return;
		}
		if ( function_exists( 'wp_get_workflow' ) && null !== wp_get_workflow( self::WORKFLOW_ID ) ) {
			return;
		}

		wp_register_workflow(
			array(
				'id'       => self::WORKFLOW_ID,
				'version'  => '1.0.0',
				'inputs'   => array(
					'recorder_endpoint' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'Local recorder HTTP endpoint, for example http://127.0.0.1:8765/record.',
					),
					'site_url'          => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Client site URL to show at the start of the recording.',
					),
					'login_url'         => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Optional pre-authentication URL for local Studio demos.',
					),
					'client_name'       => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Client name for captions and narration.',
					),
					'industry'          => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Client industry for captions and narration.',
					),
					'blueprint'         => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Agency automation blueprint slug.',
					),
					'agent_slug'        => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Agent slug to select in the chat proof step.',
					),
					'prompt'            => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Prompt to run during the chat proof step.',
					),
					'wait_for_text'     => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Optional text the recorder should wait for after the chat proof prompt.',
					),
					'voice_enabled'     => array(
						'type'        => 'boolean',
						'required'    => false,
						'description' => 'Whether the local recorder should try to synthesize narration.',
					),
				),
				'steps'    => array(
					array(
						'id'      => 'plan',
						'type'    => 'ability',
						'ability' => self::CREATE_PLAN_ABILITY,
						'args'    => array(
							'site_url'      => '${inputs.site_url}',
							'login_url'     => '${inputs.login_url}',
							'client_name'   => '${inputs.client_name}',
							'industry'      => '${inputs.industry}',
							'blueprint'     => '${inputs.blueprint}',
							'agent_slug'    => '${inputs.agent_slug}',
							'prompt'        => '${inputs.prompt}',
							'wait_for_text' => '${inputs.wait_for_text}',
							'voice'         => array(
								'enabled' => '${inputs.voice_enabled}',
							),
						),
					),
					array(
						'id'      => 'record',
						'type'    => 'ability',
						'ability' => self::RECORD_VIDEO_ABILITY,
						'args'    => array(
							'endpoint' => '${inputs.recorder_endpoint}',
							'plan'     => '${steps.plan.output}',
						),
					),
				),
				'triggers' => array(
					array( 'type' => 'on_demand' ),
				),
				'meta'     => array(
					'source_plugin' => 'openclawp/openclawp.php',
					'source_type'   => 'demo-recorder-workflow',
				),
			)
		);
	}

	/**
	 * Check whether the current user can run demo recorder abilities.
	 */
	public static function can_manage(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Create a browser recording plan.
	 *
	 * @param array<string,mixed> $args Plan creation arguments.
	 * @return array<string,mixed>
	 */
	public static function create_plan( array $args = array() ): array {
		$site_url   = self::site_url( $args );
		$agency_url = self::admin_page_url( 'openclawp-agency', (string) ( $args['agency_url'] ?? '' ), $site_url );
		$chat_url   = self::admin_page_url( 'openclawp', (string) ( $args['chat_url'] ?? '' ), $site_url );
		$login_url  = self::clean_url( (string) ( $args['login_url'] ?? '' ) );

		$client_name = self::clean_text( (string) ( $args['client_name'] ?? self::default_site_name() ) );
		if ( '' === $client_name ) {
			$client_name = 'Client';
		}
		$industry  = self::clean_text( (string) ( $args['industry'] ?? 'client services' ) );
		$blueprint = sanitize_key( (string) ( $args['blueprint'] ?? 'booking-agent' ) );
		if ( '' === $blueprint ) {
			$blueprint = 'booking-agent';
		}
		$agent_slug    = sanitize_key( (string) ( $args['agent_slug'] ?? 'openclawp-studio-demo' ) );
		$prompt        = self::clean_textarea( (string) ( $args['prompt'] ?? 'what is my latest post?' ) );
		$wait_for_text = self::clean_textarea( (string) ( $args['wait_for_text'] ?? '' ) );
		$basename      = sanitize_title( (string) ( $args['basename'] ?? 'openclawp-agency-demo' ) );
		if ( '' === $basename ) {
			$basename = 'openclawp-agency-demo';
		}

		$format = sanitize_key( (string) ( $args['format'] ?? 'mp4' ) );
		if ( ! in_array( $format, array( 'mp4', 'webm' ), true ) ) {
			$format = 'mp4';
		}

		$voice = self::voice_config( $args['voice'] ?? array() );
		$steps = self::agency_sales_steps(
			array(
				'site_url'      => $site_url,
				'agency_url'    => $agency_url,
				'chat_url'      => $chat_url,
				'client_name'   => $client_name,
				'industry'      => $industry,
				'blueprint'     => $blueprint,
				'agent_slug'    => $agent_slug,
				'prompt'        => $prompt,
				'wait_for_text' => $wait_for_text,
			)
		);

		$plan = array(
			'schema_version' => '1.0.0',
			'scenario'       => sanitize_key( (string) ( $args['scenario'] ?? 'agency-sales-demo-v1' ) ),
			'title'          => sprintf( '%s automation demo', $client_name ),
			'summary'        => sprintf(
				'Show how an agency can turn the existing %s WordPress site into a client-specific automation proposal and working agent demo.',
				$client_name
			),
			'created_at'     => gmdate( 'c' ),
			'site_url'       => $site_url,
			'auth'           => array(
				'mode'      => '' === $login_url ? 'pre_authenticated_browser' : 'login_url',
				'login_url' => $login_url,
			),
			'recording'      => array(
				'viewport'                   => array(
					'width'  => self::bounded_int( $args['viewport_width'] ?? 1440, 800, 2560 ),
					'height' => self::bounded_int( $args['viewport_height'] ?? 1000, 600, 1800 ),
				),
				'format'                     => $format,
				'output_basename'            => $basename,
				'estimated_duration_seconds' => 70,
			),
			'voice'          => $voice,
			'inputs'         => array(
				'client_name'   => $client_name,
				'industry'      => $industry,
				'blueprint'     => $blueprint,
				'agent_slug'    => $agent_slug,
				'prompt'        => $prompt,
				'wait_for_text' => $wait_for_text,
			),
			'workflow'       => array(
				'id'    => self::WORKFLOW_ID,
				'steps' => array( 'create_plan', 'record_video' ),
			),
			'steps'          => $steps,
		);

		/**
		 * Filters the generated browser recording plan.
		 *
		 * @param array<string,mixed> $plan
		 * @param array<string,mixed> $args
		 */
		return (array) apply_filters( 'openclawp_demo_recording_plan', $plan, $args );
	}

	/**
	 * Send a recording plan to a local recorder endpoint.
	 *
	 * @param array<string,mixed> $args Recording arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function record_video( array $args ) {
		$endpoint = self::clean_url( (string) ( $args['endpoint'] ?? '' ) );
		if ( '' === $endpoint ) {
			$endpoint = self::clean_url( (string) get_option( self::OPTION_ENDPOINT, '' ) );
		}
		if ( '' === $endpoint ) {
			return new WP_Error(
				'openclawp_demo_recorder_endpoint_missing',
				__( 'No demo recorder endpoint was provided.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return new WP_Error(
				'openclawp_demo_recorder_http_unavailable',
				__( 'WordPress HTTP API is unavailable.', 'openclawp' ),
				array( 'status' => 500 )
			);
		}

		$plan = isset( $args['plan'] ) && is_array( $args['plan'] )
			? self::sanitize_plan_for_transport( $args['plan'] )
			: self::create_plan( $args );

		$async = self::bool_arg( $args['async'] ?? true, true );

		$response = wp_remote_post(
			$endpoint,
			array(
				'blocking' => ! $async,
				'timeout'  => self::bounded_int( $args['timeout'] ?? 120, 5, 600 ),
				'headers'  => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'     => wp_json_encode(
					array(
						'source'       => 'openclawp',
						'requested_at' => gmdate( 'c' ),
						'async'        => $async,
						'plan'         => $plan,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $async ) {
			return array(
				'accepted'      => true,
				'async'         => true,
				'response_code' => 0,
				'endpoint'      => $endpoint,
				'plan'          => $plan,
				'recorder'      => array( 'status' => 'queued' ),
			);
		}

		$status = function_exists( 'wp_remote_retrieve_response_code' )
			? (int) wp_remote_retrieve_response_code( $response )
			: (int) ( $response['response']['code'] ?? 0 );
		$body   = function_exists( 'wp_remote_retrieve_body' )
			? (string) wp_remote_retrieve_body( $response )
			: (string) ( $response['body'] ?? '' );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'openclawp_demo_recorder_failed',
				__( 'Demo recorder endpoint returned an error.', 'openclawp' ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}

		$decoded = json_decode( $body, true );
		return array(
			'accepted'      => true,
			'response_code' => $status,
			'endpoint'      => $endpoint,
			'plan'          => $plan,
			'recorder'      => is_array( $decoded ) ? $decoded : array( 'raw_body' => $body ),
		);
	}

	/**
	 * Register an ability unless another plugin already registered it.
	 *
	 * @param string              $name Ability name.
	 * @param array<string,mixed> $args Ability registration arguments.
	 */
	private static function register_ability( string $name, array $args ): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		$args['category']      = $args['category'] ?? 'openclawp';
		$args['output_schema'] = $args['output_schema'] ?? array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		wp_register_ability( $name, $args );
	}

	/**
	 * Build the create-plan ability input schema.
	 */
	private static function plan_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'scenario'        => array( 'type' => 'string' ),
				'site_url'        => array( 'type' => 'string' ),
				'agency_url'      => array( 'type' => 'string' ),
				'chat_url'        => array( 'type' => 'string' ),
				'login_url'       => array( 'type' => 'string' ),
				'client_name'     => array( 'type' => 'string' ),
				'industry'        => array( 'type' => 'string' ),
				'blueprint'       => array( 'type' => 'string' ),
				'agent_slug'      => array( 'type' => 'string' ),
				'prompt'          => array( 'type' => 'string' ),
				'basename'        => array( 'type' => 'string' ),
				'format'          => array(
					'type' => 'string',
					'enum' => array( 'mp4', 'webm' ),
				),
				'viewport_width'  => array( 'type' => 'integer' ),
				'viewport_height' => array( 'type' => 'integer' ),
				'wait_for_text'   => array( 'type' => 'string' ),
				'voice'           => array(
					'type'       => 'object',
					'properties' => array(
						'enabled'  => array( 'type' => 'boolean' ),
						'mode'     => array(
							'type' => 'string',
							'enum' => array( 'auto', 'local-tts', 'script-only' ),
						),
						'voice'    => array( 'type' => 'string' ),
						'rate_wpm' => array( 'type' => 'integer' ),
						'captions' => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}

	/**
	 * Build the record-video ability input schema.
	 */
	private static function record_input_schema(): array {
		$schema                           = self::plan_input_schema();
		$schema['properties']['endpoint'] = array( 'type' => 'string' );
		$schema['properties']['timeout']  = array( 'type' => 'integer' );
		$schema['properties']['async']    = array(
			'type'    => 'boolean',
			'default' => true,
		);
		$schema['properties']['plan']     = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		return $schema;
	}

	/**
	 * Build the default agency sales demo browser steps.
	 *
	 * @param array<string,mixed> $data Normalized plan input data.
	 * @return array<int,array<string,mixed>>
	 */
	private static function agency_sales_steps( array $data ): array {
		$client_name = (string) $data['client_name'];
		$industry    = (string) $data['industry'];
		$blueprint   = (string) $data['blueprint'];
		$prompt      = (string) $data['prompt'];

		return array(
			array(
				'id'          => 'existing-site',
				'type'        => 'navigate',
				'url'         => (string) $data['site_url'],
				'caption'     => array(
					'title' => 'Start with the existing client site',
					'body'  => sprintf( 'The agency does not rebuild WordPress. openclaWP uses signals already present on %s: pages, offers, forms, booking language, and support content.', $client_name ),
				),
				'narration'   => sprintf( 'Start with the existing %s WordPress site. The agency can use the client context already present on the site instead of rebuilding from scratch.', $client_name ),
				'duration_ms' => 3500,
			),
			array(
				'id'          => 'agency-audit',
				'type'        => 'navigate',
				'url'         => (string) $data['agency_url'],
				'caption'     => array(
					'title' => 'Turn site signals into automation opportunities',
					'body'  => 'The Agency audit ranks concrete services an agency can sell: lead concierge, quote intake, booking, support deflection, ecommerce recovery, and maintenance reporting.',
				),
				'narration'   => 'openclaWP audits the WordPress site and turns those signals into practical automation offers the agency can discuss with the client.',
				'highlight'   => '.widefat.striped',
				'duration_ms' => 4200,
			),
			array(
				'id'          => 'generate-package',
				'type'        => 'form',
				'url'         => (string) $data['agency_url'],
				'caption'     => array(
					'title' => 'Generate the client-specific demo package',
					'body'  => sprintf( 'For %s, choose the %s blueprint, add client context, mark available connectors, and generate proposal assets for the sales call.', $client_name, $blueprint ),
				),
				'narration'   => sprintf( 'Now generate a client-specific package for %s, a %s business. The package separates the sales demo from the production integration work.', $client_name, $industry ),
				'duration_ms' => 1600,
				'actions'     => array(
					array(
						'type'        => 'highlight',
						'selector'    => '#openclawp-agency-blueprint',
						'duration_ms' => 700,
						'optional'    => true,
					),
					array(
						'type'     => 'select',
						'selector' => '#openclawp-agency-blueprint',
						'value'    => $blueprint,
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-client-name',
						'value'    => $client_name,
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-site-url',
						'value'    => (string) $data['site_url'],
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-industry',
						'value'    => $industry,
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-summary',
						'value'    => sprintf( '%s has an existing WordPress site and wants to automate intake, routing, and follow-up without replacing the site.', $client_name ),
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-goals',
						'value'    => 'qualify requests, reduce manual follow-up, prepare handoff',
						'optional' => true,
					),
					array(
						'type'     => 'check',
						'selector' => 'input[name="connectors[]"][value="email"]',
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-channels',
						'value'    => 'site-chat, email',
						'optional' => true,
					),
					array(
						'type'     => 'fill',
						'selector' => '#openclawp-agency-answers',
						'value'    => "bookable_services: consultations and follow-up appointments\navailability_rules: weekdays, 9am to 5pm, no same-day bookings\ncalendar_destination: client calendar",
						'optional' => true,
					),
					array(
						'type'                => 'click',
						'role'                => 'button',
						'name'                => 'Generate demo package',
						'wait_for_navigation' => true,
						'optional'            => true,
					),
					array(
						'type'     => 'scroll_to_text',
						'text'     => 'Recent generated demos',
						'optional' => true,
					),
					array(
						'type'        => 'wait',
						'duration_ms' => 2200,
					),
				),
			),
			array(
				'id'            => 'chat-proof',
				'type'          => 'chat',
				'url'           => (string) $data['chat_url'],
				'caption'       => array(
					'title' => 'Prove it with a live agent run',
					'body'  => 'The same install can call real WordPress abilities. The demo closes with a chat proof instead of a static deck.',
				),
				'narration'     => 'The proposal is only half the story. The same openclaWP install can run a live agent that calls WordPress tools and answers from site data.',
				'duration_ms'   => 2200,
				'agent_slug'    => (string) $data['agent_slug'],
				'prompt'        => $prompt,
				'selectors'     => array(
					'root'   => '#openclawp-chat-root',
					'agent'  => '#openclawp-chat-root select',
					'input'  => '#openclawp-chat-root textarea[aria-label="Chat input"]',
					'send'   => '#openclawp-chat-root button[aria-label="Send message"]',
					'answer' => '',
				),
				'wait_for_text' => (string) $data['wait_for_text'],
			),
			array(
				'id'          => 'takeaway',
				'type'        => 'caption',
				'caption'     => array(
					'title' => 'Agency takeaway',
					'body'  => 'From an existing WordPress site to a client-specific automation proposal and working agent demo in minutes.',
				),
				'narration'   => 'The agency takeaway is simple: openclaWP turns an existing client site into a concrete automation proposal and a working demo in minutes.',
				'duration_ms' => 4200,
			),
		);
	}

	/**
	 * Normalize voice-over options.
	 *
	 * @param mixed $value Raw voice config.
	 * @return array<string,mixed>
	 */
	private static function voice_config( $value ): array {
		$input = is_array( $value ) ? $value : array();
		$mode  = sanitize_key( (string) ( $input['mode'] ?? 'auto' ) );
		if ( ! in_array( $mode, array( 'auto', 'local-tts', 'script-only' ), true ) ) {
			$mode = 'auto';
		}

		return array(
			'enabled'  => self::bool_arg( $input['enabled'] ?? true, true ),
			'mode'     => $mode,
			'voice'    => self::clean_text( (string) ( $input['voice'] ?? 'Samantha' ) ),
			'rate_wpm' => self::bounded_int( $input['rate_wpm'] ?? 155, 110, 210 ),
			'captions' => self::bool_arg( $input['captions'] ?? true, true ),
			'script'   => array(
				'format' => 'plain-text',
				'notes'  => 'The local recorder writes a narration script and tries local TTS when available.',
			),
		);
	}

	/**
	 * Resolve the site URL for a recording plan.
	 *
	 * @param array<string,mixed> $args Plan arguments.
	 */
	private static function site_url( array $args ): string {
		$site_url = self::clean_url( (string) ( $args['site_url'] ?? '' ) );
		if ( '' !== $site_url ) {
			return self::trailing_slash( $site_url );
		}
		if ( function_exists( 'home_url' ) ) {
			return self::trailing_slash( self::clean_url( (string) home_url( '/' ) ) );
		}
		return 'http://example.test/';
	}

	/**
	 * Resolve a wp-admin page URL.
	 *
	 * @param string $page Admin page slug.
	 * @param string $override Explicit URL override.
	 * @param string $site_url Site URL fallback.
	 */
	private static function admin_page_url( string $page, string $override, string $site_url ): string {
		$url = self::clean_url( $override );
		if ( '' !== $url ) {
			return $url;
		}
		if ( function_exists( 'admin_url' ) ) {
			return self::clean_url( (string) admin_url( 'admin.php?page=' . $page ) );
		}
		return self::trailing_slash( $site_url ) . 'wp-admin/admin.php?page=' . rawurlencode( $page );
	}

	/**
	 * Resolve the current site name without requiring WordPress in tests.
	 */
	private static function default_site_name(): string {
		return function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Client';
	}

	/**
	 * Sanitize and allow only HTTP(S) URLs.
	 *
	 * @param string $url Raw URL.
	 */
	private static function clean_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( function_exists( 'esc_url_raw' ) ) {
			$url = esc_url_raw( $url );
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * Sanitize a one-line text field.
	 *
	 * @param string $value Raw text.
	 */
	private static function clean_text( string $value ): string {
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}

	/**
	 * Sanitize a multi-line text field.
	 *
	 * @param string $value Raw text.
	 */
	private static function clean_textarea( string $value ): string {
		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : trim( strip_tags( $value ) );
	}

	/**
	 * Coerce user input to a boolean.
	 *
	 * @param mixed $value Raw boolean-like value.
	 * @param bool  $fallback Default value when input is ambiguous.
	 */
	private static function bool_arg( $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
				return false;
			}
		}
		return $fallback;
	}

	/**
	 * Bound an integer to an inclusive range.
	 *
	 * @param mixed $value Raw integer-like value.
	 * @param int   $min Minimum accepted value.
	 * @param int   $max Maximum accepted value.
	 */
	private static function bounded_int( $value, int $min, int $max ): int {
		$value = (int) $value;
		if ( $value < $min ) {
			return $min;
		}
		if ( $value > $max ) {
			return $max;
		}
		return $value;
	}

	/**
	 * Add one trailing slash to a URL.
	 *
	 * @param string $url URL.
	 */
	private static function trailing_slash( string $url ): string {
		return rtrim( $url, '/' ) . '/';
	}

	/**
	 * Normalize a generated plan before transport.
	 *
	 * @param array<string,mixed> $plan Generated plan.
	 * @return array<string,mixed>
	 */
	private static function sanitize_plan_for_transport( array $plan ): array {
		$encoded = wp_json_encode( $plan );
		$decoded = json_decode( false === $encoded ? '{}' : $encoded, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
