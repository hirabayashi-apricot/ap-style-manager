(function () {

	tinymce.PluginManager.add('apsm_buttons', function (editor) {

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

		function stripAllOuterTags(text) {
			for (;;) {
				var trimmed = text.trim();
				var match = trimmed.match(/^<([a-z][a-z0-9-]*)(?:\s[^>]*)?>([\s\S]*)<\/\1>$/i);
				if (!match) break;
				text = match[2];
			}
			return text;
		}

		function openLinkDialog(defaultValues, callback) {
			editor.windowManager.open({
				title: 'リンク設定',
				body: [
					{ type: 'textbox', name: 'text', label: 'リンクテキスト', value: defaultValues.text || '' },
					{ type: 'textbox', name: 'href', label: 'href', value: defaultValues.href || '' },
					{ type: 'checkbox', name: 'relNofollow', label: 'nofollow', checked: !!defaultValues.relNofollow },
					{ type: 'checkbox', name: 'relNoopener', label: 'noopener', checked: !!defaultValues.relNoopener }
				],
				onsubmit: function (event) {
					var href = (event.data.href || '').trim();
					if (!href) {
						editor.windowManager.alert('href は必須です。');
						return;
					}
					var relValues = [];
					if (event.data.relNofollow) relValues.push('nofollow');
					if (event.data.relNoopener) relValues.push('noopener');
					callback({
						text: (event.data.text || '').trim(),
						href: href,
						rel: relValues.join(' ')
					});
				}
			});
		}

		function openImageDialog(defaultValues, callback) {
			var currentSrc = defaultValues.src || '';
			var currentAlt = defaultValues.alt || '';

			if (typeof wp !== 'undefined' && wp.media) {
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
					currentSrc = data.url || '';
					if (data.alt) currentAlt = data.alt;

					editor.windowManager.open({
						title: '画像設定',
						body: [
							{ type: 'textbox', name: 'src', label: 'src', value: currentSrc },
							{ type: 'textbox', name: 'alt', label: 'alt', value: currentAlt }
						],
						onsubmit: function (event) {
							var src = (event.data.src || '').trim();
							if (!src) {
								editor.windowManager.alert('src は必須です。');
								return;
							}
							callback({
								src: src,
								alt: (event.data.alt || '').trim()
							});
						}
					});
				});

				frame.open();
				return;
			}

			editor.windowManager.open({
				title: '画像設定',
				body: [
					{ type: 'textbox', name: 'src', label: 'src', value: currentSrc },
					{ type: 'textbox', name: 'alt', label: 'alt', value: currentAlt }
				],
				onsubmit: function (event) {
					var src = (event.data.src || '').trim();
					if (!src) {
						editor.windowManager.alert('src は必須です。');
						return;
					}
					callback({
						src: src,
						alt: (event.data.alt || '').trim()
					});
				}
			});
		}

		function toArray(val) {
			if (Array.isArray(val)) return val;
			try { return JSON.parse(val || '[]'); } catch (e) { return []; }
		}

		var styles = toArray(editor.settings.apsm_styles);
		var blocks = toArray(editor.settings.apsm_blocks);

		/* PHP の mce_buttons_2 と同じ順番（styles → blocks）で結合 */
		var all = styles.concat(blocks);

		if (!all.length) return;

		/* フォーマット登録 */
		editor.on('init', function () {
			all.forEach(function (style, i) {
				var tag = style.block || style.inline || '';
				if (!tag) return;
				if (tag === 'a' || tag === 'img') return;

				var cls        = style.attributes && style.attributes.class ? style.attributes.class : '';
				var isBlock    = !!style.block;
				var formatConfig = isBlock
					? { block: tag }
					: { inline: tag, remove: 'all' };

				if (cls) {
					formatConfig.classes = cls;
				}

				editor.formatter.register('apsm_format_' + i, formatConfig);
			});
		});

		var stylesCount = styles.length;

		/* ボタン登録 */
		all.forEach(function (style, i) {
			var tag = style.block || style.inline || '';
			if (!tag) return;

			var isStyleType = i < stylesCount;
			var cls = style.attributes && style.attributes.class ? style.attributes.class : '';
			var isSpecialTag = (tag === 'a' || tag === 'img');

			editor.addButton('apsm_btn_' + i, {
				text: style.title,
				tooltip: style.title,
				onclick: function () {
					if (tag === 'a') {
						var selectedHtml = editor.selection.getContent({ format: 'html' });
						openLinkDialog({
							text: editor.selection.getContent({ format: 'text' }),
							href: '',
							relNofollow: false,
							relNoopener: false
						}, function (result) {
							var inner = selectedHtml
								? (isStyleType ? stripAllOuterTags(selectedHtml) : selectedHtml)
								: (result.text || result.href);

							var attrs = {
								href: result.href,
								class: cls,
								rel: result.rel
							};

							editor.selection.setContent('<a' + buildAttrString(attrs) + '>' + inner + '</a>');
						});
						return;
					}

					if (tag === 'img') {
						openImageDialog({ src: '', alt: '' }, function (result) {
							var attrs = {
								src: result.src,
								alt: result.alt,
								class: cls
							};
							editor.selection.setContent('<img' + buildAttrString(attrs) + '>');
						});
						return;
					}

					if (isStyleType) {
						/* スタイル: 既存スタイルを全解除してから適用（同じなら解除のみ） */
						var wasActive = editor.formatter.match('apsm_format_' + i);
						for (var j = 0; j < stylesCount; j++) {
							var styleTag = all[j] && (all[j].block || all[j].inline || '');
							if (styleTag === 'a' || styleTag === 'img') continue;
							editor.formatter.remove('apsm_format_' + j);
						}
						if (!wasActive) {
							editor.formatter.apply('apsm_format_' + i);
						}
					} else {
						/* ブロック: そのまま toggle */
						editor.formatter.toggle('apsm_format_' + i);
					}
				},
				onPostRender: function () {
					if (isSpecialTag) return;
					var btn = this;
					editor.on('NodeChange', function () {
						btn.active(editor.formatter.match('apsm_format_' + i));
					});
				}
			});
		});

	});

})();
