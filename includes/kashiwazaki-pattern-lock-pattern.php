<?php
/**
 * パターンの正規化・保存・検証と設定値の読み書き。
 *
 * パターンは「点の番号を並べた文字列」（例: "1,2,5,9"）として扱う。
 * 点の番号は 1〜9 で、左上を 1、右下を 9 とする。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定の既定値を返す。
 *
 * @return array
 */
function kashiwazaki_pattern_lock_get_default_settings() {
	return array(
		'pattern_hash'    => '',
		'pattern_dots'    => 0,
		'pattern_set_at'  => 0,
		'pattern_set_by'  => 0,
		'max_attempts'    => KASHIWASAKI_PATTERN_LOCK_DEFAULT_MAX_ATTEMPTS,
		'lockout_minutes' => KASHIWASAKI_PATTERN_LOCK_DEFAULT_LOCKOUT_MINUTES,
	);
}

/**
 * 設定を読み出す。
 *
 * 保存値が壊れていても既定値で動くように、型をそろえてから返す。
 *
 * @return array
 */
function kashiwazaki_pattern_lock_get_settings() {
	$stored = get_option( KASHIWASAKI_PATTERN_LOCK_OPTION_KEY, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$settings = wp_parse_args( $stored, kashiwazaki_pattern_lock_get_default_settings() );

	$settings['pattern_hash']    = is_string( $settings['pattern_hash'] ) ? $settings['pattern_hash'] : '';
	$settings['pattern_dots']    = max( 0, (int) $settings['pattern_dots'] );
	$settings['pattern_set_at']  = max( 0, (int) $settings['pattern_set_at'] );
	$settings['pattern_set_by']  = max( 0, (int) $settings['pattern_set_by'] );
	$settings['max_attempts']    = min(
		KASHIWASAKI_PATTERN_LOCK_MAX_ATTEMPTS_LIMIT,
		max( 1, (int) $settings['max_attempts'] )
	);
	$settings['lockout_minutes'] = min(
		KASHIWASAKI_PATTERN_LOCK_MAX_LOCKOUT_MINUTES,
		max( 1, (int) $settings['lockout_minutes'] )
	);

	return $settings;
}

/**
 * 設定を保存する。
 *
 * @param array $settings 保存する設定（欠けている項目は既定値で補う）。
 * @return bool
 */
function kashiwazaki_pattern_lock_update_settings( $settings ) {
	$settings = wp_parse_args( (array) $settings, kashiwazaki_pattern_lock_get_default_settings() );

	return update_option( KASHIWASAKI_PATTERN_LOCK_OPTION_KEY, $settings, false );
}

/**
 * パターンが設定されているか。
 *
 * 未設定の間はログインを妨げない（仕様）。
 *
 * @return bool
 */
function kashiwazaki_pattern_lock_is_enabled() {
	$settings = kashiwazaki_pattern_lock_get_settings();

	return ( '' !== $settings['pattern_hash'] );
}

/**
 * 2 点の間にあって、なぞったときに自動で選択される点の番号を返す。
 *
 * 例: 1 と 3 の間は 2、1 と 9 の間は 5。間に点が無い（または点が置けない）
 * 組み合わせでは 0 を返す。
 *
 * @param int $from 始点の番号（1〜9）。
 * @param int $to   終点の番号（1〜9）。
 * @return int 中間点の番号。無ければ 0。
 */
function kashiwazaki_pattern_lock_midpoint( $from, $to ) {
	$x1 = ( $from - 1 ) % 3;
	$y1 = (int) floor( ( $from - 1 ) / 3 );
	$x2 = ( $to - 1 ) % 3;
	$y2 = (int) floor( ( $to - 1 ) / 3 );

	// 縦横斜めのいずれかで、ちょうど中間に点が来る組み合わせだけが対象。
	if ( 0 !== ( ( $x1 + $x2 ) % 2 ) || 0 !== ( ( $y1 + $y2 ) % 2 ) ) {
		return 0;
	}

	$mx = (int) ( ( $x1 + $x2 ) / 2 );
	$my = (int) ( ( $y1 + $y2 ) / 2 );

	return ( $my * 3 ) + $mx + 1;
}

/**
 * 受け取ったパターン文字列を正規化する。
 *
 * - 空白やカンマ区切りの揺れを吸収する。
 * - 同じ点は 1 回だけにする（重複は読み飛ばす）。
 * - まだ選んでいない点を飛び越えた場合は、途中の点を自動で追加する。
 *
 * 形式が不正（数字以外・範囲外）な場合は空文字を返す。
 *
 * @param mixed $raw 画面から送られてきた値。
 * @return string 正規化したパターン（例: "1,2,3,4"）。不正なら空文字。
 */
function kashiwazaki_pattern_lock_normalize_pattern( $raw ) {
	if ( ! is_string( $raw ) ) {
		return '';
	}

	$raw = trim( $raw );
	if ( '' === $raw ) {
		return '';
	}

	// 9 点をすべて並べても "1,2,3,4,5,6,7,8,9" の 17 文字。余裕を見て 64 文字で切る。
	if ( strlen( $raw ) > 64 ) {
		return '';
	}

	$parts    = explode( ',', $raw );
	$sequence = array();

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( ! preg_match( '/^[1-9]$/', $part ) ) {
			return '';
		}

		$dot = (int) $part;

		// 同じ点を 2 回使うことはできない。
		if ( in_array( $dot, $sequence, true ) ) {
			continue;
		}

		$last = end( $sequence );
		if ( false !== $last ) {
			$middle = kashiwazaki_pattern_lock_midpoint( (int) $last, $dot );
			if ( $middle > 0 && ! in_array( $middle, $sequence, true ) ) {
				$sequence[] = $middle;
			}
		}

		$sequence[] = $dot;
	}

	return implode( ',', $sequence );
}

