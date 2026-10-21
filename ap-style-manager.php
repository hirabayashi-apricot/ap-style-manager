<?php
/*
Plugin Name: AP Style Tag Manager
Plugin URI: https://apricot-design.com/
Description: エディターで使用するスタイルやHTMLテンプレートをJSONで管理し、ボタンから簡単に挿入できるプラグイン
Version: 1.1.0
Author: Apricot Design
Author URI: https://apricot-design.com/
License: GPL2+
Text Domain: ap-style-manager
*/

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
/* 更新はGitHubのリリースから配る */
$updateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/hirabayashi-apricot/ap-style-manager/',
	__FILE__,
	'ap-style-manager'
);
/* リリースに添付したzip（vendor入り・フォルダ名 ap-style-manager）を配る */
$updateChecker->getVcsApi()->enableReleaseAssets();

function apsm_path(){
	return plugin_dir_path(__FILE__);
}
function apsm_url(){
	return plugin_dir_url(__FILE__);
}
/* 管理画面メニュー */
add_action('admin_menu', function () {
	add_options_page(
		'スタイルタグ管理',
		'スタイルタグ',
		'manage_options',
		'ap-style-manager',
		'apsm_admin_page'
	);
});
/* admin script */
add_action('admin_enqueue_scripts', function ($hook) {
	if ($hook !== 'settings_page_ap-style-manager') {
		return;
	}
	wp_enqueue_script(
		'apsm-admin',
		apsm_url() . 'admin.js',
		[],
		false,
		true
	);
	wp_enqueue_style(
		'apsm-admin',
		apsm_url() . 'admin.css'
	);
});
/* JSON */
function apsm_json_path(){
	return apsm_path() . 'editor-style-formats.json';
}

function apsm_is_valid_tag_name($tag){
	if(!is_string($tag)){
		return false;
	}
	return (bool) preg_match('/^[a-z][a-z0-9-]*$/', $tag);
}

function apsm_sanitize_class_list($class_list){
	if(!is_string($class_list)){
		return '';
	}
	$class_list = preg_replace('/[^A-Za-z0-9_\-\s]/', '', $class_list);
	$class_list = preg_replace('/\s+/', ' ', $class_list);
	return trim($class_list);
}

function apsm_is_valid_class_list($class_list){
	if(!is_string($class_list)){
		return false;
	}
	$class_list = trim(preg_replace('/\s+/', ' ', $class_list));
	if($class_list === ''){
		return true;
	}
	return (bool) preg_match('/^[A-Za-z0-9_-]+(\s+[A-Za-z0-9_-]+)*$/', $class_list);
}

function apsm_block_tags(){
	return [
		'p',
		'div',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'blockquote',
		'ul',
		'ol',
		'li',
		'table',
		'section',
		'article',
		'figure'
	];
}

function apsm_sanitize_style_item($item){
	if(!is_array($item)){
		return null;
	}

	$title = '';
	if(isset($item['title'])){
		$title = sanitize_text_field($item['title']);
	}
	if($title === ''){
		return null;
	}

	$type = '';
	$tag = '';
	if(isset($item['block']) && is_string($item['block'])){
		$type = 'block';
		$tag = strtolower($item['block']);
	} elseif(isset($item['inline']) && is_string($item['inline'])){
		$type = 'inline';
		$tag = strtolower($item['inline']);
	} elseif(isset($item['tag']) && is_string($item['tag'])){
		$tag = strtolower($item['tag']);
		$type = in_array($tag, apsm_block_tags(), true) ? 'block' : 'inline';
	}

	$tag = sanitize_key($tag);
	if($tag === '' || !apsm_is_valid_tag_name($tag)){
		return null;
	}

	$class_name = '';
	$raw_class_name = '';
	if(isset($item['attributes']) && is_array($item['attributes']) && isset($item['attributes']['class'])){
		$raw_class_name = (string) $item['attributes']['class'];
	} elseif(isset($item['class'])) {
		$raw_class_name = (string) $item['class'];
	}

	$raw_class_name = trim(preg_replace('/\s+/', ' ', $raw_class_name));
	if($raw_class_name !== '' && !apsm_is_valid_class_list($raw_class_name)){
		return null;
	}
	$class_name = apsm_sanitize_class_list($raw_class_name);

	return [
		'title' => $title,
		$type => $tag,
		'attributes' => [
			'class' => $class_name
		]
	];
}

