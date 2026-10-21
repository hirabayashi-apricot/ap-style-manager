(function () {

	/* QuickTags がない環境（Gutenberg のコードエディター以外）はスキップ */
	if (typeof QTags === 'undefined') return;
	if (!window.apsmData) return;

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function buildAttrString(attrs) {
		var html = '';
		Object.keys(attrs).forEach(function (key) {
			if (!attrs[key]) return;
			html += ' ' + key + '="' + escapeHtml(attrs[key]) + '"';
		});
		return html;
	}

	function ensureDialogStyles() {
		if (document.getElementById('apsm-qt-modal-style')) return;

		var style = document.createElement('style');
		style.id = 'apsm-qt-modal-style';
		style.textContent = '' +
			'.apsm-qt-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;}' +
			'.apsm-qt-modal{width:100%;max-width:520px;background:#fff;border-radius:6px;box-shadow:0 20px 40px rgba(0,0,0,.2);}' +
			'.apsm-qt-modal__header{padding:14px 16px;border-bottom:1px solid #e0e0e0;font-size:16px;font-weight:700;}' +
			'.apsm-qt-modal__body{padding:16px;display:flex;flex-direction:column;gap:12px;}' +
			'.apsm-qt-modal__field{display:flex;flex-direction:column;gap:6px;}' +
			'.apsm-qt-modal__field label{font-weight:600;font-size:12px;}' +
			'.apsm-qt-modal__field input,.apsm-qt-modal__field select{height:36px;padding:0 10px;}' +
			'.apsm-qt-modal__inline{display:flex;gap:8px;}' +
			'.apsm-qt-modal__inline input{flex:1;}' +
			'.apsm-qt-modal__error{display:none;color:#d63638;font-weight:600;font-size:12px;}' +
			'.apsm-qt-modal__error.is-visible{display:block;}' +
			'.apsm-qt-modal__footer{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #e0e0e0;}';

		document.head.appendChild(style);
	}

	function openQuickTagsModal(config) {
		ensureDialogStyles();

		return new Promise(function (resolve) {
			var overlay = document.createElement('div');
			overlay.className = 'apsm-qt-modal-overlay';
			overlay.innerHTML = '' +
				'<div class="apsm-qt-modal" role="dialog" aria-modal="true">' +
					'<div class="apsm-qt-modal__header">' + escapeHtml(config.title) + '</div>' +
					'<div class="apsm-qt-modal__body">' + config.bodyHtml + '<div class="apsm-qt-modal__error" id="apsm-qt-modal-error"></div></div>' +
					'<div class="apsm-qt-modal__footer">' +
						'<button type="button" class="button apsm-qt-cancel">キャンセル</button>' +
						'<button type="button" class="button button-primary apsm-qt-submit">挿入</button>' +
					'</div>' +
				'</div>';

			document.body.appendChild(overlay);

			var modal = overlay.querySelector('.apsm-qt-modal');
			var cancelBtn = overlay.querySelector('.apsm-qt-cancel');
			var submitBtn = overlay.querySelector('.apsm-qt-submit');
			var errorBox = overlay.querySelector('#apsm-qt-modal-error');

			function close(value) {
				document.removeEventListener('keydown', onEsc);
				overlay.remove();
				resolve(value);
			}

			function showError(message) {
				errorBox.textContent = message;
				errorBox.classList.add('is-visible');
			}

			function clearError() {
				errorBox.textContent = '';
				errorBox.classList.remove('is-visible');
			}

			function onEsc(event) {
				if (event.key === 'Escape') {
					close(null);
				}
			}

			document.addEventListener('keydown', onEsc);

			overlay.addEventListener('click', function (event) {
				if (event.target === overlay) {
					close(null);
				}
			});

			cancelBtn.addEventListener('click', function () {
				close(null);
			});

			submitBtn.addEventListener('click', function () {
				clearError();
				var values = config.getValues(modal);
				if (values && values.error) {
					showError(values.error);
					return;
				}
				close(values);
			});

			if (typeof config.bind === 'function') {
				config.bind(modal);
			}

			var firstInput = modal.querySelector('input,select,textarea,button');
			if (firstInput) firstInput.focus();
		});
	}

	function openLinkDialog(defaults) {
		var bodyHtml = '' +
			'<div class="apsm-qt-modal__field">' +
				'<label for="apsm-link-text">リンクテキスト</label>' +
				'<input id="apsm-link-text" type="text" value="' + escapeHtml(defaults.text || '') + '">' +
			'</div>' +
			'<div class="apsm-qt-modal__field">' +
				'<label for="apsm-link-href">href</label>' +
				'<input id="apsm-link-href" type="url" value="' + escapeHtml(defaults.href || '') + '" placeholder="https://example.com/">' +
			'</div>' +
			'<div class="apsm-qt-modal__field">' +
				'<label>rel オプション</label>' +
				'<label><input id="apsm-link-rel-nofollow" type="checkbox" value="nofollow"> nofollow</label>' +
				'<label><input id="apsm-link-rel-noopener" type="checkbox" value="noopener"> noopener</label>' +
			'</div>';

		return openQuickTagsModal({
			title: 'リンク設定',
			bodyHtml: bodyHtml,
			bind: function (modal) {
				modal.querySelector('#apsm-link-rel-nofollow').checked = !!defaults.relNofollow;
				modal.querySelector('#apsm-link-rel-noopener').checked = !!defaults.relNoopener;
			},
			getValues: function (modal) {
				var text = modal.querySelector('#apsm-link-text').value.trim();
				var href = modal.querySelector('#apsm-link-href').value.trim();
				var relNofollow = modal.querySelector('#apsm-link-rel-nofollow').checked;
				var relNoopener = modal.querySelector('#apsm-link-rel-noopener').checked;

				if (!href) {
					return { error: 'href は必須です。' };
				}

				return {
					text: text,
					href: href,
					relNofollow: relNofollow,
					relNoopener: relNoopener
				};
			}
		});
	}

	function openImageDialog(defaults) {
		var bodyHtml = '' +
			'<div class="apsm-qt-modal__field">' +
				'<label for="apsm-image-src">src</label>' +
				'<div class="apsm-qt-modal__inline">' +
					'<input id="apsm-image-src" type="text" value="' + escapeHtml(defaults.src || '') + '" placeholder="画像URL" readonly>' +
					'<button type="button" class="button" id="apsm-image-upload">アップロード</button>' +
				'</div>' +
			'</div>' +
			'<div class="apsm-qt-modal__field">' +
				'<label for="apsm-image-alt">alt</label>' +
				'<input id="apsm-image-alt" type="text" value="' + escapeHtml(defaults.alt || '') + '">' +
			'</div>';

		return openQuickTagsModal({
			title: '画像設定',
			bodyHtml: bodyHtml,
			bind: function (modal) {
				var uploadBtn = modal.querySelector('#apsm-image-upload');
				var srcInput = modal.querySelector('#apsm-image-src');
				var altInput = modal.querySelector('#apsm-image-alt');

				uploadBtn.addEventListener('click', function () {
					if (typeof wp === 'undefined' || !wp.media) {
						window.alert('メディアモーダルを利用できません。');
						return;
					}

					var frame = wp.media({
						title: '画像を選択',
						button: { text: 'この画像を使用' },
						multiple: false,
						library: { type: 'image' }
					});

					frame.on('select', function () {
						var attachment = frame.state().get('selection').first();
						if (!attachment) return;
						var data = attachment.toJSON();
						srcInput.value = data.url || '';
						if (!altInput.value && data.alt) {
							altInput.value = data.alt;
						}
					});

					frame.open();
				});
			},
			getValues: function (modal) {
				var src = modal.querySelector('#apsm-image-src').value.trim();
				var alt = modal.querySelector('#apsm-image-alt').value.trim();

				if (!src) {
					return { error: 'src は必須です。' };
				}

				return {
					src: src,
					alt: alt
				};
			}
		});
	}

	function replaceSelection(canvas, start, end, newText, hadSelection) {
		canvas.value =
			canvas.value.substring(0, start) +
			newText +
			canvas.value.substring(end);

		if (!hadSelection) {
			canvas.selectionStart = start + newText.length;
			canvas.selectionEnd = start + newText.length;
		} else {
			canvas.selectionStart = start;
			canvas.selectionEnd = start + newText.length;
		}
		canvas.focus();
	}

	/* 外側タグを全層剥がす（スタイル用） */
	function stripAllOuterTags(text) {
		for (;;) {
			var trimmed = text.trim();
			var match = trimmed.match(/^<([a-z][a-z0-9-]*)(?:\s[^>]*)?>([\s\S]*)<\/\1>$/i);
			if (!match) break;
			text = match[2];
		}
		return text;
	}

	function registerButton(style, index, stripOnApply) {

		var tag = style.block || style.inline || '';
		if (!tag) return;

		var cls      = style.attributes && style.attributes.class ? style.attributes.class : '';
		var id       = (stripOnApply ? 'apsm_s_' : 'apsm_b_') + index + '_' + tag;

		QTags.addButton(
			id,
			style.title,
			function (element, canvas) {
				var start    = canvas.selectionStart;
				var end      = canvas.selectionEnd;
				var selected = canvas.value.substring(start, end);
				var isSelected = !!selected;

				if (tag === 'a') {
					openLinkDialog({
						text: selected,
						href: '',
						relNofollow: false,
						relNoopener: false
					}).then(function (result) {
						if (!result) return;

						var inner = selected
							? (stripOnApply ? stripAllOuterTags(selected) : selected)
							: (result.text || result.href);

						var relValues = [];
						if (result.relNofollow) relValues.push('nofollow');
						if (result.relNoopener) relValues.push('noopener');

						var attrs = {
							href: result.href,
							class: cls,
							rel: relValues.join(' ')
						};

						var newText = '<a' + buildAttrString(attrs) + '>' + inner + '</a>';
						replaceSelection(canvas, start, end, newText, isSelected);
					});
					return;
				}

				if (tag === 'img') {
					openImageDialog({
						src: '',
						alt: ''
					}).then(function (result) {
						if (!result) return;
						var attrs = {
							src: result.src,
							alt: result.alt,
							class: cls
						};
						var newText = '<img' + buildAttrString(attrs) + '>';
						replaceSelection(canvas, start, end, newText, isSelected);
					});
					return;
				}

				var openTag  = cls ? '<' + tag + ' class="' + cls + '">' : '<' + tag + '>';
				var closeTag = '</' + tag + '>';

				/* スタイル: 外側タグを全層剥がして上書き / ブロック: そのまま囲む */
				var inner   = selected
					? (stripOnApply ? stripAllOuterTags(selected) : selected)
					: '';
				var newText = openTag + inner + closeTag;

				replaceSelection(canvas, start, end, newText, isSelected);
				if (!selected) {
					canvas.selectionStart = start + openTag.length;
					canvas.selectionEnd = start + openTag.length;
				}
			},
			'',
			'',
			style.title,
			100 + index
		);

	}

	var styles = Array.isArray(window.apsmData.styles) ? window.apsmData.styles : [];
	var blocks = Array.isArray(window.apsmData.blocks) ? window.apsmData.blocks : [];

	styles.forEach(function (style, index) {
		registerButton(style, index, true);   /* タグ上書き */
	});

	blocks.forEach(function (style, index) {
		registerButton(style, index, false);  /* タグ保持・ネスト */
	});

})();
