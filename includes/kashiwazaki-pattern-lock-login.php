<?php
/**
 * 標準ログイン画面への組み込みと、サーバー側でのパターン検証。
 *
 * 対象は WordPress 標準のログイン画面（wp-login.php）だけ。
 * REST API・アプリケーションパスワード・XML-RPC では検証を行わない（README 参照）。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * いま処理しているのが標準ログイン画面からのログイン要求かどうか。
 *
 * 判定に `action` の値は使わない。WordPress 本体は action を未加工のまま比べて分岐し、
 * 一覧に無い値（`LOGOUT`・`lo!gout`・未定義の値など）は通常ログインとして処理する
 * （wp-login.php の `$default_actions` と `has_filter( 'login_form_' . $action )` の判定）。
 * こちらで action を加工して一覧と比べると、本体とこちらの判断がずれて、
 * 本体がログインとして処理する要求でパターン検証が丸ごと飛ばされる（2026-10-28 に実測）。
 *
 * WordPress 本体が wp-login.php の中で `authenticate` を呼ぶのは、通常ログインの処理
 * （`wp_signon()`）のときだけで、パスワード再設定・登録・ログアウトなどでは呼ばない
 * （2026-10-28 に全 action を実測）。そのため「wp-login.php を処理中」であれば、
 * `authenticate` が呼ばれた時点でログインとして扱ってよい。
 *
 * 次のいずれかに当てはまる場合だけ対象外とする。
 * - wp-login.php 以外（REST API・XML-RPC・wp-cli・管理画面の Ajax など）
 *
 * @return bool
 */
function kashiwazaki_pattern_lock_is_standard_login_request() {
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	// wp-login.php の読み込み時にだけ発火するアクション。ここが無ければ標準ログイン画面ではない。
	return (bool) did_action( 'login_init' );
}

/**
 * ログイン画面にウィジェット用の CSS / JavaScript を読み込む。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_enqueue_login_assets() {
	if ( ! kashiwazaki_pattern_lock_is_enabled() ) {
		return;
	}

	wp_enqueue_style(
		'kashiwazaki-pattern-lock-widget',
		KASHIWASAKI_PATTERN_LOCK_URL . 'assets/css/kashiwazaki-pattern-lock-widget.css',
		array(),
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
add_action( 'login_enqueue_scripts', 'kashiwazaki_pattern_lock_enqueue_login_assets' );

/**
 * ログインフォーム（パスワード欄の直後）にパターン入力を差し込む。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_render_login_field() {
	if ( ! kashiwazaki_pattern_lock_is_enabled() ) {
		return;
	}
	?>
	<div class="kashiwazaki-pattern-lock-login">
		<?php
		kashiwazaki_pattern_lock_render_widget(
			array(
				'name'       => KASHIWASAKI_PATTERN_LOCK_FORM_FIELD,
				'id'         => 'kashiwazaki-pattern-lock-login',
				'label'      => __( 'パターン', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
				'class'      => 'kashiwazaki-pattern-lock--login',
				'show_clear' => true,
			)
		);
		?>
		<noscript>
			<p class="kashiwazaki-pattern-lock-noscript">
				<?php
				echo esc_html(
					__( 'パターン入力には JavaScript が必要です。ブラウザの設定で JavaScript を有効にしてください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN )
				);
				?>
			</p>
		</noscript>
	</div>
	<?php
}
add_action( 'login_form', 'kashiwazaki_pattern_lock_render_login_field' );

/**
 * ログイン制限中のエラーを作る。
 *
 * @param int $minutes 解除までの残り時間（分）。
 * @return WP_Error
 */
