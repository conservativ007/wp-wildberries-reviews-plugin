<?php
if (!defined('ABSPATH'))
  exit;

// ─── Вывод отзывов ───────────────────────────────────────────────────────────

add_filter('the_content', 'wb_reviews_append_to_content');

function wb_reviews_append_to_content($content)
{
  static $already_run = [];

  if (!is_singular() || !in_the_loop() || !is_main_query()) {
    return $content;
  }

  $post_id = get_the_ID();

  // Не выполнять дважды для одной записи
  if (isset($already_run[$post_id])) {
    return $content;
  }
  $already_run[$post_id] = true;

  error_log('[WB Reviews] append_to_content вызван, post_id: ' . $post_id);

  $field_name = get_option('wb_reviews_custom_field', 'wb_nm_id');
  $nm_id = get_post_meta(get_the_ID(), $field_name, true);

  if (empty($nm_id)) {
    return $content;
  }

  $feedbacks = wb_reviews_fetch($nm_id);

  // Debug для админов — всегда
  if (current_user_can('manage_options')) {
    $cache_key = 'wb_reviews_' . intval($nm_id);
    $cached = get_transient($cache_key);
    $error_msg = get_transient('wb_reviews_error_' . intval($nm_id));

    wp_enqueue_script(
      'wb-reviews-debug',
      WB_REVIEWS_PLUGIN_URL . 'assets/wb-reviews-debug.js',
      [],
      WB_REVIEWS_VERSION,
      true
    );

    wp_localize_script('wb-reviews-debug', 'wb_reviews_debug', [
      'nm_id' => $nm_id,
      'cache' => $cached === false ? 'нет кэша' : 'есть кэш',
      'cache_size' => is_array($cached) ? count($cached) : 0,
      'feedbacks' => count($feedbacks),
      'error' => $error_msg ?: 'нет',
      'retry_after' => (int) get_transient('wb_reviews_retry_' . intval($nm_id)),
    ]);

    if ($error_msg) {
      wp_localize_script('wb-reviews-debug', 'wb_reviews_error', [
        'message' => $error_msg,
      ]);
    }
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