/**
 * 正規化済みパターンの点数を返す。
 *
 * @param string $normalized 正規化済みパターン。
 * @return int
 */
function kashiwazaki_pattern_lock_count_dots( $normalized ) {
	if ( '' === $normalized ) {
		return 0;
	}

	return count( explode( ',', $normalized ) );
}

/**
 * パターンをハッシュ化する。
 *
 * 平文では保存しない。WordPress の wp_hash_password()（bcrypt）を使い、
 * パスワード用のハッシュと混ざらないよう用途を示す接頭辞を付ける。
 *
 * @param string $normalized 正規化済みパターン。
 * @return string ハッシュ。失敗時は空文字。
 */
function kashiwazaki_pattern_lock_hash_pattern( $normalized ) {
	if ( '' === $normalized ) {
		return '';
	}

	$hash = wp_hash_password( 'kashiwazaki-pattern-lock|1|' . $normalized );

	return is_string( $hash ) ? $hash : '';
}

/**
 * パターンが保存済みのハッシュと一致するか検証する。
 *
 * @param string $normalized 正規化済みパターン。
 * @param string $hash       保存済みハッシュ。
 * @return bool
 */
function kashiwazaki_pattern_lock_verify_pattern( $normalized, $hash ) {
	if ( '' === $normalized || ! is_string( $hash ) || '' === $hash ) {
		return false;
	}

	return (bool) wp_check_password( 'kashiwazaki-pattern-lock|1|' . $normalized, $hash );
}

/**
 * 画面から送られてきたパターンを検証する。
 *
 * 空・形式不正・4 点未満はすべて「使えないパターン」として扱う。
 *
 * @param mixed $raw 画面から送られてきた値。
 * @return array {
 *     @type bool   $valid      使えるパターンかどうか。
 *     @type string $normalized 正規化済みパターン。
 *     @type string $error      不正だった理由（missing / invalid / short）。
 * }
 */
function kashiwazaki_pattern_lock_validate_input( $raw ) {
	$result = array(
		'valid'      => false,
		'normalized' => '',
		'error'      => '',
	);

	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		$result['error'] = 'missing';
		return $result;
	}

	$normalized = kashiwazaki_pattern_lock_normalize_pattern( $raw );
	if ( '' === $normalized ) {
		$result['error'] = 'invalid';
		return $result;
	}

	if ( kashiwazaki_pattern_lock_count_dots( $normalized ) < KASHIWASAKI_PATTERN_LOCK_MIN_DOTS ) {
		$result['error']      = 'short';
		$result['normalized'] = $normalized;
		return $result;
	}

	$result['valid']      = true;
	$result['normalized'] = $normalized;

	return $result;
}
