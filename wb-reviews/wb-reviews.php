<?php

/**
 * Plugin Name: WB Reviews
 * Description: Автоматически подтягивает отзывы с Wildberries по nmId товара и выводит их в конце контента.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: wb-reviews
 */
if (!defined('ABSPATH')) {
    exit;
}

define('WB_REVIEWS_VERSION', '1.0.0');
define('WB_REVIEWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WB_REVIEWS_PLUGIN_URL', plugin_dir_url(__FILE__));

// require_once WB_REVIEWS_PLUGIN_DIR . 'includes/wb-reviews-warmup.php';

// ─── Настройки плагина ───────────────────────────────────────────────────────

add_action('admin_menu', 'wb_reviews_admin_menu');

function wb_reviews_admin_menu()
{
    add_options_page(
        'WB Reviews',
        'WB Reviews',
        'manage_options',
        'wb-reviews',
        'wb_reviews_settings_page'
    );
}

add_action('admin_init', 'wb_reviews_register_settings');

function wb_reviews_register_settings()
{
    register_setting('wb_reviews_group', 'wb_reviews_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('wb_reviews_group', 'wb_reviews_custom_field', [
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'wb_nm_id',
    ]);
    register_setting('wb_reviews_group', 'wb_reviews_cache_time', [
        'sanitize_callback' => 'absint',
        'default' => 3600,
    ]);
    register_setting('wb_reviews_group', 'wb_reviews_take', [
        'sanitize_callback' => 'absint',
        'default' => 20,
    ]);
}

