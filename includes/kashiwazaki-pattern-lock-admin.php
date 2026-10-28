<?php
/**
 * 管理画面（設定ページ）と設定保存の処理。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理メニューを登録する。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_register_menu() {
	add_menu_page(
		'Kashiwazaki Pattern Lock',
		'Kashiwazaki Pattern Lock',
		'manage_options',
		KASHIWASAKI_PATTERN_LOCK_MENU_SLUG,
		'kashiwazaki_pattern_lock_render_settings_page',
		'dashicons-lock',
		KASHIWASAKI_PATTERN_LOCK_MENU_POSITION
	);
}
add_action( 'admin_menu', 'kashiwazaki_pattern_lock_register_menu' );

/**
 * 設定ページでだけ CSS / JavaScript を読み込む。
 *
 * @param string $hook_suffix 現在の管理画面のフック名。
 * @return void
 */
function kashiwazaki_pattern_lock_enqueue_admin_assets( $hook_suffix ) {
	if ( 'toplevel_page_' . KASHIWASAKI_PATTERN_LOCK_MENU_SLUG !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'kashiwazaki-pattern-lock-widget',
		KASHIWASAKI_PATTERN_LOCK_URL . 'assets/css/kashiwazaki-pattern-lock-widget.css',
		array(),
		KASHIWASAKI_PATTERN_LOCK_VERSION
	);

	wp_enqueue_style(
		'kashiwazaki-pattern-lock-admin',
		KASHIWASAKI_PATTERN_LOCK_URL . 'assets/css/kashiwazaki-pattern-lock-admin.css',
		array( 'kashiwazaki-pattern-lock-widget' ),
		KASHIWASAKI_PATTERN_LOCK_VERSION
	);

	wp_enqueue_script(
		'kashiwazaki-pattern-lock-widget',
		KASHIWASAKI_PATTERN_LOCK_URL . 'assets/js/kashiwazaki-pattern-lock-widget.js',
		array(),
		KASHIWASAKI_PATTERN_LOCK_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'kashiwazaki_pattern_lock_enqueue_admin_assets' );

/**
 * プラグイン一覧に「設定」リンクを追加する。
 *
 * @param string[] $links 既存のリンク。
 * @return string[]
 */
function kashiwazaki_pattern_lock_add_action_link( $links ) {
	$url = admin_url( 'admin.php?page=' . KASHIWASAKI_PATTERN_LOCK_MENU_SLUG );

	$label = kashiwazaki_pattern_lock_is_enabled()
		? __( '設定', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN )
		: __( '初期設定', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );

	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'
	);

	return $links;
}
add_filter( 'plugin_action_links_' . KASHIWASAKI_PATTERN_LOCK_BASENAME, 'kashiwazaki_pattern_lock_add_action_link' );

/**
 * 設定画面へ戻る（結果の通知コードを付ける）。
 *
 * @param string $notice 通知コード。
 * @return void
 */
function kashiwazaki_pattern_lock_redirect_with_notice( $notice ) {
	$url = add_query_arg(
		array(
			'page'       => KASHIWASAKI_PATTERN_LOCK_MENU_SLUG,
			'kpl_notice' => $notice,
		),
		admin_url( 'admin.php' )
	);

	wp_safe_redirect( $url );
	exit;
}

