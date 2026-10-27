<?php
/**
 * Plugin Name: Kashiwazaki Pattern Lock
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: WordPress標準のログイン画面に、9点をなぞるパターンロック認証を追加するプラグインです。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-plugin-kashiwazaki-pattern-lock
 * Domain Path: /languages
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * プラグイン基本定数
 * ---------------------------------------------------------------------------
 * 名称は省略せず、常に「Kashiwazaki Pattern Lock」と表記する方針のため、
 * 定数・関数の接頭辞も省略形を使わずフル表記にしている。
 */
define( 'KASHIWASAKI_PATTERN_LOCK_VERSION', '1.0.0' );
define( 'KASHIWASAKI_PATTERN_LOCK_FILE', __FILE__ );
define( 'KASHIWASAKI_PATTERN_LOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'KASHIWASAKI_PATTERN_LOCK_URL', plugin_dir_url( __FILE__ ) );
define( 'KASHIWASAKI_PATTERN_LOCK_BASENAME', plugin_basename( __FILE__ ) );

/** 管理メニューのスラッグと表示位置。 */
define( 'KASHIWASAKI_PATTERN_LOCK_MENU_SLUG', 'kashiwazaki-pattern-lock' );
define( 'KASHIWASAKI_PATTERN_LOCK_MENU_POSITION', 81 );

/** 設定（wp_options）のキー。 */
define( 'KASHIWASAKI_PATTERN_LOCK_OPTION_KEY', 'kashiwazaki_pattern_lock_settings' );

/** ログイン画面でパターンを受け取る POST フィールド名。 */
define( 'KASHIWASAKI_PATTERN_LOCK_FORM_FIELD', 'kashiwazaki_pattern_lock_pattern' );

/** 入力に必要な最小の点数（仕様により 4 点以上）。 */
define( 'KASHIWASAKI_PATTERN_LOCK_MIN_DOTS', 4 );

/** 連続失敗の既定値。 */
define( 'KASHIWASAKI_PATTERN_LOCK_DEFAULT_MAX_ATTEMPTS', 5 );
define( 'KASHIWASAKI_PATTERN_LOCK_DEFAULT_LOCKOUT_MINUTES', 15 );

/** 設定として受け付ける上限。 */
define( 'KASHIWASAKI_PATTERN_LOCK_MAX_ATTEMPTS_LIMIT', 20 );
define( 'KASHIWASAKI_PATTERN_LOCK_MAX_LOCKOUT_MINUTES', 1440 );

/** 失敗回数を数える transient の接頭辞。 */
define( 'KASHIWASAKI_PATTERN_LOCK_TRANSIENT_PREFIX', 'kashiwazaki_pattern_lock_fail_' );

/** 失敗回数を数える保存先（専用テーブル）の定義バージョン。 */
define( 'KASHIWASAKI_PATTERN_LOCK_DB_VERSION', '1' );

/** 文字列のドメイン。 */
define( 'KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN', 'wp-plugin-kashiwazaki-pattern-lock' );

require_once KASHIWASAKI_PATTERN_LOCK_DIR . 'includes/kashiwazaki-pattern-lock-pattern.php';
require_once KASHIWASAKI_PATTERN_LOCK_DIR . 'includes/kashiwazaki-pattern-lock-storage.php';
require_once KASHIWASAKI_PATTERN_LOCK_DIR . 'includes/kashiwazaki-pattern-lock-rate-limit.php';
require_once KASHIWASAKI_PATTERN_LOCK_DIR . 'includes/kashiwazaki-pattern-lock-widget.php';
require_once KASHIWASAKI_PATTERN_LOCK_DIR . 'includes/kashiwazaki-pattern-lock-login.php';
require_once KASHIWASAKI_PATTERN_LOCK_DIR . 'includes/kashiwazaki-pattern-lock-admin.php';

/**
 * 翻訳ファイルを読み込む。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_load_textdomain() {
	load_plugin_textdomain(
		KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN,
		false,
		dirname( KASHIWASAKI_PATTERN_LOCK_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'kashiwazaki_pattern_lock_load_textdomain' );

/**
 * 有効化時の処理。
 *
 * パターンは空のまま（＝ログインを妨げない状態）で、失敗回数の設定だけを用意する。
 * あわせて、次の管理画面表示で初期設定画面へ案内するための目印を置く。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_activate() {
	if ( false === get_option( KASHIWASAKI_PATTERN_LOCK_OPTION_KEY, false ) ) {
		add_option(
			KASHIWASAKI_PATTERN_LOCK_OPTION_KEY,
			kashiwazaki_pattern_lock_get_default_settings(),
			'',
			'no'
		);
	}

	set_transient( 'kashiwazaki_pattern_lock_activation_redirect', 1, 60 );

	kashiwazaki_pattern_lock_install_storage();
}
register_activation_hook( __FILE__, 'kashiwazaki_pattern_lock_activate' );

/**
 * 有効化した直後の管理画面で、初期設定画面へ案内する。
 *
 * 一括有効化・ネットワーク管理・権限の無い利用者では移動しない。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_maybe_redirect_after_activation() {
	if ( ! get_transient( 'kashiwazaki_pattern_lock_activation_redirect' ) ) {
		return;
	}

	// 画面を表示しない要求（Ajax・cron・ネットワーク管理）では目印を消費しない。
	if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) {
		return;
	}

	// 一括有効化のときは、ほかのプラグインの結果も見たいはずなので移動しない。
	if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		delete_transient( 'kashiwazaki_pattern_lock_activation_redirect' );
		return;
	}

	// 権限の無い利用者の画面では消費しない（管理者が最初に開いた画面で案内する）。
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	delete_transient( 'kashiwazaki_pattern_lock_activation_redirect' );

	wp_safe_redirect( admin_url( 'admin.php?page=' . KASHIWASAKI_PATTERN_LOCK_MENU_SLUG ) );
	exit;
}
add_action( 'admin_init', 'kashiwazaki_pattern_lock_maybe_redirect_after_activation' );
