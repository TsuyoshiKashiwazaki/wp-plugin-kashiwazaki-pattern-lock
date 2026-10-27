<?php
/**
 * 失敗回数の保存先（専用テーブル）。
 *
 * transient は「読んでから書く」ため、同時に失敗が起きると更新が失われる。
 * 回数は 1 本の SQL で加算できるよう、専用テーブルに持つ。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 失敗回数を保存するテーブル名を返す。
 *
 * @return string
 */
function kashiwazaki_pattern_lock_get_attempt_table() {
	global $wpdb;

	return $wpdb->prefix . 'kashiwazaki_pattern_lock_attempts';
}

/**
 * テーブルを作る（有効化時と、定義が変わったときだけ）。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_install_storage() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = kashiwazaki_pattern_lock_get_attempt_table();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		attempt_key varchar(100) NOT NULL,
		attempt_count int unsigned NOT NULL DEFAULT 0,
		window_start bigint NOT NULL DEFAULT 0,
		expires bigint NOT NULL DEFAULT 0,
		PRIMARY KEY  (attempt_key),
		KEY expires (expires)
	) {$charset};";

	dbDelta( $sql );

	update_option( 'kashiwazaki_pattern_lock_db_version', KASHIWASAKI_PATTERN_LOCK_DB_VERSION, false );
}

/**
 * テーブルが無い・古い場合に作り直す。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_maybe_install_storage() {
	if ( get_option( 'kashiwazaki_pattern_lock_db_version' ) !== KASHIWASAKI_PATTERN_LOCK_DB_VERSION ) {
		kashiwazaki_pattern_lock_install_storage();
	}
}
add_action( 'plugins_loaded', 'kashiwazaki_pattern_lock_maybe_install_storage' );
