<?php
/**
 * Seeded custom-tool templates.
 *
 * Static catalog of starter specs admins can spin up from the New tool
 * wizard. Each entry returns a full `spec` array compatible with
 * `OpenclaWP_Custom_Tools_Store::normalise_spec()` plus a default label,
 * slug, and description suggestion.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Custom_Tools_Templates {

	/**
	 * Return the catalog. Keyed by template id.
	 *
	 * @return array<string, array{
	 *   label:string,
	 *   slug:string,
	 *   description:string,
	 *   summary:string,
	 *   spec:array
	 * }>
	 */
	public static function all(): array {
		return array(
			'slack-webhook'  => array(
				'label'       => __( 'Notify on Slack', 'openclawp' ),
				'slug'        => 'slack-notify',
				'description' => __( 'Post a message to a Slack channel through an Incoming Webhook. Call this when the user asks to "tell #channel", "ping me on Slack", or to send a quick notification.', 'openclawp' ),
				'summary'     => __( 'Slack Incoming Webhook (per-channel URL stored as a WP option).', 'openclawp' ),
				'spec'        => array(
					'type'          => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
					'input_schema'  => array(
						'type'       => 'object',
						'properties' => array(
							'text' => array(
								'type'        => 'string',
								'description' => 'Message body. Slack mrkdwn formatting is supported.',
							),
						),
						'required'   => array( 'text' ),
					),
					'http'          => array(
						'method'    => 'POST',
						// Replace this with the actual incoming-webhook URL on save.
						'url'       => 'https://hooks.slack.com/services/REPLACE/WITH/YOUR_WEBHOOK',
						'headers'   => array(),
						'body_type' => 'json',
						'body'      => '{"text": "{{text}}"}',
					),
					'auth'          => array( 'mode' => OpenclaWP_Custom_Tools_Store::AUTH_NONE ),
					'effect'        => OpenclaWP_Custom_Tools_Store::EFFECT_WRITE,
					'output'        => array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_RAW ),
					'allowed_roles' => array( 'administrator' ),
				),
			),

			'weather-lookup' => array(
				'label'       => __( 'Current weather', 'openclawp' ),
				'slug'        => 'weather-current',
				'description' => __( 'Look up the current weather for a latitude/longitude using the Open-Meteo public API. Returns temperature in Celsius.', 'openclawp' ),
				'summary'     => __( 'Open-Meteo public API — no auth required.', 'openclawp' ),
				'spec'        => array(
					'type'          => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
					'input_schema'  => array(
						'type'       => 'object',
						'properties' => array(
							'latitude'  => array(
								'type'        => 'number',
								'description' => 'Latitude in decimal degrees.',
							),
							'longitude' => array(
								'type'        => 'number',
								'description' => 'Longitude in decimal degrees.',
							),
						),
						'required'   => array( 'latitude', 'longitude' ),
					),
					'http'          => array(
						'method'    => 'GET',
						'url'       => 'https://api.open-meteo.com/v1/forecast?latitude={{latitude}}&longitude={{longitude}}&current_weather=true',
						'headers'   => array(),
						'body_type' => 'none',
						'body'      => '',
					),
					'auth'          => array( 'mode' => OpenclaWP_Custom_Tools_Store::AUTH_NONE ),
					'effect'        => OpenclaWP_Custom_Tools_Store::EFFECT_READ,
					'output'        => array(
						'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH,
						'path' => '$.current_weather',
					),
					'allowed_roles' => array( 'administrator' ),
				),
			),

			'github-issue'   => array(
				'label'       => __( 'Create GitHub issue', 'openclawp' ),
				'slug'        => 'github-issue-create',
				'description' => __( 'Open a new issue in a GitHub repository. Requires a personal access token stored as a WP option.', 'openclawp' ),
				'summary'     => __( 'GitHub REST API. Token is read from a WP option — never from agent input.', 'openclawp' ),
				'spec'        => array(
					'type'          => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
					'input_schema'  => array(
						'type'       => 'object',
						'properties' => array(
							'owner' => array(
								'type'        => 'string',
								'description' => 'GitHub repo owner (user or org).',
							),
							'repo'  => array(
								'type'        => 'string',
								'description' => 'GitHub repo name.',
							),
							'title' => array(
								'type'        => 'string',
								'description' => 'Issue title.',
							),
							'body'  => array(
								'type'        => 'string',
								'description' => 'Issue body (markdown supported).',
							),
						),
						'required'   => array( 'owner', 'repo', 'title' ),
					),
					'http'          => array(
						'method'    => 'POST',
						'url'       => 'https://api.github.com/repos/{{owner}}/{{repo}}/issues',
						'headers'   => array(
							'Accept'               => 'application/vnd.github+json',
							'X-GitHub-Api-Version' => '2022-11-28',
						),
						'body_type' => 'json',
						'body'      => '{"title": "{{title}}", "body": "{{body}}"}',
					),
					'auth'          => array(
						'mode'         => OpenclaWP_Custom_Tools_Store::AUTH_BEARER,
						'token_option' => 'openclawp_github_token',
					),
					'effect'        => OpenclaWP_Custom_Tools_Store::EFFECT_WRITE,
					'output'        => array(
						'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH,
						'path' => '$.html_url',
					),
					'allowed_roles' => array( 'administrator' ),
				),
			),
		);
	}

	public static function get( string $id ): ?array {
		$catalog = self::all();
		return $catalog[ $id ] ?? null;
	}
}
