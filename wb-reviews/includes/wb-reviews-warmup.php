<?php
if (!defined('ABSPATH'))
  exit;

define('WB_WARMUP_LOG', WP_CONTENT_DIR . '/wb-warmup.log');

wp_clear_scheduled_hook('wb_reviews_cache_warmup');

function wb_warmup_log($msg)
{
  $line = '[' . date('d-M-Y H:i:s') . ' UTC] ' . $msg . PHP_EOL;
  file_put_contents(WB_WARMUP_LOG, $line, FILE_APPEND);
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

add_action('wb_reviews_cache_warmup', 'wb_reviews_do_warmup');

function wb_reviews_is_eligible_product($post_id)
{
  return 'product' === get_post_type($post_id)
    && 'publish' === get_post_status($post_id)
    && 'instock' === get_post_meta($post_id, '_stock_status', true);
}

function wb_reviews_do_warmup()
{
  if (!defined('WB_REVIEWS_WARMUP')) {
    define('WB_REVIEWS_WARMUP', true);
  }

  $nm_id = '';
  $post_id = 0;
  $title = '';
  $next_position = null;

  $queue = wb_reviews_get_warmup_queue();

  while (!empty($queue)) {
    $item = array_shift($queue);
    wb_reviews_update_warmup_queue($queue);

    $candidate_nm_id = preg_replace('/\D/', '', (string) ($item['nm_id'] ?? ''));
    $candidate_post_id = (int) ($item['post_id'] ?? 0);

    if ($candidate_nm_id === '') {
      wb_warmup_log('Очередь: пропущена пустая запись');
      continue;
    }

    if (!wb_reviews_is_eligible_product($candidate_post_id)) {
      wb_warmup_log("Очередь: пропуск $candidate_nm_id, товар не опубликован или нет в наличии");
      continue;
    }

    $nm_id = $candidate_nm_id;
    $post_id = $candidate_post_id;
    $title = get_the_title($post_id);
    wb_warmup_log("Очередь: взят nmId $nm_id | post_id: $post_id | $title");
    break;
  }

  if ($nm_id === '') {
    wb_warmup_log('Очередь пуста, ищем опубликованные товары в наличии');

    $field_name = get_option('wb_reviews_custom_field', 'wb_nm_id');
    $posts = get_posts([
      'post_type' => 'product',
      'post_status' => 'publish',
      'numberposts' => -1,
      'fields' => 'ids',
      'meta_query' => [
        'relation' => 'AND',
        [
          'key' => $field_name,
          'value' => '',
          'compare' => '!=',
        ],
        [
          'key' => '_stock_status',
          'value' => 'instock',
          'compare' => '=',
        ],
      ],
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
      wb_warmup_log('Круг завершён, начинаем сначала');
    }

    $post_id = (int) $posts[$position];
    $nm_id = preg_replace('/\D/', '', (string) get_post_meta($post_id, $field_name, true));
    $title = get_the_title($post_id);

    if ($nm_id === '') {
      wb_warmup_log("Позиция $position/$total: пустой nmId");
      return;
    }

    wb_warmup_log("Позиция $position/$total | nmId: $nm_id | $title");
    $next_position = ($position + 1) % $total;
  }

  if ($nm_id) {
    wb_reviews_fetch($nm_id, true);

    $error = get_transient('wb_reviews_error_' . intval($nm_id));

    if ($error) {
      wb_warmup_log("✗ Ошибка: $nm_id | $error");
    } else {
      if (null !== $next_position) {
        update_option('wb_reviews_warmup_position', $next_position);
      }
      wb_warmup_log("✓ Синхронизировано: $nm_id");
    }
  }
}
