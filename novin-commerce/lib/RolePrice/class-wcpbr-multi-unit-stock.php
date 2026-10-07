<?php
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

use MobinDev\Novin_Commerce\Common\Accounting\Inventory_Scope;
use MobinDev\Novin_Commerce\Common\Accounting\Product_Health_Snapshot;
use MobinDev\Novin_Commerce\Common\Accounting\Unit_Engine;
use MobinDev\Novin_Commerce\Common\Accounting\WebPrd\WebPrd_Parser;

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
const ORDER_REDUCED_META    = '_novin_base_stock_reduced_v1';
const ORDER_RESTORED_META   = '_novin_base_stock_restored_v1';
const REFUND_RESTORED_META  = '_novin_base_stock_refund_v1';
const OPERATION_TABLE_SUFFIX = 'novin_commerce_stock_ops';

public function __construct() {
// سینک خودکار
add_action( 'admin_init',                          array( $this, 'maybe_sync_on_admin' ), 20 );
add_action( 'updated_post_meta',                   array( $this, 'sync_on_accounting_meta' ), 110, 4 );
add_action( 'added_post_meta',                     array( $this, 'sync_on_accounting_meta' ), 110, 4 );
// Stock synchronization is explicit/admin or driven by the accounting sync;
// a frontend product view must never write post meta or product transients.

// کاهش/افزایش موجودی هنگام سفارش
add_action( 'woocommerce_reduce_order_stock',  array( $this, 'reduce_base_stock_on_order' ), 20, 1 );
add_action( 'woocommerce_restore_order_stock', array( $this, 'restore_base_stock_on_order' ), 20, 1 );
add_action( 'woocommerce_order_partially_refunded', array( $this, 'restore_base_stock_on_refund' ), 20, 2 );
add_action( 'woocommerce_order_fully_refunded', array( $this, 'restore_base_stock_on_refund' ), 20, 2 );

// لایه امنیتی add-to-cart
add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 999, 4 );

// === حذف variation های ناموجود از UI ===
// 1) در سطح ووکامرس: variation ناموجود اصلاً visible نباشد
add_filter( 'woocommerce_variation_is_visible', array( $this, 'hide_outofstock_variation' ), 999, 4 );

// 2) در سطح attribute options: term مربوط به variation ناموجود در dropdown نباشد
add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( $this, 'filter_dropdown_options' ), 999, 1 );

// 3) در سطح آرایه variation های موجود: variation ناموجود حذف شود
add_filter( 'woocommerce_get_available_variations', array( $this, 'remove_outofstock_from_available' ), 999, 3 );
// A variable parent is an availability aggregate. It is never a second
// stock source, whether units are enabled or not.
add_filter( 'woocommerce_product_is_in_stock', array( $this, 'filter_parent_in_stock' ), 999, 2 );
add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_parent_purchasable' ), 999, 2 );
}

private function normalize( $value ) {
if ( ! is_string( $value ) ) return '';
$value = trim( $value );
$value = str_replace( array( ' ', "\xC2\xA0", "\xE2\x80\x8C" ), '', $value );
return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
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

public function sync_on_accounting_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
if ( ! in_array( $meta_key, array( 'WebPrd', '_np-api-sync-date' ), true ) || ! function_exists( 'wc_get_product' ) ) return;
$product = wc_get_product( absint( $object_id ) );
if ( ! $product ) return;
if ( $product->is_type( 'variation' ) ) $product = wc_get_product( $product->get_parent_id() );
if ( $product ) $this->sync_product_units( $product->get_id() );
}

public function maybe_sync_on_admin() {
global $pagenow;
if ( ! is_admin() ) return;
if ( 'post.php' !== $pagenow || empty( $_GET['post'] ) ) return;
if ( ! current_user_can( 'edit_products' ) ) return;

$post_id = absint( wp_unslash( $_GET['post'] ) );
if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) return;

$this->sync_product_units( $post_id );
}

