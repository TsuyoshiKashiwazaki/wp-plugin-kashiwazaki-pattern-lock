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
 * 失敗回数のテーブルが実在するか。
 *
 * @return bool
 */
function kashiwazaki_pattern_lock_table_exists() {
	global $wpdb;

	$table = kashiwazaki_pattern_lock_get_attempt_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

	return ( $table === $found );
}

/**
 * テーブルを作る（有効化時と、定義が変わったとき、設定画面での再試行）。
 *
 * dbDelta() の結果は戻り値では分からないため、作ったあとにテーブルの実在を確かめる。
 * 実在したときだけ db_version を保存する。作れなかったときは db_version を消し、
 * 失敗の印（option）を DB に残して、全要求が毎回作り直しを試みないようにする。
 * 印は transient ではなく通常の option にしている（transient は期限前に消えうるため）。
 *
 * @return bool 実在を確かめられたら true。
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

	if ( kashiwazaki_pattern_lock_table_exists() ) {
		update_option( 'kashiwazaki_pattern_lock_db_version', KASHIWASAKI_PATTERN_LOCK_DB_VERSION, false );
		delete_option( KASHIWASAKI_PATTERN_LOCK_STORAGE_FAILED_KEY );

		return true;
	}

	delete_option( 'kashiwazaki_pattern_lock_db_version' );
	update_option( KASHIWASAKI_PATTERN_LOCK_STORAGE_FAILED_KEY, time(), true );

	return false;
}

/**
 * テーブルの定義が古い・まだ無い場合に作る。
 *
 * 失敗の印があるときは何もしない（作れない環境で、全要求が毎回 dbDelta() を流さないため）。
 * 印が立っているあいだの再試行は、管理者がプラグインの設定画面を開いたときだけ行う。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_maybe_install_storage() {
	if ( get_option( 'kashiwazaki_pattern_lock_db_version' ) === KASHIWASAKI_PATTERN_LOCK_DB_VERSION ) {
		return;
	}

	if ( get_option( KASHIWASAKI_PATTERN_LOCK_STORAGE_FAILED_KEY ) ) {
		return;
	}

	kashiwazaki_pattern_lock_install_storage();
}
add_action( 'plugins_loaded', 'kashiwazaki_pattern_lock_maybe_install_storage' );