function wb_reviews_settings_page()
{
    ?>
<div class="wrap">
  <h1>WB Reviews — Настройки</h1>
  <form method="post" action="options.php">
    <?php settings_fields('wb_reviews_group'); ?>
    <table class="form-table">
      <tr>
        <th><label for="wb_reviews_api_key">API-ключ продавца WB</label></th>
        <td>
          <input type="password" id="wb_reviews_api_key" name="wb_reviews_api_key" value="<?php echo esc_attr(get_option('wb_reviews_api_key')); ?>" class="regular-text" />
          <p class="description">Ключ из личного кабинета Wildberries → Настройки → Доступ к API.</p>
        </td>
      </tr>
      <tr>
        <th><label for="wb_reviews_custom_field">Название custom field с nmId</label></th>
        <td>
          <input type="text" id="wb_reviews_custom_field" name="wb_reviews_custom_field" value="<?php echo esc_attr(get_option('wb_reviews_custom_field', 'wb_nm_id')); ?>" class="regular-text" />
          <p class="description">По умолчанию: <code>wb_nm_id</code></p>
        </td>
      </tr>
      <tr>
        <th><label for="wb_reviews_take">Количество отзывов</label></th>
        <td>
          <input type="number" id="wb_reviews_take" name="wb_reviews_take" value="<?php echo esc_attr(get_option('wb_reviews_take', 20)); ?>" min="1" max="200" class="small-text" />
        </td>
      </tr>
      <tr>
        <th><label for="wb_reviews_cache_time">Кэш (секунды)</label></th>
        <td>
          <input type="number" id="wb_reviews_cache_time" name="wb_reviews_cache_time" value="<?php echo esc_attr(get_option('wb_reviews_cache_time', 3600)); ?>" min="60" class="small-text" />
          <p class="description">3600 = 1 час. Отзывы не будут запрашиваться заново до истечения кэша.</p>
        </td>
      </tr>
    </table>
    <?php submit_button(); ?>
  </form>
</div>
<?php
}

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

    $nm_id = isset($_POST['wb_nm_id']) ? preg_replace('/\D/', '', $_POST['wb_nm_id']) : '';

    if ($nm_id) {
        update_post_meta($post_id, 'wb_nm_id', $nm_id);
        // Сбрасываем кэш при сохранении новго nmId
        delete_transient('wb_reviews_' . $nm_id);
    } else {
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

    $cache_key = 'wb_reviews_' . intval($nm_id);
    $cached = get_transient($cache_key);

    if (false !== $cached) {
        return $cached;
    }

    $url = add_query_arg([
        'isAnswered' => 'true',
        'take' => 40,
        'skip' => 0,
        'nmId' => intval($nm_id),
    ], 'https://feedbacks-api.wildberries.ru/api/v1/feedbacks');

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
        ],
    ]);

    if (is_wp_error($response)) {
        $msg = '[WB Reviews] Ошибка запроса: ' . $response->get_error_message();
        error_log($msg);
        set_transient('wb_reviews_error_' . intval($nm_id), $msg, 60);
        return [];
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code !== 200) {
        $msg = '[WB Reviews] API вернул код ' . $code . ' для nmId ' . $nm_id . '. Ответ: ' . $body;
        error_log($msg);

        if ($code === 429) {
            $retry_after = (int) wp_remote_retrieve_header($response, 'x-ratelimit-retry');
            if ($retry_after < 1)
                $retry_after = 60;
            error_log('[WB Reviews] 429 для nmId ' . $nm_id . '. Повтор через ' . $retry_after . ' сек.');
            set_transient($cache_key, [], $retry_after + 5);
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

    if (empty($data['data']['feedbacks'])) {
        error_log('[WB Reviews] Отзывы не найдены для nmId ' . $nm_id);
    }

    $feedbacks = $data['data']['feedbacks'] ?? [];

    $feedbacks = array_filter($feedbacks, function ($fb) {
        return intval($fb['productValuation'] ?? 0) >= 4;
    });

    usort($feedbacks, function ($a, $b) {
        $aHasText = !empty($a['text']) || !empty($a['pros']) || !empty($a['cons']);
        $bHasText = !empty($b['text']) || !empty($b['pros']) || !empty($b['cons']);
        return $bHasText - $aHasText;
    });

    $feedbacks = array_slice($feedbacks, 0, 20);

    set_transient($cache_key, $feedbacks, 10 * DAY_IN_SECONDS);

    return $feedbacks;
}

// ─── Вывод отзывов ───────────────────────────────────────────────────────────

add_filter('the_content', 'wb_reviews_append_to_content');

function wb_reviews_append_to_content($content)
{
    if (!is_singular() || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    $field_name = get_option('wb_reviews_custom_field', 'wb_nm_id');
    $nm_id = get_post_meta(get_the_ID(), $field_name, true);

    if (empty($nm_id)) {
        return $content;
    }

    $feedbacks = wb_reviews_fetch($nm_id);

    // Пробрасываем ошибку в консоль браузера
    $error_msg = get_transient('wb_reviews_error_' . intval($nm_id));
    if ($error_msg) {
        wp_enqueue_script(
            'wb-reviews-debug',
            WB_REVIEWS_PLUGIN_URL . 'assets/wb-reviews-debug.js',
            [],
            WB_REVIEWS_VERSION,
            true
        );
        wp_localize_script('wb-reviews-debug', 'wb_reviews_error', [
            'message' => $error_msg,
        ]);
    }

    if (empty($feedbacks)) {
        return $content;
    }

    ob_start();
?>
<div class="wb-reviews">
  <div class="section-separator-title">
    <span>Отзывы покупателей</span>
  </div>
  <div class="wb-reviews__carousel">
    <div class="wb-reviews__track">
      <?php
    foreach ($feedbacks as $fb):
        $author = esc_html($fb['userName'] ?? 'Аноним');
        $rating = intval($fb['productValuation'] ?? 0);
        $text = esc_html($fb['text'] ?? '');
        $pros = esc_html($fb['pros'] ?? '');
        // $cons = esc_html($fb['cons'] ?? '');
        $photoLinks = $fb['photoLinks'] ?? [];
        $date_raw = $fb['createdDate'] ?? '';
        $date = $date_raw ? date_i18n('d.m.Y', strtotime($date_raw)) : '';
        ?>
      <div class="wb-reviews__item">
        <div class="wb-reviews__header">
          <span class="wb-reviews__author"><?php echo $author; ?></span>
          <span class="wb-reviews__stars"><?php echo wb_reviews_stars($rating); ?></span>
          <?php if ($date): ?>
          <span class="wb-reviews__date"><?php echo $date; ?></span>
          <?php endif; ?>
        </div>
        <?php if ($pros): ?>
        <p class="wb-reviews__pros"><strong>Достоинства:</strong> <?php echo $pros; ?></p>
        <?php endif; ?>
        <?php if ($cons): ?>
        <p class="wb-reviews__cons"><strong>Недостатки:</strong> <?php echo $cons; ?></p>
        <?php endif; ?>
        <?php if ($text): ?>
        <p class="wb-reviews__text"><?php echo $text; ?></p>
        <?php endif; ?>
        <?php if ($photoLinks): ?>
        <div class="wb-reviews__photos">
          <?php foreach ($photoLinks as $photo): ?>
          <a href="<?php echo esc_url($photo['fullSize']); ?>" target="_blank">
            <img src="<?php echo esc_url($photo['miniSize']); ?>" alt="Фото отзыва" class="wb-reviews__photo" />
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="wb-reviews__btn wb-reviews__btn--prev" aria-label="Назад">&#8592;</button>
    <button class="wb-reviews__btn wb-reviews__btn--next" aria-label="Вперёд">&#8594;</button>
    <div class="wb-reviews__dots"></div>
  </div>
</div>

<div class="wb-foto-reviews">
  <div class="section-separator-title">
    <span>Фото покупателей</span>
  </div>
  <div class="flex justify-center gap-[5px]">
    <?php
    foreach ($feedbacks as $fb):
        $photoLinks = $fb['photoLinks'] ?? [];
        foreach ($photoLinks as $photo):
            ?>
    <img src="<?php echo esc_url($photo['fullSize']); ?>" alt="Фото отзыва" class="wb-reviews__photo" />
    <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div class="wb-lightbox" id="wbLightbox">
    <button class="wb-lightbox__close">&times;</button>
    <button class="wb-lightbox__nav wb-lightbox__prev">&#8592;</button>
    <img class="wb-lightbox__img" src="" alt="">
    <button class="wb-lightbox__nav wb-lightbox__next">&#8594;</button>
  </div>
</div>
<?php
    $html = ob_get_clean();

    wp_enqueue_style(
        'wb-reviews-style',
        WB_REVIEWS_PLUGIN_URL . 'assets/wb-reviews.css',
        [],
        WB_REVIEWS_VERSION
    );

    wp_enqueue_script(
        'wb-reviews-frontend',
        WB_REVIEWS_PLUGIN_URL . 'assets/wb-reviews-frontend.js',
        [],
        WB_REVIEWS_VERSION,
        true
    );

    wp_enqueue_script(
        'wb-photo-reviews-frontend',
        WB_REVIEWS_PLUGIN_URL . 'assets/wb-photo-reviews-frontend.js',
        [],
        WB_REVIEWS_VERSION,
        true
    );

    wp_localize_script('wb-reviews-frontend', 'wb_reviews_data', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nm_id' => $nm_id,
    ]);

    return $content . $html;
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
