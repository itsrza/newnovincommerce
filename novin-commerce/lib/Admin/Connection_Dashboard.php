<?php
namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Common\SettingAPI;
use MobinDev\Novin_Commerce\Common\SyncLog;
use MobinDev\Novin_Commerce\Models\Sync;

class Connection_Dashboard {
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
        $plugin_file = dirname( __DIR__, 2 ) . '/novin-commerce.php';
        wp_enqueue_style( 'novin-commerce-admin-table', plugins_url( 'dist/styles/admin/table.min.css', $plugin_file ), [], '1.10.11-dashboard11' );
        wp_add_inline_style( 'novin-commerce-admin-table', self::critical_css() );
        $started = microtime( true );
        self::handle_actions();
        $health = self::health();
        $logs = SyncLog::recent( 12 );
        $query_ms = round( ( microtime( true ) - $started ) * 1000, 1 );
        echo '<div class="wrap novin-dashboard">';
        echo '<header class="novin-hero"><div><span class="novin-kicker">NOVIN COMMERCE</span><h1>مرکز کنترل تبادل</h1><p>وضعیت تبادل اطلاعات و مواردی را که نیاز به بررسی دارند، یکجا ببینید.</p></div><div class="novin-hero-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-products')).'">کالاها</a><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-syncs')).'">صف تبادل</a><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-settings')).'">تنظیمات</a><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=novin-commerce-dashboard')).'">بررسی دوباره</a></div></header>';
        echo '<div class="novin-health-grid novin-health-grid-extended">';
        self::card('وضعیت اتصال', $health['api']['label'], $health['api']['status'], self::icon('link'), $health['api']['detail']);
        self::card('صف تبادل', number_format_i18n($health['queue']['all']), $health['queue']['all'] ? 'warning':'success', self::icon('queue'), $health['queue']['all'] ? 'مورد در انتظار تبادل' : 'صف خالی است');
        self::card('کالاهای سایت', number_format_i18n($health['catalog']['products']), 'info', self::icon('box'), 'محصولات اصلی منتشرشده');
        self::card('Variationها', number_format_i18n($health['catalog']['variations']), 'purple', self::icon('layers'), 'Variationهای منتشرشده');
        self::card('بدون GUID', number_format_i18n($health['catalog']['missing_guid']), $health['catalog']['missing_guid'] ? 'warning':'success', self::icon('key'), 'کالاهایی که شناسه اتصال ندارند');
        self::card('نیازمند بررسی', number_format_i18n($health['webprd']['stale'] + $health['webprd']['guid_mismatch']), ($health['webprd']['stale']+$health['webprd']['guid_mismatch'])?'warning':'success', self::icon('alert'), 'بر اساس نمونه آخر داده‌ها');
        self::card('رویدادهای اخیر', number_format_i18n($health['logs']['total']), $health['logs']['error'] ? 'error':'teal', self::icon('activity'), $health['logs']['error'] ? number_format_i18n($health['logs']['error']).' خطای ثبت‌شده' : 'بدون خطای ثبت‌شده');
        self::card('زمان بررسی', $query_ms.' ms', $query_ms < 500 ? 'success':'warning', self::icon('speed'), 'زمان تولید همین داشبورد');
        echo '</div>';

        echo '<div class="novin-dashboard-grid">';
        echo '<section class="novin-panel novin-panel-wide"><div class="novin-panel-title"><div><h2>تصویر کلی فروشگاه</h2><p>یک نمای سریع از کالاها، اتصال و وضعیت اطلاعات دریافت‌شده.</p></div></div><div class="novin-chart-row novin-chart-row-3">';
        self::donut('ترکیب کاتالوگ', $health['catalog']['products'], $health['catalog']['variations'], 'محصول', 'Variation', 'محصول');
        self::donut('وضعیت اتصال', $health['catalog']['with_guid'], $health['catalog']['missing_guid'], 'دارای GUID', 'بدون GUID', 'دارای GUID');
        self::horizontal_bars('سلامت WebPrd', [
            ['label'=>'داده معتبر','value'=>$health['webprd']['valid'],'max'=>max(1,$health['webprd']['sample'])],
            ['label'=>'عقب‌مانده از Sync','value'=>$health['webprd']['stale'],'max'=>max(1,$health['webprd']['sample'])],
            ['label'=>'مغایرت GUID','value'=>$health['webprd']['guid_mismatch'],'max'=>max(1,$health['webprd']['sample'])],
            ['label'=>'بدون WebPrd','value'=>max(0,$health['webprd']['sample']-$health['webprd']['valid']),'max'=>max(1,$health['webprd']['sample'])],
        ]);
        echo '</div></section></div>';

        echo '<div class="novin-dashboard-triple-row">';
        echo '<section class="novin-panel"><div class="novin-panel-title"><div><h2>صف تبادل</h2><p>مواردی که در نوبت تبادل قرار دارند.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-syncs')).'">مشاهده صف</a></div>';
        $queue=[ 'product'=>'کالا','variation'=>'Variation','order'=>'فاکتور','category'=>'دسته‌بندی','user'=>'شخص' ];
        $queue_counts=$health['queue']['counts'];
        $queue_all=isset($queue_counts['_total'])?(int)$queue_counts['_total']:(int)$health['queue']['all'];
        foreach($queue as $type=>$label){
            $c=isset($queue_counts[$type])?(int)$queue_counts[$type]:0;
            $pct=$queue_all?min(100,round($c/$queue_all*100)):0;
            echo '<div class="novin-bar-row"><div><span>'.esc_html($label).'</span><strong>'.number_format_i18n($c).'</strong></div><div class="novin-bar"><i style="width:'.$pct.'%"></i></div></div>';
        }
        if(!$queue_all)echo '<div class="novin-empty">صف تبادل خالی است — با تغییر کالا، فاکتور یا شخص، مورد جدیدی در صف قرار می‌گیرد.</div>';
        echo '</section>';
        echo '<section class="novin-panel"><div class="novin-panel-title"><div><h2>وضعیت فعالیت</h2><p>تعداد رویدادهای ثبت‌شده در ۲۴ ساعت اخیر.</p></div></div><div class="novin-mini-chart">';
        self::stacked_bars('رویدادهای ۲۴ ساعت اخیر', [
            ['label'=>'موفق','value'=>$health['logs']['success'],'class'=>'success'],
            ['label'=>'در انتظار','value'=>$health['logs']['warning'],'class'=>'warning'],
            ['label'=>'خطا','value'=>$health['logs']['error'],'class'=>'error'],
            ['label'=>'اطلاع','value'=>$health['logs']['info'],'class'=>'info'],
        ]);
        echo '<div class="novin-activity-summary">';
        self::metric('مجموع ۲۴ ساعت', number_format_i18n($health['logs']['total']));
        self::metric('موفق', number_format_i18n($health['logs']['success']));
        self::metric('خطا', number_format_i18n($health['logs']['error']));
        echo '</div><div class="novin-activity-foot">'.($health['logs']['latest']!==''?esc_html('آخرین فعالیت: '.self::time_label($health['logs']['latest'])):esc_html('هنوز رویدادی ثبت نشده است.')).'</div></div></section>';
        echo '<section class="novin-panel"><div class="novin-panel-title"><div><h2>آخرین موارد تبادل</h2><p>آخرین موارد در صف و تبادل‌هایی که نرم‌افزار حسابداری دریافت کرده است.</p></div></div>';
        self::render_latest_exchanges();
        echo '</section></div>';

        echo '<div class="novin-dashboard-sections">';
        echo '<section class="novin-panel novin-panel-wide"><div class="novin-panel-title"><div><h2>وضعیت انتشار کالاها</h2><p>این بخش کمک می‌کند سریع ببینید کاتالوگ سایت در چه وضعیتی قرار دارد.</p></div></div><div class="novin-dashboard-stat-grid">';
        self::stat_tile('منتشرشده', $health['catalog']['products'], 'success', self::icon('check'));
        self::stat_tile('پیش‌نویس', $health['catalog']['draft'], 'info', self::icon('edit'));
        self::stat_tile('خصوصی', $health['catalog']['private'], 'warning', self::icon('lock'));
        self::stat_tile('Variation منتشرشده', $health['catalog']['variations'], 'success', self::icon('layers'));
        echo '</div></section>';

        echo '<section class="novin-panel novin-panel-wide novin-insights-panel"><div class="novin-panel-title"><div><span class="novin-kicker">WEBPRD INSIGHTS</span><h2>داده‌های قابل تشخیص از حسابداری</h2><p>این شاخص‌ها مستقیماً از داده‌های WebPrd موجود در همین سایت خوانده می‌شوند. تعداد بررسی‌شده: '.number_format_i18n($health['webprd']['sample']).' کالا.</p></div></div><div class="novin-insights-grid">';
        self::insight_tile('موجودی مثبت',$health['webprd']['insights']['stock_positive'],'کالا با موجودی بیشتر از صفر','green',self::icon('stock'));
        self::insight_tile('SKU ثبت‌شده',$health['webprd']['insights']['sku'],'دارای Sku در WebPrd','blue',self::icon('sku'));
        self::insight_tile('بارکد مقداردار',$health['webprd']['insights']['barcode_value'],'دارای BarCode در PrdBarcode','purple',self::icon('barcode'));
        self::insight_tile('قیمت فروش (Sell1)',$health['webprd']['insights']['sell_price'],'دارای قیمت فروش پایه در WebPrd','amber',self::icon('price'));
        self::insight_tile('تخفیف زمان‌دار فعال',$health['webprd']['insights']['discount_active'],'DiscountStartDate تا DiscountEndDate','red',self::icon('clock'));
        self::insight_tile('قیمت نقش‌ها',$health['webprd']['insights']['price_roles'],'دارای PriceRoleList','amber',self::icon('price'));
        self::insight_tile('گروه کالا',$health['webprd']['insights']['group'],'دارای GuidGroup','teal',self::icon('group'));
        self::insight_tile('مشخصات فنی',$health['webprd']['insights']['technical'],'دارای PrdTechnicalList','indigo',self::icon('layers'));
        self::insight_tile('تاریخ Modified',$health['webprd']['insights']['modified'],'دارای زمان آخرین تغییر','indigo',self::icon('clock'));
        echo '</div></section>';

        echo '<section class="novin-panel novin-panel-wide"><div class="novin-panel-title"><div><h2>بررسی وضعیت تبادل</h2><p>وضعیت هر مورد را ساده و قابل فهم نشان می‌دهیم.</p></div></div><div class="novin-diagnostic-grid">';
        self::diagnostic('زمان Sync', $health['sync_time']['status']==='success', $health['sync_time']['label'], 'زمان مرجع دریافت اطلاعات از حسابداری.');
        self::diagnostic('GUID', 0===$health['webprd']['guid_mismatch'], $health['webprd']['guid_mismatch']?'اختلاف پیدا شد':'هماهنگ', 'GUID سایت با GUID داخل WebPrd مقایسه شد.');
        self::diagnostic('WebPrd', $health['webprd']['valid']===$health['webprd']['sample'], $health['webprd']['valid'].' از '.$health['webprd']['sample'].' معتبر', 'ساختار داده حسابداری بررسی شد.');
        self::diagnostic('تازه بودن اطلاعات', 0===$health['webprd']['stale'], $health['webprd']['stale']?'نیازمند Sync':'به‌روز', 'Modified حسابداری با زمان Sync سایت مقایسه شد.');
        echo '</div></section>';

        if ( ! empty($_GET['item_id']) ) self::render_detail(absint($_GET['item_id']), sanitize_key(wp_unslash($_GET['item_type'] ?? '')));

        echo '<div class="novin-dashboard-grid"><section class="novin-panel"><div class="novin-panel-title"><div><h2>آخرین رویدادها</h2><p>برای مشاهده آخرین تغییرات و عملیات انجام‌شده.</p></div></div>';
        if(!$logs) echo '<div class="novin-empty">هنوز رویدادی ثبت نشده است.</div>'; else foreach($logs as $log){echo '<div class="novin-log-row"><span class="novin-status-dot '.esc_attr($log->status).' "></span><div><strong>'.esc_html(self::event_label($log->event_type)).'</strong><p>'.esc_html($log->message ?: 'بدون توضیح').'</p></div><time>'.esc_html(self::time_label($log->created_at)).'</time></div>';}
        echo '</section><section class="novin-panel"><div class="novin-panel-title"><div><h2>وضعیت فنی</h2><p>وضعیت‌های مهم ارتباط و بروزرسانی کالاها.</p></div></div><div class="novin-system-grid">';
        self::metric('WordPress', get_bloginfo('version')); self::metric('PHP', PHP_VERSION); self::metric('WooCommerce', defined('WC_VERSION')?WC_VERSION:'نصب نیست'); self::metric('PHP Memory', ini_get('memory_limit')); self::metric('DB', self::db_name()); self::metric('Schema', get_option('novin_commerce_schema_version','—')); echo '</div></section></div>';

        echo '<section class="novin-panel"><div class="novin-panel-title"><div><h2>ابزارهای رفع مشکل</h2><p>عملیات کم‌ریسک و قابل بازگشت برای پشتیبانی.</p></div></div><div class="novin-tools-grid">';
        self::tool('جستجوی GUID','از بخش کالاها GUID یا SKU را جستجو کنید.',admin_url('admin.php?page=novin-commerce-products'));
        self::tool('مغایرت‌گیری','GUIDهای تکراری و نامنطبق را بررسی کنید.',admin_url('admin.php?page=novin-commerce-mismatch'));
        self::tool('تنظیمات','ارتباط، نقش‌ها و دسترسی پیشخوان را کنترل کنید.',admin_url('admin.php?page=novin-commerce-settings'));
        self::tool('صف تبادل','موارد گیرکرده را با اولویت بالا دوباره ارسال کنید.',admin_url('admin.php?page=novin-commerce-syncs'));
        echo '</div></section>';
        echo '</div>';
    }

    /**
     * Every usable WebPrd section, rendered in the per-product detail panel.
     * Mirrors the accounting JSON keys seen in the live REST payload
     * (meta key "WebPrd" on the product).
     */
    private static function webprd_detail_rows( $d ) {
        $rows  = [];
        $add   = function ( $label, $value, $status = 'info' ) use ( &$rows ) {
            if ( is_array( $value ) ) {
                $parts = [];
                foreach ( $value as $item ) {
                    if ( is_scalar( $item ) && '' !== (string) $item ) $parts[] = (string) $item;
                }
                $value = implode( '، ', $parts );
            }
            $rows[] = [ (string) $label, (string) $value, (string) $status ];
        };
        $money = function ( $v ) { return is_numeric( $v ) ? number_format_i18n( (float) $v ) : (string) $v; };
        $pick  = function ( $k ) use ( $d ) { return isset( $d[ $k ] ) ? $d[ $k ] : ''; };

        $add( 'کد حسابداری (Code)', $pick( 'Code' ), '' !== (string) $pick( 'Code' ) ? 'success' : 'info' );
        $add( 'شناسه کالا (IdProduct)', $pick( 'IdProduct' ) );
        $add( 'نام در حسابداری', $pick( 'Name' ) );
        $add( 'SKU', $pick( 'Sku' ), '' !== (string) $pick( 'Sku' ) ? 'success' : 'info' );
        $add( 'گروه کالا', $pick( 'GroupName' ) );
        $add( 'واحد', $pick( 'VahedName' ) );

        $add( 'قیمت فروش ۱ (Sell1)', $money( $pick( 'Sell1' ) ), is_numeric( $pick( 'Sell1' ) ) && (float) $pick( 'Sell1' ) > 0 ? 'success' : 'info' );
        $add( 'قیمت فروش ۸ (Sell8)', $money( $pick( 'Sell8' ) ) );
        $add( 'KardexPrice', $money( $pick( 'KardexPrice' ) ) );
        $add( 'BuyLast', $money( $pick( 'BuyLast' ) ) );
        $add( 'موجودی (Mojodi)', $money( $pick( 'Mojodi' ) ), is_numeric( $pick( 'Mojodi' ) ) && (float) $pick( 'Mojodi' ) > 0 ? 'success' : 'warning' );

        $pct = $pick( 'DiscountPercent' );
        if ( '' !== (string) $pct ) $add( 'درصد تخفیف', $money( $pct ) );
        $ds = (string) $pick( 'DiscountStartDate' ); $de = (string) $pick( 'DiscountEndDate' );
        if ( '' !== $ds || '' !== $de ) $add( 'بازه تخفیف', trim( $ds . ' تا ' . $de, ' تا' ) );

        $prices = $pick( 'Prices' );
        $roles  = $pick( 'PriceRoleList' );
        if ( is_array( $prices ) ) $add( 'تعداد قیمت‌گذاری‌ها (Prices)', count( $prices ), count( $prices ) ? 'success' : 'info' );
        if ( is_array( $roles ) ) {
            $with_price = 0; $names = [];
            foreach ( $roles as $role ) {
                if ( is_array( $role ) && isset( $role['Price'] ) && (float) $role['Price'] > 0 ) {
                    $with_price++;
                    $names[] = isset( $role['WordPressRoleName'] ) ? (string) $role['WordPressRoleName'] . ' ' . $money( $role['Price'] ) : $money( $role['Price'] );
                }
            }
            $add( 'نقش‌ها (PriceRoleList)', count( $roles ) . ' نقش — ' . $with_price . ' دارای قیمت' . ( $names ? ' (' . implode( '، ', array_slice( $names, 0, 3 ) ) . ')' : '' ) );
        }

        $barcodes = $pick( 'PrdBarcode' );
        if ( is_array( $barcodes ) ) {
            $codes = [];
            foreach ( $barcodes as $bc ) if ( is_array( $bc ) && isset( $bc['BarCode'] ) && '' !== (string) $bc['BarCode'] ) $codes[] = (string) $bc['BarCode'];
            $add( 'بارکدها (PrdBarcode)', $codes, count( $codes ) ? 'success' : 'info' );
        }

        $tech = $pick( 'PrdTechnicalList' );
        if ( is_array( $tech ) ) $add( 'مشخصات فنی (PrdTechnicalList)', count( $tech ) . ' مورد' );
        $imgs = $pick( 'ImageListData' );
        if ( is_array( $imgs ) ) $add( 'تصاویر (ImageListData)', count( $imgs ) . ' تصویر' );
        $files = $pick( 'Files' );
        if ( is_array( $files ) ) $add( 'فایل‌ها (Files)', count( $files ) . ' فایل' );
        $parts = $pick( 'OwnedFileParts' );
        if ( is_array( $parts ) && count( $parts ) ) $add( 'OwnedFileParts', implode( '، ', $parts ) );

        $add( 'تاریخ ایجاد (CreateDate)', $pick( 'CreateDate' ) );
        $add( 'SendToServerDate', $pick( 'SendToServerDate' ) );
        $add( 'ResetTime', $pick( 'ResetTime' ) );
        $add( 'Modified', $pick( 'Modified' ), '' !== (string) $pick( 'Modified' ) ? 'success' : 'info' );
        $add( 'Version', $pick( 'Version' ) );
        $add( 'ClientVersion', $pick( 'ClientVersion' ) );
        $add( 'ServerStatus', $pick( 'ServerStatus' ) );
        $add( 'MappId', $pick( 'MappId' ) );
        $add( 'V2Guid', $pick( 'V2Guid' ) );
        $add( 'GuidGroup', $pick( 'GuidGroup' ) );
        $add( 'GuidVahed', $pick( 'GuidVahed' ) );

        return $rows;
    }

    /**
     * "آخرین موارد تبادل": rows still waiting in the queue, followed by the
     * exchanges the accounting software already pulled (sync log events).
     */
    private static function render_latest_exchanges() {
        global $wpdb;
        $prefix  = $wpdb->prefix;
        $pending = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, item_type, priority, created_at FROM {$prefix}novin_commerce_syncs ORDER BY id DESC LIMIT %d", 4 ) );
        $done    = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, item_type, event_type, created_at FROM {$prefix}novin_commerce_sync_logs WHERE event_type IN ('synced','removed') ORDER BY id DESC LIMIT %d", 4 ) );
        $labels  = [ 'product' => 'کالا', 'variation' => 'Variation', 'order' => 'فاکتور', 'category' => 'دسته‌بندی', 'user' => 'شخص' ];
        $count   = 0;
        if ( $pending ) {
            echo '<div class="novin-exchange-group">در انتظار در صف</div>';
            foreach ( $pending as $r ) {
                $type = isset( $labels[ $r->item_type ] ) ? $labels[ $r->item_type ] : (string) $r->item_type;
                $badge = (int) $r->priority > 0 ? 'اولویت بالا' : 'عادی';
                echo self::exchange_row_html( self::item_title( $r->item_type, $r->item_id ), $type, self::time_label( $r->created_at ), $badge );
                $count++;
            }
        }
        if ( $done ) {
            echo '<div class="novin-exchange-group">دریافت‌شده توسط حسابداری</div>';
            foreach ( $done as $r ) {
                $type = isset( $labels[ $r->item_type ] ) ? $labels[ $r->item_type ] : (string) $r->item_type;
                $badge = 'synced' === $r->event_type ? 'دریافت شد' : 'حذف شد';
                echo self::exchange_row_html( self::item_title( $r->item_type, $r->item_id ), $type, self::time_label( $r->created_at ), $badge );
                $count++;
            }
        }
        if ( ! $count ) {
            echo '<div class="novin-empty">هنوز تبادلی ثبت نشده است.</div>';
        }
    }

    private static function exchange_row_html( $title, $type, $time, $badge ) {
        return '<div class="novin-refresh-row"><div><strong>' . esc_html( $title ) . '</strong><small>' . esc_html( $type ) . ' · ' . esc_html( $time ) . '</small></div><span>' . esc_html( $badge ) . '</span></div>';
    }

    /** UTC stored timestamps (Eloquent/SyncLog write UTC) shown in site timezone. */
    private static function time_label( $utc ) {
        $utc = is_string( $utc ) ? $utc : '';
        if ( '' === $utc ) return '—';
        $ts = strtotime( $utc );
        if ( ! $ts ) return $utc;
        if ( function_exists( 'get_date_from_gmt' ) ) {
            return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y/m/d H:i' );
        }
        return gmdate( 'Y/m/d H:i', $ts );
    }

    /** Resolve a queue/log item id to a human title without leaving the dashboard. */
    private static function item_title( $type, $id ) {
        $id    = absint( $id );
        $title = '#' . $id;
        if ( ! $id ) return $title;
        if ( in_array( $type, [ 'product', 'variation' ], true ) && function_exists( 'wc_get_product' ) ) {
            $p = wc_get_product( $id );
            if ( $p && method_exists( $p, 'get_name' ) && '' !== trim( (string) $p->get_name() ) ) $title = (string) $p->get_name();
        } elseif ( 'category' === $type ) {
            $t = get_term( $id );
            if ( $t && ! is_wp_error( $t ) && isset( $t->name ) && '' !== trim( (string) $t->name ) ) $title = (string) $t->name;
        } elseif ( 'user' === $type ) {
            $u = get_userdata( $id );
            if ( $u ) {
                $n = ! empty( $u->display_name ) ? $u->display_name : $u->user_login;
                if ( '' !== trim( (string) $n ) ) $title = (string) $n;
            }
        } elseif ( 'order' === $type && function_exists( 'wc_get_order' ) ) {
            $o = wc_get_order( $id );
            if ( $o && method_exists( $o, 'get_order_number' ) ) $title = '#' . (string) $o->get_order_number();
        }
        return $title;
    }

    private static function health(){
        $cached=get_transient('novin_commerce_health_v3'); if(is_array($cached))return $cached; global $wpdb;
        $api=SettingAPI::get('api_url',''); $sync=(int)SettingAPI::get('sync_datetime',0);
        $product_status=wp_count_posts('product');
        $products=(int)($product_status->publish??0); $draft=(int)($product_status->draft??0); $private=(int)($product_status->private??0);
        $vars=(int)wp_count_posts('product_variation')->publish;
        $variable=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE p.post_type='product' AND p.post_status='publish' AND tt.taxonomy='product_type' AND t.slug='variable'");
        $total=$products+$vars; $with=(int)$wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='guid' AND m.meta_value<>'' WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish'");
        $missing=max(0,$total-$with);
        $rows=$wpdb->get_results("SELECT p.ID,g.meta_value guid,w.meta_value webprd,s.meta_value sync_date FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} g ON g.post_id=p.ID AND g.meta_key='guid' LEFT JOIN {$wpdb->postmeta} w ON w.post_id=p.ID AND w.meta_key='WebPrd' LEFT JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_np-api-sync-date' WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND w.meta_value<>'' ORDER BY p.ID DESC LIMIT 150");
        $valid=$gm=$stale=0; $now=time();
        $webprd_insights=['modified'=>0,'stock_positive'=>0,'sku'=>0,'barcode'=>0,'barcode_value'=>0,'sell_price'=>0,'discount_active'=>0,'price_roles'=>0,'group'=>0,'vahed'=>0,'technical'=>0,'pics'=>0];
        foreach($rows as $r){
            $d=json_decode((string)$r->webprd,true,12);
            if(!is_array($d)||JSON_ERROR_NONE!==json_last_error())continue;
            $valid++;
            if(!empty($d['Guid']) && (string)$d['Guid']!==(string)$r->guid)$gm++;
            $a=!empty($d['Modified'])?strtotime((string)$d['Modified']):0;
            $b=!empty($r->sync_date)?strtotime(str_replace('/','-',(string)$r->sync_date)):0;
            if($a&&$b&&$a>$b+1)$stale++;
            if(!empty($d['Modified']))$webprd_insights['modified']++;
            if(isset($d['Mojodi'])&&is_numeric($d['Mojodi'])&&(float)$d['Mojodi']>0)$webprd_insights['stock_positive']++;
            if(!empty($d['Sku']))$webprd_insights['sku']++;
            if(!empty($d['PrdBarcode'])&&is_array($d['PrdBarcode'])){
                $webprd_insights['barcode']++;
                foreach($d['PrdBarcode'] as $bc){
                    if(is_array($bc)&&isset($bc['BarCode'])&&''!==(string)$bc['BarCode']){$webprd_insights['barcode_value']++;break;}
                }
            }
            if(isset($d['Sell1'])&&is_numeric($d['Sell1'])&&(float)$d['Sell1']>0)$webprd_insights['sell_price']++;
            $ds=!empty($d['DiscountStartDate'])?strtotime((string)$d['DiscountStartDate']):0;
            $de=!empty($d['DiscountEndDate'])?strtotime((string)$d['DiscountEndDate']):0;
            if($ds&&$de&&$ds<=$now&&$now<=$de)$webprd_insights['discount_active']++;
            if(!empty($d['PriceRoleList'])&&is_array($d['PriceRoleList']))$webprd_insights['price_roles']++;
            if(!empty($d['GuidGroup']))$webprd_insights['group']++;
            if(!empty($d['VahedName']))$webprd_insights['vahed']++;
            if(!empty($d['PrdTechnicalList'])&&is_array($d['PrdTechnicalList']))$webprd_insights['technical']++;
            if(!empty($d['ImageListData'])&&is_array($d['ImageListData']))$webprd_insights['pics']++;
        }
        $queue_counts=[]; $all=0;
        $queue_rows=$wpdb->get_results("SELECT item_type, COUNT(*) AS total FROM {$wpdb->prefix}novin_commerce_syncs GROUP BY item_type");
        foreach((array)$queue_rows as $qr){$n=(int)$qr->total;$all+=$n;$queue_counts[sanitize_key($qr->item_type)]=$n;}
        $queue_counts['_total']=$all;
        $log_counts=['total'=>0,'success'=>0,'warning'=>0,'error'=>0,'info'=>0,'latest'=>'']; $log_table=$wpdb->prefix.'novin_commerce_sync_logs';
        $log_rows=$wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM {$log_table} WHERE created_at >= %s GROUP BY status", gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS)));
        foreach((array)$log_rows as $lr){$n=(int)$lr->total;$log_counts['total']+=$n;if(isset($log_counts[$lr->status]))$log_counts[$lr->status]=$n;}
        $log_counts['latest']=(string)$wpdb->get_var("SELECT created_at FROM {$log_table} ORDER BY id DESC LIMIT 1");
        $h=['api'=>['label'=>($api&&wp_http_validate_url($api))?'پیکربندی شده':'نیازمند بررسی','status'=>($api&&wp_http_validate_url($api))?'success':'error','detail'=>$api?:'آدرس API تنظیم نشده است.'],'sync_time'=>['label'=>$sync?wp_date('Y-m-d H:i:s',$sync):'ثبت نشده','status'=>$sync?'success':'warning'],'queue'=>['all'=>$all,'counts'=>$queue_counts],'catalog'=>['products'=>$products,'variable'=>$variable,'variations'=>$vars,'draft'=>$draft,'private'=>$private,'with_guid'=>$with,'missing_guid'=>$missing],'webprd'=>['sample'=>count($rows),'valid'=>$valid,'guid_mismatch'=>$gm,'stale'=>$stale,'insights'=>$webprd_insights],'logs'=>$log_counts];
        set_transient('novin_commerce_health_v3',$h,60);return $h;
    }

    private static function render_detail($id,$type){
        if(!in_array($type,['product','variation'],true))return;
        $p=wc_get_product($id); if(!$p)return;
        $raw=$p->get_meta('WebPrd',true); $d=is_string($raw)?json_decode($raw,true,12):[]; if(!is_array($d))$d=[];
        $site=trim((string)$p->get_meta('guid',true)); $source=trim((string)($d['Guid']??''));
        echo '<section class="novin-panel novin-detail"><div class="novin-panel-title"><div><span class="novin-kicker">CONNECTION DETAIL</span><h2>'.esc_html($p->get_name()).'</h2><p>ID '.absint($id).' · '.esc_html($type).'</p></div></div><div class="novin-detail-grid">';
        self::detail_item('GUID سایت',$site?:'ثبت نشده',$source&&$source!==$site?'error':'success');
        self::detail_item('GUID حسابداری',$source?:'ثبت نشده',$source?'success':'warning');
foreach(self::webprd_detail_rows($d) as $row){self::detail_item($row[0],$row[1],$row[2]);}
        self::detail_item('آخرین Sync سایت',(string)$p->get_meta('_np-api-sync-date',true),'info');
        echo '</div><div class="novin-detail-actions">';
        echo '<form method="post">'.wp_nonce_field('novin_dashboard_action','_wpnonce',true,false).'<input type="hidden" name="novin_dashboard_action" value="requeue"><input type="hidden" name="item_id" value="'.absint($id).'"><input type="hidden" name="item_type" value="'.esc_attr($type).'"><button class="button">ارسال مجدد در صف</button></form>';
        echo '</div></section>';
    }
    private static function handle_actions(){
        if(empty($_POST['novin_dashboard_action']))return;
        if(!current_user_can('manage_options'))wp_die('Unauthorized');
        check_admin_referer('novin_dashboard_action');
        $a=sanitize_key(wp_unslash($_POST['novin_dashboard_action']));
        $id=absint($_POST['item_id']??0);
        $type=sanitize_key(wp_unslash($_POST['item_type']??''));
        if('requeue'===$a&&$id&&in_array($type,['product','variation','order','category','user'],true)){
            Sync::requeue($id,$type,10);
            delete_transient('novin_commerce_health_v3');
            AdminNotice::addSuccessDismissible('مورد دوباره در صف تبادل قرار گرفت.');
        }
        wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=novin-commerce-dashboard'));exit;
    }

    private static function card($t,$v,$s,$i,$d){echo '<div class="novin-card"><div class="novin-card-icon '.esc_attr($s).'">'.$i.'</div><div><span>'.esc_html($t).'</span><strong>'.esc_html($v).'</strong><small>'.esc_html($d).'</small></div></div>';}
    private static function metric($l,$v){echo '<div class="novin-metric"><span>'.esc_html($l).'</span><strong>'.esc_html($v).'</strong></div>';}
    private static function diagnostic($t,$ok,$v,$d){echo '<div class="novin-diagnostic"><span class="novin-diagnostic-icon '.($ok?'ok':'bad').'">'.self::icon($ok?'check':'alert').'</span><div><strong>'.esc_html($t).'</strong><b>'.esc_html($v).'</b><small>'.esc_html($d).'</small></div></div>';}
    private static function donut($title,$a,$b,$la,$lb,$center_label){$total=max(1,$a+$b);$pct=round($a/$total*100);$circ=301.59;$dash=round($circ*$pct/100,2);echo '<div class="novin-chart-card"><h3>'.esc_html($title).'</h3><div class="novin-donut"><svg viewBox="0 0 120 120"><circle class="track" cx="60" cy="60" r="48"/><circle class="value" cx="60" cy="60" r="48" stroke-dasharray="'.$dash.' '.$circ.'"/></svg><div><strong>'.$pct.'%</strong><span>'.esc_html($center_label).'</span></div></div><div class="novin-legend"><span><i></i>'.esc_html($la).' '.number_format_i18n($a).'</span><span><i></i>'.esc_html($lb).' '.number_format_i18n($b).'</span></div></div>';}
    private static function horizontal_bars($title,$rows){echo '<div class="novin-chart-card"><h3>'.esc_html($title).'</h3>';foreach($rows as $r){$max=max(1,(int)$r['max']);$pct=min(100,round(((int)$r['value']/$max)*100));echo '<div class="novin-hbar"><div><span>'.esc_html($r['label']).'</span><b>'.number_format_i18n($r['value']).'</b></div><div><i style="width:'.$pct.'%"></i></div></div>';}echo '</div>';}
    private static function stat_tile($label,$value,$status,$icon){echo '<div class="novin-stat-tile"><span class="novin-stat-icon '.esc_attr($status).'">'.$icon.'</span><div><span>'.esc_html($label).'</span><strong>'.number_format_i18n((int)$value).'</strong></div></div>';}
    private static function insight_tile($label,$value,$detail,$tone,$icon){echo '<div class="novin-insight-tile tone-'.esc_attr($tone).'"><div class="novin-insight-icon">'.$icon.'</div><div class="novin-insight-copy"><div class="novin-insight-line"><span class="novin-insight-label">'.esc_html($label).' </span><strong>'.number_format_i18n((int)$value).'</strong><span class="novin-insight-detail"> '.esc_html($detail).'</span></div></div></div>'; }
    private static function stacked_bars($title,$rows){$total=0;foreach($rows as $r)$total+=(int)$r['value'];$total=max(1,$total);echo '<div class="novin-stack-title"><strong>'.esc_html($title).'</strong><span>'.number_format_i18n($total).'</span></div><div class="novin-stack">';foreach($rows as $r){$pct=round(((int)$r['value']/$total)*100,1);echo '<div class="novin-stack-segment '.esc_attr($r['class']).'" style="width:'.$pct.'%" title="'.esc_attr($r['label'].' '.$r['value']).'"></div>';}echo '</div><div class="novin-stack-legend">';foreach($rows as $r){echo '<span><i class="'.esc_attr($r['class']).'"></i>'.esc_html($r['label']).' '.number_format_i18n((int)$r['value']).'</span>';}echo '</div>';}
    private static function tool($t,$d,$u){echo '<a class="novin-tool" href="'.esc_url($u).'"><span>'.self::icon('arrow').'</span><div><strong>'.esc_html($t).'</strong><small>'.esc_html($d).'</small></div></a>';}
    private static function detail_item($l,$v,$s){echo '<div class="novin-detail-item"><span>'.esc_html($l).'</span><strong class="'.esc_attr($s).'">'.esc_html($v).'</strong></div>';}
    private static function event_label($e){$m=['queued'=>'ورود به صف','requeue'=>'ارسال مجدد با اولویت','synced'=>'دریافت توسط حسابداری','removed'=>'حذف از صف','priority'=>'تغییر اولویت','disconnect'=>'قطع ارتباط','sync_datetime'=>'تغییر زمان Sync','health'=>'بررسی سلامت'];return $m[$e]??ucwords(str_replace('_',' ',(string)$e));}
    private static function db_name(){global $wpdb;return method_exists($wpdb,'db_version')?$wpdb->db_version():'—';}
    private static function critical_css(){
        return '.novin-dashboard{width:100%!important;max-width:1500px!important;margin:0 auto!important;padding:0 16px 40px!important;direction:rtl!important;overflow:visible!important}.novin-dashboard,.novin-dashboard *{box-sizing:border-box!important}.novin-dashboard .novin-dashboard-grid{display:block!important;width:100%!important;max-width:100%!important;margin:0 0 16px!important}.novin-dashboard .novin-dashboard-triple-row{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;align-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;gap:16px!important;margin:0 0 16px!important;padding:0!important}.novin-dashboard .novin-dashboard-triple-row>.novin-panel{display:flex!important;flex-direction:column!important;width:auto!important;min-width:0!important;max-width:none!important;margin:0!important;overflow:hidden!important}.novin-dashboard .novin-dashboard-triple-row>.novin-panel>.novin-panel-title{min-height:62px!important;flex:0 0 auto!important}.novin-dashboard .novin-dashboard-triple-row .novin-panel-title>div{min-width:0!important;overflow:hidden!important}.novin-dashboard .novin-dashboard-triple-row .novin-panel-title h2,.novin-dashboard .novin-dashboard-triple-row .novin-panel-title p{overflow-wrap:anywhere!important;word-break:normal!important}.novin-dashboard .novin-dashboard-triple-row .novin-panel-title .button{flex:0 0 auto!important;white-space:nowrap!important}.novin-dashboard .novin-panel-wide{width:100%!important;max-width:100%!important;min-width:0!important;margin-left:0!important;margin-right:0!important}.novin-dashboard .novin-card-icon{width:42px!important;height:42px!important;min-width:42px!important;display:grid!important;place-items:center!important}.novin-dashboard .novin-card-icon svg{display:block!important;width:21px!important;height:21px!important;max-width:21px!important;max-height:21px!important}.novin-dashboard svg{max-width:none!important}.novin-dashboard .novin-stat-icon svg,.novin-dashboard .novin-diagnostic-icon svg,.novin-dashboard .novin-tool>span>svg,.novin-dashboard .novin-insight-icon>svg{display:block!important;width:20px!important;height:20px!important;max-width:20px!important;max-height:20px!important;flex:0 0 20px!important}.novin-dashboard .novin-insight-icon{width:40px!important;height:40px!important;min-width:40px!important;max-width:40px!important;display:grid!important;place-items:center!important;flex:0 0 40px!important;border-radius:12px!important;overflow:hidden!important}.novin-dashboard .novin-insight-icon svg{fill:none!important;stroke:currentColor!important;stroke-width:1.8!important;stroke-linecap:round!important;stroke-linejoin:round!important}.novin-dashboard .novin-insight-tile{display:flex!important;align-items:center!important;gap:12px!important;min-width:0!important;padding:14px!important;border:1px solid #eef2f7!important;border-radius:14px!important;background:#fbfcfe!important;overflow:hidden!important}.novin-dashboard .novin-insight-tile.tone-green,.novin-dashboard .novin-insight-tile.tone-blue,.novin-dashboard .novin-insight-tile.tone-purple,.novin-dashboard .novin-insight-tile.tone-amber,.novin-dashboard .novin-insight-tile.tone-teal,.novin-dashboard .novin-insight-tile.tone-indigo{border-top:1px solid #eef2f7!important;background:#fbfcfe!important;border-radius:14px!important}.novin-dashboard .novin-insight-tile>div{min-width:0!important;overflow:hidden!important}.novin-dashboard .novin-insight-tile span,.novin-dashboard .novin-insight-tile small{display:block!important;color:#64748b!important;font-size:11px!important;line-height:1.6!important;overflow:hidden!important;text-overflow:ellipsis!important}.novin-dashboard .novin-insight-tile strong{display:block!important;font-size:22px!important;line-height:1.2!important;margin:3px 0!important}.novin-dashboard .novin-chart-row-3{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:20px!important;min-width:0!important}.novin-dashboard .novin-health-grid-extended{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:14px!important}.novin-dashboard .novin-dashboard-stat-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}.novin-dashboard .novin-diagnostic-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}.novin-dashboard .novin-tools-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px!important}.novin-dashboard .novin-system-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:9px!important}.novin-dashboard .novin-insights-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:12px!important;min-width:0!important}.novin-dashboard > .novin-panel{margin:0 0 18px!important}.novin-dashboard > .novin-dashboard-grid,.novin-dashboard > .novin-dashboard-triple-row{margin-bottom:18px!important}.novin-dashboard > .novin-panel:last-child{margin-bottom:0!important}.novin-dashboard .novin-insights-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:14px!important;min-width:0!important}.novin-dashboard .novin-insight-tile{display:flex!important;align-items:center!important;gap:14px!important;min-height:76px!important;padding:15px 16px!important;position:relative!important}.novin-dashboard .novin-insight-copy{min-width:0!important;flex:1 1 auto!important;overflow:hidden!important}.novin-dashboard .novin-insight-line{display:flex!important;align-items:baseline!important;flex-wrap:wrap!important;column-gap:6px!important;row-gap:2px!important;line-height:1.5!important}.novin-dashboard .novin-insight-label{display:inline!important;color:#334155!important;font-size:12px!important;font-weight:600!important;white-space:nowrap!important}.novin-dashboard .novin-insight-line strong{display:inline!important;font-size:22px!important;line-height:1.2!important;margin:0!important;font-weight:800!important}.novin-dashboard .novin-insight-detail{display:inline!important;color:#64748b!important;font-size:11px!important;line-height:1.6!important}.novin-dashboard .novin-insight-icon{box-shadow:0 4px 12px rgba(15,23,42,.06)!important;border:1px solid rgba(255,255,255,.8)!important}.novin-dashboard .novin-donut svg{width:170px!important;height:170px!important;max-width:170px!important;max-height:170px!important}.novin-dashboard .tone-green .novin-insight-icon{color:#15803d!important;background:#ecfdf3!important}.novin-dashboard .tone-blue .novin-insight-icon{color:#2563eb!important;background:#eff6ff!important}.novin-dashboard .tone-purple .novin-insight-icon{color:#7c3aed!important;background:#f5f3ff!important}.novin-dashboard .tone-amber .novin-insight-icon{color:#b45309!important;background:#fffbeb!important}.novin-dashboard .tone-teal .novin-insight-icon{color:#0f766e!important;background:#f0fdfa!important}.novin-dashboard .tone-indigo .novin-insight-icon{color:#4f46e5!important;background:#eef2ff!important}.novin-dashboard .tone-red .novin-insight-icon{color:#dc2626!important;background:#fef2f2!important}@media(max-width:1100px){.novin-dashboard .novin-dashboard-triple-row{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-chart-row-3{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-chart-row-3 .novin-chart-card:last-child{grid-column:1/-1!important}.novin-dashboard .novin-insights-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-dashboard-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:900px){.novin-dashboard .novin-health-grid-extended{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-dashboard-grid{display:block!important}.novin-dashboard .novin-dashboard-triple-row{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:700px){.novin-dashboard{padding-left:8px!important;padding-right:8px!important}.novin-dashboard .novin-dashboard-triple-row{grid-template-columns:1fr!important}.novin-dashboard .novin-health-grid-extended,.novin-dashboard .novin-chart-row-3,.novin-dashboard .novin-dashboard-stat-grid,.novin-dashboard .novin-diagnostic-grid,.novin-dashboard .novin-tools-grid,.novin-dashboard .novin-system-grid,.novin-dashboard .novin-insights-grid{grid-template-columns:1fr!important}.novin-dashboard .novin-chart-row-3 .novin-chart-card:last-child{grid-column:auto!important}}.novin-dashboard .novin-stack-segment.info{background:#0ea5e9!important}.novin-dashboard .novin-stack-legend i.info{background:#0ea5e9!important}.novin-dashboard .novin-activity-foot{margin-top:12px!important;padding-top:8px!important;border-top:1px solid #eef2f7!important;font-size:11px!important;color:#64748b!important;line-height:1.8!important}.novin-dashboard .novin-exchange-group{display:flex!important;align-items:center!important;gap:8px!important;font-size:11px!important;font-weight:700!important;color:#64748b!important;margin:12px 0 2px!important;white-space:nowrap!important}.novin-dashboard .novin-exchange-group::after{content:"";height:1px!important;background:#eef2f7!important;flex:1!important}';
    }

    private static function icon($n){$i=['link'=>'<svg viewBox="0 0 24 24"><path d="M10.6 13.4a4.2 4.2 0 0 0 5.9 0l2.2-2.2a4.2 4.2 0 1 0-5.9-5.9l-1.2 1.2M13.4 10.6a4.2 4.2 0 0 0-5.9 0l-2.2 2.2a4.2 4.2 0 1 0 5.9 5.9l1.2-1.2"/></svg>','queue'=>'<svg viewBox="0 0 24 24"><path d="M5 7h14M5 12h14M5 17h9"/><circle cx="18" cy="17" r="2"/></svg>','alert'=>'<svg viewBox="0 0 24 24"><path d="m12 4 9 16H3L12 4Z"/><path d="M12 9v5M12 17h.01"/></svg>','speed'=>'<svg viewBox="0 0 24 24"><path d="M4 14a8 8 0 1 1 16 0"/><path d="m12 14 4-5"/><path d="M7 18h10"/></svg>','check'=>'<svg viewBox="0 0 24 24"><path d="m5 12 4 4 10-10"/></svg>','box'=>'<svg viewBox="0 0 24 24"><path d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Z"/><path d="m4 7.5 8 4 8-4M12 11.5V20"/></svg>','layers'=>'<svg viewBox="0 0 24 24"><path d="m12 4 8 4-8 4-8-4 8-4Z"/><path d="m4 12 8 4 8-4M4 16l8 4 8-4"/></svg>','key'=>'<svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="3"/><path d="m10.5 13 7-7 2 2-2 2 2 2-2 2-2-2-3.5 3.5"/></svg>','activity'=>'<svg viewBox="0 0 24 24"><path d="M3 12h4l2-6 4 12 2-6h6"/></svg>','edit'=>'<svg viewBox="0 0 24 24"><path d="m4 16 9.5-9.5a2.1 2.1 0 0 1 3 3L7 19H4v-3Z"/><path d="m13 7 4 4"/></svg>','lock'=>'<svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>','arrow'=>'<svg viewBox="0 0 24 24"><path d="M5 12h13M13 6l6 6-6 6"/></svg>','stock'=>'<svg viewBox="0 0 24 24"><path d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Z"/><path d="M4 7.5 12 11l8-3.5M12 11v9"/></svg>','sku'=>'<svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><path d="m10.5 10.5 8 8M13 5h6M16 8h3"/></svg>','barcode'=>'<svg viewBox="0 0 24 24"><path d="M4 5v14M7 5v14M10 5v14M14 5v14M17 5v14M20 5v14"/></svg>','price'=>'<svg viewBox="0 0 24 24"><path d="M4 7h10l6 5-6 5H4l4-5-4-5Z"/><circle cx="11" cy="12" r="1"/></svg>','group'=>'<svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/><path d="M11 7.5h2M7.5 11v2M16.5 11v2M11 16.5h2"/></svg>','clock'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>'];return str_replace('<svg viewBox=', '<svg width="20" height="20" aria-hidden="true" focusable="false" viewBox=', $i[$n]??'');}
}