public function sync_product_units( $product_id ) {
$product_id = absint( $product_id );
if ( ! $product_id ) return false;

$product = wc_get_product( $product_id );
if ( ! $product || ! $product->is_type( 'variable' ) ) return false;

$parser = WebPrd_Parser::from( $product->get_meta( 'WebPrd', true ) );
if ( ! $parser->is_valid() ) return false;
$unit_result = Unit_Engine::resolve_product( $product, $parser );
$mode = $unit_result['mode'];
$scope_config = Inventory_Scope::configuration();
$sync_hash = md5( $parser->hash() . '|' . serialize( $scope_config ) );
if ( get_post_meta( $product_id, '_novin_unit_mode', true ) === $mode && get_post_meta( $product_id, self::META_SYNC_MARKER, true ) === $sync_hash ) {
return true;
}

update_post_meta( $product_id, '_novin_unit_mode', $mode );
if ( Unit_Engine::MODE_MULTI_UNIT !== $mode ) {
// Normal Variable Products retain independently-managed child stock. The
// plugin never creates pack metadata for them and the parent is only an
// aggregate container, not a second inventory source.
$this->clear_multi_unit_metadata( $product );
$this->normalize_variable_parent( $product );
update_post_meta( $product_id, self::META_SYNC_MARKER, $sync_hash );
Product_Health_Snapshot::rebuild( $product_id, 'product' );
return Unit_Engine::MODE_UNRESOLVED !== $mode;
}

$scope = Inventory_Scope::aggregate( $parser, $scope_config );
if ( 'known' !== $scope['status'] || null === $scope['total'] ) {
// Never substitute Mojodi for a warehouse total. An unresolved scope is
// explicit and leaves normal variation stock untouched.
update_post_meta( $product_id, '_novin_unit_mode', Unit_Engine::MODE_UNRESOLVED );
$this->clear_multi_unit_metadata( $product );
$this->normalize_variable_parent( $product );
Product_Health_Snapshot::rebuild( $product_id, 'product' );
return false;
}

$base_stock = max( 0, (float) $scope['total'] );
update_post_meta( $product_id, self::META_BASE_STOCK, wc_format_decimal( $base_stock ) );
$mapped_ids = array();
foreach ( $unit_result['units'] as $unit ) {
$vid = absint( $unit['variation_id'] );
$variation = wc_get_product( $vid );
if ( ! $variation ) continue;
$mapped_ids[] = $vid;
update_post_meta( $vid, self::META_UNITS_PER_PACK, wc_format_decimal( $unit['units_per_pack'] ) );
update_post_meta( $vid, self::META_IS_BASE_UNIT, 'base' === $unit['unit_key'] ? 1 : 0 );
$this->apply_stock_to_variation( $variation, $base_stock, (float) $unit['units_per_pack'] );
Product_Health_Snapshot::rebuild( $vid, 'variation' );
}
if ( count( $mapped_ids ) !== count( (array) $product->get_children() ) ) {
update_post_meta( $product_id, '_novin_unit_mode', Unit_Engine::MODE_UNRESOLVED );
$this->clear_multi_unit_metadata( $product );
$this->normalize_variable_parent( $product );
Product_Health_Snapshot::rebuild( $product_id, 'product' );
return false;
}

$this->normalize_variable_parent( $product );
update_post_meta( $product_id, self::META_SYNC_MARKER, $sync_hash );
wc_delete_product_transients( $product_id );
Product_Health_Snapshot::rebuild( $product_id, 'product' );
return true;
}

private function clear_multi_unit_metadata( $product ) {
if ( ! ( $product instanceof WC_Product ) ) return;
delete_post_meta( $product->get_id(), self::META_BASE_STOCK );
delete_post_meta( $product->get_id(), self::META_SYNC_MARKER );
foreach ( (array) $product->get_children() as $vid ) {
delete_post_meta( absint( $vid ), self::META_UNITS_PER_PACK );
delete_post_meta( absint( $vid ), self::META_IS_BASE_UNIT );
wc_delete_product_transients( absint( $vid ) );
}
}

private function normalize_variable_parent( $product ) {
if ( ! ( $product instanceof WC_Product ) || ! $product->is_type( 'variable' ) ) return;
$available = $this->has_available_child( $product );
$changed = false;
if ( $product->get_manage_stock() ) { $product->set_manage_stock( false ); $changed = true; }
if ( null !== $product->get_stock_quantity() ) { $product->set_stock_quantity( null ); $changed = true; }
$status = $available ? 'instock' : 'outofstock';
if ( $product->get_stock_status() !== $status ) { $product->set_stock_status( $status ); $changed = true; }
if ( $changed ) $product->save();
}

private function has_available_child( $product ) {
if ( ! ( $product instanceof WC_Product ) || ! $product->is_type( 'variable' ) ) return false;
foreach ( (array) $product->get_children() as $vid ) {
$variation = wc_get_product( absint( $vid ) );
if ( ! $variation || 'publish' !== $variation->get_status() ) continue;
if ( $variation->is_in_stock() && $variation->is_purchasable() ) return true;
}
return false;
}