function apsm_normalize_style_formats($styles, &$invalid_count = 0){
	if(!is_array($styles)){
		return [];
	}
	$normalized = [];
	foreach($styles as $style){
		if(!is_array($style)){
			$invalid_count++;
			continue;
		}
		if(isset($style['items']) && is_array($style['items'])){
			foreach($style['items'] as $item){
				$sanitized_item = apsm_sanitize_style_item($item);
				if($sanitized_item){
					$normalized[] = $sanitized_item;
				} else {
					$invalid_count++;
				}
			}
			continue;
		}
		$sanitized_item = apsm_sanitize_style_item($style);
		if($sanitized_item){
			$normalized[] = $sanitized_item;
		} else {
			$invalid_count++;
		}
	}
	return array_values($normalized);
}

function apsm_target_post_types($output = 'names'){
	$post_types = get_post_types(
		[
			'public' => true,
			'_builtin' => false
		],
		$output
	);

	if($output === 'objects'){
		$post_type_post = get_post_type_object('post');
		if($post_type_post){
			$post_types = array_merge(['post' => $post_type_post], $post_types);
		}
		return $post_types;
	}

	return array_values(array_unique(array_merge(['post'], $post_types)));
}

function apsm_allowed_data_keys(){
	$target_post_types = apsm_target_post_types('names');
	return array_merge(['base'], $target_post_types);
}

function apsm_sanitize_all_formats_data($decoded, &$has_invalid = false){
	$allowed_keys = apsm_allowed_data_keys();

	if(!is_array($decoded)){
		$decoded = [];
	}

	$decoded = array_intersect_key($decoded, array_flip($allowed_keys));

	$has_invalid = false;
	foreach($allowed_keys as $key){
		if(!isset($decoded[$key])){
			$decoded[$key] = [];
		}

		if(!isset($decoded[$key]['styles']) && !isset($decoded[$key]['blocks'])){
			$old = is_array($decoded[$key]) ? array_values($decoded[$key]) : [];
			$decoded[$key] = ['styles' => $old, 'blocks' => []];
		}

		$invalid_count = 0;
		$decoded[$key]['styles'] = apsm_normalize_style_formats($decoded[$key]['styles'] ?? [], $invalid_count);
		$decoded[$key]['blocks'] = apsm_normalize_style_formats($decoded[$key]['blocks'] ?? [], $invalid_count);
		if($invalid_count > 0){
			$has_invalid = true;
		}
	}

	return $decoded;
}

function apsm_get_json(){
	$path = apsm_json_path();
	if(file_exists($path)){
		$json = file_get_contents($path);
		$data = json_decode($json, true);
	} else {
		$data = [];
	}
	if(json_last_error() !== JSON_ERROR_NONE){
		$data = [];
	}
	if(!$data){
		$data = [];
	}
	if(!isset($data['base'])){
		$data['base'] = [];
	}

	$data = apsm_sanitize_all_formats_data($data);

	file_put_contents(
		$path,
		wp_json_encode(
			$data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
		)
	);
	return $data;
}

/* Classic Editor ビジュアルタブ: ツールバーボタン */
add_filter('tiny_mce_before_init', function ($init) {
	global $post;
	$formats   = apsm_get_json();
	$post_type = isset($post->post_type) ? $post->post_type : 'post';

	$styles = [];
	$blocks = [];
	if (isset($formats['base'])) {
		$styles = array_merge($styles, $formats['base']['styles'] ?? []);
		$blocks = array_merge($blocks, $formats['base']['blocks'] ?? []);
	}
	if (isset($formats[$post_type])) {
		$styles = array_merge($styles, $formats[$post_type]['styles'] ?? []);
		$blocks = array_merge($blocks, $formats[$post_type]['blocks'] ?? []);
	}

	$init['apsm_styles'] = wp_json_encode($styles);
	$init['apsm_blocks'] = wp_json_encode($blocks);

	return $init;
});

add_filter('mce_external_plugins', function ($plugins) {
	$plugins['apsm_buttons'] = apsm_url() . 'tinymce-plugin.js';
	return $plugins;
});

