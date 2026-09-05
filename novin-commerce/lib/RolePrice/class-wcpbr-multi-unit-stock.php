<?php
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * مدیریت موجودی چند-واحدی برای محصولات متغیر.
 *
 * === نکات مهم برای یکپارچگی افزونه ===
 * این ماژول فقط زمانی فعالانه در فروش دخالت می‌کند که:
 *   - محصول از نوع variable باشد
 *   - محصول متای WebPrd معتبر داشته باشد (یعنی از حسابداری سینک شده)
 *   - variation ها متای _wcpbr_units_per_pack داشته باشند
 *
 * اگر هیچ‌کدام از این شرایط نباشد، این ماژول کاملاً بی‌اثر است و در روند
 * عادی فروش محصولات ساده/متغیر دیگر (که با افزونه ما مدیریت نمی‌شوند)
 * هیچ اختلالی ایجاد نمی‌کند.
 *
 * === منطق ===
 * - داده حسابداری (WebPrd) شامل:
 *     * VahedName    : نام واحد پایه (مثلاً "دستگاه")
 *     * V2Qt         : تعداد واحد پایه در واحد دوم (مثلاً 10 = هر کارتن 10 دستگاه)
 *     * PrdAnbarRelation[].Amount : موجودی کل بر حسب واحد پایه
 *
 * - برای هر variation:
 *     * اگر attribute آن با VahedName مطابقت داشته باشد → units_per_pack = 1
 *     * در غیر این صورت (مثل "کارتن") → units_per_pack = V2Qt
 *
 * - موجودی هر variation = floor(base_stock / units_per_pack)
 *   اگر < 1 باشد → آن variation کاملاً از UI حذف می‌شود (unpurchasable + hidden از dropdown)
 *
 * === استراتژی حذف از dropdown ===
 * برای اینکه variation های ناموجود در dropdown "واحد" نمایش داده نشوند،
 * از فیلتر woocommerce_variation_is_visible استفاده می‌کنیم که استاندارد ووکامرس
 * است و اکثر قالب‌ها به آن احترام می‌گذارند. همچنین با فیلتر
 * woocommerce_product_get_variation_attributes، term های مربوط به variation های
 * ناموجود را از لیست attribute های قابل انتخاب حذف می‌کنیم.
 */
class NovinCommerce_RolePrice_Multi_Unit_Stock {

const META_BASE_STOCK      = '_wcpbr_base_stock';
const META_UNITS_PER_PACK  = '_wcpbr_units_per_pack';
const META_IS_BASE_UNIT    = '_wcpbr_is_base_unit';
const META_SYNC_MARKER     = '_wcpbr_units_sync_hash';

public function __construct() {
// سینک خودکار
add_action( 'admin_init',                          array( $this, 'maybe_sync_on_admin' ), 20 );
add_action( 'woocommerce_before_single_product',   array( $this, 'maybe_sync_on_front' ), 20 );

// کاهش/افزایش موجودی هنگام سفارش
add_action( 'woocommerce_reduce_order_stock',  array( $this, 'reduce_base_stock_on_order' ), 20, 1 );
add_action( 'woocommerce_restore_order_stock', array( $this, 'restore_base_stock_on_order' ), 20, 1 );

// لایه امنیتی add-to-cart
add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 999, 4 );

// === حذف variation های ناموجود از UI ===
// 1) در سطح ووکامرس: variation ناموجود اصلاً visible نباشد
add_filter( 'woocommerce_variation_is_visible', array( $this, 'hide_outofstock_variation' ), 999, 4 );

// 2) در سطح attribute options: term مربوط به variation ناموجود در dropdown نباشد
add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( $this, 'filter_dropdown_options' ), 999, 1 );

// 3) در سطح آرایه variation های موجود: variation ناموجود حذف شود
add_filter( 'woocommerce_get_available_variations', array( $this, 'remove_outofstock_from_available' ), 999, 3 );
}

/**
 * تشخیص اینکه آیا این variation تحت مدیریت افزونه ماست یا نه.
 */
private function is_managed_variation( $variation_id ) {
$variation_id = absint( $variation_id );
if ( ! $variation_id ) return false;
$units = get_post_meta( $variation_id, self::META_UNITS_PER_PACK, true );
return ( '' !== $units && null !== $units && (int) $units >= 1 );
}

/**
 * چک اینکه محصول والد تحت مدیریت افزونه ماست یا نه.
 */
private function is_managed_product( $product_id ) {
$product_id = absint( $product_id );
if ( ! $product_id ) return false;
$base_stock = get_post_meta( $product_id, self::META_BASE_STOCK, true );
return ( '' !== $base_stock && null !== $base_stock );
}

/* ------------------------------------------------------------------ */
/* سینک خودکار                                                          */
/* ------------------------------------------------------------------ */

