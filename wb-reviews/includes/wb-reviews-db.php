<?php
if (!defined('ABSPATH')) {
    exit;
}

function wb_reviews_save_to_db($nm_id, $feedbacks)
{
    global $wpdb;

    $table = $wpdb->prefix . 'wb_reviews';
    $existing_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT review_id FROM {$table} WHERE nm_id = %d",
            absint($nm_id)
        )
    );
    $existing_ids = array_fill_keys(array_map('strval', $existing_ids), true);
    $inserted = 0;

    foreach ($feedbacks as $fb) {
        $review_id = sanitize_text_field($fb['id'] ?? '');

        if ('' === $review_id || isset($existing_ids[$review_id])) {
            continue;
        }

        $photo_links = !empty($fb['photoLinks']) ? wp_json_encode($fb['photoLinks']) : '';
        $created_date = !empty($fb['createdDate'])
            ? gmdate('Y-m-d H:i:s', strtotime($fb['createdDate']))
            : null;

        $result = $wpdb->insert(
            $table,
            [
                'nm_id' => absint($nm_id),
                'review_id' => $review_id,
                'author' => sanitize_text_field($fb['userName'] ?? ''),
                'rating' => absint($fb['productValuation'] ?? 0),
                'text' => sanitize_textarea_field($fb['text'] ?? ''),
                'pros' => sanitize_textarea_field($fb['pros'] ?? ''),
                'cons' => sanitize_textarea_field($fb['cons'] ?? ''),
                'answer' => sanitize_textarea_field($fb['answer']['text'] ?? ''),
                'photo_links' => $photo_links,
                'color' => sanitize_text_field($fb['color'] ?? ''),
                'order_status' => sanitize_text_field($fb['orderStatus'] ?? ''),
                'created_date' => $created_date,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (false !== $result) {
            $existing_ids[$review_id] = true;
            ++$inserted;
        }
    }

    return $inserted;
}

function wb_reviews_get_from_db($nm_id)
{
    global $wpdb;

    $table = $wpdb->prefix . 'wb_reviews';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table}
         WHERE nm_id = %d AND rating >= 4
         ORDER BY
             CASE WHEN text != '' OR pros != '' OR cons != '' THEN 0 ELSE 1 END,
             created_date DESC
         LIMIT 20",
            absint($nm_id)
        ),
        ARRAY_A
    );

    foreach ($rows as &$row) {
        $row['photoLinks'] = !empty($row['photo_links'])
            ? json_decode($row['photo_links'], true)
            : [];
        $row['productValuation'] = $row['rating'];
        $row['userName'] = $row['author'];
        $row['createdDate'] = $row['created_date'];
    }

    return $rows;
}
