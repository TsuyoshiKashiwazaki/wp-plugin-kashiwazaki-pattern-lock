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
 * 値が IP アドレス（IPv4 または IPv6）の文字列かを返す。
 *
 * 通常は PHP の filter_var() で判定する。filter_var() が使えない環境（disable_functions で
 * 無効にしている場合など）では、同じ判定になる PHP だけの検査に切り替える。そのままだと、
 * 関数が無いという致命的エラーでログインの処理が止まってしまう。
 *
 * @param mixed $value 検査する値。
 * @return bool
 */
function kashiwazaki_pattern_lock_is_valid_ip( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}
	if ( function_exists( 'filter_var' ) ) {
		return false !== filter_var( $value, FILTER_VALIDATE_IP );
	}

	// 文字を絞ってから調べる（途中に NUL や空白を含む値は、ここで false になる）。
	if ( strlen( $value ) > 45 || 1 !== preg_match( '/\A[0-9A-Fa-f:.]+\z/', $value ) ) {
		return false;
	}
	$octet = '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])';
	$ipv4  = '/\A' . $octet . '(?:\.' . $octet . '){3}\z/';
	if ( false === strpos( $value, ':' ) ) {
		return 1 === preg_match( $ipv4, $value );
	}

	// IPv6。末尾が IPv4 の形なら、2 組として数える。
	$last = substr( $value, (int) strrpos( $value, ':' ) + 1 );
	if ( false !== strpos( $last, '.' ) ) {
		if ( 1 !== preg_match( $ipv4, $last ) ) {
			return false;
		}
		$value = substr( $value, 0, strlen( $value ) - strlen( $last ) ) . '0:0';
	} elseif ( false !== strpos( $value, '.' ) ) {
		return false;
	}
	if ( false !== strpos( $value, ':::' ) || substr_count( $value, '::' ) > 1 ) {
		return false;
	}

	// 各組は 1〜4 桁の 16 進。'::' があれば前後の組の合計は 7 以下、無ければ 8 組ちょうど。
	$groups = function ( $part ) {
		if ( '' === $part ) {
			return array();
		}
		$list = explode( ':', $part );
		foreach ( $list as $group ) {
			if ( 1 !== preg_match( '/\A[0-9A-Fa-f]{1,4}\z/', $group ) ) {
				return false;
			}
		}
		return $list;
	};
	if ( false !== strpos( $value, '::' ) ) {
		list( $head, $tail ) = explode( '::', $value, 2 );
		$head_groups         = $groups( $head );
		$tail_groups         = $groups( $tail );
		return false !== $head_groups && false !== $tail_groups && count( $head_groups ) + count( $tail_groups ) <= 7;
	}
	$all = $groups( $value );
	return false !== $all && 8 === count( $all );
}

/**
 * 内蔵の、Cloudflare が使う IP アドレスの範囲（CIDR）を返す。
 *
 * 出典: https://www.cloudflare.com/ips-v4 と https://www.cloudflare.com/ips-v6（2026-10-28 に取得）。
 * 自動取得（kashiwazaki_pattern_lock_refresh_cloudflare_ranges）に成功していないときに使う。
 *
 * @return string[]
 */
function kashiwazaki_pattern_lock_cloudflare_builtin_ranges() {
	return array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);
}

/**
 * Cloudflare の範囲として使えない（予約された・内部用の）範囲の一覧を返す。
 *
 * Cloudflare の公開の範囲と重なることはない。これらと重なる範囲が一覧に入っていたら、一覧ごと使わない。
 *
 * @return string[]
 */
function kashiwazaki_pattern_lock_reserved_ranges() {
	return array(
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
		'192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24',
		'198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
		'::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '100:0:0:1::/64', '2001::/23',
		'2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
	);
}

/**
 * 2 つの範囲（CIDR）が重なるかを返す（片方の先頭のアドレスが、もう片方に入っていれば重なる）。
 *
 * @param string $a 範囲。
 * @param string $b 範囲。
 * @return bool
 */
function kashiwazaki_pattern_lock_cidrs_overlap( $a, $b ) {
	$net_a = explode( '/', $a, 2 );
	$net_b = explode( '/', $b, 2 );
	return kashiwazaki_pattern_lock_ip_in_cidr( $net_a[0], $b ) || kashiwazaki_pattern_lock_ip_in_cidr( $net_b[0], $a );
}

/**
 * 範囲の先頭のアドレスに、ホスト部（範囲の長さより後ろのビット）が残っていないか（正規の CIDR でないか）を返す。
 *
 * @param string $bin  inet_pton() で変換したアドレス。
 * @param int    $bits 範囲の長さ。
 * @return bool 残っている（正規でない）とき true。
 */