public function filter_parent_in_stock( $in_stock, $product ) {
if ( ! ( $product instanceof WC_Product ) || ! $product->is_type( 'variable' ) ) return $in_stock;
return $this->has_available_child( $product );
}

public function filter_parent_purchasable( $purchasable, $product ) {
if ( ! ( $product instanceof WC_Product ) || ! $product->is_type( 'variable' ) ) return $purchasable;
return $this->has_available_child( $product );
}

private function apply_stock_to_variation( $variation, $base_stock, $units_per_pack ) {
if ( ! ( $variation instanceof WC_Product ) ) return;
$calculated = Unit_Engine::calculate( $base_stock, $units_per_pack );
if ( ! $calculated ) return;
$stock = (int) $calculated['stock'];
$new_status = $stock > 0 ? 'instock' : 'outofstock';
$changed = false;
if ( ! $variation->get_manage_stock() ) { $variation->set_manage_stock( true ); $changed = true; }
if ( (int) $variation->get_stock_quantity() !== $stock ) { $variation->set_stock_quantity( $stock ); $changed = true; }
if ( $variation->get_stock_status() !== $new_status ) { $variation->set_stock_status( $new_status ); $changed = true; }
if ( $changed ) $variation->save();
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
if ( ! ( $order instanceof WC_Order ) ) return false;

// The order meta is useful for diagnostics, but it is not the concurrency
// primitive: two requests can read it before either save() completes. The
// stock-operation table is claimed in the same transaction as the decrement.
if ( $order->get_meta( self::ORDER_REDUCED_META, true ) ) return true;

$changes = $this->collect_order_stock_changes( $order );
if ( empty( $changes ) ) return true;

if ( ! $this->apply_base_stock_changes_atomically( $changes, false, 'reduce_order:' . $order->get_id(), $order->get_id() ) ) {
$this->log_stock_failure( $order->get_id(), 'کاهش اتمیک موجودی پایه انجام نشد؛ موجودی کافی یا متای معتبر یافت نشد.' );
return false;
}

$order->update_meta_data( self::ORDER_REDUCED_META, gmdate( 'c' ) );
$order->save();
$this->rebalance_parents( array_keys( $changes ) );
return true;
}

public function restore_base_stock_on_order( $order ) {
if ( ! ( $order instanceof WC_Order ) ) return false;
if ( ! $order->get_meta( self::ORDER_REDUCED_META, true ) || $order->get_meta( self::ORDER_RESTORED_META, true ) ) {
return true;
}

$changes = $this->collect_order_stock_changes( $order );
if ( empty( $changes ) ) return true;
if ( ! $this->apply_base_stock_changes_atomically( $changes, true, 'restore_order:' . $order->get_id(), $order->get_id() ) ) {
$this->log_stock_failure( $order->get_id(), 'بازگردانی اتمیک موجودی پایه انجام نشد.' );
return false;
}

$order->update_meta_data( self::ORDER_RESTORED_META, gmdate( 'c' ) );
$order->save();
$this->rebalance_parents( array_keys( $changes ) );
return true;
}

/**
 * Restore only the quantities represented by a WooCommerce refund. The
 * refund id is the idempotency key, so repeated full/partial refund hooks do
 * not duplicate stock.
 */
public function restore_base_stock_on_refund( $order_id, $refund_id ) {
$order  = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
$refund = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $refund_id ) ) : false;
if ( ! ( $order instanceof WC_Order ) || ! ( $refund instanceof WC_Order ) ) return false;
if ( ! $order->get_meta( self::ORDER_REDUCED_META, true ) || $order->get_meta( self::ORDER_RESTORED_META, true ) ) return true;
if ( $refund->get_meta( self::REFUND_RESTORED_META, true ) ) return true;

$changes = $this->collect_order_stock_changes( $refund, true );
if ( empty( $changes ) ) return true;
if ( ! $this->apply_base_stock_changes_atomically( $changes, true, 'refund:' . $refund->get_id(), $refund->get_id() ) ) {
$this->log_stock_failure( absint( $order_id ), 'بازگردانی موجودی برای refund انجام نشد.' );
return false;
}

$refund->update_meta_data( self::REFUND_RESTORED_META, gmdate( 'c' ) );
$refund->save();
$this->rebalance_parents( array_keys( $changes ) );
return true;
}

/**
 * @param WC_Order $order
 * @param bool     $refund Quantities in refund line items are negative.
 * @return array<int,int> Parent product id => base-unit delta.
 */
