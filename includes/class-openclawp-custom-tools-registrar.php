<?php
/**
 * Register each persisted custom tool as a WordPress ability at runtime.
 *
 * Hooks into `wp_abilities_api_init` and walks every enabled `openclawp_tool`
 * post, calling `wp_register_ability()` with an `execute_callback` that
 * delegates to `OpenclaWP_Custom_Tools_Executor`. Permission is gated to
 * the tool's role allowlist (defaulting to administrator-only).
 *
 * No plugin restart is required — the next call to `wp_abilities_api_init`
 * (every request, on the same `init` tick the agent registers on) picks up
 * newly-created tools.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Custom_Tools_Registrar {

	public const ABILITY_PREFIX = 'openclawp/tool-';

	public const CATEGORY = 'openclawp-custom-tools';

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'openclaWP — User-defined tools', 'openclawp' ),
				'description' => __( 'Custom HTTP tools authored in wp-admin → openclaWP → Custom Tools.', 'openclawp' ),
			)
		);
	}

	/**
	 * Register one ability per enabled tool post.
	 */
	public static function register_tools(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$posts = OpenclaWP_Custom_Tools_Store::all_enabled();
		foreach ( $posts as $post ) {
			self::register_one( $post );
		}
	}

	public static function register_one( \WP_Post $post ): void {
		$ability_name = self::ability_name_for_slug( $post->post_name );
		if ( '' === $ability_name ) {
			return;
		}
		if ( wp_has_ability( $ability_name ) ) {
			return;
		}

		$spec        = OpenclaWP_Custom_Tools_Store::get_spec( $post );
		$description = (string) $post->post_content;
		if ( '' === $description ) {
			$description = (string) $post->post_title;
		}

		$allowed_roles = isset( $spec['allowed_roles'] ) && is_array( $spec['allowed_roles'] )
			? $spec['allowed_roles']
			: array( 'administrator' );

		wp_register_ability(
			$ability_name,
			array(
				'label'               => (string) $post->post_title,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => is_array( $spec['input_schema'] ?? null )
					? $spec['input_schema']
					: array( 'type' => 'object' ),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'meta'                => array(
					'effect'      => (string) ( $spec['effect'] ?? OpenclaWP_Custom_Tools_Store::EFFECT_READ ),
					'source_post' => (int) $post->ID,
					'source_slug' => (string) $post->post_name,
				),
				'execute_callback'    => static function ( array $input ) use ( $spec ) {
					return OpenclaWP_Custom_Tools_Executor::execute( $spec, $input );
				},
				'permission_callback' => static function () use ( $allowed_roles, $post ): bool {
					/**
					 * Whether the current user may invoke the given custom tool.
					 *
					 * Defaults to "any user role in the tool's allowlist". Adopters
					 * can override per-tool with this filter.
					 *
					 * @since 0.7.0
					 *
					 * @param bool      $allowed       Default decision.
					 * @param \WP_Post  $post          Tool post.
					 * @param array     $allowed_roles Tool's role allowlist.
					 */
					return (bool) apply_filters(
						'openclawp_custom_tool_permission',
						self::user_has_any_role( get_current_user_id(), $allowed_roles ),
						$post,
						$allowed_roles
					);
				},
			)
		);
	}

	/**
	 * Slug → ability name with a deterministic prefix so custom tools never
	 * shadow first-party abilities (`openclawp/echo`, `openclawp/chat`, …).
	 */
	public static function ability_name_for_slug( string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return '';
		}
		return self::ABILITY_PREFIX . $slug;
	}

	/**
	 * True when $user has at least one of $roles.
	 *
	 * @param array<int, string> $roles
	 */
	private static function user_has_any_role( int $user_id, array $roles ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		foreach ( $roles as $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				return true;
			}
		}
		return false;
	}
}
