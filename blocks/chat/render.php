<?php
/**
 * Server-side render for the openclawp/chat block.
 *
 * Receives `$attributes`, `$content`, `$block` from `register_block_type`.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_get_agents' ) ) {
	return;
}

$openclawp_agents         = wp_get_agents();
$openclawp_default_agent  = isset( $attributes['defaultAgent'] ) ? (string) $attributes['defaultAgent'] : '';
$openclawp_wrapper_attrs  = function_exists( 'get_block_wrapper_attributes' )
	? get_block_wrapper_attributes( array( 'class' => 'openclawp-chat' ) )
	: 'class="openclawp-chat"';
?>
<div <?php echo $openclawp_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns escaped string. ?>>
	<?php if ( empty( $openclawp_agents ) ) : ?>
		<p class="openclawp-empty-state">
			<?php esc_html_e( 'No agents are registered. A plugin needs to register one on the wp_agents_api_init hook.', 'openclawp' ); ?>
		</p>
	<?php else : ?>
		<div class="openclawp-toolbar">
			<label for="openclawp-agent">
				<?php esc_html_e( 'Agent', 'openclawp' ); ?>
			</label>
			<select id="openclawp-agent">
				<?php foreach ( $openclawp_agents as $openclawp_agent_slug => $openclawp_agent_obj ) : ?>
					<option
						value="<?php echo esc_attr( (string) $openclawp_agent_slug ); ?>"
						<?php selected( $openclawp_default_agent, (string) $openclawp_agent_slug ); ?>
					>
						<?php echo esc_html( $openclawp_agent_obj instanceof WP_Agent ? $openclawp_agent_obj->get_label() : (string) $openclawp_agent_slug ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="button" id="openclawp-new-session" class="button">
				<?php esc_html_e( 'New session', 'openclawp' ); ?>
			</button>

			<span id="openclawp-session-id" class="openclawp-session-id"></span>
		</div>

		<div id="openclawp-transcript" class="openclawp-transcript" aria-live="polite"></div>

		<form id="openclawp-form" class="openclawp-form">
			<textarea
				id="openclawp-input"
				rows="3"
				placeholder="<?php esc_attr_e( 'Type a message…', 'openclawp' ); ?>"
				required
			></textarea>
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Send', 'openclawp' ); ?>
			</button>
		</form>
	<?php endif; ?>
</div>
