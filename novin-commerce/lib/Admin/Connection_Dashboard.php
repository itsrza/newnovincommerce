<?php
namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Common\SettingAPI;
use MobinDev\Novin_Commerce\Common\SyncLog;
use MobinDev\Novin_Commerce\Common\WebPrd_Applier;
use MobinDev\Novin_Commerce\Models\Sync;

class Connection_Dashboard {
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
        $plugin_file = dirname( __DIR__, 2 ) . '/novin-commerce.php';
        wp_enqueue_style( 'novin-commerce-admin-table', plugins_url( 'dist/styles/admin/table.min.css', $plugin_file ), [], '1.10.15-dash15' );
        wp_add_inline_style( 'novin-commerce-admin-table', self::critical_css() );
        wp_enqueue_script( 'novin-commerce-dashboard-js', plugins_url( 'dist/scripts/admin/dashboard.js', $plugin_file ), [], '1.10.15-dash15', true );
        $started = microtime( true );
        self::handle_actions();
        // Accounting data always wins: sweep the newest products once every 6h
        // and write stock/price/variable-structure changes automatically.
        $auto_changed = WebPrd_Applier::catchup();
        if ( $auto_changed ) Sync::flushHealthCache();
        $health = self::health();
        echo '<div class="wrap novin-dashboard">';
        echo '<header class="novin-hero"><div><span class="novin-kicker">NOVIN COMMERCE</span><h1>داشبورد همگام‌سازی کالا</h1><p>نمای زندهٔ کاتالوگ از دادهٔ حسابداری (WebPrd) و ووکامرس — همگام‌سازی موجودی/قیمت/ساختار به‌صورت خودکار انجام می‌شود.</p></div><div class="novin-hero-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-products')).'">کالاها</a><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-syncs')).'">صف تبادل</a><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-settings')).'">تنظیمات</a><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=novin-commerce-dashboard')).'">بررسی دوباره</a></div></header>';
        self::render_catalog_summary( $health );
        self::render_auto_report();
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
     * "آخرین موارد تبادل": items still waiting in the queue, exchanges the
     * accounting software already pulled ('synced' events), and — separately,
     * so they are never mistaken for completed exchanges — items that were
     * deleted from the queue without being sent ('removed' events).
     */
    private static function render_latest_exchanges() {
        global $wpdb;
        $prefix  = $wpdb->prefix;
        $pending = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, item_type, priority, created_at FROM {$prefix}novin_commerce_syncs ORDER BY id DESC LIMIT %d", 4 ) );
        $received = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, item_type, event_type, created_at FROM {$prefix}novin_commerce_sync_logs WHERE event_type='synced' ORDER BY id DESC LIMIT %d", 4 ) );
        $removed = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, item_type, event_type, created_at FROM {$prefix}novin_commerce_sync_logs WHERE event_type='removed' ORDER BY id DESC LIMIT %d", 2 ) );
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
        if ( $received ) {
            echo '<div class="novin-exchange-group">دریافت‌شده توسط حسابداری</div>';
            foreach ( $received as $r ) {
                $type = isset( $labels[ $r->item_type ] ) ? $labels[ $r->item_type ] : (string) $r->item_type;
                echo self::exchange_row_html( self::item_title( $r->item_type, $r->item_id ), $type, self::time_label( $r->created_at ), 'دریافت شد' );
                $count++;
            }
        }
        if ( $removed ) {
            echo '<div class="novin-exchange-group">حذف‌شده از صف (به حسابداری ارسال نشده)</div>';
            foreach ( $removed as $r ) {
                $type = isset( $labels[ $r->item_type ] ) ? $labels[ $r->item_type ] : (string) $r->item_type;
                echo self::exchange_row_html( self::item_title( $r->item_type, $r->item_id ), $type, self::time_label( $r->created_at ), 'حذف از صف' );
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
        $rows=$wpdb->get_results("SELECT p.ID,g.meta_value guid,w.meta_value webprd,s.meta_value sync_date, st.meta_value wc_stock, p.post_type nv_type FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} g ON g.post_id=p.ID AND g.meta_key='guid' LEFT JOIN {$wpdb->postmeta} w ON w.post_id=p.ID AND w.meta_key='WebPrd' LEFT JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key='_np-api-sync-date' LEFT JOIN {$wpdb->postmeta} st ON st.post_id=p.ID AND st.meta_key='_stock' WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND w.meta_value<>'' ORDER BY p.ID DESC LIMIT 150");
        $valid=$gm=$stale=$issues=0; $now=time();
        $nv_fresh=['h24'=>0,'d7'=>0,'d30'=>0,'old'=>0,'none'=>0];
        $nv_stock=['ok'=>0,'missing'=>0,'diff'=>0,'unknown'=>0];
        $nv_issues=[];
        $webprd_insights=['modified'=>0,'stock_positive'=>0,'sku'=>0,'barcode'=>0,'barcode_value'=>0,'sell_price'=>0,'discount_active'=>0,'price_roles'=>0,'group'=>0,'vahed'=>0,'technical'=>0,'pics'=>0];
        foreach($rows as $r){
            $d=json_decode((string)$r->webprd,true,12);
            if(!is_array($d)||JSON_ERROR_NONE!==json_last_error())continue;
            $valid++; $row_issue=false;
            if(!empty($d['Guid']) && (string)$d['Guid']!==(string)$r->guid){$gm++;$row_issue=true;}
            $a=!empty($d['Modified'])?strtotime((string)$d['Modified']):0;
            $b=!empty($r->sync_date)?strtotime(str_replace('/','-',(string)$r->sync_date)):0;
            if($a&&$b&&$a>$b+1){$stale++;$row_issue=true;}
            if($row_issue)$issues++;
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

            // --- Catalog snapshot (1.10.13): real accounting stock vs WooCommerce ---
            // Accounting stock = sum of Amount entries in PrdAnbarRelation (the live
            // payload stores the sellable warehouse stock there, e.g. Amount:7.0).
            $a_stock=0.0;$a_has=false;
            if(!empty($d['PrdAnbarRelation'])&&is_array($d['PrdAnbarRelation'])){
                foreach($d['PrdAnbarRelation'] as $rel){
                    if(is_array($rel)&&isset($rel['Amount'])&&is_numeric($rel['Amount'])){ $a_stock+=(float)$rel['Amount']; $a_has=true; }
                }
            }
            $w_stock=null;
            if(property_exists($r,'wc_stock')&&$r->wc_stock!==null&&$r->wc_stock!==''&&is_numeric($r->wc_stock))$w_stock=(float)$r->wc_stock;
            if(!$a_has){ $nv_stock['unknown']++; }
            elseif($w_stock===null){ $nv_stock['missing']++; if(count($nv_issues)<8)$nv_issues[]=['id'=>(int)$r->ID,'type'=>(string)$r->nv_type,'a'=>$a_stock,'w'=>null]; }
            elseif(abs($w_stock-$a_stock)<0.005){ $nv_stock['ok']++; }
            else { $nv_stock['diff']++; if(count($nv_issues)<8)$nv_issues[]=['id'=>(int)$r->ID,'type'=>(string)$r->nv_type,'a'=>$a_stock,'w'=>$w_stock]; }
            // --- Sync freshness buckets (based on _np-api-sync-date meta) ---
            if(!$b)$nv_fresh['none']++;
            else { $age_h=($now-$b)/3600; if($age_h<24)$nv_fresh['h24']++; elseif($age_h<168)$nv_fresh['d7']++; elseif($age_h<720)$nv_fresh['d30']++; else $nv_fresh['old']++; }
        }
        $cut24=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS);
        $new_24=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status='publish' AND post_date_gmt>=%s",$cut24));
        $cut24_fa=str_replace('-','/',$cut24);
        $synced_24=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m WHERE m.meta_key='_np-api-sync-date' AND m.meta_value<>'' AND m.meta_value>=%s",$cut24_fa));
        $queue_counts=[]; $all=0;
        $queue_rows=$wpdb->get_results("SELECT item_type, COUNT(*) AS total FROM {$wpdb->prefix}novin_commerce_syncs GROUP BY item_type");
        foreach((array)$queue_rows as $qr){$n=(int)$qr->total;$all+=$n;$queue_counts[sanitize_key($qr->item_type)]=$n;}
        $queue_counts['_total']=$all;
        $log_counts=['total'=>0,'success'=>0,'warning'=>0,'error'=>0,'info'=>0,'latest'=>'']; $log_table=$wpdb->prefix.'novin_commerce_sync_logs';
        $log_rows=$wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM {$log_table} WHERE created_at >= %s GROUP BY status", gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS)));
        foreach((array)$log_rows as $lr){$n=(int)$lr->total;$log_counts['total']+=$n;if(isset($log_counts[$lr->status]))$log_counts[$lr->status]=$n;}
        $log_counts['latest']=(string)$wpdb->get_var("SELECT created_at FROM {$log_table} ORDER BY id DESC LIMIT 1");
        // --- 7-day WooCommerce activity (date_created_gmt / date_modified_gmt) ---
        $days=[];$c7=[];$m7=[];$cut7=gmdate('Y-m-d',time()-6*DAY_IN_SECONDS).' 00:00:00';
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT DATE(post_date_gmt) AS d, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status='publish' AND post_date_gmt>=%s GROUP BY DATE(post_date_gmt)",$cut7)) as $row){$c7[(string)$row->d]=(int)$row->c;}
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT DATE(post_modified_gmt) AS d, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status='publish' AND post_modified_gmt>=%s GROUP BY DATE(post_modified_gmt)",$cut7)) as $row){$m7[(string)$row->d]=(int)$row->c;}
        for($i=6;$i>=0;$i--){$day=gmdate('Y-m-d',time()-($i*DAY_IN_SECONDS));$days[]=$day;$c7[$day]=isset($c7[$day])?(int)$c7[$day]:0;$m7[$day]=isset($m7[$day])?(int)$m7[$day]:0;}
        $h=['api'=>['label'=>($api&&wp_http_validate_url($api))?'پیکربندی شده':'نیازمند بررسی','status'=>($api&&wp_http_validate_url($api))?'success':'error','detail'=>$api?:'آدرس API تنظیم نشده است.'],'sync_time'=>['label'=>$sync?wp_date('Y-m-d H:i:s',$sync):'ثبت نشده','status'=>$sync?'success':'warning'],'queue'=>['all'=>$all,'counts'=>$queue_counts],'catalog'=>['products'=>$products,'variable'=>$variable,'variations'=>$vars,'draft'=>$draft,'private'=>$private,'with_guid'=>$with,'missing_guid'=>$missing,'new_24h'=>$new_24,'synced_24h'=>$synced_24],'webprd'=>['sample'=>count($rows),'valid'=>$valid,'guid_mismatch'=>$gm,'stale'=>$stale,'issues'=>$issues,'insights'=>$webprd_insights],'logs'=>$log_counts,'nv'=>['fresh'=>$nv_fresh,'stock'=>$nv_stock,'issues'=>$nv_issues,'days'=>$days,'created'=>$c7,'modified'=>$m7]];
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
    /**
     * Write the accounting stock (sum of Amount inside PrdAnbarRelation of
     * the WebPrd meta — the field the live payload actually uses, e.g.
     * Amount:7.0) into the WooCommerce product stock. $only_id=0 applies to
     * every sampled product whose accounting stock differs from the site.
     */
    private static function apply_accounting_stock( $only_id = 0 ) {
        if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) return 0;
        global $wpdb;
        $only_id = absint( $only_id );
        $q = "SELECT p.ID,w.meta_value webprd,st.meta_value wc_stock FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} w ON w.post_id=p.ID AND w.meta_key='WebPrd' LEFT JOIN {$wpdb->postmeta} st ON st.post_id=p.ID AND st.meta_key='_stock' WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' AND w.meta_value<>''";
        if ( $only_id ) $q .= $wpdb->prepare( ' AND p.ID=%d', $only_id );
        $q .= ' ORDER BY p.ID DESC LIMIT 150';
        $done = 0; $bad = 0;
        foreach ( (array) $wpdb->get_results( $q ) as $r ) {
            $d = json_decode( (string) $r->webprd, true, 12 );
            if ( ! is_array( $d ) ) continue;
            $amount = 0.0; $has = false;
            if(!empty($d['PrdAnbarRelation'])&&is_array($d['PrdAnbarRelation'])){
                foreach($d['PrdAnbarRelation'] as $rel){
                    if ( is_array( $rel ) && isset( $rel['Amount'] ) && is_numeric( $rel['Amount'] ) ) { $amount += (float) $rel['Amount']; $has = true; }
                }
            }
            if ( ! $has ) continue;
            $current = null;
            if ( isset( $r->wc_stock ) && $r->wc_stock !== null && $r->wc_stock !== '' && is_numeric( $r->wc_stock ) ) $current = (float) $r->wc_stock;
            if ( $current !== null && abs( $current - $amount ) < 0.005 ) continue;
            if ( $bad >= 5 ) continue;
            $prod = wc_get_product( (int) $r->ID );
            if ( ! $prod ) continue;
            try {
                wc_update_product_stock( $prod, $amount );
                $done++;
                SyncLog::add( 'stock_sync', 'success', $prod->is_type( 'variation' ) ? 'variation' : 'product', (int) $r->ID, 'موجودی حسابداری اعمال شد: '.number_format_i18n( $amount ).' عدد (از PrdAnbarRelation).' );
            } catch ( \Throwable $e ) {
                $bad++;
            }
        }
        return $done;
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
        if('apply_stock'===$a&&$id){
            $n=self::apply_accounting_stock($id);
            Sync::flushHealthCache();
            AdminNotice::addSuccessDismissible($n>0?('موجودی حسابداری روی '.number_format_i18n($n).' کالا اعمال شد.'):'موجودی حسابداری این کالا در WebPrd موجود نیست یا از قبل هماهنگ است.');
        }
        if('apply_stock_all'===$a){
            $n=self::apply_accounting_stock(0);
            Sync::flushHealthCache();
            AdminNotice::addSuccessDismissible($n>0?('موجودی حسابداری (جمع Amount در PrdAnbarRelation) روی '.number_format_i18n($n).' کالای دارای اختلاف اعمال شد.'):'موردی برای اعمال موجودی نمانده بود — همه هماهنگ‌اند یا WebPrd موجودی ندارد.');
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
    private static function event_label($e){$m=['queued'=>'ورود به صف','requeue'=>'ارسال مجدد با اولویت','synced'=>'دریافت توسط حسابداری','removed'=>'حذف از صف','priority'=>'تغییر اولویت','disconnect'=>'قطع ارتباط','sync_datetime'=>'تغییر زمان Sync','health'=>'بررسی سلامت','stock_sync'=>'اعمال موجودی از حسابداری','auto_stock'=>'همگام‌سازی خودکار موجودی','auto_price'=>'همگام‌سازی خودکار قیمت','auto_structure'=>'همگام‌سازی خودکار ساختار متغیر','auto_variation'=>'پیوند خودکار متغیرها','auto_sync_all'=>'همگام‌سازی خودکار دوره‌ای'];return $m[$e]??ucwords(str_replace('_',' ',(string)$e));}
    private static function db_name(){global $wpdb;return method_exists($wpdb,'db_version')?$wpdb->db_version():'—';}
    private static function critical_css(){
        return '.novin-dashboard{width:100%!important;max-width:1500px!important;margin:0 auto!important;padding:0 16px 40px!important;direction:rtl!important;overflow:visible!important}.novin-dashboard,.novin-dashboard *{box-sizing:border-box!important}.novin-dashboard .novin-dashboard-grid{display:block!important;width:100%!important;max-width:100%!important;margin:0 0 16px!important}.novin-dashboard .novin-dashboard-triple-row{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;align-items:stretch!important;width:100%!important;max-width:100%!important;min-width:0!important;gap:16px!important;margin:0 0 16px!important;padding:0!important}.novin-dashboard .novin-dashboard-triple-row>.novin-panel{display:flex!important;flex-direction:column!important;width:auto!important;min-width:0!important;max-width:none!important;margin:0!important;overflow:hidden!important}.novin-dashboard .novin-dashboard-triple-row>.novin-panel>.novin-panel-title{min-height:62px!important;flex:0 0 auto!important}.novin-dashboard .novin-dashboard-triple-row .novin-panel-title>div{min-width:0!important;overflow:hidden!important}.novin-dashboard .novin-dashboard-triple-row .novin-panel-title h2,.novin-dashboard .novin-dashboard-triple-row .novin-panel-title p{overflow-wrap:anywhere!important;word-break:normal!important}.novin-dashboard .novin-dashboard-triple-row .novin-panel-title .button{flex:0 0 auto!important;white-space:nowrap!important}.novin-dashboard .novin-panel-wide{width:100%!important;max-width:100%!important;min-width:0!important;margin-left:0!important;margin-right:0!important}.novin-dashboard .novin-card-icon{width:42px!important;height:42px!important;min-width:42px!important;display:grid!important;place-items:center!important}.novin-dashboard .novin-card-icon svg{display:block!important;width:21px!important;height:21px!important;max-width:21px!important;max-height:21px!important}.novin-dashboard svg{max-width:none!important}.novin-dashboard .novin-stat-icon svg,.novin-dashboard .novin-diagnostic-icon svg,.novin-dashboard .novin-tool>span>svg,.novin-dashboard .novin-insight-icon>svg{display:block!important;width:20px!important;height:20px!important;max-width:20px!important;max-height:20px!important;flex:0 0 20px!important}.novin-dashboard .novin-insight-icon{width:40px!important;height:40px!important;min-width:40px!important;max-width:40px!important;display:grid!important;place-items:center!important;flex:0 0 40px!important;border-radius:12px!important;overflow:hidden!important}.novin-dashboard .novin-insight-icon svg{fill:none!important;stroke:currentColor!important;stroke-width:1.8!important;stroke-linecap:round!important;stroke-linejoin:round!important}.novin-dashboard .novin-insight-tile{display:flex!important;align-items:center!important;gap:12px!important;min-width:0!important;padding:14px!important;border:1px solid #eef2f7!important;border-radius:14px!important;background:#fbfcfe!important;overflow:hidden!important}.novin-dashboard .novin-insight-tile.tone-green,.novin-dashboard .novin-insight-tile.tone-blue,.novin-dashboard .novin-insight-tile.tone-purple,.novin-dashboard .novin-insight-tile.tone-amber,.novin-dashboard .novin-insight-tile.tone-teal,.novin-dashboard .novin-insight-tile.tone-indigo{border-top:1px solid #eef2f7!important;background:#fbfcfe!important;border-radius:14px!important}.novin-dashboard .novin-insight-tile>div{min-width:0!important;overflow:hidden!important}.novin-dashboard .novin-insight-tile span,.novin-dashboard .novin-insight-tile small{display:block!important;color:#64748b!important;font-size:11px!important;line-height:1.6!important;overflow:hidden!important;text-overflow:ellipsis!important}.novin-dashboard .novin-insight-tile strong{display:block!important;font-size:22px!important;line-height:1.2!important;margin:3px 0!important}.novin-dashboard .novin-chart-row-3{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:20px!important;min-width:0!important}.novin-dashboard .novin-health-grid-extended{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:14px!important}.novin-dashboard .novin-dashboard-stat-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}.novin-dashboard .novin-diagnostic-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}.novin-dashboard .novin-tools-grid{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px!important}.novin-dashboard .novin-system-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:9px!important}.novin-dashboard .novin-insights-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:12px!important;min-width:0!important}.novin-dashboard > .novin-panel{margin:0 0 18px!important}.novin-dashboard > .novin-dashboard-grid,.novin-dashboard > .novin-dashboard-triple-row{margin-bottom:18px!important}.novin-dashboard > .novin-panel:last-child{margin-bottom:0!important}.novin-dashboard .novin-insights-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:14px!important;min-width:0!important}.novin-dashboard .novin-insight-tile{display:flex!important;align-items:center!important;gap:14px!important;min-height:76px!important;padding:15px 16px!important;position:relative!important}.novin-dashboard .novin-insight-copy{min-width:0!important;flex:1 1 auto!important;overflow:hidden!important}.novin-dashboard .novin-insight-line{display:flex!important;align-items:baseline!important;flex-wrap:wrap!important;column-gap:6px!important;row-gap:2px!important;line-height:1.5!important}.novin-dashboard .novin-insight-label{display:inline!important;color:#334155!important;font-size:12px!important;font-weight:600!important;white-space:nowrap!important}.novin-dashboard .novin-insight-line strong{display:inline!important;font-size:22px!important;line-height:1.2!important;margin:0!important;font-weight:800!important}.novin-dashboard .novin-insight-detail{display:inline!important;color:#64748b!important;font-size:11px!important;line-height:1.6!important}.novin-dashboard .novin-insight-icon{box-shadow:0 4px 12px rgba(15,23,42,.06)!important;border:1px solid rgba(255,255,255,.8)!important}.novin-dashboard .novin-donut svg{width:170px!important;height:170px!important;max-width:170px!important;max-height:170px!important}.novin-dashboard .tone-green .novin-insight-icon{color:#15803d!important;background:#ecfdf3!important}.novin-dashboard .tone-blue .novin-insight-icon{color:#2563eb!important;background:#eff6ff!important}.novin-dashboard .tone-purple .novin-insight-icon{color:#7c3aed!important;background:#f5f3ff!important}.novin-dashboard .tone-amber .novin-insight-icon{color:#b45309!important;background:#fffbeb!important}.novin-dashboard .tone-teal .novin-insight-icon{color:#0f766e!important;background:#f0fdfa!important}.novin-dashboard .tone-indigo .novin-insight-icon{color:#4f46e5!important;background:#eef2ff!important}.novin-dashboard .tone-red .novin-insight-icon{color:#dc2626!important;background:#fef2f2!important}@media(max-width:1100px){.novin-dashboard .novin-dashboard-triple-row{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-chart-row-3{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-chart-row-3 .novin-chart-card:last-child{grid-column:1/-1!important}.novin-dashboard .novin-insights-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-dashboard-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:900px){.novin-dashboard .novin-health-grid-extended{grid-template-columns:repeat(2,minmax(0,1fr))!important}.novin-dashboard .novin-dashboard-grid{display:block!important}.novin-dashboard .novin-dashboard-triple-row{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:700px){.novin-dashboard{padding-left:8px!important;padding-right:8px!important}.novin-dashboard .novin-dashboard-triple-row{grid-template-columns:1fr!important}.novin-dashboard .novin-health-grid-extended,.novin-dashboard .novin-chart-row-3,.novin-dashboard .novin-dashboard-stat-grid,.novin-dashboard .novin-diagnostic-grid,.novin-dashboard .novin-tools-grid,.novin-dashboard .novin-system-grid,.novin-dashboard .novin-insights-grid{grid-template-columns:1fr!important}.novin-dashboard .novin-chart-row-3 .novin-chart-card:last-child{grid-column:auto!important}}.novin-dashboard .novin-stack-segment.info{background:#0ea5e9!important}.novin-dashboard .novin-stack-legend i.info{background:#0ea5e9!important}.novin-dashboard .novin-activity-foot{margin-top:12px!important;padding-top:8px!important;border-top:1px solid #eef2f7!important;font-size:11px!important;color:#64748b!important;line-height:1.8!important}.novin-dashboard .novin-exchange-group{display:flex!important;align-items:center!important;gap:8px!important;font-size:11px!important;font-weight:700!important;color:#64748b!important;margin:12px 0 2px!important;white-space:nowrap!important}.novin-dashboard .novin-exchange-group::after{content:"";height:1px!important;background:#eef2f7!important;flex:1!important}';
    }

    /**
     * Catalog snapshot (1.10.13): what the live WooCommerce/WebPrd data can
     * truthfully tell us about the catalog, rendered with animated charts.
     * All numbers are integers already computed in health(); nothing heavy here.
     */
    private static function render_auto_report() {
        $auto_events = array( 'auto_stock', 'auto_price', 'auto_structure', 'auto_variation', 'auto_sync_all', 'stock_sync' );
        $logs = array();
        foreach ( (array) SyncLog::recent( 60 ) as $log ) {
            if ( in_array( $log->event_type, $auto_events, true ) ) {
                $logs[] = $log;
                if ( count( $logs ) >= 8 ) break;
            }
        }
        echo '<section class="novin-panel novin-panel-wide"><div class="novin-panel-title"><div><span class="novin-kicker">AUTO SYNC</span><h2>گزارش همگام‌سازی خودکار</h2><p>اعمال خودکار داده‌های WebPrd (موجودی / قیمت / ساختار متغیر) روی کالاها — بدون نیاز به تأیید دستی.</p></div></div>';
        if ( ! $logs ) {
            echo '<div class="novin-empty">هنوز عملیات خودکاری ثبت نشده است — هنگام به‌روزرسانی کالا توسط نرم‌افزار حسابداری، تغییرات همین‌جا نمایش داده می‌شود.</div>';
        } else {
            foreach($logs as $log){echo '<div class="novin-log-row"><span class="novin-status-dot '.esc_attr($log->status).'"></span><div><strong>'.esc_html(self::event_label($log->event_type)).'</strong><p>'.esc_html($log->message ?: 'بدون توضیح').'</p></div><time>'.esc_html(self::time_label($log->created_at)).'</time></div>';}
        }
        echo '</section>';
    }

    private static function render_catalog_summary( $health ) {
        $nv  = isset( $health['nv'] ) && is_array( $health['nv'] ) ? $health['nv'] : [];
        $fresh = isset( $nv['fresh'] ) ? $nv['fresh'] : [ 'h24'=>0,'d7'=>0,'d30'=>0,'old'=>0,'none'=>0 ];
        $stock = isset( $nv['stock'] ) ? $nv['stock'] : [ 'ok'=>0,'missing'=>0,'diff'=>0,'unknown'=>0 ];
        $issues = isset( $nv['issues'] ) && is_array( $nv['issues'] ) ? array_slice( $nv['issues'], 0, 8 ) : [];
        $days = isset( $nv['days'] ) && is_array( $nv['days'] ) ? array_values( $nv['days'] ) : [];
        $c7   = isset( $nv['created'] ) && is_array( $nv['created'] ) ? $nv['created'] : [];
        $m7   = isset( $nv['modified'] ) && is_array( $nv['modified'] ) ? $nv['modified'] : [];
        $sample = isset( $health['webprd']['sample'] ) ? (int) $health['webprd']['sample'] : 0;
        $prods  = isset( $health['catalog']['products'] ) ? (int) $health['catalog']['products'] : 0;
        $vars   = isset( $health['catalog']['variations'] ) ? (int) $health['catalog']['variations'] : 0;
        $missg  = isset( $health['catalog']['missing_guid'] ) ? (int) $health['catalog']['missing_guid'] : 0;
        $valid  = isset( $health['webprd']['valid'] ) ? (int) $health['webprd']['valid'] : 0;
        $inv    = max( 0, $sample - $valid );
        $st_ok=(int)$stock['ok']; $st_mis=(int)$stock['missing']; $st_dif=(int)$stock['diff']; $st_unk=(int)$stock['unknown'];
        $checked=max(1,$st_ok+$st_mis+$st_dif); $pct_ok=(int)round(($st_ok/$checked)*100);
        $fresh_max=max(1,(int)$fresh['h24'],(int)$fresh['d7'],(int)$fresh['d30'],(int)$fresh['old'],(int)$fresh['none']);

        echo '<style>
.nv-snap{width:100%!important;max-width:100%!important;min-width:0!important;margin:0 0 18px!important;overflow:hidden!important}
.nv-snap .nv-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important;flex-wrap:wrap!important}
.nv-snap .nv-head h2{margin:0!important;font-size:17px!important}
.nv-snap .nv-head p{margin:2px 0 0!important;color:#64748b!important;font-size:12px!important}
.nv-chips{display:grid!important;grid-template-columns:repeat(6,minmax(0,1fr))!important;gap:10px!important;margin:14px 0 0!important}
.nv-chip{display:flex!important;align-items:center!important;gap:10px!important;padding:12px 13px!important;border-radius:14px!important;border:1px solid #eef2f7!important;background:linear-gradient(145deg,#ffffff,#f6f9ff)!important;box-shadow:0 4px 14px rgba(15,23,42,.05)!important;min-width:0!important}
.nv-chip .nv-ic{width:36px!important;height:36px!important;min-width:36px!important;display:grid!important;place-items:center!important;border-radius:11px!important;color:#fff!important}
.nv-chip b{display:block!important;font-size:20px!important;line-height:1.15!important;font-weight:800!important;color:#0f172a!important}
.nv-chip span{display:block!important;font-size:11px!important;color:#64748b!important;line-height:1.5!important}
.t-green .nv-ic{background:linear-gradient(135deg,#10b981,#059669)!important}.t-blue .nv-ic{background:linear-gradient(135deg,#3b82f6,#2563eb)!important}.t-purple .nv-ic{background:linear-gradient(135deg,#a855f7,#7c3aed)!important}.t-amber .nv-ic{background:linear-gradient(135deg,#f59e0b,#d97706)!important}.t-red .nv-ic{background:linear-gradient(135deg,#ef4444,#dc2626)!important}.t-slate .nv-ic{background:linear-gradient(135deg,#64748b,#475569)!important}
.nv-pulse{animation:nvPulse 1.8s ease-out infinite!important}
@keyframes nvPulse{0%{box-shadow:0 0 0 0 rgba(220,38,38,.35)!important}100%{box-shadow:0 0 0 14px rgba(220,38,38,0)!important}}
.nv-cols{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:14px!important;margin-top:14px!important}
.nv-card{border:1px solid #eef2f7!important;border-radius:16px!important;background:#fff!important;padding:14px 16px!important;box-shadow:0 4px 14px rgba(15,23,42,.04)!important;min-width:0!important}
.nv-card h3{margin:0 0 10px!important;font-size:13px!important;color:#334155!important}
.nv-ring{position:relative!important;width:120px!important;height:120px!important;margin:4px auto 6px!important}
.nv-ring svg{width:120px!important;height:120px!important;transform:rotate(-90deg)!important}
.nv-ring .track{fill:none!important;stroke:#eef2f7!important;stroke-width:12!important}
.nv-ring .val{fill:none!important;stroke:url(#nvGrad)!important;stroke-width:12!important;stroke-linecap:round!important;transition:stroke-dashoffset 1.2s cubic-bezier(.22,1,.36,1)!important}
.nv-ring .center{position:absolute!important;inset:0!important;display:grid!important;place-items:center!important;text-align:center!important}
.nv-ring .center b{font-size:22px!important;display:block!important;color:#0f172a!important}
.nv-ring .center span{font-size:10px!important;color:#94a3b8!important}
.nv-legend{display:flex!important;flex-wrap:wrap!important;gap:5px 12px!important;justify-content:center!important;font-size:11px!important;color:#475569!important;margin-top:2px!important}
.nv-legend i{display:inline-block!important;width:9px!important;height:9px!important;border-radius:3px!important;margin-left:4px!important;vertical-align:-1px!important}
.nv-segrow{display:flex!important;flex-direction:column!important;gap:8px!important}
.nv-segrow .row{display:grid!important;grid-template-columns:96px 1fr 44px!important;align-items:center!important;gap:8px!important;font-size:11px!important}
.nv-segrow .row>span{color:#475569!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
.nv-segrow .row b{text-align:left!important;font-size:12px!important;color:#0f172a!important}
.nv-bar{height:8px!important;background:#eef2f7!important;border-radius:99px!important;overflow:hidden!important}
.nv-bar i{display:block!important;height:100%!important;width:0!important;border-radius:99px!important;transition:width 1s cubic-bezier(.22,1,.36,1)!important}
.nv-bars7{display:flex!important;align-items:flex-end!important;justify-content:space-between!important;gap:6px!important;height:86px!important;padding-top:4px!important}
.nv-bars7 .day{display:flex!important;flex-direction:column!important;justify-content:flex-end!important;align-items:center!important;flex:1!important;height:100%!important;gap:3px!important;min-width:0!important}
.nv-bars7 .bars{display:flex!important;align-items:flex-end!important;gap:2px!important;height:70px!important;width:100%!important;justify-content:center!important}
.nv-bars7 .bars i{display:block!important;width:38%!important;height:0!important;border-radius:4px 4px 0 0!important;transition:height .9s cubic-bezier(.22,1,.36,1)!important}
.nv-bars7 .bars i.c{background:linear-gradient(180deg,#38bdf8,#0284c7)!important}
.nv-bars7 .bars i.m{background:linear-gradient(180deg,#c084fc,#7c3aed)!important}
.nv-bars7 .day small{font-size:9.5px!important;color:#94a3b8!important}
.nv-legend7{display:flex!important;justify-content:center!important;gap:14px!important;font-size:11px!important;color:#475569!important;margin-top:6px!important}
.nv-issues{margin-top:14px!important;border-top:1px dashed #e2e8f0!important;padding-top:10px!important}
.nv-issue{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:8px!important;padding:6px 2px!important;font-size:12px!important;border-bottom:1px solid #f6f8fb!important}
.nv-issue .nm{color:#334155!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;max-width:42%!important}
.nv-issue form{margin:0!important}.nv-issue form .button{font-size:10.5px!important;line-height:1.7!important;min-height:24px!important;margin:0!important}.nv-applyrow{display:flex!important;align-items:center!important;gap:10px!important;flex-wrap:wrap!important;margin:2px 0 6px!important}
.nv-badge{font-size:10.5px!important;font-weight:700!important;padding:3px 8px!important;border-radius:99px!important;white-space:nowrap!important}
.nv-badge.red{background:#fef2f2!important;color:#b91c1c!important;border:1px solid #fecaca!important}
.nv-badge.grn{background:#f0fdf4!important;color:#15803d!important;border:1px solid #bbf7d0!important}
.nv-note{margin-top:10px!important;font-size:10.5px!important;color:#94a3b8!important;line-height:1.8!important}
@media(max-width:1100px){.nv-cols{grid-template-columns:1fr!important}.nv-chips{grid-template-columns:repeat(3,minmax(0,1fr))!important}}
@media(max-width:700px){.nv-chips{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
</style>';

        $cat_t = $prods + $vars;
        $ic_box=self::icon('box');$ic_lay=self::icon('layers');$ic_key=self::icon('key');$ic_ok=self::icon('check');$ic_alert=self::icon('alert');$ic_sync=self::icon('activity');
        echo '<section class="novin-panel novin-panel-wide nv-snap"><div class="novin-panel-title"><div><span class="novin-kicker">CATALOG SNAPSHOT</span><h2>خلاصهٔ کاتالوگ — حسابداری × ووکامرس</h2><p>داده‌های زندهٔ همین محصولات (متا WebPrd + متادیتای ووکامرس)؛ اعداد زیر با انیمیشن نمایش داده می‌شوند.</p></div></div><div class="nv-chips">';
        self::nv_chip('کل کاتالوگ',$cat_t,'t-blue',$ic_lay,'محصول + وریشن منتشرشده');
        self::nv_chip('دارای WebPrd',$valid,'t-green',$ic_ok,'از '.$sample.' کالای نمونهٔ آخر');
        self::nv_chip('JSON نامعتبر',$inv,'t-red',$ic_alert,'WebPrd قابل‌دکوپ نیست');
        self::nv_chip('بدون GUID سایت',$missg,'t-amber',$ic_key,'نیاز به اتصال');
        self::nv_chip('موجودی هماهنگ',$st_ok,'t-green',$ic_box,'از نمونهٔ بررسی‌شده');
        self::nv_chip('اختلاف موجودی',$st_mis+$st_dif,'t-red',$ic_alert,'حسابداری ≠ سایت', $st_mis+$st_dif>0);
        echo '</div><div class="nv-cols">';

        // --- donut: stock harmony ---
        echo '<div class="nv-card"><h3>هماهنگی موجودی — حسابداری (PrdAnbarRelation) × سایت (Woo)</h3><div class="nv-ring"><svg viewBox="0 0 120 120"><defs><linearGradient id="nvGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#10b981"/></linearGradient></defs><circle class="track" cx="60" cy="60" r="52"/><circle class="val" cx="60" cy="60" r="52" data-nv-ring="'.$pct_ok.'" stroke-dasharray="326.7" stroke-dashoffset="326.7"/></svg><div class="center"><div><b data-nv-count="'.$pct_ok.'">0</b><span>درصد هماهنگ</span></div></div></div><div class="nv-legend"><span><i style="background:#10b981"></i>هماهنگ '.$st_ok.'</span><span><i style="background:#f59e0b"></i>در سایت ثبت نشده '.$st_mis.'</span><span><i style="background:#ef4444"></i>اختلاف عدد '.$st_dif.'</span><span><i style="background:#cbd5e1"></i>نامشخص '.$st_unk.'</span></div></div>';

        // --- freshness ---
        $fr=[['<24 ساعت','h24','#10b981',$fresh['h24']],['1 تا 7 روز','d7','#3b82f6',$fresh['d7']],['8 تا 30 روز','d30','#f59e0b',$fresh['d30']],['بیشتر از ۳۰ روز','old','#ef4444',$fresh['old']],['بدون تاریخ sync','none','#94a3b8',$fresh['none']]];
        echo '<div class="nv-card"><h3>تازگی آخرین Sync کالاها (متای _np-api-sync-date)</h3><div class="nv-segrow">';
        foreach($fr as $f){ $pct=(int)round(((int)$f[3]/$fresh_max)*100); echo '<div class="row"><span>'.$f[0].'</span><div class="nv-bar"><i data-nv-bar="'.$pct.'" style="background:'.$f[2].'"></i></div><b data-nv-count="'.(int)$f[3].'">0</b></div>'; }
        echo '</div><div class="nv-note">بر پایهٔ '.$sample.' کالای آخر دارای WebPrd؛ مقیاس نوارها نسبت به بیشترین سطل.</div></div>';

        // --- 7-day activity ---
        $max7=1; foreach($days as $d){ $max7=max($max7,(int)($c7[$d]??0),(int)($m7[$d]??0)); }
        echo '<div class="nv-card"><h3>فعالیت ۷ روز اخیر — تاریخ ووکامرس (GMT)</h3><div class="nv-bars7">';
        foreach($days as $d){ $cc=(int)($c7[$d]??0);$cm=(int)($m7[$d]??0);$hc=(int)round(($cc/$max7)*70);$hm=(int)round(($cm/$max7)*70);$lab=substr($d,8,2).'/'.substr($d,5,2); echo '<div class="day"><div class="bars"><i class="c" data-nv-h="'.$hc.'" title="ایجاد: '.$cc.'"></i><i class="m" data-nv-h="'.$hm.'" title="ویرایش: '.$cm.'"></i></div><small>'.$lab.'</small></div>'; }
        echo '</div><div class="nv-legend7"><span><i class="c" style="display:inline-block;width:9px;height:9px;border-radius:3px;margin-left:4px"></i>ایجاد شده</span><span><i class="m" style="display:inline-block;width:9px;height:9px;border-radius:3px;margin-left:4px"></i>ویرایش شده</span></div></div>';

        echo '</div>';
        // --- issues ---
        $total_mis = $st_mis + $st_dif;
        echo '<div class="nv-issues"><div style="margin-bottom:2px"><strong style="font-size:12px;color:#334155">کالاهایی که موجودی‌شان هنوز با حسابداری هماهنگ نشده:</strong></div>';
        echo '<div style="font-size:11px;color:#64748b;line-height:1.8;margin:0 0 6px">همگام‌سازی موجودی خودکار است: هنگام به‌روزرسانی کالا توسط نرم‌افزار حسابداری یا در اولین بازدید داشبورد، موجودی حسابداری (جمع Amount در PrdAnbarRelation) روی کالا اعمال می‌شود — بدون نیاز به تأیید.</div>';
        if($issues){
            foreach($issues as $it){
                $itype = ( isset($it['type']) && 'product_variation' === $it['type'] ) ? 'variation' : 'product';
                $ttl=self::item_title( $itype, (int)$it['id'] );
                $wv = isset($it['w']) && $it['w']!==null ? number_format_i18n((float)$it['w']) : 'ثبت نشده';
                                echo '<div class="nv-issue"><span class="nm">'.esc_html($ttl).'</span><span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><span class="nv-badge grn">حسابداری: '.esc_html(number_format_i18n((float)$it['a'])).'</span><span class="nv-badge red">سایت: '.esc_html($wv).'</span>&nbsp;<span class="nv-badge">اصلاح خودکار در نوبت</span></div>';
            }
        } else {
            echo '<div class="nv-issue"><span class="nm" style="color:#94a3b8">همهٔ کالاهای نمونه هماهنگ‌اند یا موجودی حسابداری ندارند.</span></div>';
        }
        echo '<div class="nv-note">توضیح منبع: موجودی حسابداری = جمع Amount داخل PrdAnbarRelation (در JSON زندهٔ شما مثل Amount:7.0 برای کالای 16212 است)؛ موجودی سایت = stock_quantity/متای _stock ووکامرس؛ «در سایت ثبت نشده» یعنی ووکامرس عددی ندارد ولی حسابداری دارد. نمونه = ۱۵۰ کالای آخر دارای WebPrd.</div></div></section>';
    }

    private static function nv_chip( $label, $value, $tone, $icon, $sub, $pulse = false ) {
        echo '<div class="nv-chip '.$tone.($pulse?' nv-pulse':'').'"><div class="nv-ic">'.$icon.'</div><div style="min-width:0"><b data-nv-count="'.(int)$value.'">0</b><span>'.esc_html($label).' · '.esc_html($sub).'</span></div></div>';
    }

    private static function icon($n){$i=['link'=>'<svg viewBox="0 0 24 24"><path d="M10.6 13.4a4.2 4.2 0 0 0 5.9 0l2.2-2.2a4.2 4.2 0 1 0-5.9-5.9l-1.2 1.2M13.4 10.6a4.2 4.2 0 0 0-5.9 0l-2.2 2.2a4.2 4.2 0 1 0 5.9 5.9l1.2-1.2"/></svg>','queue'=>'<svg viewBox="0 0 24 24"><path d="M5 7h14M5 12h14M5 17h9"/><circle cx="18" cy="17" r="2"/></svg>','alert'=>'<svg viewBox="0 0 24 24"><path d="m12 4 9 16H3L12 4Z"/><path d="M12 9v5M12 17h.01"/></svg>','speed'=>'<svg viewBox="0 0 24 24"><path d="M4 14a8 8 0 1 1 16 0"/><path d="m12 14 4-5"/><path d="M7 18h10"/></svg>','check'=>'<svg viewBox="0 0 24 24"><path d="m5 12 4 4 10-10"/></svg>','box'=>'<svg viewBox="0 0 24 24"><path d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Z"/><path d="m4 7.5 8 4 8-4M12 11.5V20"/></svg>','layers'=>'<svg viewBox="0 0 24 24"><path d="m12 4 8 4-8 4-8-4 8-4Z"/><path d="m4 12 8 4 8-4M4 16l8 4 8-4"/></svg>','key'=>'<svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="3"/><path d="m10.5 13 7-7 2 2-2 2 2 2-2 2-2-2-3.5 3.5"/></svg>','activity'=>'<svg viewBox="0 0 24 24"><path d="M3 12h4l2-6 4 12 2-6h6"/></svg>','edit'=>'<svg viewBox="0 0 24 24"><path d="m4 16 9.5-9.5a2.1 2.1 0 0 1 3 3L7 19H4v-3Z"/><path d="m13 7 4 4"/></svg>','lock'=>'<svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>','arrow'=>'<svg viewBox="0 0 24 24"><path d="M5 12h13M13 6l6 6-6 6"/></svg>','stock'=>'<svg viewBox="0 0 24 24"><path d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Z"/><path d="M4 7.5 12 11l8-3.5M12 11v9"/></svg>','sku'=>'<svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><path d="m10.5 10.5 8 8M13 5h6M16 8h3"/></svg>','barcode'=>'<svg viewBox="0 0 24 24"><path d="M4 5v14M7 5v14M10 5v14M14 5v14M17 5v14M20 5v14"/></svg>','price'=>'<svg viewBox="0 0 24 24"><path d="M4 7h10l6 5-6 5H4l4-5-4-5Z"/><circle cx="11" cy="12" r="1"/></svg>','group'=>'<svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/><path d="M11 7.5h2M7.5 11v2M16.5 11v2M11 16.5h2"/></svg>','clock'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>'];return str_replace('<svg viewBox=', '<svg width="20" height="20" aria-hidden="true" focusable="false" viewBox=', $i[$n]??'');}
}