/**
 * 権限が無い場合に処理を止める。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_require_manage_options() {
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_die(
		esc_html__( 'この操作を行う権限がありません。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
		esc_html__( 'Kashiwazaki Pattern Lock', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
		array( 'response' => 403 )
	);
}

/**
 * パターンを保存する。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_handle_save_pattern() {
	kashiwazaki_pattern_lock_require_manage_options();
	check_admin_referer( 'kashiwazaki_pattern_lock_save_pattern' );

	$new_raw = isset( $_POST['kashiwazaki_pattern_lock_new'] )
		? wp_unslash( $_POST['kashiwazaki_pattern_lock_new'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下の検証で 1〜9 の並びだけに限定している。
		: '';
	$confirm_raw = isset( $_POST['kashiwazaki_pattern_lock_confirm'] )
		? wp_unslash( $_POST['kashiwazaki_pattern_lock_confirm'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下の検証で 1〜9 の並びだけに限定している。
		: '';

	$validated = kashiwazaki_pattern_lock_validate_input( $new_raw );

	if ( ! $validated['valid'] ) {
		$notice = 'pattern_invalid';
		if ( 'missing' === $validated['error'] ) {
			$notice = 'pattern_missing';
		} elseif ( 'short' === $validated['error'] ) {
			$notice = 'pattern_short';
		}

		kashiwazaki_pattern_lock_redirect_with_notice( $notice );
	}

	$confirmed = kashiwazaki_pattern_lock_normalize_pattern( $confirm_raw );
	if ( $confirmed !== $validated['normalized'] ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'pattern_mismatch' );
	}

	$hash = kashiwazaki_pattern_lock_hash_pattern( $validated['normalized'] );
	if ( '' === $hash ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'pattern_failed' );
	}

	$settings = kashiwazaki_pattern_lock_get_settings();

	$was_enabled = ( '' !== $settings['pattern_hash'] );

	$settings['pattern_hash']   = $hash;
	$settings['pattern_dots']   = kashiwazaki_pattern_lock_count_dots( $validated['normalized'] );
	$settings['pattern_set_at'] = time();
	$settings['pattern_set_by'] = get_current_user_id();

	kashiwazaki_pattern_lock_update_settings( $settings );

	$stored = kashiwazaki_pattern_lock_get_settings();
	if ( $stored['pattern_hash'] !== $hash ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'pattern_failed' );
	}

	kashiwazaki_pattern_lock_redirect_with_notice( $was_enabled ? 'pattern_saved' : 'pattern_created' );
}
add_action( 'admin_post_kashiwazaki_pattern_lock_save_pattern', 'kashiwazaki_pattern_lock_handle_save_pattern' );

/**
 * 失敗回数と制限時間の設定を保存する。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_handle_save_settings() {
	kashiwazaki_pattern_lock_require_manage_options();
	check_admin_referer( 'kashiwazaki_pattern_lock_save_settings' );

	$attempts = isset( $_POST['kashiwazaki_pattern_lock_max_attempts'] )
		? absint( wp_unslash( $_POST['kashiwazaki_pattern_lock_max_attempts'] ) )
		: 0;
	$minutes  = isset( $_POST['kashiwazaki_pattern_lock_lockout_minutes'] )
		? absint( wp_unslash( $_POST['kashiwazaki_pattern_lock_lockout_minutes'] ) )
		: 0;

	if ( $attempts < 1 || $attempts > KASHIWASAKI_PATTERN_LOCK_MAX_ATTEMPTS_LIMIT ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'settings_invalid' );
	}

	if ( $minutes < 1 || $minutes > KASHIWASAKI_PATTERN_LOCK_MAX_LOCKOUT_MINUTES ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'settings_invalid' );
	}

	// パターンの設定とは別の option に書く（同時に別の保存があっても、お互いの値を巻き戻さない）。
	// チェックが付いていなければ、フォームには値が届かない（オフ）。
	$trust_cloudflare = ! empty( $_POST['kashiwazaki_pattern_lock_trust_cloudflare'] ) ? 1 : 0;

	kashiwazaki_pattern_lock_update_limits( $attempts, $minutes, (bool) $trust_cloudflare );

	$stored = kashiwazaki_pattern_lock_get_settings();
	if ( (int) $stored['max_attempts'] !== $attempts || (int) $stored['lockout_minutes'] !== $minutes || (int) $stored['trust_cloudflare'] !== $trust_cloudflare ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'settings_failed' );
	}

	kashiwazaki_pattern_lock_redirect_with_notice( 'settings_saved' );
}
add_action( 'admin_post_kashiwazaki_pattern_lock_save_settings', 'kashiwazaki_pattern_lock_handle_save_settings' );

/**
 * パターンを解除する（ログインを標準認証に戻す）。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_handle_clear_pattern() {
	kashiwazaki_pattern_lock_require_manage_options();
	check_admin_referer( 'kashiwazaki_pattern_lock_clear_pattern' );

	if ( empty( $_POST['kashiwazaki_pattern_lock_confirm_clear'] ) ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'clear_not_confirmed' );
	}

	$settings = kashiwazaki_pattern_lock_get_settings();

	$settings['pattern_hash']   = '';
	$settings['pattern_dots']   = 0;
	$settings['pattern_set_at'] = 0;
	$settings['pattern_set_by'] = 0;

	kashiwazaki_pattern_lock_update_settings( $settings );

	if ( '' !== kashiwazaki_pattern_lock_get_settings()['pattern_hash'] ) {
		kashiwazaki_pattern_lock_redirect_with_notice( 'clear_failed' );
	}

	kashiwazaki_pattern_lock_redirect_with_notice( 'pattern_cleared' );
}
add_action( 'admin_post_kashiwazaki_pattern_lock_clear_pattern', 'kashiwazaki_pattern_lock_handle_clear_pattern' );

/**
 * プラグイン一覧で、パターン未設定のときだけ案内を出す。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_maybe_show_setup_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) || kashiwazaki_pattern_lock_is_enabled() ) {
		return;
	}

	$url = admin_url( 'admin.php?page=' . KASHIWASAKI_PATTERN_LOCK_MENU_SLUG );
	?>
	<div class="notice notice-info">
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: settings page URL. */
					__( '<strong>Kashiwazaki Pattern Lock:</strong> パターンがまだ設定されていません。<a href="%s">初期設定画面</a>でパターンを設定してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
					esc_url( $url )
				),
				array(
					'strong' => array(),
					'a'      => array( 'href' => array() ),
				)
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'kashiwazaki_pattern_lock_maybe_show_setup_notice' );

