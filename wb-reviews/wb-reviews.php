<?php

/**
 * Plugin Name: WB Reviews
 * Description: Автоматически подтягивает отзывы с Wildberries по nmId товара и выводит их в конце контента.
 * Version: 1.0.0
 * Author: Conservative
 * Text Domain: wb-reviews
 */
if (!defined('ABSPATH')) {
    exit;
}

define('WB_REVIEWS_VERSION', '1.0.0');
define('WB_REVIEWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WB_REVIEWS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WB_REVIEWS_PLUGIN_DIR . 'includes/wb-reviews-db.php';
require_once WB_REVIEWS_PLUGIN_DIR . 'includes/wb-reviews-frontend.php';
require_once WB_REVIEWS_PLUGIN_DIR . 'includes/wb-reviews-warmup.php';

// ─── Метабокс в боковой панели редактора ────────────────────────────────────

add_action('add_meta_boxes', 'wb_reviews_add_metabox');

function wb_reviews_add_metabox()
{
    $post_types = get_post_types(['public' => true], 'names');
    add_meta_box(
        'wb_reviews_metabox',
        '🛍 Wildberries nmId',
        'wb_reviews_metabox_html',
        array_values($post_types),
        'side',
        'default'
    );
}

function wb_reviews_metabox_html($post)
{
    $nm_id = get_post_meta($post->ID, 'wb_nm_id', true);
    wp_nonce_field('wb_reviews_save_meta', 'wb_reviews_nonce');
?>
<p style="margin-bottom: 6px; color: #555; font-size: 12px;">
  ID товара с Wildberries.<br>
  Пример URL: wildberries.ru/catalog/<strong>229857564</strong>/detail.aspx
</p>
<input type="text" id="wb_nm_id" name="wb_nm_id" value="<?php echo esc_attr($nm_id); ?>" placeholder="например: 229857564" style="width: 100%; box-sizing: border-box;" inputmode="numeric" pattern="[0-9]*" />
<?php if ($nm_id): ?>
<p style="margin-top: 8px; font-size: 12px; color: #888;">
  <a href="https://www.wildberries.ru/catalog/<?php echo esc_attr($nm_id); ?>/detail.aspx" target="_blank" rel="noopener">Открыть товар на WB ↗</a>
  &nbsp;|&nbsp;
  <a href="#" onclick="wb_reviews_clear_cache(<?php echo esc_attr($nm_id); ?>, this); return false;">
    Сбросить кэш
  </a>
</p>
<script>
function wb_reviews_clear_cache(nmId, link) {
  link.textContent = 'Сбрасываю...';
  fetch(ajaxurl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: 'action=wb_reviews_clear_cache&nm_id=' + nmId + '&nonce=<?php echo wp_create_nonce('wb_reviews_clear_cache'); ?>'
    })
    .then(r => r.json())
    .then(data => {
      link.textContent = data.success ? '✓ Кэш сброшен' : '✗ Ошибка';
      setTimeout(() => link.textContent = 'Сбросить кэш', 3000);
    });
}
</script>
<?php endif; ?>
<?php
}

add_action('save_post', 'wb_reviews_save_metabox');

function wb_reviews_save_metabox($post_id)
{
    if (!isset($_POST['wb_reviews_nonce']))
        return;
    if (!wp_verify_nonce($_POST['wb_reviews_nonce'], 'wb_reviews_save_meta'))
        return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (!current_user_can('edit_post', $post_id))
        return;

    $old_nm_id = get_post_meta($post_id, 'wb_nm_id', true);
    $nm_id = isset($_POST['wb_nm_id']) ? preg_replace('/\D/', '', $_POST['wb_nm_id']) : '';

    if ($nm_id) {
        update_post_meta($post_id, 'wb_nm_id', $nm_id);
        if ($old_nm_id && $old_nm_id !== $nm_id) {
            wb_reviews_remove_from_queue($old_nm_id);
        }

        // Сбрасываем кэш при сохранении нового nmId
        delete_transient('wb_reviews_' . $nm_id);
        wb_reviews_queue_nm_id($nm_id, $post_id);
    } else {
        if ($old_nm_id) {
            wb_reviews_remove_from_queue($old_nm_id);
        }
        delete_post_meta($post_id, 'wb_nm_id');
    }
}

// AJAX: сброс кэша вручную
add_action('wp_ajax_wb_reviews_clear_cache', 'wb_reviews_ajax_clear_cache');

function wb_reviews_ajax_clear_cache()
{
    check_ajax_referer('wb_reviews_clear_cache', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error();
    }
    $nm_id = preg_replace('/\D/', '', $_POST['nm_id'] ?? '');
    if ($nm_id) {
        delete_transient('wb_reviews_' . $nm_id);
        wp_send_json_success();
    }
    wp_send_json_error();
}