function kashiwazaki_pattern_lock_cidr_host_bits_set( $bin, $bits ) {
	$len  = strlen( $bin );
	$full = intdiv( $bits, 8 );
	$rest = $bits % 8;
	if ( $rest > 0 ) {
		if ( ( ord( $bin[ $full ] ) & ( 0xff >> $rest ) ) !== 0 ) {
			return true;
		}
		$full++;
	}
	for ( $i = $full; $i < $len; $i++ ) {
		if ( "\0" !== $bin[ $i ] ) {
			return true;
		}
	}
	return false;
}

/**
 * 取得した範囲の一覧を検査して、安全なものだけを返す（1 つでも不正なら false）。
 *
 * Cloudflare の公式 API の応答を、そのまま信用しないための検査。信用の判断に使う一覧なので、
 * 形式が正しく（正規の CIDR で、ホスト部が 0）、広すぎず（IPv4 は /12 以上、IPv6 は /28 以上）、狭すぎず（IPv4 は /24 まで、
 * IPv6 は /48 まで。1 つの IP アドレスだけの範囲は通さない）、予約された範囲と重ならず、重複しない（別の表記も同じ範囲と数える）
 * ものだけを受け入れる（一覧の中で範囲が重なるものも通さない）。Cloudflare の実際の範囲は IPv4 が /13〜/22、IPv6 が /29〜/32。
 *
 * 注意: この検査は、危険な形や範囲を除くもので、一覧が Cloudflare のものであることの証明ではない。
 * 真正性は、公式 API への HTTPS 接続（証明書の検証）と、サイトのデータベースの保護に依る。
 *
 * @param mixed  $list   検査する一覧。
 * @param string $family 'v4' または 'v6'。
 * @return string[]|false
 */
function kashiwazaki_pattern_lock_sanitize_cidrs( $list, $family ) {
	if ( ! function_exists( 'inet_pton' ) ) {
		return false; // 範囲を判定できない環境では、自動取得した一覧を使わない（内蔵の一覧のまま）。
	}
	$min_count = ( 'v6' === $family ) ? 3 : 5;
	$min_bits  = ( 'v6' === $family ) ? 28 : 12;
	$max_bits  = ( 'v6' === $family ) ? 48 : 24;
	if ( ! is_array( $list ) || count( $list ) > 200 ) {
		return false;
	}
	$out  = array();
	$seen = array();
	foreach ( $list as $cidr ) {
		if ( ! is_string( $cidr ) ) {
			return false;
		}
		$parts = explode( '/', $cidr );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/\A[0-9]{1,3}\z/', $parts[1] ) ) {
			return false;
		}
		$is_v6 = ( false !== strpos( $parts[0], ':' ) );
		if ( $is_v6 !== ( 'v6' === $family ) || ! kashiwazaki_pattern_lock_is_valid_ip( $parts[0] ) ) {
			return false;
		}
		$bits = (int) $parts[1];
		if ( $bits < $min_bits || $bits > $max_bits ) {
			return false;
		}
		$bin = @inet_pton( $parts[0] );
		if ( false === $bin || kashiwazaki_pattern_lock_cidr_host_bits_set( $bin, $bits ) ) {
			return false; // 正規の CIDR でない（例: 8.8.8.1/24）。
		}
		$key = bin2hex( $bin ) . '/' . $bits;
		if ( isset( $seen[ $key ] ) ) {
			return false; // 重複（別の表記でも同じ範囲）で件数を水増しさせない。
		}
		$seen[ $key ] = true;
		foreach ( $out as $prev ) {
			if ( kashiwazaki_pattern_lock_cidrs_overlap( $cidr, $prev ) ) {
				return false; // 一覧の中で範囲が重なる（片方がもう片方を含む）。
			}
		}
		foreach ( kashiwazaki_pattern_lock_reserved_ranges() as $reserved ) {
			if ( kashiwazaki_pattern_lock_cidrs_overlap( $cidr, $reserved ) ) {
				return false;
			}
		}
		$out[] = $cidr;
	}
	return count( $out ) >= $min_count ? $out : false;
}

/**
 * 保存してある（公式 API から取得した）範囲を、検査して返す。使えなければ null。
 *
 * 取得から 45 日以内で、取得時刻が整数で未来でなく、一覧が検査を通ったときだけ返す。
 * 読むとき（使う範囲の決定）と、表示（管理画面の状態）の両方が、この関数の判定を使う。
 *
 * @return array|null { 'ipv4' => string[], 'ipv6' => string[], 'fetched_at' => int }
 */