/**
 * 失敗回数のテーブルが無い状態かどうか（この要求での確認結果）を覚える・返す。
 *
 * @param bool|null $set 確認結果を記録するときだけ渡す（true = テーブルが無い）。
 * @return bool
 */
function kashiwazaki_pattern_lock_storage_missing( $set = null ) {
	static $missing = false;

	if ( null !== $set ) {
		$missing = (bool) $set;
	}

	return $missing;
}

/**
 * 管理者の画面で、失敗回数のテーブルの有無を確認する（画面の描画の前）。
 *
 * テーブルが無いとログイン制限が働かないため、管理者にだけ警告を出す。
 * 作り直しを試みるのは、プラグインの設定画面を開いたときだけ（通常ページ・ほかの管理画面では試みない）。
 * 描画の前（admin_init）で行うので、作れれば同じ画面の警告も出ない。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_check_storage_in_admin() {
	if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$exists = kashiwazaki_pattern_lock_table_exists();

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示する画面の判定だけで、状態は変えない。
	if ( ! $exists && KASHIWASAKI_PATTERN_LOCK_MENU_SLUG === $page ) {
		kashiwazaki_pattern_lock_install_storage();
		$exists = kashiwazaki_pattern_lock_table_exists();
	}

	kashiwazaki_pattern_lock_storage_missing( ! $exists );
}
add_action( 'admin_init', 'kashiwazaki_pattern_lock_check_storage_in_admin' );

/**
 * 失敗回数のテーブルが無いときに、管理者へ警告を出す。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_maybe_show_storage_notice() {
	if ( ! kashiwazaki_pattern_lock_storage_missing() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$url = admin_url( 'admin.php?page=' . KASHIWASAKI_PATTERN_LOCK_MENU_SLUG );
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: settings page URL. */
					__( '<strong>Kashiwazaki Pattern Lock:</strong> ログイン制限に使うテーブルがありません。このままでは、ログインに失敗しても回数が数えられず、ログイン制限が働きません（パターンの確認は働きます）。データベースの利用者にテーブルを作る権限（CREATE）があるかを確認し、<a href="%s">設定画面</a>を開き直してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
					esc_url( $url )
				),
				array(
					'strong' => array(),
					'a'      => array( 'href' => array() ),
				)
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'kashiwazaki_pattern_lock_maybe_show_storage_notice' );

/**
 * 結果の通知コードに対応する文言を返す。
 *
 * @param string $notice 通知コード。
 * @return array|null {
 *     @type string $type    success / error。
 *     @type string $message 表示する文言。
 * } 不明なコードなら null。
 */
