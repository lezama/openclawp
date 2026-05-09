<?php
/**
 * REST controller for the openclawp_session post type.
 *
 * Enforces authentication and author-scoped reads so that chat transcripts
 * are never exposed to unauthenticated callers or to users who do not own
 * the session.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Session_Rest_Controller extends WP_REST_Posts_Controller {

	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'openclawp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$request->set_param( 'author', get_current_user_id() );
		}

		return parent::get_items_permissions_check( $request );
	}

	public function get_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'openclawp' ),
				array( 'status' => 401 )
			);
		}

		return parent::get_item_permissions_check( $request );
	}

	public function check_read_permission( $post ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) && (int) $post->post_author !== get_current_user_id() ) {
			return false;
		}

		return parent::check_read_permission( $post );
	}
}
