<?php
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * داشبورد تنظیمات افزونه.
 *
 * سه بخش این صفحه:
 * - تنظیمات عمومی (پیام «تماس بگیرید»)
 * - مدیریت نقش‌ها (جدول یکپارچه تمام نقش‌های وردپرس + افزودن نقش)
 * - مغایرت‌گیری قیمت‌ها (اسکن محصولات بدون قیمت برای نقش‌های فعال)
 *
 * منطق اسکن مغایرت:
 * - فقط نقش‌های فعال (تیک‌خورده) در اسکن دخالت دارند.
 * - ابتدا متای افزونه (_wcpbr_regular_price_*) چک می‌شود.
 * - اگر متای افزونه پیدا نشد، متای WebPrd (منبع حسابداری) چک می‌شود.
 * - محصولی مغایرت است که در «هر دو منبع» برای هیچ نقش فعالی قیمت نداشته باشد.
 */
class NovinCommerce_RolePrice_Settings {

const OPTION_GENERAL       = 'wcpbr_general_settings';
const CAPABILITY           = 'manage_woocommerce';
const MENU_SLUG            = 'novin-commerce-role-pricing';
const SCAN_RESULT_OPTION   = 'wcpbr_mismatch_scan_result';
const SCAN_RESULT_TIME_KEY = 'wcpbr_mismatch_scan_time';
const NONCE_ACTION         = 'novin_commerce_role_ajax_nonce';
const ACCESS_OPTION        = 'wcpbr_admin_access';
private static $instance;
public static function get_instance() { return self::$instance; }

/**
 * نگاشت نام نقش در WebPrd به کلید نقش داخلی وردپرس.
 */
private static function webprd_role_name_map() {
return array(
'Editor'      => 'editor',
'Contributor' => 'contributor',
'Subscriber'  => 'subscriber',
'Author'      => 'author',
'Customer'    => 'customer',
);
}

public function __construct() {
self::$instance = $this;
add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 60 );

add_action( 'wp_ajax_novin_commerce_role_save_general', array( $this, 'ajax_save_general' ) );
add_action( 'wp_ajax_novin_commerce_role_save_roles', array( $this, 'ajax_save_roles' ) );
add_action( 'wp_ajax_novin_commerce_role_add_role', array( $this, 'ajax_add_role' ) );
add_action( 'wp_ajax_novin_commerce_role_run_mismatch_scan', array( $this, 'ajax_run_mismatch_scan' ) );
}

public function register_admin_menu() {
return;
add_submenu_page(
'novin-commerce-products',
__( 'قیمت و نقش کاربران', 'novin-commerce' ),
__( 'قیمت و نقش کاربران', 'novin-commerce' ),
self::CAPABILITY,
self::MENU_SLUG,
array( $this, 'render_settings_page' )
);
}

public static function get_admin_access_config() {
$roles = wp_roles()->get_names();
$stored = get_option( self::ACCESS_OPTION, array() );
if ( ! is_array( $stored ) ) $stored = array();
$result = array();
foreach ( $roles as $key => $label ) $result[ sanitize_key( $key ) ] = 'administrator' === $key ? true : ! empty( $stored[ $key ] );
return $result;
}
public static function role_can_access_admin( $role ) {
if ( 'administrator' === $role ) return true;
$config = self::get_admin_access_config();
return ! empty( $config[ $role ] );
}
public static function save_admin_access( $posted ) {
if ( ! current_user_can( 'manage_options' ) || ! is_array( $posted ) ) return false;
$config = array();
foreach ( wp_roles()->get_names() as $key => $label ) if ( 'administrator' !== $key && ! empty( $posted[ $key ] ) ) $config[ sanitize_key( $key ) ] = true;
return update_option( self::ACCESS_OPTION, $config, false );
}
public function render_embedded_roles_tab() {
if ( ! current_user_can( 'manage_options' ) ) return '';
$html = $this->get_roles_page_html();
ob_start(); ?>
<div class="wcpbr-access-box">
<h3>دسترسی به پیشخوان و نوار مدیریت</h3>
<p>به صورت پیش‌فرض فقط مدیر کل به پیشخوان دسترسی دارد. فعال کردن این گزینه فقط محدودیت NovinCommerce را برای آن نقش برمی‌دارد. سطح دسترسی واقعی WordPress همچنان توسط Capabilityهای همان نقش کنترل می‌شود.</p>
<form method="post">
<?php wp_nonce_field( 'novin-role-access' ); ?>
<table class="widefat striped"><thead><tr><th>نقش</th><th>اجازه پیشخوان</th><th>وضعیت نوار مدیریت</th></tr></thead><tbody>
<?php foreach ( wp_roles()->get_names() as $key => $label ) : $allowed = self::role_can_access_admin( $key ); ?>
<tr><td><strong><?php echo esc_html( translate_user_role( $label ) ); ?></strong> <code><?php echo esc_html( $key ); ?></code></td><td><label><input type="checkbox" name="novin_admin_access[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $allowed ); ?> <?php disabled( 'administrator' === $key ); ?> /> اجازه ورود به پیشخوان</label></td><td><?php echo $allowed ? '<span style="color:#15803d;font-weight:700">فعال</span>' : '<span style="color:#64748b">مخفی</span>'; ?></td></tr>
<?php endforeach; ?></tbody></table>
<p><button class="button button-primary" name="novin_save_admin_access" value="1">ذخیره دسترسی‌ها</button></p>
</form></div>
<?php return $html . ob_get_clean();
}

