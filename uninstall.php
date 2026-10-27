<?php
/**
 * プラグイン削除時の処理。
 *
 * 設定（パターンのハッシュ・失敗回数の設定）と、失敗回数の一時データを消す。
 * このファイルは WordPress が単独で読み込むため、プラグイン本体は読み込まれない。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 1 サイト分のデータを消す。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_uninstall_site() {
	global $wpdb;

	delete_option( 'kashiwazaki_pattern_lock_settings' );
	delete_option( 'kashiwazaki_pattern_lock_db_version' );
	delete_transient( 'kashiwazaki_pattern_lock_activation_redirect' );

	// 失敗回数のテーブル（現在の保存先）。
	$table = $wpdb->prefix . 'kashiwazaki_pattern_lock_attempts';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	// 失敗回数の transient（期限付き）を接頭辞でまとめて消す。
	$prefix = $wpdb->esc_like( '_transient_kashiwazaki_pattern_lock_fail_' ) . '%';
	$timeout_prefix = $wpdb->esc_like( '_transient_timeout_kashiwazaki_pattern_lock_fail_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$prefix,
			$timeout_prefix
		)
	);
}

if ( is_multisite() ) {
	$kashiwazaki_pattern_lock_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0, // 既定の 100 件で打ち切らない。
		)
	);
	foreach ( $kashiwazaki_pattern_lock_sites as $kashiwazaki_pattern_lock_site_id ) {
		switch_to_blog( $kashiwazaki_pattern_lock_site_id );
		kashiwazaki_pattern_lock_uninstall_site();
		restore_current_blog();
	}
} else {
	kashiwazaki_pattern_lock_uninstall_site();
}
