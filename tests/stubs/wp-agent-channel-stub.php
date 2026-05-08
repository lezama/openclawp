<?php
/**
 * Minimal stub of `\AgentsAPI\AI\Channels\WP_Agent_Channel` for unit tests.
 *
 * The real implementation lives in `automattic/agents-api`. PHPUnit unit
 * tests only exercise pure-PHP helpers on the openclaWP subclasses, so this
 * stub provides just enough surface to let those subclasses load without a
 * real composer dep installed.
 */

declare( strict_types=1 );

namespace AgentsAPI\AI\Channels;

abstract class WP_Agent_Channel {
	public const SILENT_SKIP_CODE = 'silent_skip';

	protected string $agent_slug;
	protected string $response_status = 'ok';

	public function __construct( string $agent_slug ) {
		$this->agent_slug = $agent_slug;
	}

	abstract public function get_external_id_provider(): string;
	abstract public function get_external_id(): ?string;
	abstract public function get_client_name(): string;

	abstract protected function get_job_action(): string;
	abstract protected function validate( array $data ): ?\WP_Error;
	abstract protected function extract_message( array $data ): string;
	abstract protected function send_response( string $text ): void;
	abstract protected function send_error( string $text ): void;

	protected function get_room_kind( array $data ): ?string {
		unset( $data );
		return null;
	}

	protected function client_context_source(): string {
		return 'channel';
	}

	protected function session_storage_key(): string {
		return '';
	}
}
