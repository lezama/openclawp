<?php
/**
 * Long-running wacli process manager.
 *
 * Spawns `wacli auth --follow` so a single detached process handles both
 * pairing and continuous sync. Reads the NDJSON event stream out of a temp
 * file to expose state to wp-admin without holding any HTTP request open.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Wacli_Process {

	private const STATE_OPTION = 'openclawp_wacli_state';
	private const BINARY_OPTION = 'openclawp_wacli_binary';

	public const MODE_IDLE     = 'idle';
	public const MODE_PAIRING  = 'pairing';
	public const MODE_SYNCING  = 'syncing';
	public const MODE_FAILED   = 'failed';

	/**
	 * Resolve the wacli executable. Reads the setting first; on miss, walks
	 * `which` and the usual Homebrew paths and persists what it finds.
	 */
	public static function resolve_binary(): string {
		$configured = (string) get_option( self::BINARY_OPTION, '' );
		if ( '' !== $configured && self::is_executable( $configured ) ) {
			return $configured;
		}

		$default_candidates = array(
			trim( (string) shell_exec( 'command -v wacli 2>/dev/null' ) ),
			'/opt/homebrew/bin/wacli',
			'/usr/local/bin/wacli',
			'/usr/bin/wacli',
		);

		/**
		 * Filter the list of candidate paths checked when wacli's location
		 * isn't pre-configured. Default covers Homebrew on Apple Silicon,
		 * Homebrew on Intel/Linux, and FHS standard paths. Add /snap/bin,
		 * /opt/wacli, /usr/local/sbin, etc. for non-standard installs.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $candidates Ordered list of paths to probe.
		 *                             First executable match wins and is
		 *                             persisted to openclawp_wacli_binary.
		 */
		$candidates = (array) apply_filters( 'openclawp_wacli_binary_candidates', $default_candidates );

		foreach ( $candidates as $path ) {
			$path = (string) $path;
			if ( '' !== $path && self::is_executable( $path ) ) {
				update_option( self::BINARY_OPTION, $path, false );
				return $path;
			}
		}

		return '';
	}

	private static function is_executable( string $path ): bool {
		return '' !== $path && file_exists( $path ) && is_executable( $path );
	}

	/**
	 * Spawn `wacli auth --follow --qr-format text --events`, detached, with
	 * stderr (the events stream) redirected to a temp file we can poll.
	 *
	 * @return WP_Error|array{pid:int,events_file:string}
	 */
	public static function start_auth() {
		$binary = self::resolve_binary();
		if ( '' === $binary ) {
			return new WP_Error( 'wacli_not_found', 'wacli binary not found. Install via `brew install steipete/tap/wacli` or set the openclawp_wacli_binary option.' );
		}

		$current = self::get_state();
		if ( in_array( $current['mode'], array( self::MODE_PAIRING, self::MODE_SYNCING ), true ) && self::is_alive( (int) $current['pid'] ) ) {
			return new WP_Error( 'wacli_already_running', 'wacli is already running.', $current );
		}

		// Clean any stale events file before starting.
		if ( ! empty( $current['events_file'] ) && file_exists( $current['events_file'] ) ) {
			@unlink( $current['events_file'] );
		}

		$events_file = tempnam( sys_get_temp_dir(), 'wacli-' );
		if ( false === $events_file ) {
			return new WP_Error( 'wacli_tempfile_failed', 'Could not create temp file for wacli event stream.' );
		}

		$cmd = sprintf(
			'nohup %s auth --follow --qr-format text --events </dev/null >>%s 2>&1 & echo $!',
			escapeshellarg( $binary ),
			escapeshellarg( $events_file )
		);

		$pid_raw = trim( (string) shell_exec( $cmd ) );
		$pid     = (int) $pid_raw;

		if ( $pid <= 0 ) {
			return new WP_Error( 'wacli_spawn_failed', 'Could not start wacli auth process.' );
		}

		$state = array(
			'mode'        => self::MODE_PAIRING,
			'pid'         => $pid,
			'events_file' => $events_file,
			'qr_payload'  => '',
			'qr_seen_at'  => 0,
			'paired_jid'  => '',
			'started_at'  => time(),
			'last_event'  => '',
			'last_event_at' => 0,
			'error'       => '',
		);
		update_option( self::STATE_OPTION, $state, false );

		return array( 'pid' => $pid, 'events_file' => $events_file );
	}

	/**
	 * Read the latest snapshot from the events file and persist any state
	 * changes (QR rotated, paired, errored). Returns the current state map.
	 */
	public static function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['mode'] ) ) {
			return self::idle_state();
		}

		// Process gone → mark idle/failed if it had been running.
		if ( in_array( $state['mode'], array( self::MODE_PAIRING, self::MODE_SYNCING ), true ) ) {
			if ( ! self::is_alive( (int) ( $state['pid'] ?? 0 ) ) ) {
				$state['mode']  = self::MODE_FAILED;
				$state['error'] = $state['error'] ?: 'wacli process exited.';
				update_option( self::STATE_OPTION, $state, false );
			}
		}

		// Refresh state from events file if present.
		if ( ! empty( $state['events_file'] ) && file_exists( $state['events_file'] ) ) {
			$state = self::merge_events_into_state( $state );
			update_option( self::STATE_OPTION, $state, false );
		}

		return $state;
	}

	public static function stop(): void {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! empty( $state['pid'] ) ) {
			self::kill_pid( (int) $state['pid'] );
		}
		if ( ! empty( $state['events_file'] ) && file_exists( $state['events_file'] ) ) {
			@unlink( $state['events_file'] );
		}

		// Best-effort logout to invalidate the linked-device session.
		$binary = self::resolve_binary();
		if ( '' !== $binary ) {
			shell_exec( escapeshellarg( $binary ) . ' auth logout >/dev/null 2>&1' );
		}

		update_option( self::STATE_OPTION, self::idle_state(), false );
	}

	public static function is_alive( int $pid ): bool {
		if ( $pid <= 0 ) {
			return false;
		}
		if ( function_exists( 'posix_kill' ) ) {
			// posix_kill emits an E_WARNING when $pid is gone. That's the
			// path we're checking for, not an error condition — clear
			// last_error first so callers' error state isn't polluted.
			$prev_level = error_reporting( error_reporting() & ~E_WARNING );
			$alive      = posix_kill( $pid, 0 );
			error_reporting( $prev_level );
			return (bool) $alive;
		}
		// Fallback: ps -p
		$out = (string) shell_exec( sprintf( 'ps -p %d 2>/dev/null', $pid ) );
		return false !== strpos( $out, (string) $pid );
	}

	private static function kill_pid( int $pid ): void {
		if ( $pid <= 0 ) {
			return;
		}
		if ( function_exists( 'posix_kill' ) ) {
			@posix_kill( $pid, defined( 'SIGTERM' ) ? SIGTERM : 15 );
		} else {
			shell_exec( sprintf( 'kill %d 2>/dev/null', $pid ) );
		}
	}

	private static function idle_state(): array {
		return array(
			'mode'          => self::MODE_IDLE,
			'pid'           => 0,
			'events_file'   => '',
			'qr_payload'    => '',
			'qr_seen_at'    => 0,
			'paired_jid'    => '',
			'started_at'    => 0,
			'last_event'    => '',
			'last_event_at' => 0,
			'error'         => '',
		);
	}

	/**
	 * Tail the NDJSON file and fold relevant events into the state map.
	 * Public so unit tests can call it with a synthetic file.
	 *
	 * Recognized event types (best-effort — wacli's exact schema may evolve):
	 *   {type:"qr"|"qr_code", code|payload:"..."}
	 *   {type:"paired"|"pair_success", jid:"..."}
	 *   {type:"sync_started"|"connected"}
	 *   {type:"error", message:"..."}
	 */
	public static function merge_events_into_state( array $state ): array {
		$events_file = (string) ( $state['events_file'] ?? '' );
		if ( '' === $events_file || ! file_exists( $events_file ) ) {
			return $state;
		}

		$contents = (string) file_get_contents( $events_file );
		if ( '' === $contents ) {
			return $state;
		}

		$lines = preg_split( '/\R/', $contents );
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '{' !== $line[0] ) {
				continue;
			}
			$event = json_decode( $line, true );
			if ( ! is_array( $event ) ) {
				continue;
			}
			$state = self::apply_event( $state, $event );
		}

		return $state;
	}

	/**
	 * Apply a single decoded NDJSON event to the state map. Pure function for
	 * easy testing.
	 */
	public static function apply_event( array $state, array $event ): array {
		$type = (string) ( $event['type'] ?? $event['event'] ?? '' );

		switch ( $type ) {
			case 'qr':
			case 'qr_code':
			case 'pair_qr':
				$payload = (string) ( $event['code'] ?? $event['payload'] ?? $event['qr'] ?? '' );
				if ( '' !== $payload ) {
					$state['qr_payload'] = $payload;
					$state['qr_seen_at'] = isset( $event['ts'] ) ? (int) $event['ts'] : time();
					$state['mode']       = self::MODE_PAIRING;
				}
				break;

			case 'paired':
			case 'pair_success':
			case 'pairing_complete':
				$state['paired_jid'] = (string) ( $event['jid'] ?? $event['user'] ?? $state['paired_jid'] );
				$state['qr_payload'] = '';
				$state['mode']       = self::MODE_SYNCING;
				$state['error']      = '';
				break;

			case 'sync_started':
			case 'connected':
				if ( self::MODE_FAILED !== $state['mode'] ) {
					$state['mode'] = self::MODE_SYNCING;
				}
				break;

			case 'error':
			case 'fatal':
				$state['mode']  = self::MODE_FAILED;
				$state['error'] = (string) ( $event['message'] ?? $event['error'] ?? 'wacli reported an error.' );
				break;
		}

		$state['last_event']    = $type;
		$state['last_event_at'] = isset( $event['ts'] ) ? (int) $event['ts'] : time();
		return $state;
	}
}