function kashiwazaki_pattern_lock_get_notice( $notice ) {
	$notices = array(
		'pattern_created'     => array( 'success', __( 'パターンを設定しました。次回のログインからパターンが必要になります。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'pattern_saved'       => array( 'success', __( 'パターンを変更しました。次回のログインから新しいパターンが必要になります。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'pattern_cleared'     => array( 'success', __( 'パターンを解除しました。ログインはパターンなしの標準認証に戻りました。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'settings_saved'      => array(
			'success',
			sprintf(
				/* translators: 1: max attempts, 2: lockout minutes. */
				__( 'ログイン制限を保存しました。同じ IP アドレスとユーザー名の組み合わせで %1$d 回失敗すると、%2$d 分間ログインを制限します。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
				(int) kashiwazaki_pattern_lock_get_settings()['max_attempts'],
				(int) kashiwazaki_pattern_lock_get_settings()['lockout_minutes']
			),
		),
		'pattern_missing'     => array( 'error', __( 'パターンが入力されていません。9点のうち4点以上をなぞって入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'pattern_short'       => array( 'error', sprintf( /* translators: %d: minimum number of dots. */ __( 'パターンが短すぎます。%d点以上で入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), (int) KASHIWASAKI_PATTERN_LOCK_MIN_DOTS ) ),
		'pattern_invalid'     => array( 'error', __( 'パターンを読み取れませんでした。もう一度なぞって入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'pattern_mismatch'    => array( 'error', __( '確認用のパターンが一致しませんでした。新しいパターンと確認用パターンを、両方入力し直してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'pattern_failed'      => array( 'error', __( 'パターンを保存できませんでした。もう一度お試しください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'clear_failed'        => array( 'error', __( 'パターンを解除できませんでした。もう一度お試しください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'settings_failed'     => array( 'error', __( 'ログイン制限の設定を保存できませんでした。もう一度お試しください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
		'settings_invalid'    => array( 'error', sprintf( /* translators: 1: max attempts, 2: max minutes. */ __( '設定の値が範囲外です。失敗回数は 1〜%1$d、制限時間は 1〜%2$d 分で指定してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), (int) KASHIWASAKI_PATTERN_LOCK_MAX_ATTEMPTS_LIMIT, (int) KASHIWASAKI_PATTERN_LOCK_MAX_LOCKOUT_MINUTES ) ),
		'clear_not_confirmed' => array( 'error', __( '解除の影響を確認し、「上記を確認しました」にチェックを入れてください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ),
	);

	if ( ! is_string( $notice ) || ! isset( $notices[ $notice ] ) ) {
		return null;
	}

	return array(
		'type'    => $notices[ $notice ][0],
		'message' => $notices[ $notice ][1],
	);
}

/**
 * 設定ページを描画する。
 *
 * パターン未設定のときは初期設定画面、設定済みのときは管理画面を出す。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice_code = isset( $_GET['kpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['kpl_notice'] ) ) : '';
	$notice      = kashiwazaki_pattern_lock_get_notice( $notice_code );
	$settings    = kashiwazaki_pattern_lock_get_settings();

	if ( ! kashiwazaki_pattern_lock_is_enabled() ) {
		kashiwazaki_pattern_lock_render_setup_screen( $notice, $notice_code );
		return;
	}

	kashiwazaki_pattern_lock_render_management_screen( $settings, $notice, $notice_code );
}

/**
 * 上部の通知を出力する。
 *
 * @param array|null $notice 通知（type / message）。
 * @return void
 */
function kashiwazaki_pattern_lock_render_notice( $notice ) {
	if ( ! $notice ) {
		return;
	}
	?>
	<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
		<p><?php echo esc_html( $notice['message'] ); ?></p>
	</div>
	<?php
}

/**
 * パターンの入力エラーを表す通知コードか。
 *
 * @param string $notice_code 通知コード。
 * @return bool
 */
function kashiwazaki_pattern_lock_is_pattern_error( $notice_code ) {
	return in_array(
		$notice_code,
		array( 'pattern_missing', 'pattern_short', 'pattern_invalid', 'pattern_mismatch', 'pattern_failed' ),
		true
	);
}

/**
 * パターン設定フォーム（2 回入力）を出力する。
 *
 * @param string $first_label   1 つ目の入力欄の見出し。
 * @param string $submit_label  保存ボタンの文言。
 * @param string $note_html     保存ボタンの直前に出す補足（エスケープ済みの HTML）。
 * @param string $error_message 盤面の直前に出すエラー（空なら出さない）。
 * @param string $form_class    フォームに足す CSS クラス。
 * @return void
 */
function kashiwazaki_pattern_lock_render_pattern_form( $first_label, $submit_label, $note_html = '', $error_message = '', $form_class = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kashiwazaki-pattern-lock-form <?php echo esc_attr( $form_class ); ?>">
		<input type="hidden" name="action" value="kashiwazaki_pattern_lock_save_pattern" />
		<?php wp_nonce_field( 'kashiwazaki_pattern_lock_save_pattern' ); ?>

		<noscript>
			<p class="description">
				<?php echo esc_html__( 'パターンの入力には JavaScript が必要です。ブラウザの設定で JavaScript を有効にしてください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
			</p>
		</noscript>

		<?php if ( '' !== $error_message ) : ?>
			<div class="kashiwazaki-pattern-lock-admin__form-error" role="alert">
				<?php echo esc_html( $error_message ); ?>
			</div>
		<?php endif; ?>


		<div class="kashiwazaki-pattern-lock-form__fields">
			<?php
			kashiwazaki_pattern_lock_render_widget(
				array(
					'name'        => 'kashiwazaki_pattern_lock_new',
					'id'          => 'kashiwazaki-pattern-lock-new',
					'label'       => $first_label,
					'description' => sprintf( /* translators: %d: minimum number of dots. */ __( '%d点以上', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), (int) KASHIWASAKI_PATTERN_LOCK_MIN_DOTS ),
					'show_clear'  => true,
				)
			);

			kashiwazaki_pattern_lock_render_widget(
				array(
					'name'        => 'kashiwazaki_pattern_lock_confirm',
					'id'          => 'kashiwazaki-pattern-lock-confirm',
					'label'       => __( '確認用パターン', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
					'description' => __( '1で入力したパターンを、同じ順番で入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
					'show_clear'  => true,
				)
			);
			?>
		</div>

		<p class="description kashiwazaki-pattern-lock-admin__keyboard">
			<?php echo esc_html__( 'キーボードでも入力できます（矢印キーで移動、Space / Enter で選択、Backspace で1点戻す、Esc でクリア）。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
		</p>

		<?php echo $note_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し側でエスケープ済みの HTML を渡している。 ?>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php echo esc_html( $submit_label ); ?>
			</button>
		</p>
	</form>
	<?php
}

/**
 * 初期設定画面（パターンが未設定のとき）を描画する。
 *
 * @param array|null $notice      通知（type / message）。
 * @param string     $notice_code 通知コード。
 * @return void
 */
function kashiwazaki_pattern_lock_render_setup_screen( $notice, $notice_code = '' ) {
	$error_message = '';
	if ( $notice && kashiwazaki_pattern_lock_is_pattern_error( $notice_code ) ) {
		$error_message = $notice['message'];
	}
	?>
	<div class="wrap kashiwazaki-pattern-lock-admin">
		<h1><?php echo esc_html( 'Kashiwazaki Pattern Lock' ); ?></h1>

		<?php kashiwazaki_pattern_lock_render_notice( $notice ); ?>

		<div class="kpl-layout">
			<section class="postbox kpl-panel" aria-labelledby="kpl-setup-title">
				<div class="postbox-header">
					<h2 class="hndle" id="kpl-setup-title"><?php echo esc_html__( '初期設定', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2>
				</div>
				<div class="inside">
					<p class="kashiwazaki-pattern-lock-admin__lead">
						<?php
						echo esc_html(
							__( '9点のうち4点以上をなぞって、ログイン用のパターンを決めます。このサイトの標準ログイン画面を使う全ユーザーが同じパターンを使い、次回のログインからパターンが必要になります。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN )
						);
						?>
					</p>

					<?php
					kashiwazaki_pattern_lock_render_pattern_form(
						__( '1. 新しいパターン', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
						__( 'パターンを保存して開始', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
						'',
						$error_message
					);
					?>
				</div>
			</section>

			<details class="postbox kpl-panel kpl-help">
				<summary><h2 class="hndle"><?php echo esc_html__( '補足（推奨設定・困ったときの戻し方）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2></summary>
				<div class="inside">
					<ul>
						<li><?php echo esc_html__( '点が多いほど第三者が言い当てにくくなります（6点以上を推奨します）。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'ログイン制限（連続失敗の回数・時間）は、パターン設定後にこの画面で設定できます。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
					</ul>
					<p><strong><?php echo esc_html__( 'パターンを忘れた場合', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></strong></p>
					<p>
						<?php echo esc_html__( '管理画面にも入れなくなります。FTP クライアントやサーバーのファイルマネージャーで、次の作業をしてください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
					</p>
					<ol class="kashiwazaki-pattern-lock-admin__recovery">
						<li><?php echo esc_html__( 'wp-content/plugins/ を開きます。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'wp-plugin-kashiwazaki-pattern-lock フォルダの名前を、wp-plugin-kashiwazaki-pattern-lock.disabled などに変更します。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'WordPress がこのプラグインを自動的に無効化し、標準のユーザー名・パスワードだけでログインできる状態に戻ります。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'ログイン後、フォルダ名を元に戻して再度有効化し、この画面でパターンを設定し直してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
					</ol>
				</div>
			</details>
		</div>
	</div>
	<?php
}

/**
 * 管理画面（パターンが設定済みのとき）を描画する。
 *
 * @param array      $settings    設定。
 * @param array|null $notice      通知（type / message）。
 * @param string     $notice_code 通知コード。
 * @return void
 */
function kashiwazaki_pattern_lock_render_management_screen( $settings, $notice, $notice_code = '' ) {
	$set_user = '';
	if ( $settings['pattern_set_by'] > 0 ) {
		$user = get_userdata( $settings['pattern_set_by'] );
		if ( $user ) {
			$set_user = $user->display_name;
		}
	}

	// パターンの入力でエラーになったときだけ、変更フォームを開いた状態で戻す。
	$is_pattern_error = ( is_string( $notice_code ) && kashiwazaki_pattern_lock_is_pattern_error( $notice_code ) );
	$change_open      = $is_pattern_error ? ' open' : '';
	$error_message    = ( $notice && $is_pattern_error ) ? $notice['message'] : '';
	?>
	<div class="wrap kashiwazaki-pattern-lock-admin">
		<h1><?php echo esc_html( 'Kashiwazaki Pattern Lock' ); ?></h1>

		<?php kashiwazaki_pattern_lock_render_notice( $notice ); ?>

		<div class="kpl-layout">
			<section class="postbox kpl-panel" aria-labelledby="kpl-status-title">
				<div class="postbox-header">
					<h2 class="hndle" id="kpl-status-title"><?php echo esc_html__( '現在の状態', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2>
				</div>
				<div class="inside">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php echo esc_html__( 'サイト共通パターン', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></th>
								<td>
									<strong><?php echo esc_html__( '設定済み', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></strong>
									（<?php echo esc_html( sprintf( /* translators: %d: number of dots. */ __( '%d点', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), (int) $settings['pattern_dots'] ) ); ?>）
									<?php if ( (int) $settings['pattern_dots'] < 6 ) : ?>
										<p class="description">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: number of dots in use. */
													__( '現在は%d点です。点が多いほど推測されにくくなります（6点以上を推奨します）。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
													(int) $settings['pattern_dots']
												)
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
							<?php if ( $settings['pattern_set_at'] > 0 ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html__( '最終更新', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></th>
									<td>
										<?php
										echo esc_html(
											wp_date(
												get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
												$settings['pattern_set_at']
											)
										);

										if ( '' !== $set_user ) {
											echo '（' . esc_html( $set_user ) . '）';
										}
										?>
									</td>
								</tr>
							<?php endif; ?>
							<tr>
								<th scope="row"><?php echo esc_html__( 'ログイン保護', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></th>
								<td>
									<strong><?php echo esc_html__( '有効', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></strong>
									<?php echo esc_html__( '標準ログイン画面で、ユーザー名・パスワードに加えてパターンが必要です。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="postbox kpl-panel" aria-labelledby="kpl-change-title">
				<div class="postbox-header">
					<h2 class="hndle" id="kpl-change-title"><?php echo esc_html__( 'パターンの変更', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2>
				</div>
				<div class="inside">
					<p>
						<?php echo esc_html__( '変更すると、このサイトの標準ログイン画面を使う全ユーザーが新しいパターンを使うことになります。変更内容を利用者へ案内してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
					</p>

					<details class="kashiwazaki-pattern-lock-admin__disclosure kpl-change"<?php echo $change_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定の属性文字列。 ?>>
						<summary class="button button-secondary"><?php echo esc_html__( 'パターンを変更する', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></summary>
						<?php
						kashiwazaki_pattern_lock_render_pattern_form(
							__( '1. 新しいパターン', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
							__( 'パターンを保存', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
							'',
							$error_message
						);
						?>
					</details>
				</div>
			</section>

			<section class="postbox kpl-panel" aria-labelledby="kpl-limit-title">
				<div class="postbox-header">
					<h2 class="hndle" id="kpl-limit-title"><?php echo esc_html__( 'ログイン制限の設定', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kashiwazaki_pattern_lock_save_settings" />
					<?php wp_nonce_field( 'kashiwazaki_pattern_lock_save_settings' ); ?>

					<div class="inside">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row">
										<label for="kashiwazaki-pattern-lock-max-attempts"><?php echo esc_html__( '連続失敗の上限回数', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></label>
									</th>
									<td>
										<input
											type="number"
											id="kashiwazaki-pattern-lock-max-attempts"
											name="kashiwazaki_pattern_lock_max_attempts"
											value="<?php echo esc_attr( (string) $settings['max_attempts'] ); ?>"
											min="1"
											max="<?php echo esc_attr( (string) KASHIWASAKI_PATTERN_LOCK_MAX_ATTEMPTS_LIMIT ); ?>"
											step="1"
											class="small-text"
											required="required"
										/>
										<p class="description">
											<?php echo esc_html__( '同じ IP アドレスとユーザー名の組み合わせで、この回数だけ認証に失敗するとログインを制限します。1〜20 で指定します。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="kashiwazaki-pattern-lock-lockout-minutes"><?php echo esc_html__( '制限する時間（分）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></label>
									</th>
									<td>
										<input
											type="number"
											id="kashiwazaki-pattern-lock-lockout-minutes"
											name="kashiwazaki_pattern_lock_lockout_minutes"
											value="<?php echo esc_attr( (string) $settings['lockout_minutes'] ); ?>"
											min="1"
											max="<?php echo esc_attr( (string) KASHIWASAKI_PATTERN_LOCK_MAX_LOCKOUT_MINUTES ); ?>"
											step="1"
											class="small-text"
											required="required"
										/>
										<p class="description">
											<?php echo esc_html__( '上限に達したあと、この時間が経つまでログインを受け付けません。1〜1440（24時間）で指定します。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<?php echo esc_html__( 'Cloudflare 経由のとき', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
									</th>
									<td>
										<label for="kashiwazaki-pattern-lock-trust-cloudflare">
											<input
												type="checkbox"
												id="kashiwazaki-pattern-lock-trust-cloudflare"
												name="kashiwazaki_pattern_lock_trust_cloudflare"
												value="1"
												<?php checked( ! empty( $settings['trust_cloudflare'] ) ); ?>
											/>
											<?php echo esc_html__( '本当の接続元の IP アドレスを使う', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
										</label>
										<p class="description">
											<?php echo esc_html__( 'Cloudflare 経由のとき、訪問者の本当の IP アドレスで失敗を数えます。IP アドレスの範囲は、Cloudflare の公式 API（api.cloudflare.com）から 1 日 1 回、自動で取得します（サイトの情報は送りません）。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
										</p>
										<p class="description">
											<?php
											$kpl_cf_status = kashiwazaki_pattern_lock_cloudflare_status();
											$kpl_cf_info   = kashiwazaki_pattern_lock_cloudflare_ranges_info();
											$kpl_cf_reasons = array(
												'request' => __( '通信できません。外部への HTTP 通信を禁止する設定ではありませんか', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
												'status'  => __( '公式 API が成功以外の応答を返しました', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
												'format'  => __( '公式 API の応答が想定と違いました', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
												'invalid' => __( '公式 API の応答に使えない範囲がありました', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
												'save'    => __( 'データベースに保存できませんでした', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
											);
											if ( 'via' === $kpl_cf_status ) {
												$kpl_access = __( 'Cloudflare 経由です', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
											} elseif ( 'outside' === $kpl_cf_status ) {
												$kpl_access = __( 'Cloudflare 経由ではありません（Cloudflare のヘッダーは届いていますが、接続元が範囲の外です）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
											} else {
												$kpl_access = __( 'Cloudflare 経由ではありません', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
											}
											if ( 'api' === $kpl_cf_info['source'] ) {
												/* translators: %s: date and time. */
												$kpl_range = sprintf( __( '公式 API から取得（最終更新: %s）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), wp_date( 'Y-m-d H:i', $kpl_cf_info['fetched_at'] ) );
											} elseif ( $kpl_cf_info['checked_at'] > 0 && '' !== $kpl_cf_info['error'] ) {
												/* translators: %s: reason. */
												$kpl_range = sprintf( __( '内蔵の一覧（自動取得に失敗: %s）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), isset( $kpl_cf_reasons[ $kpl_cf_info['error'] ] ) ? $kpl_cf_reasons[ $kpl_cf_info['error'] ] : $kpl_cf_info['error'] );
											} else {
												$kpl_range = __( '内蔵の一覧（自動取得は未実施）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
											}
											echo esc_html( sprintf( /* translators: 1: access state, 2: range source. */ __( 'いまのアクセス: %1$s ／ IP アドレスの範囲: %2$s', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), $kpl_access, $kpl_range ) );
											?>
										</p>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<div class="kpl-actions">
						<button type="submit" class="button button-primary">
							<?php echo esc_html__( 'ログイン制限を保存', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
						</button>
					</div>
				</form>
			</section>

			<section class="postbox kpl-panel kpl-panel--danger" aria-labelledby="kpl-clear-title">
				<div class="postbox-header">
					<h2 class="hndle" id="kpl-clear-title"><?php echo esc_html__( 'パターンの解除', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kashiwazaki_pattern_lock_clear_pattern" />
					<?php wp_nonce_field( 'kashiwazaki_pattern_lock_clear_pattern' ); ?>
					<div class="inside">
						<p>
							<?php echo esc_html__( '解除すると、このサイトの標準ログイン画面を使う全ユーザーで、パターン入力が不要になります。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
						</p>
						<p>
							<label for="kashiwazaki-pattern-lock-confirm-clear">
								<input type="checkbox" name="kashiwazaki_pattern_lock_confirm_clear" id="kashiwazaki-pattern-lock-confirm-clear" value="1" />
								<?php echo esc_html__( '上記を確認しました（全ユーザーのログインが、パターン不要の状態に戻ります）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
							</label>
						</p>
					</div>
					<div class="kpl-actions">
						<button type="submit" class="button button-secondary">
							<?php echo esc_html__( 'パターン設定を解除', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?>
						</button>
					</div>
				</form>
			</section>

			<details class="postbox kpl-panel kpl-help">
				<summary><h2 class="hndle"><?php echo esc_html__( '対応範囲と制限', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></h2></summary>
				<div class="inside">
					<ul class="kashiwazaki-pattern-lock-admin__notes">
						<li><?php echo esc_html__( 'パターン認証が働くのは WordPress 標準のログイン画面（wp-login.php）だけです。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'REST API・アプリケーションパスワード・XML-RPC はパターンの対象外で、これまでどおりユーザー名とパスワードだけで認証されます。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'ログイン済みの Cookie はパターンの対象外です。すでにログインしている利用者はそのまま操作できます。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'ログイン制限は、接続元の IP アドレス（サーバーが申告する REMOTE_ADDR）で数えます。Cloudflare の背後では、本当の接続元の IP アドレスを自動で使います（Cloudflare の IP アドレスの範囲は、公式 API から 1 日 1 回、自動で取得します。上の「ログイン制限の設定」で止められます）。それ以外のリバースプロキシや CDN の背後では全員が同じ IP アドレスに見え、1 人の失敗が全員のログイン制限になります。その場合は、フィルター kashiwazaki_pattern_lock_client_ip で、信頼できる経路の本当の IP アドレスを返してください（設定例は README.md を参照）。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
						<li><?php echo esc_html__( 'パターンを忘れた場合や設定を誤った場合は、FTP などでプラグインのフォルダ名を変更して無効化してください。詳しくは README.md を参照してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ); ?></li>
					</ul>
				</div>
			</details>
		</div>
	</div>
	<?php
}
