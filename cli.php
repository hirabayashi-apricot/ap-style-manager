<?php
/* WP-CLI: wp apsm check（1.2.0〜） */

if (!defined('ABSPATH')) {
	exit;
}

class APSM_CLI_Command {

	/**
	 * 装飾のクラスが、テーマの表側のCSSと編集画面のCSSの両方にあるかを確かめる。
	 *
	 * 表側のCSSは、テーマ内の .css のうち編集画面用（add_editor_style で登録したもの）以外。
	 * 命名は --class-pattern を渡したときだけ確かめる（プラグインには特定の命名ルールを持たせない）。
	 * 1つでも × があれば、一覧を出したあと失敗（終了コード1）で終わる。
	 *
	 * ## OPTIONS
	 *
	 * [--class-pattern=<regex>]
	 * : クラス名1つずつが満たす正規表現。区切り文字（/）は付けずに書く。
	 *
	 * [--format=<format>]
	 * : 出力の形式。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp apsm check
	 *     wp apsm check --class-pattern='^(c|u)-[a-z0-9]+(-[a-z0-9]+)*$'
	 *
	 * @when after_wp_load
	 */
	public function check($args, $assoc_args) {
		$pattern = isset($assoc_args['class-pattern']) ? (string) $assoc_args['class-pattern'] : '';
		$format  = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

		if ($pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/', '') === false) {
			WP_CLI::error('--class-pattern の正規表現が正しくありません。');
		}

		/* テーマの apsm.json が壊れていないか */
		$theme_path = apsm_theme_json_path();
		if ($theme_path !== null) {
			json_decode((string) file_get_contents($theme_path), true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				WP_CLI::error('テーマの apsm.json が読めません（' . json_last_error_msg() . '）: ' . $theme_path);
			}
		}

		$css = $this->collect_css();
		if ($css['editor_files'] === []) {
			WP_CLI::warning('編集画面のCSSが登録されていません（テーマで add_editor_style() を呼んでいるか確認してください）。');
		}

		$rows   = [];
		$failed = 0;
		$labels = ['styles' => 'スタイル', 'blocks' => 'ブロック'];

		foreach (apsm_get_json() as $target => $set) {
			foreach ($labels as $kind => $kind_label) {
				foreach (($set[$kind] ?? []) as $item) {
					$class_list = trim($item['attributes']['class'] ?? '');
					$classes    = $class_list === '' ? [''] : preg_split('/\s+/', $class_list);

					foreach ($classes as $class) {
						$row = [
							'対象'       => $target,
							'種類'       => $kind_label,
							'項目'       => $item['title'],
							'クラス'     => $class === '' ? '（なし）' : $class,
							'表側CSS'    => '-',
							'編集画面CSS' => '-',
							'命名'       => '-',
							'説明'       => isset($item['description']) ? '○' : '-',
						];

						if ($class !== '') {
							$in_front  = $this->has_class($css['front'], $class);
							$in_editor = $this->has_class($css['editor'], $class);
							$row['表側CSS']    = $in_front ? '○' : '×';
							$row['編集画面CSS'] = $in_editor ? '○' : '×';
							if (!$in_front || !$in_editor) {
								$failed++;
							}
							if ($pattern !== '') {
								$ok = (bool) preg_match('/' . str_replace('/', '\/', $pattern) . '/', $class);
								$row['命名'] = $ok ? '○' : '×';
								if (!$ok) {
									$failed++;
								}
							}
						}

						$rows[] = $row;
					}
				}
			}
		}

		WP_CLI::log('設定の出どころ: ' . apsm_get_source() . ($theme_path ? '（テーマ: ' . $theme_path . '）' : ''));
		WP_CLI::log('表側CSS: ' . count($css['front_files']) . 'ファイル／編集画面CSS: ' . count($css['editor_files']) . 'ファイル');

		if ($rows === []) {
			WP_CLI::success('確かめる項目がありません。');
			return;
		}

		WP_CLI\Utils\format_items($format, $rows, array_keys($rows[0]));

		if ($failed > 0) {
			WP_CLI::error($failed . '件の × があります。');
		}
		WP_CLI::success('すべて ○ です。');
	}

	/* テーマ内のCSSを、表側と編集画面用に分けて読む */
	private function collect_css() {
		global $editor_styles;

		$dirs = array_values(array_unique([get_stylesheet_directory(), get_template_directory()]));

		$editor_files = [];
		foreach ((array) $editor_styles as $style) {
			if (preg_match('#^(https?:)?//#', $style)) {
				continue;
			}
			foreach ($dirs as $dir) {
				$path = realpath($dir . '/' . ltrim($style, '/'));
				if ($path && is_readable($path)) {
					$editor_files[] = $path;
					break;
				}
			}
		}
		$editor_files = array_values(array_unique($editor_files));

		$front_files = [];
		foreach ($dirs as $dir) {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				$path = $file->getPathname();
				if (strtolower($file->getExtension()) !== 'css') {
					continue;
				}
				if (preg_match('#/(node_modules|vendor|\.git)/#', $path)) {
					continue;
				}
				$real = realpath($path);
				if ($real && !in_array($real, $editor_files, true)) {
					$front_files[] = $real;
				}
			}
		}
		$front_files = array_values(array_unique($front_files));

		return [
			'front'        => $this->read_css($front_files),
			'editor'       => $this->read_css($editor_files),
			'front_files'  => $front_files,
			'editor_files' => $editor_files,
		];
	}

	private function read_css($files) {
		$css = '';
		foreach ($files as $path) {
			$css .= "\n" . (string) file_get_contents($path);
		}
		/* コメントの中の記述は数えない */
		return preg_replace('#/\*.*?\*/#s', '', $css);
	}

	private function has_class($css, $class) {
		return (bool) preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css);
	}
}

WP_CLI::add_command('apsm', 'APSM_CLI_Command');
