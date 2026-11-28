<?php
/**
 * Uninstall cleanup for iWorks.ie SMTP.
 *
 * @package IWorksIE\SMTP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'iwiesm_daily' );

// Email log table (one per site on multisite).
$iwiesm_drop_log = static function () use ( $wpdb ) {
	$table = $wpdb->prefix . 'iwiesm_log';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
};

$iwiesm_prefix = 'iwiesm_';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$iwiesm_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $iwiesm_prefix ) . '%'
	)
);

if ( is_array( $iwiesm_options ) ) {
	foreach ( $iwiesm_options as $iwiesm_option ) {
		delete_option( $iwiesm_option );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$iwiesm_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_' . $iwiesm_prefix ) . '%',
		$wpdb->esc_like( '_transient_timeout_' . $iwiesm_prefix ) . '%'
	)
);

if ( is_array( $iwiesm_transients ) ) {
	foreach ( $iwiesm_transients as $iwiesm_transient_option ) {
		if ( 0 === strpos( $iwiesm_transient_option, '_transient_timeout_' ) ) {
			$iwiesm_key = substr( $iwiesm_transient_option, strlen( '_transient_timeout_' ) );
			delete_transient( $iwiesm_key );
			continue;
		}
		if ( 0 === strpos( $iwiesm_transient_option, '_transient_' ) ) {
			$iwiesm_key = substr( $iwiesm_transient_option, strlen( '_transient_' ) );
			delete_transient( $iwiesm_key );
		}
	}
}

if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$iwiesm_site_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $iwiesm_prefix ) . '%'
		)
	);
	if ( is_array( $iwiesm_site_options ) ) {
		foreach ( $iwiesm_site_options as $iwiesm_site_option ) {
			delete_site_option( $iwiesm_site_option );
		}
	}
}

// User meta (last test address).
delete_metadata( 'user', 0, 'iwiesm_test_to', '', true );

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $iwiesm_site_id ) {
		switch_to_blog( (int) $iwiesm_site_id );
		$iwiesm_drop_log();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$iwiesm_site_opts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'iwiesm_' ) . '%', $wpdb->esc_like( '_transient_iwiesm_' ) . '%', $wpdb->esc_like( '_transient_timeout_iwiesm_' ) . '%' ) );
		foreach ( (array) $iwiesm_site_opts as $iwiesm_opt ) {
			delete_option( $iwiesm_opt );
		}
		wp_clear_scheduled_hook( 'iwiesm_daily' );
		restore_current_blog();
	}
} else {
	$iwiesm_drop_log();
}