private function is_our_settings_page() {
if ( ! is_admin() ) return false;
if ( ! isset( $_GET['page'] ) ) return false;
return self::MENU_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) );
}

/* ------------------------------------------------------------------ */
/* دسترسی استاتیک به تنظیمات عمومی                                     */
/* ------------------------------------------------------------------ */

public static function get_general_settings() {
$defaults = array(
'contact_us_enabled' => true,
'contact_us_text'    => __( 'تماس بگیرید', 'novin-commerce' ),
);
$settings = get_option( self::OPTION_GENERAL, array() );
if ( ! is_array( $settings ) ) $settings = array();
return wp_parse_args( $settings, $defaults );
}

public static function is_contact_us_enabled() {
$settings = self::get_general_settings();
return ! empty( $settings['contact_us_enabled'] );
}

public static function get_contact_us_text() {
$settings = self::get_general_settings();
$text = isset( $settings['contact_us_text'] ) ? trim( (string) $settings['contact_us_text'] ) : '';
return '' !== $text ? $text : __( 'تماس بگیرید', 'novin-commerce' );
}

/* ------------------------------------------------------------------ */
/* رندر صفحه اصلی                                                       */
/* ------------------------------------------------------------------ */

public function render_settings_page() {
if ( ! current_user_can( self::CAPABILITY ) ) {
wp_die( esc_html__( 'دسترسی لازم را ندارید.', 'novin-commerce' ) );
}

$general_html  = $this->get_general_page_html();
$roles_html    = $this->get_roles_page_html();
$mismatch_html = $this->get_mismatch_page_html();

$nonce = wp_create_nonce( self::NONCE_ACTION );
?>
<div class="wrap wcpbr-settings-wrap" id="wcpbr-settings-wrap" data-nonce="<?php echo esc_attr( $nonce ); ?>">

<h1 class="wp-heading-inline"><?php esc_html_e( 'قیمت بر اساس نقش کاربری', 'novin-commerce' ); ?></h1>
<hr class="wp-header-end" />

<div class="wcpbr-notice-area" id="wcpbr-notice-area"></div>

<h2 class="nav-tab-wrapper wcpbr-subnav">
<a href="#" class="nav-tab nav-tab-active" data-wcpbr-tab="general"><?php esc_html_e( 'تنظیمات عمومی', 'novin-commerce' ); ?></a>
<a href="#" class="nav-tab" data-wcpbr-tab="roles"><?php esc_html_e( 'مدیریت نقش‌ها', 'novin-commerce' ); ?></a>
<a href="#" class="nav-tab" data-wcpbr-tab="mismatch"><?php esc_html_e( 'مغایرت‌گیری قیمت‌ها', 'novin-commerce' ); ?></a>
</h2>

<div class="wcpbr-panel" id="wcpbr-panel-general"><?php echo $general_html; // phpcs:ignore ?></div>
<div class="wcpbr-panel" id="wcpbr-panel-roles" style="display:none;"><?php echo $roles_html; // phpcs:ignore ?></div>
<div class="wcpbr-panel" id="wcpbr-panel-mismatch" style="display:none;"><?php echo $mismatch_html; // phpcs:ignore ?></div>

</div>

<style>
.wcpbr-settings-wrap { margin: 20px 20px 40px 0; padding: 0; box-sizing: border-box; }
.wcpbr-settings-wrap * { box-sizing: border-box; }
.wcpbr-settings-wrap .wcpbr-subnav { margin: 20px 0; padding: 0; }
.wcpbr-settings-wrap .wcpbr-panel {
background: #fff;
border: 1px solid #ccd0d4;
border-radius: 4px;
padding: 24px 28px;
margin: 0 0 24px;
}
.wcpbr-settings-wrap .wcpbr-panel h2.wcpbr-section-title {
margin: 0 0 8px;
padding: 0;
font-size: 16px;
font-weight: 600;
}
.wcpbr-settings-wrap .wcpbr-panel h3.wcpbr-subsection-title {
margin: 0 0 8px;
padding: 0;
font-size: 14px;
font-weight: 600;
}
.wcpbr-settings-wrap .wcpbr-panel > p.description:first-of-type { margin-top: 0; }
.wcpbr-settings-wrap .form-table { margin: 16px 0 0; width: 100%; }
.wcpbr-settings-wrap .form-table th {
padding: 16px 0 16px 16px;
width: 260px;
vertical-align: top;
text-align: right;
font-weight: 600;
}
.wcpbr-settings-wrap .form-table td { padding: 12px 0; vertical-align: middle; }
.wcpbr-settings-wrap table.widefat { margin: 16px 0; border-radius: 4px; overflow: hidden; width: 100%; }
.wcpbr-settings-wrap table.widefat th,
.wcpbr-settings-wrap table.widefat td { padding: 10px 12px; vertical-align: middle; }
.wcpbr-settings-wrap .wcpbr-actions-row {
margin-top: 20px;
padding-top: 16px;
border-top: 1px solid #f0f0f1;
display: flex;
align-items: center;
gap: 12px;
flex-wrap: wrap;
}
.wcpbr-settings-wrap .description { margin-top: 6px; color: #646970; }
.wcpbr-settings-wrap hr { margin: 32px 0; border: none; border-top: 1px solid #dcdcde; }
.wcpbr-settings-wrap input.regular-text { width: 100%; max-width: 380px; }
.wcpbr-settings-wrap .wcpbr-role-active-cell { text-align: center; width: 70px; }
.wcpbr-settings-wrap .wcpbr-role-key-cell code { direction: ltr; display: inline-block; }
.wcpbr-settings-wrap .wcpbr-role-wp-name { color: #2271b1; font-weight: 600; }
#wcpbr-notice-area .notice { margin: 16px 0; }
.wcpbr-info-box {
background: #f0f6fc;
border-right: 4px solid #2271b1;
padding: 12px 16px;
margin: 12px 0 20px;
border-radius: 3px;
}
.wcpbr-info-box p { margin: 4px 0; }
</style>

<script>
jQuery(document).ready(function($){
var $wrap = $('#wcpbr-settings-wrap');
if ($wrap.length === 0) return;

var nonce   = $wrap.data('nonce');
var ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

function showNotice(message, type) {
var cls = (type === 'error') ? 'notice-error' : 'notice-success';
var $n = $('<div class="notice ' + cls + ' is-dismissible"><p></p></div>');
$n.find('p').text(message);
$('#wcpbr-notice-area').empty().append($n);
$('html, body').animate({ scrollTop: $wrap.offset().top - 40 }, 200);
}

function submitFormAsAjax(formSelector, action, busyText, errorMsg, onSuccess){
var $form = $(formSelector);
if ($form.length === 0) return;

var $btn = $form.find('button[type="submit"]').first();
var originalText = $btn.text();
$btn.prop('disabled', true).text(busyText);

$.post(ajaxUrl, $form.serialize() + '&action=' + action + '&_ajax_nonce=' + nonce)
.done(function(res){
if (res && res.success) {
showNotice(res.data.message, 'success');
if (typeof onSuccess === 'function') {
onSuccess(res);
}
} else {
showNotice((res && res.data && res.data.message) ? res.data.message : errorMsg, 'error');
}
})
.fail(function(){
showNotice('خطا در ارتباط با سرور', 'error');
})
.always(function(){
$btn.prop('disabled', false).text(originalText);
});
}

$wrap.on('click', '.wcpbr-subnav .nav-tab', function(e){
e.preventDefault();
var tab = $(this).data('wcpbr-tab');
$wrap.find('.wcpbr-subnav .nav-tab').removeClass('nav-tab-active');
$(this).addClass('nav-tab-active');
$wrap.find('.wcpbr-panel').hide();
$wrap.find('#wcpbr-panel-' + tab).show();
});

$wrap.on('submit', '#wcpbr-form-general', function(e){
e.preventDefault();
submitFormAsAjax(
'#wcpbr-form-general',
'novin_commerce_role_save_general',
'در حال ذخیره...',
'خطا در ذخیره تنظیمات'
);
});

$wrap.on('submit', '#wcpbr-form-roles', function(e){
e.preventDefault();
submitFormAsAjax(
'#wcpbr-form-roles',
'novin_commerce_role_save_roles',
'در حال ذخیره...',
'خطا در ذخیره نقش‌ها',
function(res){
if (res.data.roles_html) {
$('#wcpbr-roles-table-wrapper').html(res.data.roles_html);
}
if (res.data.existing_options_html) {
$('#existing_role').html(res.data.existing_options_html);
}
}
);
});

$wrap.on('submit', '#wcpbr-form-add-role', function(e){
e.preventDefault();
submitFormAsAjax(
'#wcpbr-form-add-role',
'novin_commerce_role_add_role',
'در حال افزودن...',
'خطا در افزودن نقش',
function(res){
if (res.data.roles_html) {
$('#wcpbr-roles-table-wrapper').html(res.data.roles_html);
}
if (res.data.existing_options_html) {
$('#existing_role').html(res.data.existing_options_html);
}
var addForm = $('#wcpbr-form-add-role')[0];
if (addForm) addForm.reset();
$wrap.find('.wcpbr-mode-existing').show();
$wrap.find('.wcpbr-mode-new').hide();
}
);
});

$wrap.on('submit', '#wcpbr-form-mismatch', function(e){
e.preventDefault();
submitFormAsAjax(
'#wcpbr-form-mismatch',
'novin_commerce_role_run_mismatch_scan',
'در حال اسکن...',
'خطا در اسکن مغایرت',
function(res){
if (res.data.results_html) {
$('#wcpbr-mismatch-results').html(res.data.results_html);
}
if (res.data.scan_time) {
$('#wcpbr-last-scan-time').text(res.data.scan_time);
}
}
);
});

$wrap.on('change', 'input[name="add_role_mode"]', function(){
var mode = $wrap.find('input[name="add_role_mode"]:checked').val();
$wrap.find('.wcpbr-mode-existing').toggle(mode === 'existing');
$wrap.find('.wcpbr-mode-new').toggle(mode === 'new');
});
});
</script>
<?php
}

/* ------------------------------------------------------------------ */
/* اعتبارسنجی AJAX                                                     */
/* ------------------------------------------------------------------ */

private function verify_ajax_request() {
check_ajax_referer( self::NONCE_ACTION );
if ( ! current_user_can( self::CAPABILITY ) ) {
wp_send_json_error( array( 'message' => __( 'شما دسترسی لازم برای این عملیات را ندارید.', 'novin-commerce' ) ) );
}
}

/* ------------------------------------------------------------------ */
/* AJAX Handlers                                                       */
/* ------------------------------------------------------------------ */

public function ajax_save_general() {
$this->verify_ajax_request();

$contact_us_enabled = ! empty( $_POST['contact_us_enabled'] );
$contact_us_text    = isset( $_POST['contact_us_text'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_us_text'] ) ) : '';
$contact_us_text    = mb_substr( $contact_us_text, 0, 100 );

update_option( self::OPTION_GENERAL, array(
'contact_us_enabled' => $contact_us_enabled,
'contact_us_text'    => $contact_us_text,
) );

wp_send_json_success( array( 'message' => __( 'تنظیمات عمومی ذخیره شد.', 'novin-commerce' ) ) );
}

public function ajax_save_roles() {
$this->verify_ajax_request();

$config       = NovinCommerce_RolePrice_Roles::get_all_configured_roles();
$all_wp_roles = NovinCommerce_RolePrice_Roles::get_all_wp_roles();
$posted_keys  = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();

$new_config = array();

foreach ( $all_wp_roles as $role_key => $role_name ) {
$role_key = sanitize_key( $role_key );
$posted   = isset( $posted_keys[ $role_key ] ) && is_array( $posted_keys[ $role_key ] ) ? $posted_keys[ $role_key ] : array();

$existing_label = isset( $config[ $role_key ]['label'] ) ? $config[ $role_key ]['label'] : $role_name;
$label          = isset( $posted['label'] ) ? sanitize_text_field( $posted['label'] ) : $existing_label;
$label          = mb_substr( trim( $label ), 0, 60 );
$active         = ! empty( $posted['active'] );

$new_config[ $role_key ] = array(
'label'  => '' !== $label ? $label : $role_key,
'active' => $active,
);
}

NovinCommerce_RolePrice_Roles::save_roles_config( $new_config );

wp_send_json_success( array(
'message'               => __( 'تنظیمات نقش‌ها ذخیره شد.', 'novin-commerce' ),
'roles_html'            => $this->render_roles_table_html(),
'existing_options_html' => $this->render_existing_role_options_html(),
) );
}

public function ajax_add_role() {
$this->verify_ajax_request();

if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => __( 'برای مدیریت نقش‌ها باید مدیر کل سایت باشید.', 'novin-commerce' ) ) );
}

$mode = isset( $_POST['add_role_mode'] ) ? sanitize_key( wp_unslash( $_POST['add_role_mode'] ) ) : 'existing';

if ( 'existing' === $mode ) {
$role_key = isset( $_POST['existing_role'] ) ? sanitize_key( wp_unslash( $_POST['existing_role'] ) ) : '';
$label    = isset( $_POST['existing_role_label'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_role_label'] ) ) : '';

if ( ! $role_key || ! NovinCommerce_RolePrice_Roles::wp_role_exists( $role_key ) ) {
wp_send_json_error( array( 'message' => __( 'نقش انتخاب‌شده معتبر نیست.', 'novin-commerce' ) ) );
}

$config              = NovinCommerce_RolePrice_Roles::get_all_configured_roles();
$config[ $role_key ] = array(
'label'  => '' !== trim( $label ) ? mb_substr( trim( $label ), 0, 60 ) : $role_key,
'active' => true,
);
NovinCommerce_RolePrice_Roles::save_roles_config( $config );

wp_send_json_success( array(
'message'               => __( 'نقش با موفقیت افزوده شد.', 'novin-commerce' ),
'roles_html'            => $this->render_roles_table_html(),
'existing_options_html' => $this->render_existing_role_options_html(),
) );
}

if ( 'new' === $mode ) {
$new_role_slug  = isset( $_POST['new_role_slug'] ) ? sanitize_key( wp_unslash( $_POST['new_role_slug'] ) ) : '';
$new_role_label = isset( $_POST['new_role_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_role_label'] ) ) : '';

if ( ! preg_match( '/^[a-z0-9_\-]{3,32}$/', $new_role_slug ) ) {
wp_send_json_error( array( 'message' => __( 'شناسه نقش باید فقط شامل حروف انگلیسی کوچک، عدد، خط تیره یا آندرلاین باشد (۳ تا ۳۲ کاراکتر).', 'novin-commerce' ) ) );
}

$reserved = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager' );
if ( in_array( $new_role_slug, $reserved, true ) || NovinCommerce_RolePrice_Roles::wp_role_exists( $new_role_slug ) ) {
wp_send_json_error( array( 'message' => __( 'این شناسه نقش قبلاً وجود دارد یا رزرو شده است.', 'novin-commerce' ) ) );
}

$new_role_label = mb_substr( trim( $new_role_label ), 0, 60 );
if ( '' === $new_role_label ) $new_role_label = $new_role_slug;

$added = add_role( $new_role_slug, $new_role_label, array( 'read' => true ) );

if ( null === $added ) {
wp_send_json_error( array( 'message' => __( 'ساخت نقش جدید ناموفق بود (ممکن است از قبل وجود داشته باشد).', 'novin-commerce' ) ) );
}

$config = NovinCommerce_RolePrice_Roles::get_all_configured_roles();
$config[ $new_role_slug ] = array( 'label' => $new_role_label, 'active' => true );
NovinCommerce_RolePrice_Roles::save_roles_config( $config );

wp_send_json_success( array(
'message'               => __( 'نقش جدید وردپرسی ساخته و به تنظیمات افزوده شد.', 'novin-commerce' ),
'roles_html'            => $this->render_roles_table_html(),
'existing_options_html' => $this->render_existing_role_options_html(),
) );
}

wp_send_json_error( array( 'message' => __( 'روش افزودن نامعتبر است.', 'novin-commerce' ) ) );
}

public function ajax_run_mismatch_scan() {
$this->verify_ajax_request();

$result = $this->scan_price_mismatches();

update_option( self::SCAN_RESULT_OPTION, $result, false );
$scan_time = current_time( 'mysql' );
update_option( self::SCAN_RESULT_TIME_KEY, $scan_time, false );

wp_send_json_success( array(
'message'      => __( 'اسکن مغایرت قیمت‌ها با موفقیت انجام شد.', 'novin-commerce' ),
'results_html' => $this->render_mismatch_results_html( $result ),
'scan_time'    => $scan_time,
) );
}

private function scan_price_mismatches() {
global $wpdb;

$active_roles = NovinCommerce_RolePrice_Roles::get_roles();
if ( empty( $active_roles ) ) return array();

$active_role_keys = array_map( 'sanitize_key', array_keys( $active_roles ) );

$results = array();
$args = array(
'status'  => array( 'publish' ),
'type'    => array( 'simple', 'variable' ),
'limit'   => -1,
'return'  => 'ids',
'orderby' => 'ID',
'order'   => 'ASC',
);
$max_products = 5000;

$product_ids = wc_get_products( $args );
if ( ! is_array( $product_ids ) ) return array();

$product_ids = array_slice( $product_ids, 0, $max_products );

foreach ( $product_ids as $product_id ) {
$product = wc_get_product( $product_id );
if ( ! $product ) continue;

$has_any_price        = false;
$missing_roles_labels = array();

$ids_to_check = array( (int) $product_id );
if ( $product->is_type( 'variable' ) ) {
$variation_ids = $product->get_children();
if ( ! empty( $variation_ids ) ) {
foreach ( $variation_ids as $vid ) {
$ids_to_check[] = (int) $vid;
}
}
}
$ids_to_check = array_unique( array_filter( array_map( 'absint', $ids_to_check ) ) );
if ( empty( $ids_to_check ) ) continue;

$webprd_raw    = get_post_meta( $product_id, 'WebPrd', true );
$webprd_prices = $this->extract_webprd_role_prices( $webprd_raw );

foreach ( $active_role_keys as $role_key ) {
$role_label     = $active_roles[ $role_key ];
$role_has_price = false;

$meta_key        = NovinCommerce_RolePrice_Roles::regular_meta_key( $role_key );
$ids_placeholder = implode( ',', array_fill( 0, count( $ids_to_check ), '%d' ) );
$query = $wpdb->prepare(
"SELECT meta_value FROM {$wpdb->postmeta}
 WHERE meta_key = %s
 AND post_id IN ($ids_placeholder)",
array_merge( array( $meta_key ), $ids_to_check )
);
$values = $wpdb->get_col( $query );

if ( ! empty( $values ) ) {
foreach ( $values as $v ) {
if ( $this->is_valid_price( $v ) ) {
$role_has_price = true;
break;
}
}
}

if ( ! $role_has_price && ! empty( $webprd_prices ) ) {
if ( isset( $webprd_prices[ $role_key ] ) && $this->is_valid_price( $webprd_prices[ $role_key ] ) ) {
$role_has_price = true;
}
}

if ( $role_has_price ) {
$has_any_price = true;
} else {
$missing_roles_labels[] = $role_label;
}
}

if ( ! $has_any_price ) {
$results[] = array(
'id'    => $product_id,
'sku'   => $product->get_sku(),
'name'  => $product->get_name(),
'slug'  => $product->get_slug(),
'edit'  => get_edit_post_link( $product_id, 'raw' ),
'roles' => $missing_roles_labels,
);
}
}

return $results;
}

private function extract_webprd_role_prices( $webprd_raw ) {
$result = array();

if ( empty( $webprd_raw ) || ! is_string( $webprd_raw ) ) return $result;
if ( strlen( $webprd_raw ) > 500000 ) return $result;

$data = json_decode( $webprd_raw, true, 10 );
if ( JSON_ERROR_NONE !== json_last_error() ) return $result;
if ( ! is_array( $data ) || empty( $data['PriceRoleList'] ) || ! is_array( $data['PriceRoleList'] ) ) return $result;

$map = self::webprd_role_name_map();

foreach ( $data['PriceRoleList'] as $entry ) {
if ( ! is_array( $entry ) ) continue;
if ( empty( $entry['WordPressRoleName'] ) || ! is_string( $entry['WordPressRoleName'] ) ) continue;
if ( ! isset( $entry['Price'] ) ) continue;

$np_name = trim( $entry['WordPressRoleName'] );
if ( ! isset( $map[ $np_name ] ) ) continue;

$internal_role = $map[ $np_name ];
$price         = $entry['Price'];

if ( is_numeric( $price ) && (float) $price > 0 ) {
$result[ $internal_role ] = (string) $price;
}
}

return $result;
}

private function is_valid_price( $raw ) {
if ( null === $raw ) return false;

if ( is_array( $raw ) ) {
foreach ( $raw as $v ) {
if ( $this->is_valid_price( $v ) ) return true;
}
return false;
}

$val = is_string( $raw ) ? trim( $raw ) : (string) $raw;
if ( '' === $val ) return false;

$cleaned = preg_replace( '/[^\d\.\-]/', '', $val );
if ( '' === $cleaned ) return false;
if ( ! is_numeric( $cleaned ) ) return false;

$num = (float) $cleaned;
if ( $num <= 0 ) return false;

return true;
}

/* ------------------------------------------------------------------ */
/* HTML builders                                                       */
/* ------------------------------------------------------------------ */

private function get_general_page_html() {
$settings = self::get_general_settings();
ob_start();
?>
<h2 class="wcpbr-section-title"><?php esc_html_e( 'تنظیمات عمومی', 'novin-commerce' ); ?></h2>
<form id="wcpbr-form-general">
<table class="form-table" role="presentation">
<tr>
<th scope="row"><?php esc_html_e( 'نمایش پیام «تماس بگیرید»', 'novin-commerce' ); ?></th>
<td>
<label>
<input type="checkbox" name="contact_us_enabled" value="1" <?php checked( ! empty( $settings['contact_us_enabled'] ) ); ?> />
<?php esc_html_e( 'در صورت خالی بودن قیمت نقش کاربر، به‌جای قیمت صفر یا خالی، این پیام نمایش داده شود و خرید غیرفعال گردد.', 'novin-commerce' ); ?>
</label>
<p class="description"><?php esc_html_e( 'در صورت غیرفعال بودن، اگر برای نقشی قیمت ثبت نشده باشد، قیمت پیش‌فرض محصول (Customer) نمایش داده می‌شود و خرید مسدود نخواهد شد.', 'novin-commerce' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="contact_us_text"><?php esc_html_e( 'متن پیام', 'novin-commerce' ); ?></label></th>
<td>
<input type="text" id="contact_us_text" name="contact_us_text" class="regular-text" maxlength="100" value="<?php echo esc_attr( $settings['contact_us_text'] ); ?>" />
</td>
</tr>
</table>
<div class="wcpbr-actions-row">
<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تنظیمات', 'novin-commerce' ); ?></button>
</div>
</form>
<?php
return ob_get_clean();
}

private function get_roles_page_html() {
ob_start();
?>
<h2 class="wcpbr-section-title"><?php esc_html_e( 'نقش‌های فعال برای قیمت‌گذاری', 'novin-commerce' ); ?></h2>

<div class="wcpbr-info-box">
<p><strong>⚠️ توجه مهم:</strong> «نام نمایشی سفارشی» که در این جدول وارد می‌کنید، فقط داخل همین افزونه استفاده می‌شود (روی فیلدهای قیمت محصول).</p>
<p>در صفحه ویرایش کاربر (کاربران → همه کاربران → ویرایش)، شما باید نقش را با <strong>«نام نمایشی وردپرس»</strong> که در ستون دوم جدول زیر نشان داده شده، انتخاب کنید.</p>
<p>مثال: اگر نقش <code>editor</code> را با نام سفارشی «همکار درجه ۱» تعریف کرده‌اید، در صفحه ویرایش کاربر باید گزینه <strong>«ویرایشگر»</strong> را انتخاب کنید (نه «همکار درجه ۱»).</p>
</div>

<form id="wcpbr-form-roles">
<div id="wcpbr-roles-table-wrapper"><?php echo $this->render_roles_table_html(); // phpcs:ignore ?></div>
<div class="wcpbr-actions-row">
<button type="submit" class="button button-primary"><?php esc_html_e( 'ذخیره تغییرات نقش‌ها', 'novin-commerce' ); ?></button>
</div>
</form>

<hr />

<h3 class="wcpbr-subsection-title"><?php esc_html_e( 'افزودن نقش جدید', 'novin-commerce' ); ?></h3>
<form id="wcpbr-form-add-role">
<table class="form-table" role="presentation">
<tr>
<th scope="row"><?php esc_html_e( 'روش افزودن', 'novin-commerce' ); ?></th>
<td>
<label style="margin-inline-end:16px;">
<input type="radio" name="add_role_mode" value="existing" checked="checked" />
<?php esc_html_e( 'انتخاب از نقش‌های موجود وردپرس', 'novin-commerce' ); ?>
</label>
<label>
<input type="radio" name="add_role_mode" value="new" />
<?php esc_html_e( 'ساخت نقش وردپرسی کاملاً جدید', 'novin-commerce' ); ?>
</label>
</td>
</tr>
<tr class="wcpbr-mode-existing">
<th scope="row"><label for="existing_role"><?php esc_html_e( 'نقش وردپرسی', 'novin-commerce' ); ?></label></th>
<td>
<select name="existing_role" id="existing_role">
<?php echo $this->render_existing_role_options_html(); // phpcs:ignore ?>
</select>
</td>
</tr>
<tr class="wcpbr-mode-existing">
<th scope="row"><label for="existing_role_label"><?php esc_html_e( 'نام نمایشی سفارشی (اختیاری)', 'novin-commerce' ); ?></label></th>
<td><input type="text" id="existing_role_label" name="existing_role_label" class="regular-text" maxlength="60" /></td>
</tr>
<tr class="wcpbr-mode-new" style="display:none;">
<th scope="row"><label for="new_role_slug"><?php esc_html_e( 'شناسه نقش (انگلیسی)', 'novin-commerce' ); ?></label></th>
<td>
<input type="text" id="new_role_slug" name="new_role_slug" class="regular-text" maxlength="32" placeholder="wholesale_partner" />
<p class="description"><?php esc_html_e( 'فقط حروف انگلیسی کوچک، عدد، خط تیره یا آندرلاین - بین ۳ تا ۳۲ کاراکتر.', 'novin-commerce' ); ?></p>
</td>
</tr>
<tr class="wcpbr-mode-new" style="display:none;">
<th scope="row"><label for="new_role_label"><?php esc_html_e( 'نام نمایشی', 'novin-commerce' ); ?></label></th>
<td><input type="text" id="new_role_label" name="new_role_label" class="regular-text" maxlength="60" /></td>
</tr>
</table>
<?php if ( current_user_can( 'manage_options' ) ) : ?>
<div class="wcpbr-actions-row">
<button type="submit" class="button button-primary"><?php esc_html_e( 'افزودن نقش', 'novin-commerce' ); ?></button>
</div>
<?php else : ?>
<p class="description"><?php esc_html_e( 'افزودن نقش فقط توسط مدیر کل سایت امکان‌پذیر است.', 'novin-commerce' ); ?></p>
<?php endif; ?>
</form>
<?php
return ob_get_clean();
}

private function get_mismatch_page_html() {
$results      = get_option( self::SCAN_RESULT_OPTION, null );
$scan_time    = get_option( self::SCAN_RESULT_TIME_KEY, '' );
$active_roles = NovinCommerce_RolePrice_Roles::get_roles();
ob_start();
?>
<h2 class="wcpbr-section-title"><?php esc_html_e( 'مغایرت‌گیری قیمت‌ها', 'novin-commerce' ); ?></h2>
<p class="description">
<?php esc_html_e( 'این بخش محصولاتی را نمایش می‌دهد که برای «هیچ‌کدام» از نقش‌های فعال (تیک‌خورده) قیمت ندارند. اسکن هم متای افزونه و هم داده حسابداری (WebPrd) را چک می‌کند.', 'novin-commerce' ); ?>
</p>

<?php if ( ! empty( $active_roles ) ) : ?>
<p class="description">
<strong><?php esc_html_e( 'نقش‌های فعال فعلی در این اسکن:', 'novin-commerce' ); ?></strong>
<?php echo esc_html( implode( '، ', $active_roles ) ); ?>
</p>
<?php else : ?>
<div class="notice notice-warning inline"><p>
<?php esc_html_e( 'هیچ نقش فعالی برای قیمت‌گذاری تعریف نشده است. ابتدا از تب «مدیریت نقش‌ها» نقش‌های موردنظر را فعال کنید.', 'novin-commerce' ); ?>
</p></div>
<?php endif; ?>

<form id="wcpbr-form-mismatch">
<div class="wcpbr-actions-row">
<button type="submit" class="button button-primary"><?php esc_html_e( 'اسکن مغایرت', 'novin-commerce' ); ?></button>
<span style="color:#646970;">
<?php esc_html_e( 'آخرین اسکن:', 'novin-commerce' ); ?>
<strong id="wcpbr-last-scan-time"><?php echo esc_html( $scan_time ? $scan_time : '—' ); ?></strong>
</span>
</div>
</form>
<div id="wcpbr-mismatch-results" style="margin-top:20px;"><?php echo $this->render_mismatch_results_html( $results ); // phpcs:ignore ?></div>
<?php
return ob_get_clean();
}

/**
 * جدول نقش‌ها با ستون‌های:
 * - فعال (چک‌باکس)
 * - شناسه فنی وردپرس (editor, author, ...)
 * - نام نمایشی وردپرس (ویرایشگر, نویسنده, ...) - همان چیزی که در صفحه ویرایش کاربر دیده می‌شود
 * - نام نمایشی سفارشی (اختیاری - فقط داخل این افزونه)
 */
private function render_roles_table_html() {
$config       = NovinCommerce_RolePrice_Roles::get_all_configured_roles();
$all_wp_roles = NovinCommerce_RolePrice_Roles::get_all_wp_roles();

// ترجمه نام‌های نقش را از وردپرس بگیریم (اگر ترجمه فارسی نصب باشد، فارسی نمایش می‌دهد)
$translated_names = array();
foreach ( $all_wp_roles as $role_key => $role_name ) {
$translated_names[ $role_key ] = translate_user_role( $role_name );
}

ob_start();
?>
<table class="widefat striped">
<thead>
<tr>
<th class="wcpbr-role-active-cell"><?php esc_html_e( 'فعال', 'novin-commerce' ); ?></th>
<th><?php esc_html_e( 'شناسه فنی وردپرس', 'novin-commerce' ); ?></th>
<th><?php esc_html_e( 'نام نمایشی وردپرس', 'novin-commerce' ); ?><br /><small style="font-weight:normal;color:#646970;">(همان چیزی که در ویرایش کاربر می‌بینید)</small></th>
<th><?php esc_html_e( 'نام نمایشی سفارشی (فقط داخل این افزونه)', 'novin-commerce' ); ?></th>
</tr>
</thead>
<tbody>
<?php if ( ! empty( $all_wp_roles ) ) : ?>
<?php foreach ( $all_wp_roles as $role_key => $role_name ) :
$existing        = isset( $config[ $role_key ] ) ? $config[ $role_key ] : null;
$active          = ! empty( $existing['active'] );
$label           = ( $existing && isset( $existing['label'] ) && '' !== trim( (string) $existing['label'] ) ) ? $existing['label'] : $role_name;
$translated_name = isset( $translated_names[ $role_key ] ) ? $translated_names[ $role_key ] : $role_name;
?>
<tr>
<td class="wcpbr-role-active-cell">
<input type="checkbox" name="roles[<?php echo esc_attr( $role_key ); ?>][active]" value="1" <?php checked( $active ); ?> />
</td>
<td class="wcpbr-role-key-cell">
<code><?php echo esc_html( $role_key ); ?></code>
</td>
<td>
<span class="wcpbr-role-wp-name"><?php echo esc_html( $translated_name ); ?></span>
</td>
<td>
<input type="text" class="regular-text" maxlength="60" name="roles[<?php echo esc_attr( $role_key ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" />
</td>
</tr>
<?php endforeach; ?>
<?php else : ?>
<tr><td colspan="4"><?php esc_html_e( 'هیچ نقشی در وردپرس یافت نشد.', 'novin-commerce' ); ?></td></tr>
<?php endif; ?>
</tbody>
</table>
<p class="description">
<?php esc_html_e( '«نام نمایشی سفارشی» فقط در همین افزونه (فیلدهای قیمت محصول) استفاده می‌شود؛ نام واقعی نقش در وردپرس تغییر نمی‌کند و در صفحه ویرایش کاربر همچنان با «نام نمایشی وردپرس» نشان داده می‌شود.', 'novin-commerce' ); ?>
</p>
<?php
return ob_get_clean();
}

/**
 * گزینه‌های select برای افزودن نقش جدید.
 * هر آیتم شامل شناسه فنی + نام نمایشی وردپرس (ترجمه‌شده) است.
 */
private function render_existing_role_options_html() {
$config       = NovinCommerce_RolePrice_Roles::get_all_configured_roles();
$all_wp_roles = NovinCommerce_RolePrice_Roles::get_all_wp_roles();
ob_start();
?>
<option value=""><?php esc_html_e( '— انتخاب کنید —', 'novin-commerce' ); ?></option>
<?php foreach ( $all_wp_roles as $role_key => $role_name ) :
if ( isset( $config[ $role_key ] ) ) continue;
$translated = translate_user_role( $role_name );
?>
<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $translated . ' (' . $role_key . ')' ); ?></option>
<?php endforeach; ?>
<?php
return ob_get_clean();
}

private function render_mismatch_results_html( $results ) {
ob_start();
if ( null === $results ) {
?>
<p><?php esc_html_e( 'هنوز اسکنی انجام نشده است. دکمه بالا را بزنید.', 'novin-commerce' ); ?></p>
<?php
} elseif ( empty( $results ) ) {
?>
<div class="notice notice-success inline"><p><?php esc_html_e( 'هیچ مغایرتی یافت نشد؛ همه محصولات حداقل برای یکی از نقش‌های فعال قیمت دارند.', 'novin-commerce' ); ?></p></div>
<?php
} else {
?>
<table class="widefat striped">
<thead>
<tr>
<th><?php esc_html_e( 'SKU', 'novin-commerce' ); ?></th>
<th><?php esc_html_e( 'نام محصول', 'novin-commerce' ); ?></th>
<th><?php esc_html_e( 'Slug', 'novin-commerce' ); ?></th>
<th><?php esc_html_e( 'نقش‌های بدون قیمت', 'novin-commerce' ); ?></th>
<th><?php esc_html_e( 'عملیات', 'novin-commerce' ); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ( $results as $row ) : ?>
<tr>
<td><?php echo esc_html( $row['sku'] ? $row['sku'] : '—' ); ?></td>
<td><?php echo esc_html( $row['name'] ); ?></td>
<td><code><?php echo esc_html( urldecode( $row['slug'] ) ); ?></code></td>
<td><?php echo esc_html( implode( '، ', $row['roles'] ) ); ?></td>
<td>
<?php if ( $row['edit'] ) : ?>
<a href="<?php echo esc_url( $row['edit'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ویرایش محصول', 'novin-commerce' ); ?></a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="description">
<?php
printf(
/* translators: %d: number of products */
esc_html__( 'تعداد کل موارد یافت‌شده: %d', 'novin-commerce' ),
count( $results )
);
?>
</p>
<?php
}
return ob_get_clean();
}
}