// ─── Получение отзывов из API ────────────────────────────────────────────────

function wb_reviews_fetch($nm_id)
{
    delete_transient('wb_reviews_error_' . intval($nm_id));

    $api_key = defined('WB_API_KEY') ? WB_API_KEY : get_option('wb_reviews_api_key', '');

    if (empty($api_key)) {
        $msg = '[WB Reviews] API-ключ не задан. Зайди в Настройки → WB Reviews.';
        error_log($msg);
        set_transient('wb_reviews_error_' . intval($nm_id), $msg, 60);
        return [];
    }

    if (empty($nm_id)) {
        error_log('[WB Reviews] nmId не указан для записи.');
        return [];
    }

    // Сначала проверяем БД
    $from_db = wb_reviews_get_from_db($nm_id);
    if (!empty($from_db)) {
        return $from_db;
    }

    // Если в БД нет — не идём к API, крон прогреет позже
    if (!defined('WB_REVIEWS_WARMUP') || !WB_REVIEWS_WARMUP) {
        return [];
    }

    $url = add_query_arg([
        'isAnswered' => 'true',
        'take' => 40,
        'skip' => 0,
        'nmId' => intval($nm_id),
    ], 'https://feedbacks-api.wildberries.ru/api/v1/feedbacks');

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
    ]);

    if (is_wp_error($response)) {
        $msg = '[WB Reviews] Ошибка запроса: ' . $response->get_error_message();
        error_log($msg);
        set_transient('wb_reviews_error_' . intval($nm_id), $msg, 60);
        return [];
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    error_log('[WB Reviews] Код ответа: ' . $code . ' для nmId ' . $nm_id);

    if ($code !== 200) {
        $msg = '[WB Reviews] API вернул код ' . $code . ' для nmId ' . $nm_id . '. Ответ: ' . $body;
        error_log($msg);

        if ($code === 429) {
            $retry_after = (int) wp_remote_retrieve_header($response, 'x-ratelimit-retry');
            if ($retry_after < 1)
                $retry_after = 60;
            error_log('[WB Reviews] 429 для nmId ' . $nm_id . '. Повтор через ' . $retry_after . ' сек.');
            set_transient('wb_reviews_retry_' . intval($nm_id), $retry_after, 3600);
        }

        set_transient('wb_reviews_error_' . intval($nm_id), $msg, 60);
        return [];
    }

    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $msg = '[WB Reviews] Не удалось разобрать JSON. Ответ: ' . $body;
        error_log($msg);
        set_transient('wb_reviews_error_' . intval($nm_id), $msg, 60);
        return [];
    }

    $feedbacks = $data['data']['feedbacks'] ?? [];
    error_log('[WB Reviews] Получено отзывов: ' . count($feedbacks));

    if (!empty($feedbacks)) {
        wb_reviews_save_to_db($nm_id, $feedbacks);
    } else {
        error_log('[WB Reviews] Отзывы не найдены для nmId ' . $nm_id);
    }

    return wb_reviews_get_from_db($nm_id);
}

function wb_reviews_stars($rating)
{
    $rating = max(0, min(5, intval($rating)));
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $stars .= $i <= $rating ? '★' : '☆';
    }
    return '<span class="wb-reviews__star-icons" aria-label="' . $rating . ' из 5">' . $stars . '</span>';
}

// AJAX
add_action('wp_ajax_wb_get_reviews', 'wb_reviews_ajax_get');
add_action('wp_ajax_nopriv_wb_get_reviews', 'wb_reviews_ajax_get');

function wb_reviews_ajax_get()
{
    $nm_id = intval($_GET['nm_id'] ?? 0);

    if (!$nm_id) {
        wp_send_json_error('nmId не передан', 400);
    }

    $feedbacks = wb_reviews_fetch($nm_id);

    wp_send_json_success($feedbacks);
}

add_filter('plugin_action_links_wb-reviews/wb-reviews.php', 'wb_reviews_action_links');

function wb_reviews_action_links($links)
{
    $settings_link = '<a href="' . admin_url('options-general.php?page=wb-reviews') . '">Настройки</a>';
    array_unshift($links, $settings_link);
    return $links;
}

add_action('init', function () {
    if (isset($_GET['wb_warmup']) && current_user_can('manage_options')) {
        do_action('wb_reviews_cache_warmup');
        die('Warmup запущен, смотри лог');
    }
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('wb-reviews warmup', function () {
        do_action('wb_reviews_cache_warmup');
        WP_CLI::success('WB Reviews warmup completed.');
    });
}