public function maybe_sync_on_admin() {
global $pagenow;
if ( ! is_admin() ) return;
if ( 'post.php' !== $pagenow || empty( $_GET['post'] ) ) return;
if ( ! current_user_can( 'edit_products' ) ) return;

$post_id = absint( wp_unslash( $_GET['post'] ) );
if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) return;

$this->sync_product_units( $post_id );
}

public function maybe_sync_on_front() {
global $product;
if ( ! ( $product instanceof WC_Product ) ) return;

$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
$this->sync_product_units( $product_id );
}

public function sync_product_units( $product_id ) {
$product_id = absint( $product_id );
if ( ! $product_id ) return;

$product = wc_get_product( $product_id );
if ( ! $product || ! $product->is_type( 'variable' ) ) return;

$webprd_raw = get_post_meta( $product_id, 'WebPrd', true );
$info       = $this->extract_unit_info( $webprd_raw );
if ( ! $info ) return;

$sync_hash = md5( (string) $webprd_raw );
if ( get_post_meta( $product_id, self::META_SYNC_MARKER, true ) === $sync_hash ) {
return;
}

update_post_meta( $product_id, self::META_BASE_STOCK, $info['base_stock'] );

$variation_ids = $product->get_children();
if ( empty( $variation_ids ) ) return;

$base_unit_name = $this->normalize( $info['base_unit_name'] );
$v2_qty         = $info['v2_qty'];

foreach ( $variation_ids as $vid ) {
$vid       = absint( $vid );
$variation = wc_get_product( $vid );
if ( ! $variation ) continue;

$attrs = $variation->get_attributes();
$is_base_unit = false;

foreach ( $attrs as $attr_key => $attr_value ) {
$term_name = $this->get_readable_attribute_value( $attr_key, $attr_value );
$normalized_value = $this->normalize( $term_name );

if ( $normalized_value === $base_unit_name ) {
$is_base_unit = true;
break;
}
}

if ( $is_base_unit ) {
update_post_meta( $vid, self::META_UNITS_PER_PACK, 1 );
update_post_meta( $vid, self::META_IS_BASE_UNIT, 1 );
$this->apply_stock_to_variation( $variation, $info['base_stock'], 1 );
} else {
$units = $v2_qty > 0 ? $v2_qty : 1;
update_post_meta( $vid, self::META_UNITS_PER_PACK, $units );
update_post_meta( $vid, self::META_IS_BASE_UNIT, 0 );
$this->apply_stock_to_variation( $variation, $info['base_stock'], $units );
}

wc_delete_product_transients( $vid );
}

update_post_meta( $product_id, self::META_SYNC_MARKER, $sync_hash );
wc_delete_product_transients( $product_id );
}

private function apply_stock_to_variation( $variation, $base_stock, $units_per_pack ) {
if ( ! ( $variation instanceof WC_Product ) ) return;
if ( $units_per_pack < 1 ) $units_per_pack = 1;

$stock = (int) floor( $base_stock / $units_per_pack );
if ( $stock < 0 ) $stock = 0;

$new_status = $stock > 0 ? 'instock' : 'outofstock';
$changed = false;

if ( ! $variation->get_manage_stock() ) {
$variation->set_manage_stock( true );
$changed = true;
}
if ( (int) $variation->get_stock_quantity() !== $stock ) {
$variation->set_stock_quantity( $stock );
$changed = true;
}
if ( $variation->get_stock_status() !== $new_status ) {
$variation->set_stock_status( $new_status );
$changed = true;
}

if ( $changed ) {
$variation->save();
}
}

private function extract_unit_info( $webprd_raw ) {
if ( empty( $webprd_raw ) || ! is_string( $webprd_raw ) ) return null;
if ( strlen( $webprd_raw ) > 500000 ) return null;

$data = json_decode( $webprd_raw, true, 10 );
if ( JSON_ERROR_NONE !== json_last_error() ) return null;
if ( ! is_array( $data ) ) return null;

$base_unit_name = isset( $data['VahedName'] ) && is_string( $data['VahedName'] ) ? trim( $data['VahedName'] ) : '';
$v2_qty         = isset( $data['V2Qt'] ) && is_numeric( $data['V2Qt'] ) ? (int) $data['V2Qt'] : 0;

if ( '' === $base_unit_name ) return null;

$base_stock = 0;
if ( ! empty( $data['PrdAnbarRelation'] ) && is_array( $data['PrdAnbarRelation'] ) ) {
foreach ( $data['PrdAnbarRelation'] as $anbar ) {
if ( is_array( $anbar ) && isset( $anbar['Amount'] ) && is_numeric( $anbar['Amount'] ) ) {
$base_stock += (float) $anbar['Amount'];
}
}
}
$base_stock = (int) floor( $base_stock );
if ( $base_stock < 0 ) $base_stock = 0;

return array(
'base_unit_name' => $base_unit_name,
'v2_qty'         => $v2_qty,
'base_stock'     => $base_stock,
);
}

