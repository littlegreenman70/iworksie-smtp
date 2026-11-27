<?php
/**
 * Email log with a retention cap.
 *
 * @package IWorksIE\SMTP
 */

namespace IWorksIE\SMTP\Log;

use IWorksIE\SMTP\Mail\Router;
use IWorksIE\SMTP\Settings\Settings;

/**
 * Records every wp_mail() outcome in {prefix}iwiesm_log.
 *
 * Message content is only kept when the admin opts in (it can contain
 * password-reset links). Old rows are pruned daily by age and count so the
 * table can never grow without limit.
 */
final class Logger {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'iwiesm_db_version';
	const CRON_HOOK         = 'iwiesm_daily';

	/**
	 * True while resending, so the resend is logged as new row but
	 * the original keeps its own status.
	 *
	 * @var int
	 */
	private static $resend_of = 0;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'iwiesm_log';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp_mail_succeeded', array( $this, 'on_success' ), 10, 1 );
		add_action( 'wp_mail_failed', array( $this, 'on_failure' ), 10, 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_gmt datetime NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			mailer varchar(20) NOT NULL DEFAULT '',
			to_addr text NOT NULL,
			subject text NOT NULL,
			error text NULL,
			headers text NULL,
			message longtext NULL,
			resend_of bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_gmt (created_gmt),
			KEY status (status)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Install when the stored schema version differs.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Whether the table has been installed (autoloaded option, no query).
	 *
	 * @return bool
	 */
	public static function table_exists() {
		return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * wp_mail_succeeded.
	 *
	 * @param array<string,mixed> $mail_data Mail data.
	 * @return void
	 */
	public function on_success( $mail_data ) {
		$this->insert( 'sent', is_array( $mail_data ) ? $mail_data : array(), '' );
	}

	/**
	 * wp_mail_failed.
	 *
	 * @param \WP_Error $error Error with mail data.
	 * @return void
	 */
	public function on_failure( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$this->insert( 'failed', $data, $error->get_error_message() );
		update_option(
			'iwiesm_last_failure',
			array(
				'time'    => time(),
				'mailer'  => Router::last_mailer(),
				'message' => $error->get_error_message(),
			),
			false
		);
	}

	/**
	 * Insert a row.
	 *
	 * @param string              $status    sent|failed.
	 * @param array<string,mixed> $mail_data to, subject, message, headers.
	 * @param string              $error     Error.
	 * @return void
	 */
	private function insert( $status, array $mail_data, $error ) {
		if ( ! Settings::get( 'log_enabled' ) || ! self::table_exists() ) {
			return;
		}
		global $wpdb;

		$to = isset( $mail_data['to'] ) ? $mail_data['to'] : array();
		if ( is_string( $to ) ) {
			$to = explode( ',', $to );
		}
		$to = implode( ', ', array_map( 'trim', array_map( 'strval', (array) $to ) ) );

		$keep    = (bool) Settings::get( 'log_keep_content' );
		$headers = isset( $mail_data['headers'] ) ? $mail_data['headers'] : array();
		if ( is_array( $headers ) ) {
			$headers = implode( "\n", array_map( 'strval', $headers ) );
		}

		$row = array(
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'status'      => $status,
			'mailer'      => Router::last_mailer(),
			'to_addr'     => mb_substr( $to, 0, 2000 ),
			'subject'     => mb_substr( isset( $mail_data['subject'] ) ? (string) $mail_data['subject'] : '', 0, 500 ),
			'error'       => '' === $error ? null : mb_substr( (string) $error, 0, 2000 ),
			'headers'     => $keep ? (string) $headers : null,
			'message'     => $keep && isset( $mail_data['message'] ) ? (string) $mail_data['message'] : null,
			'resend_of'   => self::$resend_of,
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom log table.
	}

	/**
	 * Delete rows older than the retention period and over the row cap.
	 *
	 * @return void
	 */
	public static function prune() {
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table  = self::table();
		$days   = max( 1, (int) Settings::get( 'log_days' ) );
		$max    = max( 100, (int) Settings::get( 'log_max_rows' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table name from $wpdb->prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", $cutoff ) );
		$nth_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) );
		if ( null !== $nth_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $nth_id ) );
		}
		// phpcs:enable
	}

	/**
	 * Query rows for the log screen.
	 *
	 * @param string $status   '', sent or failed.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Search in recipient/subject.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( $status, $page, $per_page, $search = '' ) {
		if ( ! self::table_exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		global $wpdb;
		$table  = self::table();
		$where  = '1=1';
		$params = array();
		if ( in_array( $status, array( 'sent', 'failed' ), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( to_addr LIKE %s OR subject LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is built from fixed fragments with placeholders.
		if ( empty( $params ) ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_gmt, status, mailer, to_addr, subject, error, ( message IS NOT NULL ) AS has_content, resend_of FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * One full row.
	 *
	 * @param int $id Row id.
	 * @return array<string,mixed>|null
	 */
	public static function find( $id ) {
		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count rows by status since a time.
	 *
	 * @param string $status sent|failed.
	 * @param int    $since  Unix time.
	 * @return int
	 */
	public static function count_since( $status, $since ) {
		if ( ! self::table_exists() ) {
			return 0;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND created_gmt >= %s", $status, gmdate( 'Y-m-d H:i:s', $since ) ) );
	}

	/**
	 * Most recent failed row since a time.
	 *
	 * @param int $since Unix time.
	 * @return array<string,mixed>|null
	 */
	public static function last_failure_since( $since ) {
		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_gmt, mailer, error FROM {$table} WHERE status = 'failed' AND created_gmt >= %s ORDER BY id DESC LIMIT 1", gmdate( 'Y-m-d H:i:s', $since ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete all rows.
	 *
	 * @return void
	 */
	public static function clear() {
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Resend a logged email (only possible when its content was kept).
	 *
	 * @param int $id Row id.
	 * @return bool|null Null when the row has no stored content.
	 */
	public static function resend( $id ) {
		$row = self::find( $id );
		if ( null === $row || null === $row['message'] ) {
			return null;
		}
		$headers = '' === (string) $row['headers'] ? array() : explode( "\n", (string) $row['headers'] );
		$to      = array_filter( array_map( 'trim', explode( ',', (string) $row['to_addr'] ) ) );

		self::$resend_of = (int) $row['id'];
		try {
			return wp_mail( $to, (string) $row['subject'], (string) $row['message'], $headers );
		} finally {
			self::$resend_of = 0;
		}
	}
}
