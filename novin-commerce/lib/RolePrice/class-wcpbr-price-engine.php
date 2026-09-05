<?php
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * موتور اصلی قیمت‌گذاری بر اساس نقش کاربر (نسخه پایدار، بدون دیباگ).
 *
 * ویژگی‌ها:
 * - اولویت فیلترها بسیار بالا (999999) تا بعد از تمام افزونه‌های دیگر اجرا شود.
 * - پشتیبانی کامل از محصولات ساده و متغیر.
 * - برای محصول متغیر: چک variation ها برای تشخیص وجود قیمت نقش.
 * - در صورت نبود قیمت برای نقش و فعال بودن گزینه «تماس بگیرید»:
 *     * قیمت به‌صورت متن «تماس بگیرید» نمایش داده می‌شود.
 *     * دکمه افزودن به سبد خرید غیرفعال می‌شود.
 *     * اعتبارسنجی add-to-cart جلوی خرید مستقیم را می‌گیرد.
 * - کش قیمت variation ها بر اساس نقش کاربر تفکیک می‌شود.
 *
 * === یکپارچگی با سایت‌های دیگر ===
 * این کلاس فقط زمانی روی قیمت‌گذاری اثر می‌گذارد که:
 *   - کاربر لاگین‌کرده باشد
 *   - نقش کاربر جزو نقش‌های فعال (تیک‌خورده) در تنظیمات افزونه باشد
 * در غیر این صورت، قیمت‌گذاری استاندارد ووکامرس بدون تغییر باقی می‌ماند.
 */
class NovinCommerce_RolePrice_Price_Engine {

private $role_cache = null;
private $has_role_price_cache = array();
private $effective_price_cache = array();

const FILTER_PRIORITY = 999999;

public function __construct() {
$p = self::FILTER_PRIORITY;

add_filter( 'woocommerce_product_get_price',                array( $this, 'filter_price' ), $p, 2 );
add_filter( 'woocommerce_product_get_regular_price',        array( $this, 'filter_regular_price' ), $p, 2 );
add_filter( 'woocommerce_product_get_sale_price',           array( $this, 'filter_sale_price' ), $p, 2 );

add_filter( 'woocommerce_product_variation_get_price',          array( $this, 'filter_price' ), $p, 2 );
add_filter( 'woocommerce_product_variation_get_regular_price',  array( $this, 'filter_regular_price' ), $p, 2 );
add_filter( 'woocommerce_product_variation_get_sale_price',     array( $this, 'filter_sale_price' ), $p, 2 );

add_filter( 'woocommerce_product_is_on_sale', array( $this, 'filter_is_on_sale' ), $p, 2 );

add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_price_in_cart' ), $p, 1 );

add_filter( 'woocommerce_get_price_html', array( $this, 'maybe_show_contact_us' ), $p, 2 );

add_filter( 'woocommerce_is_purchasable',            array( $this, 'maybe_disable_purchase' ), $p, 2 );
add_filter( 'woocommerce_variation_is_purchasable',  array( $this, 'maybe_disable_purchase' ), $p, 2 );

add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), $p, 4 );

add_filter( 'woocommerce_variation_prices_price',          array( $this, 'filter_price' ), $p, 2 );
add_filter( 'woocommerce_variation_prices_regular_price',  array( $this, 'filter_regular_price' ), $p, 2 );
add_filter( 'woocommerce_variation_prices_sale_price',     array( $this, 'filter_sale_price' ), $p, 2 );

add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'add_role_to_price_hash' ), $p, 3 );
}

private function get_role() {
if ( null === $this->role_cache ) {
$this->role_cache = NovinCommerce_RolePrice_Roles::get_current_user_role();
}
return $this->role_cache;
}

public function add_role_to_price_hash( $hash, $product, $for_display ) {
if ( ! is_array( $hash ) ) $hash = array();
$role   = $this->get_role();
$hash[] = $role ? $role : 'no-role';
return $hash;
}

private function get_valid_product_id( $product ) {
if ( ! ( $product instanceof WC_Product ) ) return null;
$product_id = absint( $product->get_id() );
return $product_id > 0 ? $product_id : null;
}