private function get_readable_attribute_value( $attr_key, $attr_value ) {
if ( ! is_string( $attr_value ) || '' === $attr_value ) return '';

$decoded = urldecode( $attr_value );

if ( taxonomy_exists( $attr_key ) ) {
$term = get_term_by( 'slug', $decoded, $attr_key );
if ( $term && ! is_wp_error( $term ) ) {
return $term->name;
}
$term = get_term_by( 'slug', $attr_value, $attr_key );
if ( $term && ! is_wp_error( $term ) ) {
return $term->name;
}
$term = get_term_by( 'name', $decoded, $attr_key );
if ( $term && ! is_wp_error( $term ) ) {
return $term->name;
}
}

return $decoded;
}

private function normalize( $str ) {
if ( ! is_string( $str ) ) return '';
$str = trim( $str );
$str = str_replace( array( ' ', "\xC2\xA0", "\xE2\x80\x8C" ), '', $str );
$str = mb_strtolower( $str, 'UTF-8' );
return $str;
}

/* ------------------------------------------------------------------ */
/* حذف variation های ناموجود از UI                                      */
/* ------------------------------------------------------------------ */

/**
 * variation ناموجود اصلاً visible نباشد.
 * این فیلتر باعث می‌شود ووکامرس آن را در لیست variation های در دسترس نگذارد.
 */
public function hide_outofstock_variation( $visible, $variation_id, $parent_id, $variation = null ) {
if ( ! $this->is_managed_variation( $variation_id ) ) return $visible;

$v = $variation ? $variation : wc_get_product( $variation_id );
if ( ! ( $v instanceof WC_Product ) ) return $visible;

if ( 'outofstock' === $v->get_stock_status() || (int) $v->get_stock_quantity() < 1 ) {
return false;
}
return $visible;
}

/**
 * از آرایه گزینه‌های dropdown، مقادیری که فقط مربوط به variation های ناموجود
 * هستند را حذف کن.
 */
public function filter_dropdown_options( $args ) {
if ( empty( $args['product'] ) || ! ( $args['product'] instanceof WC_Product ) ) return $args;

$product = $args['product'];
if ( ! $product->is_type( 'variable' ) ) return $args;
if ( ! $this->is_managed_product( $product->get_id() ) ) return $args;

$attribute = isset( $args['attribute'] ) ? $args['attribute'] : '';
if ( ! $attribute ) return $args;

$options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
if ( empty( $options ) ) return $args;

// برای هر option، بررسی کن آیا حداقل یک variation موجود با این مقدار وجود دارد
$in_stock_options = array();

foreach ( $options as $option ) {
if ( $this->option_has_instock_variation( $product, $attribute, $option ) ) {
$in_stock_options[] = $option;
}
}

$args['options'] = $in_stock_options;
return $args;
}

/**
 * بررسی می‌کند که آیا برای مقدار مشخص یک attribute، حداقل یک variation
 * موجود (in-stock) وجود دارد یا نه.
 */
private function option_has_instock_variation( $product, $attribute, $option ) {
$variation_ids = $product->get_children();
if ( empty( $variation_ids ) ) return false;

$option_normalized = $this->normalize( urldecode( $option ) );

foreach ( $variation_ids as $vid ) {
$v = wc_get_product( $vid );
if ( ! $v ) continue;

// اگر variation تحت مدیریت ما نیست، آن را in-stock فرض کن
if ( ! $this->is_managed_variation( $vid ) ) {
return true;
}

$attrs = $v->get_attributes();
$match = false;

foreach ( $attrs as $attr_key => $attr_value ) {
if ( $attr_key !== $attribute ) continue;
if ( $this->normalize( urldecode( $attr_value ) ) === $option_normalized ) {
$match = true;
break;
}
}

if ( ! $match ) continue;

// این variation مقدارش مطابق است؛ چک کن آیا موجود است
if ( 'instock' === $v->get_stock_status() && (int) $v->get_stock_quantity() >= 1 ) {
return true;
}
}

return false;
}

/**
 * از لیست available_variations که به JS ووکامرس می‌رود،
 * variation های ناموجود را حذف کن.
 */
public function remove_outofstock_from_available( $available, $product, $parent = null ) {
if ( ! is_array( $available ) ) return $available;

$parent_obj = $parent ? $parent : $product;
if ( ! ( $parent_obj instanceof WC_Product ) ) return $available;
if ( ! $this->is_managed_product( $parent_obj->get_id() ) ) return $available;

$filtered = array();
foreach ( $available as $var_data ) {
if ( empty( $var_data['variation_id'] ) ) continue;
$vid = absint( $var_data['variation_id'] );

if ( ! $this->is_managed_variation( $vid ) ) {
$filtered[] = $var_data;
continue;
}

$v = wc_get_product( $vid );
if ( ! $v ) continue;

if ( 'outofstock' === $v->get_stock_status() || (int) $v->get_stock_quantity() < 1 ) {
// حذف می‌شود
continue;
}
$filtered[] = $var_data;
}

return array_values( $filtered );
}

