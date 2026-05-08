<?php
/**
 * Optional example agent.
 *
 * openclaWP intentionally ships zero default agents. A plugin that wants the
 * smallest possible smoke-test agent for development can opt in:
 *
 *     add_filter( 'openclawp_register_example_agent', '__return_true' );
 *
 * Production installs and any plugin shipping a real agent should leave this
 * filter alone and register agents directly on `wp_agents_api_init` (defined
 * by `agents-api`).
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agent_Registrar {

	public const EXAMPLE_AGENT_SLUG  = 'openclawp-example';
	public const DRAFTER_AGENT_SLUG  = 'openclawp-workflow-drafter';

	public static function register(): void {
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_example_agent' ), 10 );
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_loop_demo_agent' ), 10 );
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_site_introspection_agent' ), 10 );
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_workflow_drafter_agent' ), 10 );
	}

	public static function maybe_register_site_introspection_agent(): void {
		// Reuses the same opt-in filter as the site-introspection abilities so they
		// ship together — registering the agent without the abilities would be useless.
		if ( ! apply_filters( 'openclawp_register_site_introspection', false ) ) {
			return;
		}

		wp_register_agent(
			'openclawp-site-introspection',
			array(
				'label'          => __( 'openclaWP Site Introspection', 'openclawp' ),
				'description'    => __(
					'You are a helpful assistant that answers questions about this WordPress site. You have read-only access to four tools: openclawp__get-recent-posts (recent published posts), openclawp__count-comments (comment moderation totals), openclawp__get-active-plugins (currently active plugins), and openclawp__get-current-user (the human you are talking to). Always call the relevant tool before answering a factual question — never guess. Quote tool output values directly. Be concise.',
					'openclawp'
				),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider'  => 'auto',
					'model'     => 'claude-haiku-4-5',
					'tools'     => array(
						'openclawp/get-recent-posts',
						'openclawp/count-comments',
						'openclawp/get-active-plugins',
						'openclawp/get-current-user',
					),
					'max_turns' => 6,
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'site-introspection-demo-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}

	public static function maybe_register_loop_demo_agent(): void {
		// Reuses the same opt-in filter as the get_time ability so they ship
		// together — there's no point in registering one without the other.
		if ( ! apply_filters( 'openclawp_register_loop_demo', false ) ) {
			return;
		}

		wp_register_agent(
			'openclawp-loop-demo',
			array(
				'label'          => __( 'openclaWP Loop Demo', 'openclawp' ),
				'description'    => __(
					'You are a precise assistant. You have access to one tool: openclawp__get-time, which returns the current time. When the user asks for the time, the current date, or anything time-related, you MUST call openclawp__get-time first and use its result in your reply. Never guess the time.',
					'openclawp'
				),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider'  => 'auto',
					'model'     => 'claude-haiku-4-5',
					'tools'     => array( 'openclawp/get-time' ),
					'max_turns' => 5,
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'loop-demo-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}

	public static function maybe_register_example_agent(): void {
		/**
		 * Whether to register the bundled example agent (`openclawp-example`).
		 *
		 * Off by default. Production installs should leave this off and register
		 * real agents directly on `wp_agents_api_init`.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! apply_filters( 'openclawp_register_example_agent', false ) ) {
			return;
		}

		wp_register_agent(
			self::EXAMPLE_AGENT_SLUG,
			array(
				'label'          => __( 'openclaWP Example', 'openclawp' ),
				'description'    => __( 'Bundled example agent for smoke-testing openclaWP. Opt in via the openclawp_register_example_agent filter.', 'openclawp' ),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider' => 'auto',
					'model'    => 'claude-haiku-4-5',
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'example-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}

	/**
	 * Workflow drafter agent. Translates a user's natural-language
	 * description into a valid workflow spec for the agents-api Workflows
	 * substrate. Auto-pulled in when the workflow surface is loaded
	 * (see {@see OpenclaWP_Workflow_Bootstrap::register()}); admins can
	 * customise the system prompt by replacing the agent registration via
	 * `openclawp_workflow_drafter_agent_args` filter or by registering a
	 * different agent and pointing the drafter REST handler at it.
	 */
	public static function maybe_register_workflow_drafter_agent(): void {
		/**
		 * Whether to register the bundled `openclawp-workflow-drafter`
		 * agent. Default true so the LLM-driven authoring flow under
		 * *openclaWP → Workflows → New* works out of the box.
		 *
		 * @since 0.4.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'openclawp_register_workflow_drafter', true ) ) {
			return;
		}

		wp_register_agent(
			self::DRAFTER_AGENT_SLUG,
			array(
				'label'          => __( 'openclaWP Workflow Drafter', 'openclawp' ),
				'description'    => self::workflow_drafter_system_prompt(),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider' => 'auto',
					'model'    => 'claude-haiku-4-5',
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'workflow-drafter',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}

	/**
	 * System prompt for the workflow drafter. Static contract documentation;
	 * the dynamic site discovery (registered abilities + agents) is appended
	 * to the user message at call time so it stays current without
	 * re-registering the agent.
	 */
	public static function workflow_drafter_system_prompt(): string {
		return <<<PROMPT
You are a WordPress workflow author. Your job is to translate a one-paragraph description into a valid workflow spec for the openclaWP / agents-api Workflows substrate.

Spec contract (JSON shape):
{
  "id":      "<my-namespace>/<workflow-id>",
  "version": "1.0.0",
  "inputs":  { "<name>": { "type": "string"|"integer"|"boolean", "required": true|false, "description": "..." } },
  "steps": [
    { "id": "<step-id>", "type": "ability", "ability": "<ability-slug>", "args": { ... } },
    { "id": "<step-id>", "type": "agent",   "agent":   "<agent-slug>",   "message": "...prompt for the LLM..." }
  ],
  "triggers": [
    { "type": "on_demand" },
    { "type": "wp_action", "hook": "<wp-action-name>" },
    { "type": "cron",      "interval": <seconds> }
  ],
  "meta": { "source_plugin": "openclawp/openclawp.php", "source_type": "user-drafted" }
}

Step types:
- `ability` — invokes a deterministic Abilities API ability. Use for read/write operations against WordPress, services, or external systems.
- `agent`   — calls an LLM via agents/chat. Use for reasoning, classification, summarization, decision-making.

Bindings (template syntax inside `args` / `message`):
- `\${inputs.<name>}`                      — pulls from the workflow input
- `\${steps.<step-id>.output.<dot.path>}` — pulls from a previous step's output

Rules:
1. Always include at least one step.
2. Always include a `meta` object with `source_plugin` and `source_type` keys.
3. If the user asks "every time X happens" use a `wp_action` trigger; "every N minutes/hours" → `cron`; otherwise `on_demand`.
4. Prefer ability steps over agent steps when the operation is deterministic. Agent steps are for reasoning, not data fetching.
5. Do not invent ability or agent slugs. Use the lists the caller provides as runtime context. If no fit, leave a placeholder slug (e.g. `my-plugin/my-ability`) and call it out in the explanation.

Worked example. User says: *When a new comment is posted, classify it for spam and notify me if it's spam.*

```json
{
  "id": "demo/spam-classify",
  "version": "1.0.0",
  "inputs": { "comment_id": { "type": "integer", "required": true } },
  "steps": [
    {
      "id": "classify",
      "type": "agent",
      "agent": "openclawp-site-introspection",
      "message": "Decide whether comment \${inputs.comment_id} is spam. Return JSON {\\"is_spam\\": true|false, \\"reason\\": \\"...\\"}."
    }
  ],
  "triggers": [
    { "type": "wp_action", "hook": "comment_post" }
  ],
  "meta": { "source_plugin": "openclawp/openclawp.php", "source_type": "user-drafted" }
}
```

Output format: respond with **the JSON spec inside a single ```json code fence**, then a short (one-paragraph) plain-English explanation **outside** the fence. Do not include any other prose before the code fence.
PROMPT;
	}
}
