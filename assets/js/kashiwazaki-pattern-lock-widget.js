/**
 * Kashiwazaki Pattern Lock - 9点パターン入力ウィジェット。
 *
 * ログイン画面と管理画面で同じスクリプトを使う。
 * 入力した点の並びは hidden フィールドに入れ、送信は通常のフォーム POST で行う
 * （URL に載せない）。
 */
(function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var DOT_SELECTOR = '[data-kashiwazaki-pattern-lock-dot]';
	var SEPARATOR = ' → ';

	/**
	 * 2 点の間にある点の番号を返す（PHP 側の kashiwazaki_pattern_lock_midpoint と同じ規則）。
	 *
	 * @param {number} from 始点（1〜9）。
	 * @param {number} to   終点（1〜9）。
	 * @return {number} 中間点。無ければ 0。
	 */
	function midpoint(from, to) {
		var ax = (from - 1) % 3;
		var ay = Math.floor((from - 1) / 3);
		var bx = (to - 1) % 3;
		var by = Math.floor((to - 1) / 3);

		if ((ax + bx) % 2 !== 0 || (ay + by) % 2 !== 0) {
			return 0;
		}

		return ((ay + by) / 2) * 3 + ((ax + bx) / 2) + 1;
	}

	/**
	 * SVG の要素を作る。
	 *
	 * @param {string} name       要素名。
	 * @param {Object} attributes 属性。
	 * @return {Element}
	 */
	function createSvgNode(name, attributes) {
		var node = document.createElementNS(SVG_NS, name);

		Object.keys(attributes).forEach(function (key) {
			node.setAttribute(key, attributes[key]);
		});

		return node;
	}

	/**
	 * 1 つのパターン入力欄を扱う。
	 *
	 * @param {Element} root ウィジェットのルート要素。
	 */
	function PatternWidget(root) {
		this.root = root;
		this.frame = root.querySelector('[data-kashiwazaki-pattern-lock-frame]');
		this.grid = root.querySelector('[data-kashiwazaki-pattern-lock-grid]');
		this.svg = root.querySelector('[data-kashiwazaki-pattern-lock-lines]');
		this.input = root.querySelector('[data-kashiwazaki-pattern-lock-input]');
		this.status = root.querySelector('[data-kashiwazaki-pattern-lock-status]');
		this.clearButton = root.querySelector('[data-kashiwazaki-pattern-lock-clear]');

		this.disabled = !this.frame || !this.grid || !this.svg || !this.input;
		if (this.disabled) {
			return;
		}

		this.minDots = parseInt(root.getAttribute('data-kashiwazaki-pattern-lock-min'), 10) || 4;
		this.emptyLabel = root.getAttribute('data-kashiwazaki-pattern-lock-empty-label') || '';
		this.selectedLabel = root.getAttribute('data-kashiwazaki-pattern-lock-selected-label') || '%1$s';
		this.completeLabel = root.getAttribute('data-kashiwazaki-pattern-lock-complete-label') || '%1$s';
		this.emptyError = root.getAttribute('data-kashiwazaki-pattern-lock-empty-error') || '';
		this.shortError = root.getAttribute('data-kashiwazaki-pattern-lock-short-error') || '';
		this.form = root.closest ? root.closest('form') : null;

		this.dots = [];
		for (var number = 1; number <= 9; number += 1) {
			var dot = this.grid.querySelector('[data-kashiwazaki-pattern-lock-dot="' + number + '"]');
			if (dot) {
				this.dots.push(dot);
			}
		}

		this.sequence = [];
		this.pointerActive = false;
		this.pointerId = null;
		this.pointerPosition = null;
		this.errorMessage = '';

		this.handlePointerDown = this.handlePointerDown.bind(this);
		this.handlePointerMove = this.handlePointerMove.bind(this);
		this.handlePointerUp = this.handlePointerUp.bind(this);
		this.handleKeyDown = this.handleKeyDown.bind(this);
		this.handleFocusIn = this.handleFocusIn.bind(this);

		this.grid.addEventListener('pointerdown', this.handlePointerDown);
		this.grid.addEventListener('pointermove', this.handlePointerMove);
		this.grid.addEventListener('keydown', this.handleKeyDown);
		this.grid.addEventListener('focusin', this.handleFocusIn);

		// ポインターキャプチャが使えない環境でも指を離したことを拾えるようにする。
		window.addEventListener('pointerup', this.handlePointerUp);
		window.addEventListener('pointercancel', this.handlePointerUp);

		if (this.clearButton) {
			this.clearButton.addEventListener(
				'click',
				function () {
					this.clear();
				}.bind(this)
			);
		}

		this.update();
	}

	/**
	 * 指定した番号の点を返す。
	 *
	 * @param {number} number 点の番号（1〜9）。
	 * @return {Element|null}
	 */
	PatternWidget.prototype.getDot = function (number) {
		return this.dots[number - 1] || null;
	};

	/**
	 * 押された位置にある点の番号を返す。
	 *
	 * @param {Element} target イベントの発生元。
	 * @return {number} 点の番号。点でなければ 0。
	 */
	PatternWidget.prototype.getNumberFromTarget = function (target) {
		if (!target || typeof target.closest !== 'function') {
			return 0;
		}

		var dot = target.closest(DOT_SELECTOR);
		if (!dot) {
			return 0;
		}

		return parseInt(dot.getAttribute('data-kashiwazaki-pattern-lock-dot'), 10) || 0;
	};

	PatternWidget.prototype.handlePointerDown = function (event) {
		if (event.pointerType === 'mouse' && event.button !== 0) {
			return;
		}

		var number = this.getNumberFromTarget(event.target);
		if (!number) {
			return;
		}

		event.preventDefault();

		this.pointerActive = true;
		this.pointerId = event.pointerId;

		try {
			this.grid.setPointerCapture(event.pointerId);
		} catch (error) {
			// ポインターキャプチャが使えない環境では、そのまま位置で追う。
		}

		this.focusDot(this.getDot(number));
		this.pointerPosition = this.toFramePoint(event.clientX, event.clientY);
		this.selectDot(number);
	};

	PatternWidget.prototype.handlePointerMove = function (event) {
		if (!this.pointerActive || event.pointerId !== this.pointerId) {
			return;
		}

		event.preventDefault();

		this.pointerPosition = this.toFramePoint(event.clientX, event.clientY);

		var element = document.elementFromPoint(event.clientX, event.clientY);
		var number = this.getNumberFromTarget(element);

		if (number) {
			this.selectDot(number);
		}

		this.render();
	};

	PatternWidget.prototype.handlePointerUp = function (event) {
		if (!this.pointerActive) {
			return;
		}

		if (event.pointerId !== undefined && this.pointerId !== null && event.pointerId !== this.pointerId) {
			return;
		}

		// 最後の pointermove の後に点へ入って離した場合を取りこぼさない。
		if (event.clientX || event.clientY) {
			var element = document.elementFromPoint(event.clientX, event.clientY);
			var number = this.getNumberFromTarget(element);
			if (number) {
				this.selectDot(number);
			}
		}

		try {
			this.grid.releasePointerCapture(this.pointerId);
		} catch (error) {
			// すでに解放されている場合は何もしない。
		}

		this.pointerActive = false;
		this.pointerId = null;
		this.pointerPosition = null;
		this.render();
	};

	PatternWidget.prototype.handleFocusIn = function (event) {
		var dot = event.target && typeof event.target.closest === 'function'
			? event.target.closest(DOT_SELECTOR)
			: null;

		if (!dot) {
			return;
		}

		this.dots.forEach(function (candidate) {
			candidate.tabIndex = candidate === dot ? 0 : -1;
		});
	};

	PatternWidget.prototype.handleKeyDown = function (event) {
		var number = this.getNumberFromTarget(event.target);
		if (!number) {
			return;
		}

		var index = number - 1;
		var handled = true;

		switch (event.key) {
			case 'ArrowLeft':
				this.focusIndex(index - 1);
				break;
			case 'ArrowRight':
				this.focusIndex(index + 1);
				break;
			case 'ArrowUp':
				this.focusIndex(index - 3);
				break;
			case 'ArrowDown':
				this.focusIndex(index + 3);
				break;
			case 'Home':
				this.focusIndex(0);
				break;
			case 'End':
				this.focusIndex(this.dots.length - 1);
				break;
			case 'Enter':
			case ' ':
			case 'Spacebar':
				this.selectDot(number);
				break;
			case 'Backspace':
				this.undo();
				break;
			case 'Escape':
			case 'Delete':
				this.clear();
				break;
			default:
				handled = false;
		}

		if (handled) {
			event.preventDefault();
			event.stopPropagation();
		}
	};

	PatternWidget.prototype.focusDot = function (dot) {
		if (!dot) {
			return;
		}

		try {
			dot.focus({ preventScroll: true });
		} catch (error) {
			dot.focus();
		}
	};

	PatternWidget.prototype.focusIndex = function (index) {
		if (index < 0 || index >= this.dots.length) {
			return;
		}

		this.focusDot(this.dots[index]);
	};

	/**
	 * 点を 1 つ選ぶ。使用済みの点は無視し、飛び越えた点は自動で追加する。
	 *
	 * @param {number} number 点の番号。
	 * @return {void}
	 */
	PatternWidget.prototype.selectDot = function (number) {
		var sequence = this.sequence;
		var last = sequence.length ? sequence[sequence.length - 1] : 0;

		if (number === last || sequence.indexOf(number) !== -1) {
			return;
		}

		if (last) {
			var middle = midpoint(last, number);
			if (middle && sequence.indexOf(middle) === -1) {
				sequence.push(middle);
			}
		}

		sequence.push(number);
		this.update();
	};

	/**
	 * 最後に選んだ点を取り消す。
	 *
	 * @return {void}
	 */
	PatternWidget.prototype.undo = function () {
		if (!this.sequence.length) {
			return;
		}

		this.sequence.pop();
		this.update();
	};

	/**
	 * 入力をすべて消す。
	 *
	 * @return {void}
	 */
	PatternWidget.prototype.clear = function () {
		if (!this.sequence.length) {
			return;
		}

		this.sequence = [];
		this.update();
	};

	PatternWidget.prototype.update = function () {
		if (this.disabled) {
			return;
		}

		// 何か入力されたら、送信時のエラー表示は消す。
		if (this.errorMessage) {
			this.errorMessage = '';
			this.root.classList.remove('has-error');
			if (this.status) {
				this.status.classList.remove('is-error');
			}
		}

		var sequence = this.sequence;

		this.input.value = sequence.join(',');

		this.dots.forEach(function (dot, index) {
			var selected = sequence.indexOf(index + 1) !== -1;
			dot.setAttribute('aria-pressed', selected ? 'true' : 'false');
			dot.classList.toggle('is-selected', selected);
		});

		if (this.clearButton) {
			this.clearButton.disabled = sequence.length === 0;
		}

		this.updateStatus();
		this.render();
	};

	/**
	 * 送信できる点数が入っているか。
	 *
	 * @return {boolean}
	 */
	PatternWidget.prototype.isValid = function () {
		return this.sequence.length >= this.minDots;
	};

	/**
	 * 送信前のエラーを表示する。
	 *
	 * @param {string} message 表示する文言。
	 * @return {void}
	 */
	PatternWidget.prototype.showError = function (message) {
		if (!message) {
			return;
		}

		this.errorMessage = message;
		this.root.classList.add('has-error');

		if (this.status) {
			this.status.textContent = message;
			this.status.classList.add('is-error');
		}
	};

	PatternWidget.prototype.updateStatus = function () {
		if (!this.status) {
			return;
		}

		var sequence = this.sequence;

		if (!sequence.length) {
			this.status.textContent = this.emptyLabel;
			return;
		}

		var template = sequence.length >= this.minDots ? this.completeLabel : this.selectedLabel;

		this.status.textContent = template
			.replace('%1$s', sequence.join(SEPARATOR))
			.replace('%2$d', String(sequence.length))
			.replace('%3$d', String(this.minDots));
	};

	PatternWidget.prototype.toFramePoint = function (clientX, clientY) {
		var rect = this.frame.getBoundingClientRect();

		return {
			x: clientX - rect.left,
			y: clientY - rect.top
		};
	};

	PatternWidget.prototype.getDotCenter = function (number) {
		var dot = this.getDot(number);
		if (!dot) {
			return null;
		}

		var frameRect = this.frame.getBoundingClientRect();
		var rect = dot.getBoundingClientRect();

		return {
			x: rect.left - frameRect.left + rect.width / 2,
			y: rect.top - frameRect.top + rect.height / 2
		};
	};

	/**
	 * 選んだ点と、点を結ぶ線を描く。
	 *
	 * @return {void}
	 */
	PatternWidget.prototype.render = function () {
		if (this.disabled) {
			return;
		}

		var frameRect = this.frame.getBoundingClientRect();
		if (!frameRect.width || !frameRect.height) {
			return;
		}

		this.svg.setAttribute('viewBox', '0 0 ' + frameRect.width + ' ' + frameRect.height);
		this.svg.setAttribute('width', String(frameRect.width));
		this.svg.setAttribute('height', String(frameRect.height));

		while (this.svg.firstChild) {
			this.svg.removeChild(this.svg.firstChild);
		}

		var self = this;
		var points = this.sequence
			.map(function (number) {
				return self.getDotCenter(number);
			})
			.filter(function (point) {
				return point !== null;
			});

		if (points.length > 1) {
			this.svg.appendChild(
				createSvgNode('polyline', {
					points: points
						.map(function (point) {
							return point.x + ',' + point.y;
						})
						.join(' '),
					'class': 'kashiwazaki-pattern-lock__path',
					fill: 'none'
				})
			);
		}

		if (this.pointerActive && this.pointerPosition && points.length) {
			var last = points[points.length - 1];

			this.svg.appendChild(
				createSvgNode('line', {
					x1: last.x,
					y1: last.y,
					x2: this.pointerPosition.x,
					y2: this.pointerPosition.y,
					'class': 'kashiwazaki-pattern-lock__preview'
				})
			);
		}
	};

	var widgets = [];

	/**
	 * フォームの送信前に、4点未満の入力をその場で止める。
	 *
	 * サーバー側の検証は必ず行うが、点が足りないだけで往復させると
	 * ログインの失敗回数が無駄に増えるため、画面側でも先に知らせる。
	 *
	 * @param {HTMLFormElement|null} form フォーム。
	 * @return {void}
	 */
	function bindForm(form) {
		if (!form || form.getAttribute('data-kashiwazaki-pattern-lock-bound') === '1') {
			return;
		}

		form.setAttribute('data-kashiwazaki-pattern-lock-bound', '1');

		form.addEventListener('submit', function (event) {
			if (event.defaultPrevented) {
				return;
			}

			var invalid = null;

			widgets.forEach(function (widget) {
				if (widget.disabled || widget.form !== form) {
					return;
				}

				if (!invalid && !widget.isValid()) {
					invalid = widget;
				}
			});

			if (!invalid) {
				return;
			}

			event.preventDefault();
			invalid.showError(invalid.sequence.length === 0 ? invalid.emptyError : invalid.shortError);

			// クリック処理の後始末（フォーカス移動）に負けないよう、次の処理に回す。
			window.setTimeout(function () {
				invalid.focusIndex(0);
			}, 0);
		});
	}

	function init() {
		var roots = document.querySelectorAll('[data-kashiwazaki-pattern-lock]');

		Array.prototype.forEach.call(roots, function (root) {
			if (root.getAttribute('data-kashiwazaki-pattern-lock-ready') === '1') {
				return;
			}

			root.setAttribute('data-kashiwazaki-pattern-lock-ready', '1');

			var widget = new PatternWidget(root);
			widgets.push(widget);
			bindForm(widget.form);
		});
	}

	function redraw() {
		widgets.forEach(function (widget) {
			widget.render();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	window.addEventListener('load', redraw);
	window.addEventListener('resize', redraw);
	window.addEventListener('orientationchange', redraw);
})();
