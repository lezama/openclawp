<?php
/**
 * Server-side render for the openclawp/chat block.
 *
 * Emits the empty state when no agents are registered. Otherwise emits a
 * single root div carrying the agent list as a JSON payload, which the
 * React entry (`build/view.js`) hydrates into the chat UI.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_get_agents' ) ) {
	return;
}

$openclawp_agents        = wp_get_agents();
$openclawp_default_agent = isset( $attributes['defaultAgent'] ) ? (string) $attributes['defaultAgent'] : '';
$openclawp_wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
	? get_block_wrapper_attributes( array( 'class' => 'openclawp-chat' ) )
	: 'class="openclawp-chat"';

$openclawp_agent_payload = array();
foreach ( $openclawp_agents as $openclawp_agent_slug => $openclawp_agent_obj ) {
	$openclawp_agent_payload[] = array(
		'slug'  => (string) $openclawp_agent_slug,
		'label' => $openclawp_agent_obj instanceof WP_Agent
			? (string) $openclawp_agent_obj->get_label()
			: (string) $openclawp_agent_slug,
	);
}
?>
<div <?php echo $openclawp_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes returns escaped string. ?>>
	<?php if ( empty( $openclawp_agent_payload ) ) : ?>
		<p class="openclawp-empty-state">
			<?php esc_html_e( 'No agents are registered. A plugin needs to register one on the wp_agents_api_init hook.', 'openclawp' ); ?>
		</p>
	<?php else : ?>
		<div
			id="openclawp-chat-root"
			data-agents="<?php echo esc_attr( wp_json_encode( $openclawp_agent_payload ) ); ?>"
			data-default-agent="<?php echo esc_attr( $openclawp_default_agent ); ?>"
		>
			<noscript>
				<?php esc_html_e( 'JavaScript is required to chat with an agent.', 'openclawp' ); ?>
			</noscript>
		</div>
	<?php endif; ?>
</div>