function kashiwazaki_pattern_lock_cloudflare_stored_ranges() {
	$stored = get_option( KASHIWASAKI_PATTERN_LOCK_CF_RANGES_KEY, array() );
	if ( ! is_array( $stored ) || ! isset( $stored['fetched_at'] ) || ! is_int( $stored['fetched_at'] ) ) {
		return null;
	}
	$at  = $stored['fetched_at'];
	$age = time() - $at;
	if ( $at <= 0 || $age < 0 || $age > 45 * DAY_IN_SECONDS ) {
		return null;
	}
	$v4 = kashiwazaki_pattern_lock_sanitize_cidrs( isset( $stored['ipv4'] ) ? $stored['ipv4'] : null, 'v4' );
	$v6 = kashiwazaki_pattern_lock_sanitize_cidrs( isset( $stored['ipv6'] ) ? $stored['ipv6'] : null, 'v6' );
	if ( false === $v4 || false === $v6 ) {
		return null;
	}
	return array( 'ipv4' => $v4, 'ipv6' => $v6, 'fetched_at' => $at );
}

/**
 * Cloudflare が使う IP アドレスの範囲を返す。
 *
 * Cloudflare の公式 API から自動で取得した一覧（45 日以内に取得し、検査を通ったもの）があれば、それを使う。
 * 無い・古い・壊れているときは、内蔵の一覧を使う。
 *
 * @return string[]
 */
function kashiwazaki_pattern_lock_cloudflare_ranges() {
	$ranges = kashiwazaki_pattern_lock_cloudflare_builtin_ranges();
	$fetched = kashiwazaki_pattern_lock_cloudflare_stored_ranges();
	if ( null !== $fetched ) {
		$ranges = array_merge( $fetched['ipv4'], $fetched['ipv6'] );
	}

	/**
	 * Cloudflare の IP アドレスの範囲（CIDR の配列）を差し替える。
	 *
	 * @param string[] $ranges 使う範囲。
	 */
	$filtered = apply_filters( 'kashiwazaki_pattern_lock_cloudflare_ranges', $ranges );
	return is_array( $filtered ) ? $filtered : $ranges;
}

/**
 * Cloudflare の IP アドレスの範囲の状態を返す（管理画面の表示用）。
 *
 * @return array { 'source' => 'api'|'builtin', 'fetched_at' => int, 'checked_at' => int, 'error' => string }
 */
