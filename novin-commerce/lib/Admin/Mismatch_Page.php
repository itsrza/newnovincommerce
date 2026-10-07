<?php

namespace Novinwp\Novin_Commerce\Admin;

if (!defined('ABSPATH')) exit;
if (!class_exists(__NAMESPACE__ . '\\Mismatch_Page')) {

class Mismatch_Page {

    const COUNT_TRANSIENT = 'novin_guid_mismatch_count';
    const ROWS_TRANSIENT  = 'novin_guid_mismatch_rows';
	const CACHE_TTL       = 30 * MINUTE_IN_SECONDS;
    // گرفتن تعداد مغایرت‌ها (با cache)
    public static function getMismatchCount() {
        global $wpdb;

        $cached = get_transient(self::COUNT_TRANSIENT);
        if ($cached !== false) {
            return (int) $cached;
        }

        $table = $wpdb->postmeta;

        $count = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM (
                SELECT meta_value
                FROM $table
                WHERE meta_key = 'guid'
                  AND meta_value != ''
                GROUP BY meta_value
                HAVING COUNT(*) > 1
            ) t
        ");

        set_transient(self::COUNT_TRANSIENT, $count, self::CACHE_TTL);

        return $count;
    }

    // رندر صفحه مغایرت‌ها (با cache)
    public static function renderPage() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
		}
		$plugin_file = dirname( __DIR__, 2 ) . '/novin-commerce.php';
		wp_enqueue_style( 'novin-commerce-admin-table', plugins_url( 'dist/styles/admin/table.min.css', $plugin_file ), [], '1.16.0' );
		if (isset($_GET['novin_refresh']) && current_user_can('manage_woocommerce')) {
			self::clearCache();
			echo '<div class="notice notice-info"><p>کش مغایرت‌ها پاک شد.</p></div>';
		}

        global $wpdb;

        $rows = get_transient(self::ROWS_TRANSIENT);

        if ($rows === false) {
            $rows = $wpdb->get_results("
                SELECT p.ID, p.post_title, p.post_type, m.meta_value AS guid
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
                INNER JOIN (
                    SELECT meta_value
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = 'guid'
                      AND meta_value != ''
                    GROUP BY meta_value
                    HAVING COUNT(*) > 1
                ) d ON d.meta_value = m.meta_value
                WHERE m.meta_key = 'guid'
                ORDER BY m.meta_value
            ");

            set_transient(self::ROWS_TRANSIENT, $rows, self::CACHE_TTL);
        }

        echo '<div class="wrap" dir="rtl">';
        echo '<div class="novin-item-header"><div><span class="novin-kicker">NOVIN COMMERCE</span><h1>مغایرت‌گیری</h1><p>در این بخش کالاهایی نمایش داده می‌شوند که شناسه حسابداری مشابه دارند.</p></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=novin-commerce-dashboard' ) ) . '">بازگشت به داشبورد</a></div>';

		echo '<p><a href="' . esc_url(add_query_arg('novin_refresh', '1')) . '" class="button">بروزرسانی نتایج</a></p>';

        if (empty($rows)) {
            echo '<div class="notice notice-success"><p>هیچ مغایرتی یافت نشد.</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:20px;">';
        echo '<thead><tr>
                <th>عنوان پست</th>
                <th>نوع پست</th>
                <th>شناسه حسابداری</th>
                <th>نام در نرم‌افزار حسابداری</th>
                <th>عملیات</th>
              </tr></thead><tbody>';

        foreach ($rows as $r) {
            $webPrd = get_post_meta($r->ID, 'WebPrd', true);
            $decoded = json_decode($webPrd, true);
            $accName = is_array($decoded) && isset($decoded['Name']) ? esc_html($decoded['Name']) : '—';

            $nonce = wp_create_nonce('remove_guid_' . $r->ID);
            $ajax_type = 'product_variation' === $r->post_type ? 'variation' : ( 'shop_order' === $r->post_type ? 'order' : 'product' );

            echo '<tr>';
            echo '<td><a href="' . get_edit_post_link($r->ID) . '" target="_blank">' . esc_html($r->post_title) . '</a></td>';
            echo '<td>' . esc_html($r->post_type) . '</td>';
            echo '<td><code>' . esc_html($r->guid) . '</code></td>';
            echo '<td>' . $accName . '</td>';
            echo '<td><a href="#" class="remove-guid" data-id="' . esc_attr($r->ID) . '" data-type="' . esc_attr($ajax_type) . '" data-nonce="' . esc_attr($nonce) . '" style="color:#d63638;text-decoration:none;">قطع ارتباط</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.remove-guid').forEach(link => {
                link.addEventListener('click', e => {
                    e.preventDefault();
                    if (!confirm('آیا از حذف ارتباط این آیتم اطمینان دارید؟')) return;

                    const id = link.dataset.id;
                    const type = link.dataset.type || 'product';
                    const nonce = link.dataset.nonce;
                    link.textContent = 'در حال حذف...';

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'remove_guid',
                            id,
                            type,
                            _wpnonce: nonce
                        })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            link.closest('tr').remove();
                        } else {
                            alert(res.data?.message || 'خطا در حذف ارتباط');
                        }
                    })
                    .catch(() => alert('خطا در ارتباط با سرور'));
                });
            });
        });
        </script>
        <?php
    }

    // پاک‌سازی cache (باید بعد از حذف guid صدا زده شود)
    public static function clearCache() {
        delete_transient( self::COUNT_TRANSIENT );
        delete_transient( self::ROWS_TRANSIENT );
    }
	}
}