private function collect_order_stock_changes( $order, $refund = false ) {
$changes = array();
foreach ( $order->get_items() as $item ) {
if ( ! ( $item instanceof WC_Order_Item_Product ) ) continue;
$variation_id = absint( $item->get_variation_id() );
if ( ! $variation_id || ! $this->is_managed_variation( $variation_id ) ) continue;
$product_id = absint( $item->get_product_id() );
$quantity   = absint( abs( (int) $item->get_quantity() ) );
if ( ! $product_id || ! $quantity ) continue;
$units = max( 1, (int) get_post_meta( $variation_id, self::META_UNITS_PER_PACK, true ) );
$delta = $quantity * $units;
$changes[ $product_id ] = (int) ( $changes[ $product_id ] ?? 0 ) + $delta;
}
return $changes;
}

/**
 * Apply all parent deltas in one InnoDB transaction. A decrement updates only
 * when the current value is at least the requested amount; overselling is an
 * explicit failure, never silently clamped with max(0, ...). The operation
 * identity is inserted in the same transaction, so a duplicate WooCommerce
 * lifecycle hook cannot apply the delta twice even when order meta saves race.
 */
private function apply_base_stock_changes_atomically( array $changes, $addition, $operation_key, $entity_id ) {
global $wpdb;
$meta_table = $wpdb->postmeta;
$ops_table  = $wpdb->prefix . self::OPERATION_TABLE_SUFFIX;
if ( empty( $changes ) || '' === (string) $operation_key ) return true;

$operation_key = preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) $operation_key );
$operation_key = substr( $operation_key, 0, 100 );
if ( '' === $operation_key ) return false;

$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ops_table ) ) );
if ( $ops_table !== $table_exists ) {
// Without the verified idempotency table, fail closed rather than falling
// back to a non-atomic order-meta check.
return false;
}
if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
return false;
}

$claimed = $wpdb->query(
$wpdb->prepare(
"INSERT IGNORE INTO {$ops_table} (operation_key, entity_id, operation_type, created_at) VALUES (%s, %d, %s, %s)",
$operation_key,
absint( $entity_id ),
$addition ? 'restore' : 'reduce',
current_time( 'mysql', true )
)
);
if ( false === $claimed ) {
$wpdb->query( 'ROLLBACK' );
return false;
}
if ( 0 === (int) $claimed ) {
// The previous request committed this exact operation. There is no stock
// work left to do, but the caller may repair its CRUD marker.
if ( false === $wpdb->query( 'COMMIT' ) ) {
$wpdb->query( 'ROLLBACK' );
return false;
}
return true;
}

foreach ( $changes as $product_id => $delta ) {
$product_id = absint( $product_id );
$delta      = absint( $delta );
if ( ! $product_id || ! $delta ) continue;

$meta_id = $wpdb->get_var( $wpdb->prepare(
"SELECT meta_id FROM {$meta_table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
$product_id,
self::META_BASE_STOCK
) );
if ( ! $meta_id ) {
$wpdb->query( 'ROLLBACK' );
return false;
}

if ( $addition ) {
$sql = "UPDATE {$meta_table} SET meta_value = CAST(meta_value AS UNSIGNED) + %d WHERE meta_id = %d";
$result = $wpdb->query( $wpdb->prepare( $sql, $delta, absint( $meta_id ) ) );
} else {
$sql = "UPDATE {$meta_table} SET meta_value = CAST(meta_value AS UNSIGNED) - %d WHERE meta_id = %d AND CAST(meta_value AS UNSIGNED) >= %d";
$result = $wpdb->query( $wpdb->prepare( $sql, $delta, absint( $meta_id ), $delta ) );
}
if ( 1 !== (int) $result ) {
$wpdb->query( 'ROLLBACK' );
return false;
}
}

if ( false === $wpdb->query( 'COMMIT' ) ) {
$wpdb->query( 'ROLLBACK' );
return false;
}
foreach ( array_keys( $changes ) as $product_id ) {
clean_post_cache( absint( $product_id ) );
}
return true;
}

private function rebalance_parents( array $product_ids ) {
foreach ( $product_ids as $product_id ) {
$this->rebalance_variations( absint( $product_id ) );
}
}

private function log_stock_failure( $order_id, $message ) {
if ( class_exists( '\MobinDev\Novin_Commerce\Common\SyncLog' ) ) {
\MobinDev\Novin_Commerce\Common\SyncLog::add( 'stock', 'error', 'order', absint( $order_id ), $message );
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
Product_Health_Snapshot::rebuild( $vid, 'variation' );
wc_delete_product_transients( $vid );
}

wc_delete_product_transients( $product_id );
Product_Health_Snapshot::rebuild( $product_id, 'product' );
}
}
