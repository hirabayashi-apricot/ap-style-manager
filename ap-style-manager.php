<?php
/*
Plugin Name: AP Style Tag Manager
Plugin URI: https://apricot-design.com/
Description: エディターで使用するスタイルやHTMLテンプレートをJSONで管理し、ボタンから簡単に挿入できるプラグイン
Version: 1.2.1
Author: Apricot Design
Author URI: https://apricot-design.com/
License: GPL2+
Text Domain: ap-style-manager
Update URI: https://github.com/hirabayashi-apricot/ap-style-manager
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
/* 設定はDB（オプション）に保存する。1.1.0まではプラグインのフォルダ内のJSONに保存していたため、更新のたびに消えていた */
const APSM_OPTION = 'apsm_style_formats';
const APSM_OPTION_BAK = 'apsm_style_formats_bak';

/* 1.1.0までの保存先（移行のときだけ読む） */
function apsm_json_path(){
	return apsm_path() . 'editor-style-formats.json';
}

/* 中身（スタイルかブロック）が1つでもあるか */
function apsm_has_items($data){
	if(!is_array($data)) return false;
	foreach($data as $set){
		if(is_array($set) && (!empty($set['styles']) || !empty($set['blocks']))) return true;
	}
	return false;
}

/* 1.1.0までのJSONファイルから設定を探す。更新の途中でWordPressが残す一時控えも見る */
function apsm_find_legacy_json(){
	$candidates = [
		apsm_json_path(),
		WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/ap-style-manager/editor-style-formats.json',
	];
	foreach($candidates as $path){
		if(!is_readable($path)) continue;
		$data = json_decode((string) file_get_contents($path), true);
		if(json_last_error() !== JSON_ERROR_NONE) continue;
		$data = apsm_sanitize_all_formats_data($data);
		if(apsm_has_items($data)){
			/* プラグインのフォルダ内のファイルは、移したら名前を変える（「テーマのファイルに戻す」でまた移されないように） */
			if($path === apsm_json_path() && is_writable($path)){
				@rename($path, $path . '.migrated');
			}
			return $data;
		}
	}
	return null;
}

/* テーマの apsm.json（子テーマを優先） */
function apsm_theme_json_path(){
	$candidates = array_unique([
		get_stylesheet_directory() . '/apsm.json',
		get_template_directory() . '/apsm.json',
	]);
	foreach($candidates as $path){
		if(is_readable($path)) return $path;
	}
	return null;
}

/* テーマの apsm.json を読む。読めない・壊れているときは null */
function apsm_load_theme_json(){
	$path = apsm_theme_json_path();
	if($path === null) return null;
	$data = json_decode((string) file_get_contents($path), true);
	if(json_last_error() !== JSON_ERROR_NONE || !is_array($data)) return null;
	return apsm_sanitize_all_formats_data($data);
}

/* 今の設定がどこから来ているか: db / theme / none */
function apsm_get_source(){
	if(is_array(get_option(APSM_OPTION, null))) return 'db';
	if(apsm_load_theme_json() !== null) return 'theme';
	return 'none';
}

