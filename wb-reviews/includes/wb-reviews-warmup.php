<?php
if (!defined('ABSPATH'))
  exit;

define('WB_WARMUP_LOG', WP_CONTENT_DIR . '/wb-warmup.log');

function wb_warmup_log($msg)
{
  $line = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $msg . PHP_EOL;
  file_put_contents(WB_WARMUP_LOG, $line, FILE_APPEND);
}

register_activation_hook(WB_REVIEWS_PLUGIN_DIR . 'wb-reviews.php', 'wb_reviews_schedule_warmup');

function wb_reviews_schedule_warmup()
{
  if (!wp_next_scheduled('wb_reviews_cache_warmup')) {
    wp_schedule_event(time(), 'every_20_minutes', 'wb_reviews_cache_warmup');
  }
}

function wb_reviews_get_warmup_queue()
{
  $queue = get_option('wb_reviews_warmup_queue', []);
  return is_array($queue) ? array_values($queue) : [];
}

function wb_reviews_update_warmup_queue($queue)
{
  update_option('wb_reviews_warmup_queue', array_values($queue), false);
}

function wb_reviews_queue_nm_id($nm_id, $post_id = 0)
{
  $nm_id = preg_replace('/\D/', '', (string) $nm_id);
  if ($nm_id === '') {
    return false;
  }

  if (!empty(wb_reviews_get_from_db($nm_id))) {
    wb_warmup_log("Очередь: пропуск $nm_id, отзывы уже есть в БД");
    return false;
  }

  $queue = wb_reviews_get_warmup_queue();

  foreach ($queue as $item) {
    if ((string) ($item['nm_id'] ?? '') === $nm_id) {
      return false;
    }
  }

  $queue[] = [
    'nm_id' => $nm_id,
    'post_id' => (int) $post_id,
    'queued_at' => current_time('mysql', true),
  ];

  wb_reviews_update_warmup_queue($queue);
  wb_warmup_log("Очередь: добавлен nmId $nm_id для post_id " . (int) $post_id);

  return true;
}

function wb_reviews_remove_from_queue($nm_id)
{
  $nm_id = preg_replace('/\D/', '', (string) $nm_id);
  if ($nm_id === '') {
    return;
  }

  $queue = array_filter(wb_reviews_get_warmup_queue(), function ($item) use ($nm_id) {
    return (string) ($item['nm_id'] ?? '') !== $nm_id;
  });

  wb_reviews_update_warmup_queue($queue);
}

add_filter('cron_schedules', function ($schedules) {
  $schedules['every_20_minutes'] = [
    'interval' => 1200,
    'display' => 'Every 20 minutes'
  ];
  return $schedules;
});

register_deactivation_hook(WB_REVIEWS_PLUGIN_DIR . 'wb-reviews.php', function () {
  wp_clear_scheduled_hook('wb_reviews_cache_warmup');
});

add_action('wb_reviews_cache_warmup', 'wb_reviews_do_warmup');

function wb_reviews_do_warmup()
{
  if (!defined('WB_REVIEWS_WARMUP')) {
    define('WB_REVIEWS_WARMUP', true);
  }

  $nm_id = '';
  $post_id = 0;
  $title = '';

  $queue = wb_reviews_get_warmup_queue();

  while (!empty($queue)) {
    $item = array_shift($queue);
    wb_reviews_update_warmup_queue($queue);

    $nm_id = preg_replace('/\D/', '', (string) ($item['nm_id'] ?? ''));
    $post_id = (int) ($item['post_id'] ?? 0);

    if ($nm_id === '') {
      wb_warmup_log('Очередь: пропущена пустая запись');
      continue;
    }

    if (!empty(wb_reviews_get_from_db($nm_id))) {
      wb_warmup_log("Очередь: пропуск $nm_id, отзывы уже есть в БД");
      continue;
    }

    $title = $post_id ? get_the_title($post_id) : '';
    wb_warmup_log("Очередь: взят nmId $nm_id | post_id: $post_id | $title");
    break;
  }

  if ($nm_id === '') {
    wb_warmup_log('Очередь пуста, запускаем поиск непрогретых товаров');

    $field_name = get_option('wb_reviews_custom_field', 'wb_nm_id');
    $posts = get_posts([
      'post_type' => 'product',
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [[
        'key' => $field_name,
        'value' => '',
        'compare' => '!='
      ]],
      'meta_key' => $field_name,
      'orderby' => 'ID',
      'order' => 'ASC',
    ]);

    if (empty($posts)) {
      wb_warmup_log('Нет товаров для прогрева');
      return;
    }

    $total = count($posts);
    $position = (int) get_option('wb_reviews_warmup_position', 0);

    if ($position >= $total) {
      $position = 0;
      wb_warmup_log('Очередь прошла полный круг, начинаем сначала');
    }

    $found = false;
    while ($position < $total) {
      $post_id = $posts[$position];
      $nm_id = get_post_meta($post_id, $field_name, true);
      $title = get_the_title($post_id);
      $existing = wb_reviews_get_from_db($nm_id);

      if (!empty($existing)) {
        wb_warmup_log("⏭ Пропущен (уже в БД): $nm_id | $title");
        $position++;
        continue;
      }

      $found = true;
      break;
    }

    if (!$found) {
      wb_warmup_log('Все товары уже в БД, начинаем сначала');
      update_option('wb_reviews_warmup_position', 0);
      return;
    }

    wb_warmup_log("Позиция $position/$total | nmId: $nm_id | $title");
    update_option('wb_reviews_warmup_position', $position + 1);
  }

  if ($nm_id) {
    wb_reviews_fetch($nm_id);

    $from_db = wb_reviews_get_from_db($nm_id);
    $error = get_transient('wb_reviews_error_' . intval($nm_id));

    if (!empty($from_db)) {
      wb_warmup_log("✓ Сохранено в БД: $nm_id, записей: " . count($from_db));
    } else {
      wb_warmup_log("✗ Не удалось: $nm_id | Ошибка: " . ($error ?: 'нет ошибки, просто пустой ответ'));
    }
  }
}