function kashiwazaki_pattern_lock_locked_error( $minutes ) {
	return new WP_Error(
		'kashiwazaki_pattern_lock_locked',
		sprintf(
			/* translators: %d: minutes until the login restriction is lifted. */
			__( '<strong>ログインを制限しています。</strong>認証の失敗が続いたため、約 %d 分後にもう一度お試しください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
			(int) $minutes
		)
	);
}

/**
 * ログイン制限中なら、最終的な認証結果をログイン制限のエラーに差し替える。
 *
 * 優先度を 100（最後）にしているのは、WordPress 本体の認証ハンドラ
 * （wp_authenticate_username_password など、優先度 20）が
 * 「WP_User のときだけ処理を打ち切る」作りで、前段で返した WP_Error を
 * 新しい WP_User で上書きしてしまうため。優先度 5 では制限が効かない。
 *
 * @param null|WP_User|WP_Error $user     WordPress が渡してくるユーザー。
 * @param string                $username 入力されたユーザー名。
 * @param string                $password 入力されたパスワード。
 * @return null|WP_User|WP_Error
 */
function kashiwazaki_pattern_lock_block_locked_login( $user, $username, $password ) {
	unset( $password );

	if ( ! kashiwazaki_pattern_lock_is_enabled() ) {
		return $user;
	}

	if ( ! kashiwazaki_pattern_lock_is_standard_login_request() ) {
		return $user;
	}

	$username = is_string( $username ) ? $username : '';
	if ( '' === trim( $username ) ) {
		return $user;
	}

	$status = kashiwazaki_pattern_lock_get_lock_status( $username );
	if ( ! $status['locked'] ) {
		return $user;
	}

	return kashiwazaki_pattern_lock_locked_error( $status['minutes'] );
}
add_filter( 'authenticate', 'kashiwazaki_pattern_lock_block_locked_login', 100, 3 );

/**
 * ユーザー名・パスワードが正しい場合に、さらにパターンを照合する。
 *
 * 照合は必ずサーバー側で行う。画面からは点の番号の並び（例: "1,2,5,9"）だけを受け取り、
 * 飛び越えた点の補完もサーバー側でやり直してからハッシュと比べる。
 *
 * @param null|WP_User|WP_Error $user     WordPress が渡してくるユーザー。
 * @param string                $username 入力されたユーザー名。
 * @param string                $password 入力されたパスワード。
 * @return null|WP_User|WP_Error
 */
function kashiwazaki_pattern_lock_authenticate( $user, $username, $password ) {
	unset( $password );

	$settings = kashiwazaki_pattern_lock_get_settings();
	if ( '' === $settings['pattern_hash'] ) {
		return $user;
	}

	if ( ! kashiwazaki_pattern_lock_is_standard_login_request() ) {
		return $user;
	}

	// ユーザー名・パスワードの時点で失敗している場合は、そのエラーをそのまま返す。
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	if ( ! ( $user instanceof WP_User ) ) {
		return $user;
	}

	// 制限中は、パターンの照合（bcrypt）を実行しない。
	$lock = kashiwazaki_pattern_lock_get_lock_status( is_string( $username ) ? $username : '' );
	if ( $lock['locked'] ) {
		return kashiwazaki_pattern_lock_locked_error( $lock['minutes'] );
	}

	$raw = isset( $_POST[ KASHIWASAKI_PATTERN_LOCK_FORM_FIELD ] )
		? wp_unslash( $_POST[ KASHIWASAKI_PATTERN_LOCK_FORM_FIELD ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下の検証で 1〜9 の並びだけに限定している。
		: '';

	$validated = kashiwazaki_pattern_lock_validate_input( $raw );

	if ( ! $validated['valid'] ) {
		if ( 'missing' === $validated['error'] ) {
			return new WP_Error(
				'kashiwazaki_pattern_lock_missing',
				__( '<strong>パターンが入力されていません。</strong>9点のうち4点以上をなぞって入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN )
			);
		}

		if ( 'short' === $validated['error'] ) {
			return new WP_Error(
				'kashiwazaki_pattern_lock_short',
				sprintf(
					/* translators: %d: minimum number of dots. */
					__( '<strong>パターンが短すぎます。</strong>9点のうち%d点以上をなぞって入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
					(int) KASHIWASAKI_PATTERN_LOCK_MIN_DOTS
				)
			);
		}

		return new WP_Error(
			'kashiwazaki_pattern_lock_invalid',
			__( '<strong>パターンを読み取れませんでした。</strong>点をなぞり直して入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN )
		);
	}

	if ( ! kashiwazaki_pattern_lock_verify_pattern( $validated['normalized'], $settings['pattern_hash'] ) ) {
		return new WP_Error(
			'kashiwazaki_pattern_lock_wrong',
			__( '<strong>パターンが正しくありません。</strong>もう一度なぞって入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN )
		);
	}

	return $user;
}
add_filter( 'authenticate', 'kashiwazaki_pattern_lock_authenticate', 30, 3 );

/**
 * 認証に失敗したら回数を数える。
 *
 * ユーザー名・パスワードの誤りも、パターンの誤りも同じように数える。
 * ただしログイン制限そのものによる失敗は数えない（制限が延び続けないようにするため）。
 *
 * @param string        $username 入力されたユーザー名。
 * @param WP_Error|null $error    認証失敗の内容。
 * @return void
 */
function kashiwazaki_pattern_lock_handle_login_failed( $username, $error = null ) {
	if ( ! kashiwazaki_pattern_lock_is_enabled() ) {
		return;
	}

	if ( ! kashiwazaki_pattern_lock_is_standard_login_request() ) {
		return;
	}

	if ( $error instanceof WP_Error && 'kashiwazaki_pattern_lock_locked' === $error->get_error_code() ) {
		return;
	}

	$username = is_string( $username ) ? $username : '';
	if ( '' === trim( $username ) ) {
		return;
	}

	kashiwazaki_pattern_lock_record_failure( $username );
}
add_action( 'wp_login_failed', 'kashiwazaki_pattern_lock_handle_login_failed', 10, 2 );

/**
 * ログインに成功したら、その組み合わせの失敗回数を消す。
 *
 * @param string  $user_login ログインしたユーザー名。
 * @param WP_User $user       ログインしたユーザー。
 * @return void
 */
function kashiwazaki_pattern_lock_handle_login_success( $user_login, $user = null ) {
	if ( ! is_string( $user_login ) || '' === $user_login ) {
		return;
	}

	kashiwazaki_pattern_lock_clear_failures( $user_login );

	// メールアドレスでログインした場合、失敗はメールアドレスをキーに数えている。
	if ( $user instanceof WP_User && ! empty( $user->user_email ) ) {
		kashiwazaki_pattern_lock_clear_failures( $user->user_email );
	}
}
add_action( 'wp_login', 'kashiwazaki_pattern_lock_handle_login_success', 10, 2 );