/* 設定を保存する。直前の設定は1世代だけ控える */
function apsm_save_formats($data){
	$current = get_option(APSM_OPTION, null);
	if(is_array($current)){
		update_option(APSM_OPTION_BAK, $current, false);
	}
	update_option(APSM_OPTION, $data, false);
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

	$sanitized = [
		'title' => $title,
		$type => $tag,
		'attributes' => [
			'class' => $class_name
		]
	];

	/* いつ使うか（1.2.0〜）。人とAIが、どの装飾をいつ使うかを判断するための一文 */
	if(isset($item['description']) && is_string($item['description'])){
		$description = mb_substr(sanitize_text_field($item['description']), 0, 200);
		if($description !== ''){
			$sanitized['description'] = $description;
		}
	}

	return $sanitized;
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

/* DBにまだ設定が無いときは、表側の表示でも移行を試す。
   自動更新では、WordPressが更新直後に表側を1回開いて動作を確かめるので、
   そのとき（一時控えがまだ残っているうち）に古い設定を拾える見込みがある */
add_action('init', function(){
	if(get_option(APSM_OPTION, null) === null){
		apsm_get_json();
	}
});

function apsm_get_json(){
	$data = get_option(APSM_OPTION, null);

	/* 読む順番: DB（管理画面で保存したもの）→ 1.1.0以前のJSONファイル（見つかればDBへ移す）→ テーマの apsm.json → 空 */
	if(!is_array($data)){
		$legacy = apsm_find_legacy_json();
		if($legacy !== null){
			apsm_save_formats($legacy);
			$data = $legacy;
		} else {
			$theme = apsm_load_theme_json();
			$data = $theme !== null ? $theme : [];
		}
	}

	if(!isset($data['base'])){
		$data['base'] = [];
	}

	return apsm_sanitize_all_formats_data($data);
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
		<?php
		$source = apsm_get_source();
		$theme_path = apsm_theme_json_path();
		?>
		<div class="apsm-source">
			<?php if($source === 'db'){ ?>
			<p>今の設定: <strong>この画面で保存した内容</strong><?php if($theme_path){ ?>（テーマの <code>apsm.json</code> より優先しています）<?php } ?></p>
			<?php if($theme_path){ ?>
			<form method="post" onsubmit="return confirm('この画面で保存した内容を消して、テーマの apsm.json に戻します。よろしいですか？（直前の内容は1世代だけ控えます）');">
				<?php wp_nonce_field('apsm_reset_to_theme', 'apsm_reset_nonce'); ?>
				<button type="submit" class="button">テーマのファイルに戻す</button>
			</form>
			<?php } ?>
			<?php } elseif($source === 'theme'){ ?>
			<p>今の設定: <strong>テーマの <code>apsm.json</code></strong>。この画面で保存すると、以後はこの画面の内容を優先します。</p>
			<?php } else { ?>
			<p>今の設定: まだありません。テーマに <code>apsm.json</code> を置くか、この画面で作ってください。</p>
			<?php } ?>
		</div>
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
					<button id="apsm-add-style" type="button" class="button">＋スタイル追加</button>
				</div>
				<div class="apsm-section">
					<h2 class="apsm-section-title">ブロック</h2>
					<p class="apsm-section-desc">タグで囲みます（既存タグを残したまま追加）</p>
					<div id="apsm-editor-blocks" class="apsm-editor"></div>
					<button id="apsm-add-block" type="button" class="button">＋ブロック追加</button>
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

		apsm_save_formats($decoded);
		apsm_admin_notice('JSONをインポートしました', 'updated');
		return;
	}

	if (isset($_POST['apsm_reset_nonce'])) {
		if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apsm_reset_nonce'])), 'apsm_reset_to_theme')) {
			apsm_admin_notice('セキュリティチェックに失敗しました。', 'error');
			return;
		}
		$current = get_option(APSM_OPTION, null);
		if (is_array($current)) {
			update_option(APSM_OPTION_BAK, $current, false);
		}
		delete_option(APSM_OPTION);
		apsm_admin_notice('テーマの apsm.json に戻しました', 'updated');
		return;
	}

	if (!isset($_POST['json']) || !isset($_POST['apsm_nonce'])) {
		return;
	}

	if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apsm_nonce'])), 'apsm_save_json')) {
		apsm_admin_notice('セキュリティチェックに失敗しました。', 'error');
		return;
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

	apsm_save_formats($decoded);
	apsm_admin_notice('保存しました', 'updated');
}
add_action('admin_init', 'apsm_handle_save_request');

/* WP-CLI のコマンド（wp apsm check） */
if (defined('WP_CLI') && WP_CLI) {
	require_once __DIR__ . '/cli.php';
}
