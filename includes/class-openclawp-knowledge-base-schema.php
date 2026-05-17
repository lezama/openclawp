<?php
/**
 * Knowledge base storage schema.
 *
 * Owns the `wp_openclawp_kb` table: one row per indexed chunk, with a
 * FULLTEXT index over `title + content` so the `knowledge-base/search`
 * ability can do `MATCH ... AGAINST` lookups without an external search
 * engine. The table is created on plugin activation and on version
 * bumps via a stored DB-version option.
 *
 * Phase 1 only stores plain text. Phase 2 will add an `embedding` column
 * (see follow-up issue) and switch the same ability to vector lookups
 * when a provider is configured.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge-base storage schema.
 */
final class OpenclaWP_Knowledge_Base_Schema {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'openclawp_kb_db_version';

	public const SOURCE_POST = 'post';
	public const SOURCE_URL  = 'url';

	/**
	 * Return the fully-prefixed table name. Always read from `$wpdb->prefix`
	 * at call time so tests and multisite swaps are picked up correctly.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'openclawp_kb';
	}

	/**
	 * Ensure the table exists at the expected version. Safe to call on every
	 * request — bails out fast when the option already matches.
	 */
	public static function maybe_install(): void {
		$current = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( self::DB_VERSION === $current ) {
			return;
		}
		self::install();
	}

	/**
	 * Create the table via dbDelta. Idempotent — dbDelta handles upgrades by
	 * comparing the schema string to the live table.
	 */
	public static function install(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// FULLTEXT index requires InnoDB on MySQL 5.6+ (or MyISAM); modern WP
		// installs ship InnoDB by default. The index covers `title + content`
		// so the search ability can rank title hits higher than body hits via
		// MATCH on the composite column set.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type VARCHAR(20) NOT NULL,
			source_id VARCHAR(64) NOT NULL,
			source_ref TEXT NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
			content LONGTEXT NOT NULL,
			indexed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source_lookup (source_type, source_id, chunk_index),
			FULLTEXT KEY content_search (title, content)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Drop the table. Only called from uninstall flows / tests. Not wired to
	 * deactivation — we never destroy user data on deactivation.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name is internal.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
