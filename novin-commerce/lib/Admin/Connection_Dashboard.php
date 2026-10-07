<?php

namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Common\Accounting\Product_Health_Snapshot;

/**
 * Bounded, read-only product health dashboard.
 *
 * The page intentionally does not run sync, rebuild snapshots, enqueue work,
 * or mutate stock. Write-side work belongs to the explicit sync/import paths.
 */
final class Connection_Dashboard {

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
		}

		$plugin_file = dirname( __DIR__, 2 ) . '/novin-commerce.php';
		wp_enqueue_style( 'novin-commerce-admin-table', plugins_url( 'dist/styles/admin/table.min.css', $plugin_file ), array(), '1.20.0-dashboard' );
		wp_add_inline_style( 'novin-commerce-admin-table', self::critical_css() );

		$summary = Product_Health_Snapshot::summary();
		$page    = isset( $_GET['health_page'] ) ? max( 1, absint( wp_unslash( $_GET['health_page'] ) ) ) : 1;
		$rows    = Product_Health_Snapshot::list_rows( array(), $page, 12 );
		$actions = self::actions( $summary );
		$catalog = $summary['catalog'];
		$health  = $summary['health'];
		$warning_count = self::warning_count( $summary );

		echo '<div class="wrap novin-dashboard" dir="rtl">';
		echo '<header class="novin-dashboard-header"><div><span class="novin-kicker">NOVIN COMMERCE 1.20.0</span><h1>مرکز سلامت کاتالوگ</h1><p>این صفحه فقط از Health Snapshot و queryهای bounded می‌خواند؛ هیچ sync، queue insert یا تغییر stock در page view انجام نمی‌شود.</p></div><nav class="novin-dashboard-actions" aria-label="لینک‌های داشبورد">';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=novin-commerce-products' ) ) . '">کالاها</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=novin-commerce-syncs' ) ) . '">صف تبادل</a>';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=novin-commerce-dashboard' ) ) . '">بازخوانی</a>';
		echo '<form class="novin-backfill-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="novin_health_backfill"><input type="hidden" name="limit" value="50"><input type="hidden" name="offset" value="' . esc_attr( isset( $_GET['health_backfill_offset'] ) ? absint( wp_unslash( $_GET['health_backfill_offset'] ) ) : 0 ) . '">' . wp_nonce_field( 'novin_health_backfill', '_wpnonce', true, false ) . '<button class="button" type="submit">Backfill پنجاه‌تایی</button></form>';
		echo '</nav></header>';
		if ( isset( $_GET['health_backfill'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Backfill: ' . number_format_i18n( absint( wp_unslash( $_GET['health_backfill'] ) ) ) . ' processed، ' . number_format_i18n( isset( $_GET['health_backfill_failed'] ) ? absint( wp_unslash( $_GET['health_backfill_failed'] ) ) : 0 ) . ' failed.</p></div>';

		echo '<section class="novin-health-cards" aria-label="خلاصه سلامت">';
		self::card( 'محصولات اصلی', (int) $catalog['products'], 'تعداد Product منتشرشده', 'blue' );
		self::card( 'Variationها', (int) $catalog['variations'], 'جدا از Product شمارش شده‌اند', 'purple' );
		self::card( 'Healthy', (int) $health['healthy'], 'فقط رکوردهای واقعاً سالم', 'green' );
		self::card( 'نیازمند اقدام', $warning_count, 'Warning و Critical و Unknown', $warning_count ? 'amber' : 'green' );
		echo '</section>';

		echo '<div class="novin-dashboard-grid">';
		echo '<section class="novin-panel"><div class="novin-panel-title"><div><span class="novin-kicker">CATALOG MIX</span><h2>ترکیب Product</h2><p>Simple و Variable در یک نمودار؛ Variation در نمودار Product مخلوط نشده است.</p></div></div>';
		self::product_mix( (int) $catalog['simple'], (int) $catalog['variable'] );
		echo '</section>';
		echo '<section class="novin-panel"><div class="novin-panel-title"><div><span class="novin-kicker">VARIATIONS</span><h2>وضعیت Variation</h2><p>تعداد Variationها مستقل از درصد و Product Parent نمایش داده می‌شود.</p></div></div>';
		self::bar( 'Variation منتشرشده', (int) $catalog['variations'], max( 1, (int) $catalog['variations'] ), 'purple' );
		self::bar( 'Variation Healthy', self::bounded_int( $health['healthy'] ), max( 1, (int) $catalog['variations'] ), 'green' );
		self::bar( 'Variation Unknown', self::bounded_int( $health['unknown'] ), max( 1, (int) $catalog['variations'] ), 'amber' );
		echo '</section>';
		echo '</div>';

		echo '<section class="novin-panel novin-action-panel"><div class="novin-panel-title"><div><span class="novin-kicker">ACTION CENTER</span><h2>مرکز اقدام</h2><p>هر warning با Count، Description و Action مشخص شده است.</p></div></div><div class="novin-actions-list">';
		if ( empty( $actions ) ) {
			echo '<p class="novin-empty">موردی برای اقدام ثبت نشده است.</p>';
		} else {
			foreach ( $actions as $action ) {
				echo '<article class="novin-action-row ' . esc_attr( $action['tone'] ) . '"><div class="novin-action-count" aria-label="تعداد">' . number_format_i18n( $action['count'] ) . '</div><div class="novin-action-copy"><h3>' . esc_html( $action['title'] ) . '</h3><p>' . esc_html( $action['description'] ) . '</p></div><a class="button" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a></article>';
			}
		}
		echo '</div></section>';

		echo '<section class="novin-panel"><div class="novin-panel-title"><div><span class="novin-kicker">HEALTH SNAPSHOT</span><h2>آخرین Snapshotها</h2><p>حداکثر ۱۲ ردیف در هر صفحه؛ برای جزئیات از صفحه کالا استفاده کنید.</p></div><span class="novin-readonly">READ ONLY</span></div>';
		if ( empty( $rows['rows'] ) ) {
			echo '<p class="novin-empty">Snapshot هنوز آماده نیست. پس از اجرای migration و backfill، این جدول پر می‌شود.</p>';
		} else {
			echo '<div class="novin-table-wrap"><table class="widefat novin-health-table"><caption class="screen-reader-text">آخرین وضعیت سلامت محصولات</caption><thead><tr><th scope="col">شناسه</th><th scope="col">نوع</th><th scope="col">Identity</th><th scope="col">Inventory</th><th scope="col">Pricing</th><th scope="col">Unit</th><th scope="col">Health</th><th scope="col">Updated</th></tr></thead><tbody>';
			foreach ( $rows['rows'] as $row ) {
				echo '<tr><td>' . absint( $row->item_id ) . '</td><td>' . esc_html( self::type_label( $row->item_type, $row->woo_type ) ) . '</td><td>' . esc_html( $row->identity_state ) . '</td><td>' . esc_html( $row->inventory_state ) . '</td><td>' . esc_html( $row->pricing_state ) . '</td><td>' . esc_html( $row->unit_mode ) . '</td><td><strong class="status-' . esc_attr( $row->health_status ) . '">' . esc_html( $row->health_status ) . '</strong></td><td>' . esc_html( (string) $row->updated_at ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			$total_pages = max( 1, (int) ceil( (int) $rows['total'] / 12 ) );
			if ( $total_pages > 1 ) {
				echo '<nav class="novin-pagination" aria-label="صفحه‌های Snapshot">';
				for ( $i = 1; $i <= min( $total_pages, 50 ); $i++ ) {
					$url = add_query_arg( array( 'page' => 'novin-commerce-dashboard', 'health_page' => $i ), admin_url( 'admin.php' ) );
					echo '<a class="' . ( $i === $page ? 'current' : '' ) . '" href="' . esc_url( $url ) . '" aria-label="صفحه ' . absint( $i ) . '">' . absint( $i ) . '</a>';
				}
				echo '</nav>';
			}
		}
		echo '</section>';

		echo '<footer class="novin-dashboard-footer">';
		echo '<span>Snapshot ready: <strong>' . ( ! empty( $summary['ready'] ) ? 'yes' : 'no' ) . '</strong></span>';
		echo '<span>آخرین به‌روزرسانی: <strong>' . esc_html( (string) ( $summary['last_updated'] ?? '—' ) ) . '</strong></span>';
		echo '</footer></div>';
	}

	private static function card( $label, $value, $detail, $tone ) {
		echo '<article class="novin-card tone-' . esc_attr( $tone ) . '"><span>' . esc_html( $label ) . '</span><strong>' . number_format_i18n( (int) $value ) . '</strong><small>' . esc_html( $detail ) . '</small></article>';
	}

	private static function product_mix( $simple, $variable ) {
		$total = max( 1, $simple + $variable );
		$simple_angle = round( ( $simple / $total ) * 360, 2 );
		echo '<div class="novin-mix"><div class="novin-donut" style="--simple:' . esc_attr( $simple_angle ) . 'deg" role="img" aria-label="Simple ' . esc_attr( number_format_i18n( $simple ) ) . '، Variable ' . esc_attr( number_format_i18n( $variable ) ) . '"><span>' . number_format_i18n( $simple + $variable ) . '<small>Product</small></span></div><div class="novin-legend"><div><i class="simple"></i><span>Simple</span><strong>' . number_format_i18n( $simple ) . '</strong></div><div><i class="variable"></i><span>Variable</span><strong>' . number_format_i18n( $variable ) . '</strong></div></div></div>';
	}

	private static function bar( $label, $value, $max, $tone ) {
		$percent = min( 100, max( 0, round( ( (int) $value / max( 1, (int) $max ) ) * 100 ) ) );
		echo '<div class="novin-bar-row"><div><span>' . esc_html( $label ) . '</span><strong>' . number_format_i18n( (int) $value ) . '</strong></div><div class="novin-bar"><i class="' . esc_attr( $tone ) . '" style="width:' . esc_attr( $percent ) . '%"></i></div></div>';
	}

	private static function actions( array $summary ) {
		$counts = isset( $summary['actions'] ) && is_array( $summary['actions'] ) ? $summary['actions'] : array();
		$counts['health_attention'] = isset( $summary['health']['attention'] ) ? (int) $summary['health']['attention'] : 0;
		$counts['health_unknown'] = isset( $summary['health']['unknown'] ) ? (int) $summary['health']['unknown'] : 0;
		$url = admin_url( 'admin.php?page=novin-commerce-products' );
		$definitions = array(
			'guid_mismatch'     => array( 'title' => 'مغایرت Identity', 'description' => 'GUID حسابداری و WooCommerce یکسان نیست یا اتصال ناقص است.', 'label' => 'بررسی کالاها', 'tone' => 'critical' ),
			'stale'             => array( 'title' => 'Sync قدیمی', 'description' => 'داده منبع از آخرین sync محلی جدیدتر است.', 'label' => 'بررسی صف', 'tone' => 'warning', 'url' => admin_url( 'admin.php?page=novin-commerce-syncs' ) ),
			'inventory_mismatch' => array( 'title' => 'مغایرت موجودی', 'description' => 'Woo stock با مقدار قابل محاسبه از warehouse برابر نیست.', 'label' => 'بررسی موجودی', 'tone' => 'critical' ),
			'unit_unresolved'   => array( 'title' => 'Unit unresolved', 'description' => 'تعریف Accounting یا mapping variation بدون ابهام نیست؛ stock محاسبه نشده است.', 'label' => 'بررسی واحدها', 'tone' => 'warning' ),
			'price_review'      => array( 'title' => 'قیمت نیازمند بررسی', 'description' => 'سطح قیمت نامعتبر، role mapping ناقص یا منبع قیمت نامشخص است.', 'label' => 'بررسی قیمت', 'tone' => 'warning' ),
			'manual_price'      => array( 'title' => 'Manual role price', 'description' => 'قیمت دستی در کنار قیمت Accounting وجود دارد و باید مالکیت آن روشن باشد.', 'label' => 'بررسی قیمت', 'tone' => 'warning' ),
			'role_without_price' => array( 'title' => 'Role price missing', 'description' => 'برای بخشی از نقش‌های فعال قیمت معتبر ثبت نشده است.', 'label' => 'بررسی قیمت', 'tone' => 'warning' ),
			'sync_failed'       => array( 'title' => 'Sync failed', 'description' => 'آخرین عملیات sync شکست خورده است و باید از لاگ امن بررسی شود.', 'label' => 'بررسی صف', 'tone' => 'critical', 'url' => admin_url( 'admin.php?page=novin-commerce-syncs' ) ),
			'health_attention'  => array( 'title' => 'Health attention', 'description' => 'Snapshotها warning دارند اما هنوز critical نیستند؛ علت هر ردیف را در جدول ببینید.', 'label' => 'بررسی Snapshot', 'tone' => 'warning' ),
			'health_unknown'    => array( 'title' => 'Health unknown', 'description' => 'داده کافی برای Healthy اعلام کردن این Snapshotها وجود ندارد.', 'label' => 'تکمیل داده', 'tone' => 'warning' ),
		);
		$result = array();
		foreach ( $definitions as $key => $definition ) {
			$count = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
			if ( $count < 1 ) continue;
			$definition['count'] = $count;
			$definition['url'] = $definition['url'] ?? $url;
			$result[] = $definition;
		}
		return $result;
	}

	private static function warning_count( array $summary ) {
		$health = isset( $summary['health'] ) && is_array( $summary['health'] ) ? $summary['health'] : array();
		return (int) ( $health['attention'] ?? 0 ) + (int) ( $health['critical'] ?? 0 ) + (int) ( $health['unknown'] ?? 0 );
	}

	private static function bounded_int( $value ) {
		return max( 0, absint( $value ) );
	}

	private static function type_label( $item_type, $woo_type ) {
		if ( 'variation' === $item_type ) return 'Variation';
		return 'variable' === $woo_type ? 'Variable Product' : 'Simple Product';
	}

	private static function critical_css() {
		return '.novin-dashboard{max-width:1400px;margin:24px 20px 48px;color:#172033}.novin-dashboard *{box-sizing:border-box}.novin-dashboard-header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:22px}.novin-dashboard-header h1{font-size:30px;margin:5px 0 8px}.novin-dashboard-header p{margin:0;color:#64748b;max-width:720px;line-height:1.8}.novin-kicker{font-size:11px;font-weight:800;letter-spacing:.08em;color:#2563eb}.novin-dashboard-actions{display:flex;gap:8px;flex-wrap:wrap}.novin-health-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.novin-card,.novin-panel{background:#fff;border:1px solid #e6ebf2;border-radius:16px;box-shadow:0 4px 16px rgba(15,23,42,.04)}.novin-card{padding:18px;display:flex;flex-direction:column;gap:5px;border-top:3px solid #2563eb}.novin-card.tone-purple{border-top-color:#7c3aed}.novin-card.tone-green{border-top-color:#16a34a}.novin-card.tone-amber{border-top-color:#d97706}.novin-card span{font-weight:700;color:#334155}.novin-card strong{font-size:28px;line-height:1.2}.novin-card small{color:#64748b}.novin-dashboard-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:18px}.novin-panel{padding:20px;margin-bottom:18px;min-width:0}.novin-panel-title{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.novin-panel h2{font-size:18px;margin:4px 0 5px}.novin-panel-title p{margin:0;color:#64748b;line-height:1.7}.novin-readonly{font-size:10px;font-weight:800;border-radius:99px;background:#f1f5f9;color:#64748b;padding:5px 9px}.novin-mix{display:flex;align-items:center;justify-content:center;gap:34px;min-height:210px}.novin-donut{width:170px;height:170px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#2563eb 0 var(--simple),#7c3aed var(--simple) 360deg);position:relative}.novin-donut:after{content:"";position:absolute;inset:22px;background:#fff;border-radius:50%}.novin-donut span{position:relative;z-index:1;text-align:center;font-size:25px;font-weight:800}.novin-donut small{display:block;font-size:11px;color:#64748b;font-weight:600}.novin-legend{display:flex;flex-direction:column;gap:15px;min-width:130px}.novin-legend div{display:grid;grid-template-columns:12px 1fr auto;align-items:center;gap:7px}.novin-legend i{width:10px;height:10px;border-radius:50%;display:block}.novin-legend i.simple{background:#2563eb}.novin-legend i.variable{background:#7c3aed}.novin-legend span{color:#475569}.novin-legend strong{font-size:16px}.novin-bar-row{margin:16px 0}.novin-bar-row>div:first-child{display:flex;justify-content:space-between;gap:8px;margin-bottom:7px}.novin-bar-row span{color:#475569}.novin-bar{height:9px;background:#eef2f7;border-radius:99px;overflow:hidden}.novin-bar i{display:block;height:100%;border-radius:99px;background:#2563eb}.novin-bar i.purple{background:#7c3aed}.novin-bar i.green{background:#16a34a}.novin-bar i.amber{background:#d97706}.novin-actions-list{display:flex;flex-direction:column;gap:10px}.novin-action-row{display:grid;grid-template-columns:72px minmax(0,1fr) auto;align-items:center;gap:15px;padding:13px 15px;border:1px solid #e6ebf2;border-right:4px solid #d97706;border-radius:12px}.novin-action-row.critical{border-right-color:#dc2626;background:#fffafa}.novin-action-row.warning{background:#fffdf6}.novin-action-count{font-size:24px;font-weight:800;text-align:center}.novin-action-copy h3{font-size:14px;margin:0 0 4px}.novin-action-copy p{font-size:12px;color:#64748b;margin:0;line-height:1.7}.novin-empty{color:#64748b;margin:0;padding:16px;background:#f8fafc;border-radius:10px}.novin-table-wrap{overflow:auto}.novin-health-table{border:0;border-collapse:collapse;min-width:760px}.novin-health-table th{background:#f8fafc;color:#475569;font-size:12px}.novin-health-table td,.novin-health-table th{padding:11px 10px;border-bottom:1px solid #eef2f7;text-align:right;white-space:nowrap}.novin-health-table td{font-size:12px}.status-healthy{color:#15803d}.status-attention{color:#b45309}.status-critical{color:#dc2626}.status-unknown{color:#64748b}.novin-pagination{display:flex;gap:6px;margin-top:15px}.novin-pagination a{padding:5px 10px;border:1px solid #dbe3ec;border-radius:7px;text-decoration:none}.novin-pagination a.current{background:#2563eb;color:#fff;border-color:#2563eb}.novin-dashboard-footer{display:flex;justify-content:space-between;gap:12px;color:#64748b;font-size:12px}.novin-dashboard-footer strong{color:#172033}@media(max-width:900px){.novin-dashboard-header{display:block}.novin-dashboard-actions{margin-top:16px}.novin-health-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.novin-dashboard-grid{grid-template-columns:1fr}}@media(max-width:560px){.novin-dashboard{margin:16px 8px 30px}.novin-health-cards{grid-template-columns:1fr}.novin-mix{gap:18px;flex-direction:column}.novin-action-row{grid-template-columns:52px minmax(0,1fr)}.novin-action-row .button{grid-column:2;justify-self:start}.novin-dashboard-footer{display:block}.novin-dashboard-footer span{display:block;margin-top:7px}}';
	}
}