add_filter('mce_buttons_2', function ($buttons) {
	global $post;
	$formats   = apsm_get_json();
	$post_type = isset($post->post_type) ? $post->post_type : 'post';

	$all = [];
	if (isset($formats['base'])) {
		$all = array_merge($all, $formats['base']['styles'] ?? [], $formats['base']['blocks'] ?? []);
	}
	if (isset($formats[$post_type])) {
		$all = array_merge($all, $formats[$post_type]['styles'] ?? [], $formats[$post_type]['blocks'] ?? []);
	}

	foreach ($all as $i => $style) {
		$tag = $style['block'] ?? ($style['inline'] ?? '');
		if ($tag !== '') {
			$buttons[] = 'apsm_btn_' . $i;
		}
	}

	return $buttons;
});

/* エディター QuickTags注入 */
add_action('admin_enqueue_scripts', function ($hook) {
	if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
		return;
	}
	global $post;
	$formats   = apsm_get_json();
	$post_type = isset($post->post_type) ? $post->post_type : 'post';
	$styles_data = [];
	$blocks_data = [];
	if (isset($formats['base'])) {
		$styles_data = array_merge($styles_data, $formats['base']['styles'] ?? []);
		$blocks_data = array_merge($blocks_data, $formats['base']['blocks'] ?? []);
	}
	if (isset($formats[$post_type])) {
		$styles_data = array_merge($styles_data, $formats[$post_type]['styles'] ?? []);
		$blocks_data = array_merge($blocks_data, $formats[$post_type]['blocks'] ?? []);
	}
	wp_enqueue_script(
		'apsm-editor',
		apsm_url() . 'editor.js',
		['quicktags'],
		'1.0.0',
		true
	);
	wp_enqueue_media();
	wp_localize_script(
		'apsm-editor',
		'apsmData',
		[
			'styles' => $styles_data,
			'blocks' => $blocks_data,
		]
	);
});

/* 管理画面 */
function apsm_admin_page(){
	$data = apsm_get_json();
	$json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	$export_url = wp_nonce_url(
		admin_url('options-general.php?page=ap-style-manager&apsm_export=1'),
		'apsm_export_json',
		'apsm_export_nonce'
	);
	if(!$json){
		$json = '{}';
	}

?>
	<div class="wrap">
		<h1>スタイルタグ管理</h1>
		<form method="post">
			<?php wp_nonce_field('apsm_save_json', 'apsm_nonce'); ?>
			<select id="apsm-posttype">
			<option value="base">共通</option>
			<?php
			$post_types = apsm_target_post_types('objects');
			foreach($post_types as $pt){
				echo '<option value="'.esc_attr($pt->name).'">'.esc_html($pt->labels->singular_name).'</option>';
			}
			?>
			</select>
			<div class="apsm-sections">
				<div class="apsm-section">
					<h2 class="apsm-section-title">スタイル</h2>
					<p class="apsm-section-desc">選択テキストのタグを上書きします</p>
					<div id="apsm-editor-styles" class="apsm-editor"></div>
					<button id="add-style" type="button" class="button">＋スタイル追加</button>
				</div>
				<div class="apsm-section">
					<h2 class="apsm-section-title">ブロック</h2>
					<p class="apsm-section-desc">タグで囲みます（既存タグを残したまま追加）</p>
					<div id="apsm-editor-blocks" class="apsm-editor"></div>
					<button id="add-block" type="button" class="button">＋ブロック追加</button>
				</div>
			</div>
			<p id="apsm-validation" class="apsm-validation" hidden></p>
			<textarea id="apsm-json" name="json" hidden><?php echo esc_textarea($json); ?></textarea>
			<p>
				<button class="button button-primary">保存</button>
			</p>
		</form>
		<hr>
		<h2>JSONインポート / エクスポート</h2>
		<p>
			<a href="<?php echo esc_url($export_url); ?>" class="button">JSONをエクスポート</a>
		</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field('apsm_import_json', 'apsm_import_nonce'); ?>
			<p>
				<input type="file" name="apsm_import_file" accept=".json,application/json" required>
				<button type="submit" class="button">JSONをインポート</button>
			</p>
			<p class="description">インポート時は現在の設定を置き換えます。不正なデータが含まれる場合は中止します。</p>
		</form>
	</div>
<?php
}

