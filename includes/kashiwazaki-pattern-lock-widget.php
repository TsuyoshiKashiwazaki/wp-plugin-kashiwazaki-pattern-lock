<?php
/**
 * 9 点パターン入力ウィジェットの描画。
 *
 * ログイン画面と管理画面の両方で同じマークアップを使う。
 * 入力値は hidden フィールド（POST）にだけ入れ、URL には出さない。
 *
 * @package Kashiwazaki_Pattern_Lock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * パターン入力ウィジェットを出力する。
 *
 * @param array $args {
 *     省略可能。既定値は下記。
 *
 *     @type string $name         hidden フィールドの name。
 *     @type string $id           ウィジェットの id（画面内で一意にする）。
 *     @type string $label        見出し。
 *     @type string $description  補足説明。空なら出さない。
 *     @type string $class        追加の CSS クラス。
 *     @type bool   $show_clear   クリアボタンを出すか。
 * }
 * @return void
 */
function kashiwazaki_pattern_lock_render_widget( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'name'        => KASHIWASAKI_PATTERN_LOCK_FORM_FIELD,
			'id'          => 'kashiwazaki-pattern-lock-field',
			'label'       => __( 'パターン', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
			'description' => '',
			'class'       => '',
			'show_clear'  => true,
		)
	);

	$base_id  = sanitize_html_class( $args['id'] );
	$label_id = $base_id . '-label';
	$help_id  = $base_id . '-help';
	$status_id = $base_id . '-status';

	$min_dots = (int) KASHIWASAKI_PATTERN_LOCK_MIN_DOTS;

	$empty_label    = __( '未入力', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
	$selected_label = __( '選択中：%2$d点（%3$d点以上必要）', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
	$complete_label = __( '入力済み：%2$d点', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
	$empty_error    = __( 'パターンが入力されていません。点をなぞって入力してください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN );
	$short_error    = sprintf(
		/* translators: %d: minimum number of dots. */
		__( 'パターンが短すぎます。%d点以上をなぞってください。', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ),
		$min_dots
	);

	$described_by = $status_id;
	if ( '' !== $args['description'] ) {
		$described_by = $help_id . ' ' . $status_id;
	}
	?>
	<div
		class="kashiwazaki-pattern-lock <?php echo esc_attr( $args['class'] ); ?>"
		id="<?php echo esc_attr( $base_id ); ?>"
		data-kashiwazaki-pattern-lock
		data-kashiwazaki-pattern-lock-min="<?php echo esc_attr( (string) $min_dots ); ?>"
		data-kashiwazaki-pattern-lock-empty-label="<?php echo esc_attr( $empty_label ); ?>"
		data-kashiwazaki-pattern-lock-selected-label="<?php echo esc_attr( $selected_label ); ?>"
		data-kashiwazaki-pattern-lock-complete-label="<?php echo esc_attr( $complete_label ); ?>"
		data-kashiwazaki-pattern-lock-empty-error="<?php echo esc_attr( $empty_error ); ?>"
		data-kashiwazaki-pattern-lock-short-error="<?php echo esc_attr( $short_error ); ?>"
	>
		<div class="kashiwazaki-pattern-lock__heading">
			<span class="kashiwazaki-pattern-lock__label" id="<?php echo esc_attr( $label_id ); ?>"><?php echo esc_html( $args['label'] ); ?></span>
			<?php if ( '' !== $args['description'] ) : ?>
				<span class="kashiwazaki-pattern-lock__help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $args['description'] ); ?></span>
			<?php endif; ?>
		</div>

		<div class="kashiwazaki-pattern-lock__frame" data-kashiwazaki-pattern-lock-frame>
			<svg
				class="kashiwazaki-pattern-lock__lines"
				data-kashiwazaki-pattern-lock-lines
				aria-hidden="true"
				focusable="false"
				xmlns="http://www.w3.org/2000/svg"
			></svg>
			<div
				class="kashiwazaki-pattern-lock__grid"
				data-kashiwazaki-pattern-lock-grid
				role="group"
				aria-labelledby="<?php echo esc_attr( $label_id ); ?>"
				aria-describedby="<?php echo esc_attr( $described_by ); ?>"
			>
				<?php for ( $dot = 1; $dot <= 9; $dot++ ) : ?>
					<button
						type="button"
						class="kashiwazaki-pattern-lock__dot"
						data-kashiwazaki-pattern-lock-dot="<?php echo esc_attr( (string) $dot ); ?>"
						aria-pressed="false"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %d: dot number. */ __( '点 %d', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ), $dot ) ); ?>"
						tabindex="<?php echo ( 1 === $dot ) ? '0' : '-1'; ?>"
					><span aria-hidden="true"><?php echo esc_html( (string) $dot ); ?></span></button>
				<?php endfor; ?>
			</div>
		</div>

		<input
			type="hidden"
			name="<?php echo esc_attr( $args['name'] ); ?>"
			id="<?php echo esc_attr( $base_id . '-input' ); ?>"
			value=""
			autocomplete="off"
			data-kashiwazaki-pattern-lock-input
		/>

		<div class="kashiwazaki-pattern-lock__footer">
			<span
				class="kashiwazaki-pattern-lock__status"
				id="<?php echo esc_attr( $status_id ); ?>"
				data-kashiwazaki-pattern-lock-status
				aria-live="polite"
				aria-atomic="true"
			><?php echo esc_html( $empty_label ); ?></span>
			<?php if ( $args['show_clear'] ) : ?>
				<button
					type="button"
					class="button kashiwazaki-pattern-lock__clear"
					data-kashiwazaki-pattern-lock-clear
					disabled="disabled"
				><?php echo esc_html( __( '入力を消す', KASHIWASAKI_PATTERN_LOCK_TEXTDOMAIN ) ); ?></button>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
