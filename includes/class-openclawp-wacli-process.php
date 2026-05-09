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

	private const STATE_OPTION  = 'openclawp_wacli_state';
	private const BINARY_OPTION = 'openclawp_wacli_binary';

	public const MODE_IDLE     = 'idle';
	public const MODE_PAIRING  = 'pairing';
	public const MODE_SYNCING  = 'syncing';
	public const MODE_FAILED   = 'failed';

	public const STAGE_AUTH = 'auth';
	public const STAGE_SYNC = 'sync';

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
	 * Remove a `<store>/LOCK` file whose recorded pid is no longer alive.
	 * Called before every `wacli sync` spawn so a previous wacli that died
	 * mid-login (container kill, OOM, network blip) doesn't strand future
	 * runs with `store is locked: resource temporarily unavailable`.
	 *
	 * The store dir is read from the `WACLI_STORE_DIR` env (set by the
	 * wp-env mu-plugin) with a sensible fallback to wacli's default.
	 */
	private static function clean_stale_store_lock( string $binary ): void {
		unset( $binary );
		$store_dir = (string) ( getenv( 'WACLI_STORE_DIR' ) ?: $_SERVER['WACLI_STORE_DIR'] ?? '' );
		if ( '' === $store_dir ) {
			// Fall back to wacli's documented defaults.
			$store_dir = is_dir( '/var/lib/wacli' ) ? '/var/lib/wacli' : ( $_SERVER['HOME'] ?? '' ) . '/.wacli';
		}
		$lock_path = rtrim( $store_dir, '/' ) . '/LOCK';
		if ( '' === $store_dir || ! file_exists( $lock_path ) ) {
			return;
		}

		$contents = (string) file_get_contents( $lock_path );
		if ( ! preg_match( '/pid=(\d+)/', $contents, $m ) ) {
			return;
		}
		$pid = (int) $m[1];
		if ( $pid <= 0 || self::is_alive( $pid ) ) {
			// Live owner — leave it alone, wacli's --lock-wait will sort it out.
			return;
		}

		@unlink( $lock_path );
	}

	/**
	 * Quick read of `wacli auth status --json` to decide whether the local
	 * store is already paired. Suppresses non-zero exit codes — wacli only
	 * returns success when the binary is callable, so any failure here just
	 * means "fall back to QR pairing".
	 */
	private static function is_already_authenticated( string $binary ): bool {
		// `--lock-wait 5s` so a momentarily-busy store doesn't make us
		// fall through to a fresh QR pair when we already have a session.
		$cmd  = escapeshellarg( $binary ) . ' --lock-wait 5s auth status --json 2>/dev/null';
		$json = (string) shell_exec( $cmd );
		if ( '' === $json ) {
			return false;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && ! empty( $decoded['data']['authenticated'] );
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

		// If the local store already has a paired session, skip QR pairing and
		// jump straight to webhook-posting sync. Avoids a second `auth` run
		// fighting the first for the store lock when the admin double-clicks.
		if ( self::is_already_authenticated( $binary ) ) {
			return self::start_sync();
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
			'stage'       => self::STAGE_AUTH,
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
	 * Spawn `wacli sync --follow --webhook <URL> --events`, detached.
	 * Called after pairing succeeds; replaces the auth process so a single
	 * wacli instance owns the session.
	 *
	 * The webhook HMAC secret is passed via the WACLI_WEBHOOK_SECRET
	 * environment variable (never on the command line) so it does not
	 * appear in /proc/<pid>/cmdline.
	 *
	 * `auth --follow` keeps the WhatsApp connection alive but does NOT
	 * forward messages anywhere. `sync --follow --webhook ...` is what
	 * actually POSTs each inbound message to /wp-json/openclawp/v1/wacli/webhook.
	 *
	 * @return WP_Error|array{pid:int,events_file:string}
	 */
	public static function start_sync() {
		$binary = self::resolve_binary();
		if ( '' === $binary ) {
			return new WP_Error( 'wacli_not_found', 'wacli binary not found.' );
		}

		$secret = self::ensure_webhook_secret();
		$url    = self::webhook_url_for_runtime();

		// Stop the auth process (and clean its events file) before starting sync.
		$current = get_option( self::STATE_OPTION, array() );
		if ( is_array( $current ) ) {
			if ( ! empty( $current['pid'] ) && self::is_alive( (int) $current['pid'] ) ) {
				self::kill_pid( (int) $current['pid'] );
				// Give the OS a moment to release the file lock the dying
				// process held. Skipping this means the new sync race-loses
				// to the old sync's still-mapped lock fd.
				usleep( 500_000 );
			}
			if ( ! empty( $current['events_file'] ) && file_exists( $current['events_file'] ) ) {
				@unlink( $current['events_file'] );
			}
		}

		// Clear any stale wacli store lock from a process that died without
		// releasing it (container kill, OOM, crash mid-login). wacli refuses
		// to start while a LOCK file with a non-self pid is present, even
		// when that pid is long gone.
		self::clean_stale_store_lock( $binary );

		$events_file = tempnam( sys_get_temp_dir(), 'wacli-sync-' );
		if ( false === $events_file ) {
			return new WP_Error( 'wacli_tempfile_failed', 'Could not create temp file for wacli sync events.' );
		}

		// Write the secret to a temp file (mode 0600) so it never appears in
		// any process's argv. The shell reads it into WACLI_WEBHOOK_SECRET,
		// deletes the file, then execs wacli — whose /proc/<pid>/environ is
		// only readable by the owning UID (mode 0400), unlike cmdline (0444).
		$secret_file = tempnam( sys_get_temp_dir(), 'wacli-secret-' );
		if ( false === $secret_file ) {
			return new WP_Error( 'wacli_tempfile_failed', 'Could not create temp file for wacli webhook secret.' );
		}
		file_put_contents( $secret_file, $secret );
		chmod( $secret_file, 0600 );

		// `--lock-wait 10s` lets the new sync wait briefly if a previous
		// process is still releasing the store, instead of hard-failing.
		// Shell variable-prefix syntax (VAR=val cmd) sets the env var for the
		// child without it ever appearing in any process's argv. $(cat ...) is
		// expanded by the parent shell internally, so the secret doesn't leak
		// into sh's cmdline either. The temp file is deleted after expansion.
		$cmd = sprintf(
			'WACLI_WEBHOOK_SECRET=$(cat %s) nohup %s --lock-wait 10s sync --follow --webhook %s --events </dev/null >>%s 2>&1 & echo $!; rm -f %s',
			escapeshellarg( $secret_file ),
			escapeshellarg( $binary ),
			escapeshellarg( $url ),
			escapeshellarg( $events_file ),
			escapeshellarg( $secret_file )
		);

		$pid = (int) trim( (string) shell_exec( $cmd ) );
		if ( $pid <= 0 ) {
			return new WP_Error( 'wacli_spawn_failed', 'Could not start wacli sync process.' );
		}

		$paired_jid = is_array( $current ) ? (string) ( $current['paired_jid'] ?? '' ) : '';
		$state      = array(
			'mode'          => self::MODE_SYNCING,
			'stage'         => self::STAGE_SYNC,
			'pid'           => $pid,
			'events_file'   => $events_file,
			'qr_payload'    => '',
			'qr_seen_at'    => 0,
			'paired_jid'    => $paired_jid,
			'started_at'    => time(),
			'last_event'    => '',
			'last_event_at' => 0,
			'error'         => '',
		);
		update_option( self::STATE_OPTION, $state, false );

		return array( 'pid' => $pid, 'events_file' => $events_file );
	}

	/**
	 * Read the HMAC secret used to sign wacli's webhook deliveries. Generated
	 * lazily on first use so admins don't have to remember to set it.
	 */
	public static function ensure_webhook_secret(): string {
		$secret = (string) get_option( OpenclaWP_Wacli_Transport::SECRET_OPTION, '' );
		if ( '' !== $secret ) {
			return $secret;
		}
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( OpenclaWP_Wacli_Transport::SECRET_OPTION, $secret, false );
		return $secret;
	}

	/**
	 * Build the webhook URL wacli should POST to. Inside dev containers
	 * (wp-env, Studio's Docker mode), home_url() resolves to the host-side
	 * URL like http://localhost:8888 — which the container itself can't
	 * reach. Apache listens on port 80 inside the container, so for
	 * localhost-style hosts we drop the port so wacli can hit Apache locally.
	 */
	public static function webhook_url_for_runtime(): string {
		$path = '/wp-json/' . OpenclaWP_Wacli_Transport::REST_NAMESPACE . '/wacli/webhook';

		$home = wp_parse_url( home_url() );
		$host = isset( $home['host'] ) ? (string) $home['host'] : 'localhost';

		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return 'http://localhost' . $path;
		}

		$scheme = isset( $home['scheme'] ) ? (string) $home['scheme'] : 'http';
		$port   = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		return $scheme . '://' . $host . $port . $path;
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

		// Auto-transition: pairing finished but the auth process is still
		// running. Swap it for `wacli sync --webhook ...` so messages start
		// flowing to the webhook. Caller-driven so the spawn happens during
		// the next admin poll, never inline with a write request.
		if (
			self::MODE_SYNCING === ( $state['mode'] ?? '' )
			&& self::STAGE_AUTH === ( $state['stage'] ?? '' )
		) {
			$result = self::start_sync();
			if ( is_wp_error( $result ) ) {
				$state['mode']  = self::MODE_FAILED;
				$state['error'] = $result->get_error_message();
				update_option( self::STATE_OPTION, $state, false );
			} else {
				$state = get_option( self::STATE_OPTION, $state );
			}
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
			'stage'         => '',
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

		// wacli wraps event-specific fields inside a `data` object.
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();

		switch ( $type ) {
			case 'qr':
			case 'qr_code':
			case 'pair_qr':
				$payload = (string) ( $data['code'] ?? $data['payload'] ?? $data['qr']
					?? $event['code'] ?? $event['payload'] ?? $event['qr'] ?? '' );
				if ( '' !== $payload ) {
					$state['qr_payload'] = $payload;
					$state['qr_seen_at'] = isset( $event['ts'] ) ? (int) $event['ts'] : time();
					$state['mode']       = self::MODE_PAIRING;
				}
				break;

			case 'paired':
			case 'pair_success':
			case 'pairing_complete':
			case 'auth_complete':
				$state['paired_jid'] = (string) ( $data['jid'] ?? $data['user']
					?? $event['jid'] ?? $event['user'] ?? $state['paired_jid'] );
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
				$state['error'] = (string) ( $data['message'] ?? $data['error']
					?? $event['message'] ?? $event['error'] ?? 'wacli reported an error.' );
				break;
		}

		$state['last_event']    = $type;
		$state['last_event_at'] = isset( $event['ts'] ) ? (int) $event['ts'] : time();
		return $state;
	}
}
