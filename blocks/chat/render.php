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

// Filter out specialty agents that aren't meant for conversational chat (e.g.
// the workflow drafter, which translates English into a workflow JSON spec
// and would look broken to a first-time user asking a plain question).
$openclawp_agents = array_filter(
	$openclawp_agents,
	static function ( $openclawp_agent_obj ) {
		if ( $openclawp_agent_obj instanceof WP_Agent && method_exists( $openclawp_agent_obj, 'get_meta' ) ) {
			$openclawp_agent_meta = $openclawp_agent_obj->get_meta();
			if ( isset( $openclawp_agent_meta['source_type'] ) && 'workflow-drafter' === $openclawp_agent_meta['source_type'] ) {
				return false;
			}
		}
		return true;
	}
);

/**
 * Filters the list of agents shown in the Chat block's agent picker.
 *
 * Allows installs to add, remove, or reorder agents — useful when a plugin
 * registers a specialty agent (translator, drafter, etc.) that shouldn't
 * appear in the general-purpose Chat surface.
 *
 * @since 0.4.0
 *
 * @param array<string, WP_Agent> $openclawp_agents Map of agent slug => WP_Agent.
 */
$openclawp_agents = apply_filters( 'openclawp_chat_block_agents', $openclawp_agents );

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
			<?php esc_html_e( 'No conversational agents are registered. Specialty agents like the workflow drafter are excluded from this picker — register a general-purpose agent on the wp_agents_api_init hook to chat here.', 'openclawp' ); ?>
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