private function is_known_role( $role ) {
if ( ! is_string( $role ) || '' === $role ) return false;
return array_key_exists( $role, NovinCommerce_RolePrice_Roles::get_roles() );
}

private function get_role_regular_raw( $post_id, $role ) {
$post_id = absint( $post_id );
if ( ! $post_id || ! $this->is_known_role( $role ) ) return '';
return get_post_meta( $post_id, NovinCommerce_RolePrice_Roles::regular_meta_key( $role ), true );
}

private function get_role_sale_raw( $post_id, $role ) {
$post_id = absint( $post_id );
if ( ! $post_id || ! $this->is_known_role( $role ) ) return '';
return get_post_meta( $post_id, NovinCommerce_RolePrice_Roles::sale_meta_key( $role ), true );
}

private function is_valid_price( $raw ) {
if ( '' === $raw || null === $raw ) return false;
if ( is_array( $raw ) ) return false;
$str = is_string( $raw ) ? trim( $raw ) : (string) $raw;
if ( '' === $str ) return false;
if ( ! is_numeric( $str ) ) return false;
return (float) $str > 0;
}

private function role_has_price_for_product( $product, $role ) {
if ( ! ( $product instanceof WC_Product ) ) return false;
if ( ! $this->is_known_role( $role ) ) return false;

$product_id = $this->get_valid_product_id( $product );
if ( ! $product_id ) return false;

$cache_key = $product_id . ':' . $role;
if ( array_key_exists( $cache_key, $this->has_role_price_cache ) ) {
return $this->has_role_price_cache[ $cache_key ];
}

if ( $product->is_type( 'variable' ) ) {
$variation_ids = $product->get_children();
if ( empty( $variation_ids ) ) return false;

foreach ( $variation_ids as $vid ) {
$vprice = $this->get_role_regular_raw( $vid, $role );
if ( $this->is_valid_price( $vprice ) ) {
$this->has_role_price_cache[ $cache_key ] = true;
return true;
}
}
$this->has_role_price_cache[ $cache_key ] = false;
return false;
}

$regular = $this->get_role_regular_raw( $product_id, $role );
$result = $this->is_valid_price( $regular );
$this->has_role_price_cache[ $cache_key ] = $result;
return $result;
}

private function get_effective_price_for_post( $post_id, $role ) {
$cache_key = absint( $post_id ) . ':' . $role;
if ( array_key_exists( $cache_key, $this->effective_price_cache ) ) {
return $this->effective_price_cache[ $cache_key ];
}

$regular_raw = $this->get_role_regular_raw( $post_id, $role );
if ( ! $this->is_valid_price( $regular_raw ) ) {
$this->effective_price_cache[ $cache_key ] = null;
return null;
}

$regular = wc_format_decimal( $regular_raw );
$sale_raw = $this->get_role_sale_raw( $post_id, $role );

if ( $this->is_valid_price( $sale_raw ) ) {
$sale = wc_format_decimal( $sale_raw );
if ( (float) $sale < (float) $regular ) {
$this->effective_price_cache[ $cache_key ] = $sale;
return $sale;
}
}
$this->effective_price_cache[ $cache_key ] = $regular;
return $regular;
}

public function filter_price( $price, $product ) {
$role = $this->get_role();
if ( ! $role ) return $price;

$product_id = $this->get_valid_product_id( $product );
if ( ! $product_id ) return $price;

if ( $product->is_type( 'variable' ) ) {
if ( ! $this->role_has_price_for_product( $product, $role ) && class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) {
return '';
}
return $price;
}

$effective = $this->get_effective_price_for_post( $product_id, $role );
if ( null === $effective ) {
if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) {
return '';
}
return $price;
}
return $effective;
}

public function filter_regular_price( $price, $product ) {
$role = $this->get_role();
if ( ! $role ) return $price;

$product_id = $this->get_valid_product_id( $product );
if ( ! $product_id ) return $price;

if ( $product->is_type( 'variable' ) ) {
if ( ! $this->role_has_price_for_product( $product, $role ) ) {
if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) return '';
return $price;
}
return $price;
}