function apsm_admin_notice($message, $type = 'updated'){
	add_action('admin_notices', function () use ($message, $type) {
		echo '<div class="' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
	});
}

function apsm_handle_save_request(){
	if (!is_admin() || !function_exists('wp_verify_nonce')) {
		return;
	}
	if (!current_user_can('manage_options')) {
		return;
	}

	$page = '';
	if (isset($_GET['page'])) {
		$page = sanitize_key(wp_unslash($_GET['page']));
	}
	if ($page !== 'ap-style-manager') {
		return;
	}

	if (isset($_GET['apsm_export']) && $_GET['apsm_export'] === '1') {
		$nonce = isset($_GET['apsm_export_nonce']) ? sanitize_text_field(wp_unslash($_GET['apsm_export_nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'apsm_export_json')) {
			apsm_admin_notice('エクスポートのセキュリティチェックに失敗しました。', 'error');
			return;
		}

		$data = apsm_get_json();
		$json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if (!$json) {
			apsm_admin_notice('JSONの生成に失敗しました。', 'error');
			return;
		}

		nocache_headers();
		header('Content-Type: application/json; charset=' . get_option('blog_charset'));
		header('Content-Disposition: attachment; filename="ap-style-formats-' . gmdate('Ymd-His') . '.json"');
		echo $json;
		exit;
	}

	if (isset($_POST['apsm_import_nonce'])) {
		if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apsm_import_nonce'])), 'apsm_import_json')) {
			apsm_admin_notice('インポートのセキュリティチェックに失敗しました。', 'error');
			return;
		}

		if (!isset($_FILES['apsm_import_file']) || !is_array($_FILES['apsm_import_file'])) {
			apsm_admin_notice('インポートするJSONファイルを選択してください。', 'error');
			return;
		}

		$file = $_FILES['apsm_import_file'];
		if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
			apsm_admin_notice('ファイルのアップロードに失敗しました。', 'error');
			return;
		}

		if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			apsm_admin_notice('アップロードファイルを確認できませんでした。', 'error');
			return;
		}

		$ext = '';
		if (isset($file['name'])) {
			$ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
		}
		if ($ext !== 'json') {
			apsm_admin_notice('JSONファイル（.json）を選択してください。', 'error');
			return;
		}

		$content = file_get_contents($file['tmp_name']);
		if ($content === false || $content === '') {
			apsm_admin_notice('JSONファイルを読み込めませんでした。', 'error');
			return;
		}

		$decoded = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			apsm_admin_notice('JSONエラーがあります', 'error');
			return;
		}

		$has_invalid = false;
		$decoded = apsm_sanitize_all_formats_data($decoded, $has_invalid);
		if ($has_invalid) {
			apsm_admin_notice('入力内容に不正な値があります。タイトル・タグ・classを確認してください。', 'error');
			return;
		}

		$path = apsm_json_path();
		if (file_exists($path)) {
			copy($path, $path . '.bak');
		}

		file_put_contents(
			$path,
			wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
		);
		apsm_admin_notice('JSONをインポートしました', 'updated');
		return;
	}

	if (!isset($_POST['json']) || !isset($_POST['apsm_nonce'])) {
		return;
	}

	if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apsm_nonce'])), 'apsm_save_json')) {
		apsm_admin_notice('セキュリティチェックに失敗しました。', 'error');
		return;
	}

	$path = apsm_json_path();
	if (file_exists($path)) {
		copy($path, $path . '.bak');
	}

	$json = wp_unslash($_POST['json']);
	$decoded = json_decode($json, true);
	if (json_last_error() !== JSON_ERROR_NONE) {
		apsm_admin_notice('JSONエラーがあります', 'error');
		return;
	}

	$has_invalid = false;
	$decoded = apsm_sanitize_all_formats_data($decoded, $has_invalid);

	if($has_invalid){
		apsm_admin_notice('入力内容に不正な値があります。タイトル・タグ・classを確認してください。', 'error');
		return;
	}

	file_put_contents(
		$path,
		wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
	);
	apsm_admin_notice('保存しました', 'updated');
}
add_action('admin_init', 'apsm_handle_save_request');