/* ------------------------------------------------------------------ */
/* لایه امنیتی add-to-cart                                              */
/* ------------------------------------------------------------------ */

public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
$variation_id = absint( $variation_id );
if ( ! $variation_id ) return $passed;
if ( ! $this->is_managed_variation( $variation_id ) ) return $passed;

$variation = wc_get_product( $variation_id );
if ( ! $variation ) return $passed;

if ( 'outofstock' === $variation->get_stock_status() || (int) $variation->get_stock_quantity() < 1 ) {
wc_add_notice( esc_html__( 'این محصول در واحد انتخابی شما ناموجود است.', 'novin-commerce' ), 'error' );
return false;
}

if ( (int) $variation->get_stock_quantity() < $quantity ) {
wc_add_notice(
sprintf(
/* translators: %d: available quantity */
esc_html__( 'حداکثر تعداد قابل خرید در واحد انتخابی: %d', 'novin-commerce' ),
$variation->get_stock_quantity()
),
'error'
);
return false;
}

return $passed;
}

/* ------------------------------------------------------------------ */
/* مدیریت موجودی هنگام سفارش                                            */
/* ------------------------------------------------------------------ */

public function reduce_base_stock_on_order( $order ) {
if ( ! ( $order instanceof WC_Order ) ) return;

$affected_parents = array();

foreach ( $order->get_items() as $item ) {
if ( ! ( $item instanceof WC_Order_Item_Product ) ) continue;

$variation_id = $item->get_variation_id();
if ( ! $variation_id ) continue;
if ( ! $this->is_managed_variation( $variation_id ) ) continue;

$product_id = $item->get_product_id();
$qty        = (int) $item->get_quantity();
if ( $qty <= 0 || ! $product_id ) continue;

$units_per_pack = (int) get_post_meta( $variation_id, self::META_UNITS_PER_PACK, true );
if ( $units_per_pack < 1 ) $units_per_pack = 1;

$base_reduction = $qty * $units_per_pack;

$current_base = (int) get_post_meta( $product_id, self::META_BASE_STOCK, true );
$new_base     = $current_base - $base_reduction;
if ( $new_base < 0 ) $new_base = 0;

update_post_meta( $product_id, self::META_BASE_STOCK, $new_base );
$affected_parents[ $product_id ] = true;
}

foreach ( array_keys( $affected_parents ) as $pid ) {
$this->rebalance_variations( $pid );
}
}

public function restore_base_stock_on_order( $order ) {
if ( ! ( $order instanceof WC_Order ) ) return;

$affected_parents = array();

foreach ( $order->get_items() as $item ) {
if ( ! ( $item instanceof WC_Order_Item_Product ) ) continue;

$variation_id = $item->get_variation_id();
if ( ! $variation_id ) continue;
if ( ! $this->is_managed_variation( $variation_id ) ) continue;

$product_id = $item->get_product_id();
$qty        = (int) $item->get_quantity();
if ( $qty <= 0 || ! $product_id ) continue;

$units_per_pack = (int) get_post_meta( $variation_id, self::META_UNITS_PER_PACK, true );
if ( $units_per_pack < 1 ) $units_per_pack = 1;

$base_addition = $qty * $units_per_pack;

$current_base = (int) get_post_meta( $product_id, self::META_BASE_STOCK, true );
$new_base     = $current_base + $base_addition;

update_post_meta( $product_id, self::META_BASE_STOCK, $new_base );
$affected_parents[ $product_id ] = true;
}

foreach ( array_keys( $affected_parents ) as $pid ) {
$this->rebalance_variations( $pid );
}
}

private function rebalance_variations( $product_id ) {
$product = wc_get_product( $product_id );
if ( ! $product || ! $product->is_type( 'variable' ) ) return;

$base_stock = (int) get_post_meta( $product_id, self::META_BASE_STOCK, true );

$variation_ids = $product->get_children();
if ( empty( $variation_ids ) ) return;

foreach ( $variation_ids as $vid ) {
if ( ! $this->is_managed_variation( $vid ) ) continue;

$variation = wc_get_product( $vid );
if ( ! $variation ) continue;

$units = (int) get_post_meta( $vid, self::META_UNITS_PER_PACK, true );
if ( $units < 1 ) $units = 1;

$this->apply_stock_to_variation( $variation, $base_stock, $units );
wc_delete_product_transients( $vid );
}

wc_delete_product_transients( $product_id );
}
}