function kashiwazaki_pattern_lock_cloudflare_ranges_info() {
	$stored = get_option( KASHIWASAKI_PATTERN_LOCK_CF_RANGES_KEY, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$fetched = kashiwazaki_pattern_lock_cloudflare_stored_ranges();
	return array(
		'source'     => null !== $fetched ? 'api' : 'builtin',
		'fetched_at' => null !== $fetched ? $fetched['fetched_at'] : 0,
		'checked_at' => isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : 0,
		'error'      => isset( $stored['error'] ) && is_string( $stored['error'] ) ? $stored['error'] : '',
	);
}

/**
 * Cloudflare の公式 API から、IP アドレスの範囲を取得して保存する（1 日 1 回、WP-Cron から呼ばれる）。
 *
 * 公式: https://developers.cloudflare.com/api/resources/ips/methods/list/（認証は不要。サイトの情報は送らない）。
 * 取得に失敗したり、検査を通らなかったりしたときは、前に保存した一覧を残し、失敗の種類だけを記録する。
 * 管理画面の「Cloudflare 経由のとき、本当の接続元の IP アドレスを使う」がオフのときは、取得しない。
 *
 * @return bool 保存できたとき true。
 */
function kashiwazaki_pattern_lock_refresh_cloudflare_ranges() {
	$settings = kashiwazaki_pattern_lock_get_settings();
	/**
	 * Cloudflare の IP アドレスの範囲を、公式 API から自動で取得するかどうか（既定: 管理画面のチェックに従う）。
	 *
	 * @param bool $enabled 取得するとき true。
	 */
	if ( ! apply_filters( 'kashiwazaki_pattern_lock_auto_update_cloudflare_ranges', ! empty( $settings['trust_cloudflare'] ) ) ) {
		return false;
	}

	$state = get_option( KASHIWASAKI_PATTERN_LOCK_CF_RANGES_KEY, array() );
	$state = is_array( $state ) ? $state : array();
	$fail  = function ( $why ) use ( $state ) {
		$state['checked_at'] = time();
		$state['error']      = $why;
		update_option( KASHIWASAKI_PATTERN_LOCK_CF_RANGES_KEY, $state, false );
		return false;
	};

	$response = wp_remote_get(
		'https://api.cloudflare.com/client/v4/ips',
		array(
			'timeout'             => 5,
			'redirection'         => 0,
			'limit_response_size' => 65536,
			'user-agent'          => 'Kashiwazaki-Pattern-Lock/' . KASHIWASAKI_PATTERN_LOCK_VERSION,
			'headers'             => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $fail( 'request' );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $fail( 'status' );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || true !== ( isset( $data['success'] ) ? $data['success'] : null ) || ! isset( $data['result'] ) || ! is_array( $data['result'] ) ) {
		return $fail( 'format' );
	}
	$v4 = kashiwazaki_pattern_lock_sanitize_cidrs( isset( $data['result']['ipv4_cidrs'] ) ? $data['result']['ipv4_cidrs'] : null, 'v4' );
	$v6 = kashiwazaki_pattern_lock_sanitize_cidrs( isset( $data['result']['ipv6_cidrs'] ) ? $data['result']['ipv6_cidrs'] : null, 'v6' );
	if ( false === $v4 || false === $v6 ) {
		return $fail( 'invalid' );
	}

	$now = time();
	update_option(
		KASHIWASAKI_PATTERN_LOCK_CF_RANGES_KEY,
		array(
			'ipv4'       => $v4,
			'ipv6'       => $v6,
			'fetched_at' => $now,
			'checked_at' => $now,
			'error'      => '',
		),
		false
	);
	$saved = get_option( KASHIWASAKI_PATTERN_LOCK_CF_RANGES_KEY, array() );
	if ( ! is_array( $saved ) || ! isset( $saved['fetched_at'], $saved['ipv4'], $saved['ipv6'] ) || $saved['fetched_at'] !== $now || $saved['ipv4'] !== $v4 || $saved['ipv6'] !== $v6 ) {
		return $fail( 'save' ); // 保存できていない（データベースの書き込みの失敗）。
	}
	return true;
}
add_action( KASHIWASAKI_PATTERN_LOCK_CF_CRON_HOOK, 'kashiwazaki_pattern_lock_refresh_cloudflare_ranges' );

/**
 * 1 日 1 回の取得の予定を入れる（既にあれば何もしない）。
 *
 * @return void
 */
function kashiwazaki_pattern_lock_schedule_cloudflare_refresh() {
	if ( ! wp_next_scheduled( KASHIWASAKI_PATTERN_LOCK_CF_CRON_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', KASHIWASAKI_PATTERN_LOCK_CF_CRON_HOOK );
	}
}
add_action( 'admin_init', 'kashiwazaki_pattern_lock_schedule_cloudflare_refresh' );

/**
 * IP アドレスが、CIDR の範囲に入っているかを返す（IPv4・IPv6）。
 *
 * inet_pton() が使えない環境や、解釈できない値は、入っていない（false）として扱う。
 *
 * @param string $ip   調べる IP アドレス。
 * @param string $cidr 範囲（例: 173.245.48.0/20）。
 * @return bool
 */
function kashiwazaki_pattern_lock_ip_in_cidr( $ip, $cidr ) {
	if ( ! function_exists( 'inet_pton' ) ) {
		return false;
	}
	$parts = explode( '/', (string) $cidr, 2 );
	if ( 2 !== count( $parts ) || 1 !== preg_match( '/\A[0-9]{1,3}\z/', $parts[1] ) ) {
		return false;
	}
	$ip_bin  = @inet_pton( (string) $ip );
	$net_bin = @inet_pton( $parts[0] );
	if ( false === $ip_bin || false === $net_bin ) {
		return false;
	}
	// IPv4 射影アドレス（::ffff:a.b.c.d）は、IPv4 として比べる。
	if ( 16 === strlen( $ip_bin ) && 4 === strlen( $net_bin ) && substr( $ip_bin, 0, 12 ) === "\0\0\0\0\0\0\0\0\0\0\xff\xff" ) {
		$ip_bin = substr( $ip_bin, 12 );
	}
	if ( strlen( $ip_bin ) !== strlen( $net_bin ) ) {
		return false;
	}
	$bits = (int) $parts[1];
	if ( $bits > strlen( $net_bin ) * 8 ) {
		return false;
	}
	$bytes = intdiv( $bits, 8 );
	if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
		return false;
	}
	$rest = $bits % 8;
	if ( $rest > 0 ) {
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Cloudflare 経由の通信なら、本当の接続元の IP アドレスを返す。そうでなければ REMOTE_ADDR を返す。
 *
 * Cloudflare は、本当の接続元の IP アドレスを CF-Connecting-IP ヘッダーに付ける。ただしこのヘッダーは
 * 訪問者が自分で書けるので、REMOTE_ADDR（サーバーが申告する接続元）が Cloudflare の IP アドレスの
 * 範囲に入っている通信のときだけ使う。範囲の外から届いた通信のヘッダーは、無視する。
 *
 * @param string $remote 正しい REMOTE_ADDR。
 * @return string
 */
function kashiwazaki_pattern_lock_restore_cloudflare_ip( $remote ) {
	/**
	 * Cloudflare 経由の通信で、CF-Connecting-IP を使うかどうか。
	 *
	 * 既定は、管理画面「ログイン制限の設定」のチェック（初期値はオン）。
	 *
	 * @param bool $trust 使うとき true。
	 */
	$settings = kashiwazaki_pattern_lock_get_settings();
	if ( ! apply_filters( 'kashiwazaki_pattern_lock_trust_cloudflare', ! empty( $settings['trust_cloudflare'] ) ) ) {
		return $remote;
	}
	if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		return $remote;
	}
	$real = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	if ( ! kashiwazaki_pattern_lock_is_valid_ip( $real ) ) {
		return $remote;
	}
	foreach ( kashiwazaki_pattern_lock_cloudflare_ranges() as $cidr ) {
		if ( kashiwazaki_pattern_lock_ip_in_cidr( $remote, $cidr ) ) {
			return $real;
		}
	}
	return $remote;
}

/**
 * このアクセスの、Cloudflare との関係を返す（管理画面の表示用）。
 *
 * @return string 'via'（Cloudflare 経由で、本当の IP を取れる）・'outside'（Cloudflare のヘッダーはあるが、接続元が Cloudflare の範囲の外）・'none'（Cloudflare 経由ではない）。
 */
function kashiwazaki_pattern_lock_cloudflare_status() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
	if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		return 'none';
	}
	if ( ! kashiwazaki_pattern_lock_is_valid_ip( $remote ) || ! kashiwazaki_pattern_lock_is_valid_ip( trim( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) {
		return 'outside';
	}
	foreach ( kashiwazaki_pattern_lock_cloudflare_ranges() as $cidr ) {
		if ( kashiwazaki_pattern_lock_ip_in_cidr( $remote, $cidr ) ) {
			return 'via';
		}
	}
	return 'outside';
}

/**
 * 接続元の IP アドレスを返す。
 *
 * 既定では、サーバーが申告する REMOTE_ADDR を使う。偽装できるヘッダー（X-Forwarded-For など）は使わない。
 * 例外は Cloudflare で、REMOTE_ADDR が Cloudflare の IP アドレスの範囲に入っている通信に限り、
 * 本当の接続元の IP アドレス（CF-Connecting-IP）を使う（設定は要らない）。
 *
 * それ以外のリバースプロキシ・CDN の背後では、REMOTE_ADDR がプロキシの IP になり、
 * 全員が同じ IP として数えられる（1 人の失敗が全員のログイン制限になる）。その場合は、
 * サイトの管理者が信頼できる経路の値（プロキシが付けるヘッダーなど）を、フィルター
 * `kashiwazaki_pattern_lock_client_ip` で返す。フィルターの戻り値が正しい IP アドレスの
 * 文字列でなければ、使わずに元の値（REMOTE_ADDR か、Cloudflare の本当の IP）に戻す。
 *
 * @return string
 */
function kashiwazaki_pattern_lock_get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
	$ip     = kashiwazaki_pattern_lock_is_valid_ip( $remote ) ? kashiwazaki_pattern_lock_restore_cloudflare_ip( $remote ) : '';

	/**
	 * ログイン制限が数える接続元の IP アドレスを差し替える。
	 *
	 * 信頼できるプロキシの背後でだけ使うこと。訪問者が自由に書き換えられるヘッダーの値を
	 * そのまま返すと、失敗の数を避けるために IP を偽装されてしまう。
	 *
	 * @param string $ip 既定の IP アドレス（REMOTE_ADDR。正しい値でなければ空文字）。
	 */
	$filtered = apply_filters( 'kashiwazaki_pattern_lock_client_ip', $ip );

	if ( is_string( $filtered ) ) {
		$filtered = trim( $filtered );
		if ( kashiwazaki_pattern_lock_is_valid_ip( $filtered ) ) {
			return $filtered;
		}
	}

	return ( '' !== $ip ) ? $ip : '0.0.0.0';
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
