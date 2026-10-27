<?php
/**
 * 連続失敗の回数とログイン制限。
 *
 * 失敗回数は transient（期限付き）に保存する。パターンそのものは保存しない。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 接続元の IP アドレスを返す。
 *
 * 偽装できるヘッダー（X-Forwarded-For など）は使わず、サーバーが申告する
 * REMOTE_ADDR だけを見る。
 *
 * @return string
 */
function kashiwazaki_pattern_lock_get_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';

	if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return $ip;
	}

	return '0.0.0.0';
}

/**
 * 失敗回数を数える transient のキーを返す。
 *
 * 「IPアドレス＋ユーザー名」の単位と、「IPアドレス」だけの単位の 2 つを持つ。
 * ユーザー名だけの単位にしないのは、他人のアカウントをわざと失敗させて
 * ログインできなくさせる嫌がらせ（アカウントロックアウト攻撃）を避けるため。
 *
 * @param string $username 入力されたユーザー名。
 * @return array {
 *     @type string $user IPアドレス＋ユーザー名のキー。
 *     @type string $ip   IPアドレスのみのキー。
 * }
 */
function kashiwazaki_pattern_lock_get_failure_keys( $username ) {
	$ip   = kashiwazaki_pattern_lock_get_client_ip();
	$user = strtolower( trim( (string) $username ) );

	return array(
		'user' => KASHIWASAKI_PATTERN_LOCK_TRANSIENT_PREFIX . md5( $ip . '|' . $user ),
		'ip'   => KASHIWASAKI_PATTERN_LOCK_TRANSIENT_PREFIX . 'ip_' . md5( $ip ),
	);
}

/**
 * 単位ごとの上限回数を返す。
 *
 * 同じ IP アドレスからの全体の失敗は、設定値の 10 倍（最低 50 回）までを上限にする。
 * これは、1 つの IP から多数のユーザー名を順に試す行為を止めるためのもので、
 * 通常の入力ミスで巻き添えにならないように余裕を持たせている。
 *
 * @return array {
 *     @type int $user IPアドレス＋ユーザー名の上限。
 *     @type int $ip   IPアドレスのみの上限。
 * }
 */
function kashiwazaki_pattern_lock_get_failure_thresholds() {
	$settings = kashiwazaki_pattern_lock_get_settings();
	$max      = (int) $settings['max_attempts'];

	return array(
		'user' => max( 1, $max ),
		'ip'   => max( 50, $max * 10 ),
	);
}

/**
 * 失敗を 1 回記録する。
 *
 * 回数は 1 本の SQL（INSERT ... ON DUPLICATE KEY UPDATE）で加算する。
 * 読んでから書く方式だと、同時に失敗が起きたときに更新が失われるため。
 *
 * @param string $username 入力されたユーザー名。
 * @return void
 */
function kashiwazaki_pattern_lock_record_failure( $username ) {
	global $wpdb;

	$settings   = kashiwazaki_pattern_lock_get_settings();
	$thresholds = kashiwazaki_pattern_lock_get_failure_thresholds();
	$table      = kashiwazaki_pattern_lock_get_attempt_table();
	$now        = time();
	$window     = (int) $settings['lockout_minutes'] * MINUTE_IN_SECONDS;

	foreach ( kashiwazaki_pattern_lock_get_failure_keys( $username ) as $type => $key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (attempt_key, attempt_count, window_start, expires)
				 VALUES (%s, 1, %d, %d)
				 ON DUPLICATE KEY UPDATE
				   attempt_count = IF(expires <= %d, 1, attempt_count + 1),
				   window_start = IF(expires <= %d, %d, window_start),
				   expires = IF(expires <= %d, %d, expires)",
				$key,
				$now,
				$now + $window,
				$now,
				$now,
				$now,
				$now,
				$now + $window
			)
		);

		// 上限に達した時点で、そこから設定した時間を数える（画面の説明と合わせる）。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires = %d WHERE attempt_key = %s AND attempt_count >= %d",
				$now + $window,
				$key,
				$thresholds[ $type ]
			)
		);
	}

	// 期限切れの行を掃除する（テーブルは小さい）。
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires < %d", $now - DAY_IN_SECONDS ) );
}

/**
 * いまログインが制限されているかを調べる。
 *
 * @param string $username 入力されたユーザー名。
 * @return array {
 *     @type bool $locked  制限中かどうか。
 *     @type int  $minutes 解除までの残り時間（分）。制限中でなければ 0。
 * }
 */
function kashiwazaki_pattern_lock_get_lock_status( $username ) {
	global $wpdb;

	$thresholds = kashiwazaki_pattern_lock_get_failure_thresholds();
	$keys       = kashiwazaki_pattern_lock_get_failure_keys( $username );
	$table      = kashiwazaki_pattern_lock_get_attempt_table();
	$now        = time();

	$status = array(
		'locked'  => false,
		'minutes' => 0,
	);

	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT attempt_key, attempt_count, expires FROM {$table} WHERE attempt_key IN ({$placeholders})",
			array_values( $keys )
		)
	);

	foreach ( (array) $rows as $row ) {
		$type = array_search( $row->attempt_key, $keys, true );
		if ( false === $type ) {
			continue;
		}

		if ( (int) $row->expires <= $now ) {
			continue;
		}

		if ( (int) $row->attempt_count >= $thresholds[ $type ] ) {
			$minutes           = (int) ceil( ( (int) $row->expires - $now ) / MINUTE_IN_SECONDS );
			$status['locked']  = true;
			$status['minutes'] = max( $status['minutes'], max( 1, $minutes ) );
		}
	}

	return $status;
}

/**
 * ログインに成功したとき、その IP アドレス＋ユーザー名の失敗回数を消す。
 *
 * IP アドレスのみの回数は消さない（別アカウントで 1 回成功するだけで
 * 全体の制限を解除できてしまうのを防ぐため）。
 *
 * @param string $username ログインしたユーザー名。
 * @return void
 */
function kashiwazaki_pattern_lock_clear_failures( $username ) {
	global $wpdb;

	$keys = kashiwazaki_pattern_lock_get_failure_keys( $username );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( kashiwazaki_pattern_lock_get_attempt_table(), array( 'attempt_key' => $keys['user'] ) );
}