$regular_raw = $this->get_role_regular_raw( $product_id, $role );
if ( ! $this->is_valid_price( $regular_raw ) ) {
if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) return '';
return $price;
}
return wc_format_decimal( $regular_raw );
}

public function filter_sale_price( $price, $product ) {
$role = $this->get_role();
if ( ! $role ) return $price;

$product_id = $this->get_valid_product_id( $product );
if ( ! $product_id ) return $price;

if ( $product->is_type( 'variable' ) ) {
if ( ! $this->role_has_price_for_product( $product, $role ) ) {
if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) return '';
return $price;
}
return $price;
}

$sale_raw = $this->get_role_sale_raw( $product_id, $role );
if ( ! $this->is_valid_price( $sale_raw ) ) {
return $price;
}
return wc_format_decimal( $sale_raw );
}

public function filter_is_on_sale( $on_sale, $product ) {
$role = $this->get_role();
if ( ! $role ) return $on_sale;

$product_id = $this->get_valid_product_id( $product );
if ( ! $product_id ) return $on_sale;

if ( $product->is_type( 'variable' ) ) {
if ( ! $this->role_has_price_for_product( $product, $role ) ) return false;
return $on_sale;
}

if ( ! $this->role_has_price_for_product( $product, $role ) ) return false;

$regular = $this->get_role_regular_raw( $product_id, $role );
$sale    = $this->get_role_sale_raw( $product_id, $role );

if ( $this->is_valid_price( $sale ) && $this->is_valid_price( $regular )
&& (float) $sale < (float) $regular ) {
return true;
}
return false;
}

public function apply_price_in_cart( $cart ) {
if ( ! ( $cart instanceof WC_Cart ) ) return;
if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

$role = $this->get_role();
if ( ! $role ) return;

foreach ( $cart->get_cart() as $cart_item ) {
if ( empty( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof WC_Product ) ) continue;

/** @var WC_Product $product */
$product    = $cart_item['data'];
$product_id = $this->get_valid_product_id( $product );
if ( ! $product_id ) continue;

$effective = $this->get_effective_price_for_post( $product_id, $role );
if ( null !== $effective && is_numeric( $effective ) ) {
$product->set_price( $effective );
}
}
}

public function maybe_show_contact_us( $price_html, $product ) {
$role = $this->get_role();
if ( ! $role ) return $price_html;

if ( ! ( $product instanceof WC_Product ) ) return $price_html;

if ( $this->role_has_price_for_product( $product, $role ) ) {
return $price_html;
}

if ( ! class_exists( 'NovinCommerce_RolePrice_Settings' ) || ! NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) {
return $price_html;
}

return '<span class="wcpbr-contact-us">' . esc_html( NovinCommerce_RolePrice_Settings::get_contact_us_text() ) . '</span>';
}

public function maybe_disable_purchase( $purchasable, $product ) {
$role = $this->get_role();
if ( ! $role ) return $purchasable;

if ( ! class_exists( 'NovinCommerce_RolePrice_Settings' ) || ! NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) {
return $purchasable;
}

if ( ! ( $product instanceof WC_Product ) ) return $purchasable;

if ( ! $this->role_has_price_for_product( $product, $role ) ) {
return false;
}
return $purchasable;
}

public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
$role = $this->get_role();
if ( ! $role ) return $passed;

if ( ! class_exists( 'NovinCommerce_RolePrice_Settings' ) || ! NovinCommerce_RolePrice_Settings::is_contact_us_enabled() ) {
return $passed;
}

$product_id   = absint( $product_id );
$variation_id = absint( $variation_id );

$check_id = $variation_id ? $variation_id : $product_id;
if ( ! $check_id ) return $passed;

$check_product = wc_get_product( $check_id );
if ( ! $check_product ) return $passed;

if ( ! $this->role_has_price_for_product( $check_product, $role ) ) {
wc_add_notice( esc_html( NovinCommerce_RolePrice_Settings::get_contact_us_text() ), 'error' );
return false;
}
return $passed;
}
}
