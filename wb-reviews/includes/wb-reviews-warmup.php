<?php
if (!defined('ABSPATH')) {
  exit;
}

// Регистрируем событие при активации плагина
register_activation_hook(WB_REVIEWS_PLUGIN_DIR . 'wb-reviews.php', 'wb_reviews_schedule_warmup');

function wb_reviews_schedule_warmup()
{
  if (!wp_next_scheduled('wb_reviews_cache_warmup')) {
    wp_schedule_event(time(), 'hourly', 'wb_reviews_cache_warmup');
  }
}

// При деактивации — убираем
register_deactivation_hook(WB_REVIEWS_PLUGIN_DIR . 'wb-reviews.php', function () {
  wp_clear_scheduled_hook('wb_reviews_cache_warmup');
});

// Сам обработчик — прогревает по 10 товаров за раз
add_action('wb_reviews_cache_warmup', 'wb_reviews_do_warmup');

function wb_reviews_do_warmup()
{
  $posts = get_posts([
    'post_type' => 'product',
    'post_status' => 'publish',
    'numberposts' => 3,  // только 3 товара за раз
    'meta_query' => [[
      'key' => 'wb_nm_id',
      'value' => '',
      'compare' => '!='
    ]],
    'meta_key' => 'wb_nm_id',
  ]);

  foreach ($posts as $post) {
    $nm_id = get_post_meta($post->ID, 'wb_nm_id', true);
    if (!$nm_id)
      continue;

    $cache_key = 'wb_reviews_' . intval($nm_id);
    if (false !== get_transient($cache_key))
      continue;

    wb_reviews_fetch($nm_id);

    // Если после запроса кэш пустой [] — значит была ошибка, останавливаемся
    $cached = get_transient($cache_key);
    if ($cached === [] || $cached === false) {
      error_log('[WB Reviews] Warmup остановлен, продолжим в следующий раз');
      break;
    }

    sleep(1);
  }
}
