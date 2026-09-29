<?php

namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Common\Currency_Conversion;
use MobinDev\Novin_Commerce\Common\SettingAPI;
use MobinDev\Novin_Commerce\Plugin;

class Setting_Menu {
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function load() {
		$this->save();
	}

	public function save() {
		if ( isset( $_POST['novin_save_admin_access'] ) && class_exists( 'NovinCommerce_RolePrice_Settings' ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'novin-role-access' ) ) {
				return;
			}
			\NovinCommerce_RolePrice_Settings::save_admin_access(
				isset( $_POST['novin_admin_access'] ) ? wp_unslash( $_POST['novin_admin_access'] ) : array()
			);
			AdminNotice::addSuccessDismissible( 'دسترسی‌ها با موفقیت ذخیره شد.', 2 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'novin-commerce-settings', 'tab' => 'roles' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// A no-JavaScript fallback for the embedded role form. The normal
		// path is AJAX, but a failed script must never turn a settings save
		// into a blank/unstyled admin response.
		if ( isset( $_POST['wcpbr_roles_form'] ) && class_exists( 'NovinCommerce_RolePrice_Settings' ) ) {
			if ( isset( $_POST['wcpbr_roles_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpbr_roles_nonce'] ) ), 'wcpbr_save_roles_form' ) ) {
				$instance = \NovinCommerce_RolePrice_Settings::get_instance();
				if ( $instance ) {
					$instance->save_roles_from_post( isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array() );
				}
				AdminNotice::addSuccessDismissible( 'تنظیمات نقش‌ها با موفقیت ذخیره شد.', 2 );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'novin-commerce-settings', 'tab' => 'roles' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_POST['novin_settings_submit'] ) ) {
			return;
		}
		check_admin_referer( 'novin-settings' );

		$woocommerce_analytics = isset( $_POST['woocommerce_analytics'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce_analytics'] ) ) : 'off';
		$tracking              = isset( $_POST['woocommerce_allow_tracking'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce_allow_tracking'] ) ) : 'no';
		$marketplace           = isset( $_POST['woocommerce_show_marketplace_suggestions'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce_show_marketplace_suggestions'] ) ) : 'no';
		$api_url               = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : SettingAPI::get( 'api_url', '' );

		if ( ! in_array( $woocommerce_analytics, array( 'on', 'off' ), true ) ) {
			$woocommerce_analytics = 'off';
		}
		if ( ! in_array( $tracking, array( 'yes', 'no' ), true ) ) {
			$tracking = 'no';
		}
		if ( ! in_array( $marketplace, array( 'yes', 'no' ), true ) ) {
			$marketplace = 'no';
		}
		if ( ! array_key_exists( $api_url, self::getApiUrls() ) ) {
			AdminNotice::addWarningDismissible( 'سرور انتخاب شده معتبر نیست.', 2 );
			return;
		}

		// This is one shared form even though the UI is split into client-side
		// tabs. Save both panels together and leave unknown legacy settings
		// untouched in the same WordPress option.
		SettingAPI::setAll(
			array(
				'woocommerce_analytics'       => $woocommerce_analytics,
				'api_user'                    => isset( $_POST['api_user'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_user'] ) ) ) : SettingAPI::get( 'api_user', '' ),
				'api_url'                     => $api_url,
				'novin_toman_conversion_enabled' => isset( $_POST['novin_toman_conversion_enabled'] ) ? 'on' : 'off',
			)
		);

		if ( isset( $_POST['api_pass'] ) ) {
			// The field is intentionally plain text in the UI. The option still
			// follows the encrypted Secret_Crypt path and old plain values remain
			// readable for backwards compatibility.
			SettingAPI::setSecret( 'api_pass', trim( sanitize_text_field( wp_unslash( $_POST['api_pass'] ) ) ) );
		}

		update_option( 'woocommerce_allow_tracking', $tracking );
		update_option( 'woocommerce_show_marketplace_suggestions', $marketplace );

		AdminNotice::addSuccessDismissible( 'تنظیمات با موفقیت ذخیره شد.', 2 );
		$tab = isset( $_POST['novin_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['novin_settings_tab'] ) ) : 'general';
		wp_safe_redirect( add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=novin-commerce-settings' ) ) );
		exit;
	}

	public function output() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
		}

		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$allowed = array( 'general', 'connection', 'roles', 'mismatch' );
		if ( ! in_array( $active, $allowed, true ) ) {
			$active = 'general';
		}

		$role_instance = class_exists( 'NovinCommerce_RolePrice_Settings' ) ? \NovinCommerce_RolePrice_Settings::get_instance() : null;
		$tabs = array(
			'general'     => 'عمومی',
			'connection'  => 'ارتباط با حسابداری',
			'roles'       => 'نقش‌ها و دسترسی',
			'mismatch'    => 'مغایرت‌گیری قیمت',
		);
		?>
		<div class="wrap novin-settings-page" dir="rtl" data-novin-settings-root>
			<div class="novin-settings-header">
				<div><span class="novin-kicker">NOVIN COMMERCE</span><h1>تنظیمات افزونه</h1><p>تنظیمات ارتباط، رفتار سایت و نقش‌های کاربری را از یک محل مدیریت کنید.</p></div>
			</div>

			<nav class="nav-tab-wrapper novin-settings-tabs" aria-label="تنظیمات نوین کامرس">
				<?php foreach ( $tabs as $tab => $label ) : ?>
					<a class="nav-tab <?php echo $active === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'novin-commerce-settings', 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>" data-novin-tab="<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" class="novin-settings-panel novin-core-settings-form" data-novin-shared-form>
				<?php wp_nonce_field( 'novin-settings' ); ?>
				<input type="hidden" name="novin_settings_submit" value="1">
				<input type="hidden" name="novin_settings_tab" value="<?php echo esc_attr( $active ); ?>" data-novin-active-tab>

				<section class="novin-settings-tab-panel" data-novin-panel="general" <?php echo 'general' === $active ? '' : 'hidden'; ?>>
					<h2>تنظیمات عمومی</h2>
					<p class="description">گزینه‌های عمومی WooCommerce و واحد نمایش قیمت در فروشگاه.</p>
					<table class="form-table" role="presentation">
						<tr><th><label for="woocommerce_analytics">تجزیه و تحلیل WooCommerce</label></th><td><select name="woocommerce_analytics" id="woocommerce_analytics"><option value="on" <?php selected( SettingAPI::get( 'woocommerce_analytics' ), 'on' ); ?>>فعال</option><option value="off" <?php selected( SettingAPI::get( 'woocommerce_analytics' ), 'off' ); ?>>غیرفعال</option></select><p class="description">در صورت نیاز می‌توانید ثبت داده‌های Analytics را خاموش کنید.</p></td></tr>
						<tr><th><label for="woocommerce_allow_tracking">رهگیری WooCommerce</label></th><td><select name="woocommerce_allow_tracking" id="woocommerce_allow_tracking"><option value="yes" <?php selected( get_option( 'woocommerce_allow_tracking' ), 'yes' ); ?>>فعال</option><option value="no" <?php selected( get_option( 'woocommerce_allow_tracking' ), 'no' ); ?>>غیرفعال</option></select><p class="description">کنترل ارسال داده‌های رهگیری WooCommerce.</p></td></tr>
						<tr><th><label for="woocommerce_show_marketplace_suggestions">پیشنهادهای Marketplace</label></th><td><select name="woocommerce_show_marketplace_suggestions" id="woocommerce_show_marketplace_suggestions"><option value="yes" <?php selected( get_option( 'woocommerce_show_marketplace_suggestions' ), 'yes' ); ?>>فعال</option><option value="no" <?php selected( get_option( 'woocommerce_show_marketplace_suggestions' ), 'no' ); ?>>غیرفعال</option></select><p class="description">کنترل پیشنهادهای Marketplace در پنل WooCommerce.</p></td></tr>
						<tr><th><label for="novin_toman_conversion_enabled">تبدیل ریال به تومان</label></th><td><label><input type="checkbox" id="novin_toman_conversion_enabled" name="novin_toman_conversion_enabled" value="on" <?php checked( Currency_Conversion::is_enabled(), true ); ?>> قیمت‌ها هنگام نمایش و محاسبه بر ۱۰ تقسیم شوند</label><p class="description">قیمت خام محصولات و نقش‌ها در حسابداری به ریال باقی می‌ماند؛ این گزینه واحد نمایش ووکامرس و پنل مدیریت را به تومان تبدیل می‌کند.</p></td></tr>
					</table>
				</section>

				<section class="novin-settings-tab-panel" data-novin-panel="connection" <?php echo 'connection' === $active ? '' : 'hidden'; ?>>
					<h2>ارتباط با حسابداری</h2>
					<p class="description">اطلاعات اتصال NovinCommerce به سرویس‌های مورد استفاده سیستم تبادل.</p>
					<table class="form-table" role="presentation">
						<tr><th><label for="api_user">نام کاربری</label></th><td><input class="regular-text" type="text" value="<?php echo esc_attr( SettingAPI::get( 'api_user', '' ) ); ?>" name="api_user" id="api_user" autocomplete="off"></td></tr>
						<tr><th><label for="api_pass">رمز عبور</label></th><td><input class="regular-text" type="text" value="<?php echo esc_attr( SettingAPI::getSecret( 'api_pass', '' ) ); ?>" name="api_pass" id="api_pass" autocomplete="off"></td></tr>
						<tr><th><label for="api_url">سرور</label></th><td><select name="api_url" id="api_url"><?php foreach ( self::getApiUrls() as $url => $label ) : ?><option value="<?php echo esc_attr( $url ); ?>" <?php selected( SettingAPI::get( 'api_url' ), $url ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
					</table>
				</section>

				<p class="submit"><button type="submit" class="button button-primary">ذخیره تغییرات</button></p>
			</form>

			<div class="novin-settings-tab-panel novin-settings-panel" data-novin-panel="roles" <?php echo 'roles' === $active ? '' : 'hidden'; ?>>
				<?php echo $role_instance ? $role_instance->render_embedded_roles_tab() : '<p>ماژول قیمت‌گذاری نقش‌ها در دسترس نیست.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="novin-settings-tab-panel novin-settings-panel" data-novin-panel="mismatch" <?php echo 'mismatch' === $active ? '' : 'hidden'; ?>>
				<div class="novin-settings-callout"><strong>بررسی مغایرت قیمت‌ها</strong><p>برای اسکن و بررسی کالاهایی که قیمت نقش‌ها با اطلاعات حسابداری هماهنگ نیست، از صفحه تخصصی مغایرت‌گیری استفاده کنید.</p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=novin-commerce-mismatch' ) ); ?>">باز کردن مغایرت‌گیری</a></div>
			</div>
		</div>
		<style>
		.novin-settings-page{max-width:1280px}.novin-settings-header{padding:26px 28px;margin:24px 0 16px;background:linear-gradient(135deg,#fff,#f8fafc);border:1px solid #e5e7eb;border-radius:20px}.novin-settings-header h1{margin:5px 0 7px;font-size:28px}.novin-settings-header p{margin:0;color:#64748b}.novin-settings-tabs{margin:0 0 16px;border-bottom:1px solid #dcdcde}.novin-settings-tabs .nav-tab{font-size:13px;padding:10px 18px}.novin-settings-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:26px 30px;box-shadow:0 8px 30px rgba(15,23,42,.04)}.novin-settings-tab-panel[hidden]{display:none!important}.novin-settings-panel h2{margin:0 0 6px;font-size:19px}.novin-settings-panel .form-table{margin-top:18px}.novin-settings-panel .form-table th{width:260px;text-align:right}.novin-settings-callout{margin:18px 0;padding:16px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-right:4px solid #2563eb;border-radius:12px}.novin-settings-callout strong{display:block;margin-bottom:6px}.novin-settings-callout p{margin:0 0 12px;color:#64748b;line-height:1.9}.novin-settings-panel input.regular-text{max-width:420px;width:100%}
		</style>
		<script>
		(function(){
			var root = document.querySelector('[data-novin-settings-root]');
			if (!root) return;
			var links = root.querySelectorAll('[data-novin-tab]');
			var panels = root.querySelectorAll('[data-novin-panel]');
			var activeInput = root.querySelector('[data-novin-active-tab]');
			var allowed = <?php echo wp_json_encode( array_keys( $tabs ) ); ?>;
			function setTab(tab, updateUrl) {
				if (allowed.indexOf(tab) === -1) tab = 'general';
				links.forEach(function(link){ link.classList.toggle('nav-tab-active', link.getAttribute('data-novin-tab') === tab); });
				panels.forEach(function(panel){ panel.hidden = panel.getAttribute('data-novin-panel') !== tab; });
				if (activeInput) activeInput.value = tab;
				if (updateUrl && window.history && window.history.replaceState) {
					var url = new URL(window.location.href);
					url.searchParams.set('tab', tab);
					window.history.replaceState({}, document.title, url.toString());
				}
			}
			links.forEach(function(link){ link.addEventListener('click', function(event){ event.preventDefault(); setTab(link.getAttribute('data-novin-tab'), true); }); });
			setTab(<?php echo wp_json_encode( $active ); ?>, false);
		})();
		</script>
		<?php
	}

	public static function getApiUrls() {
		return array(
			'https://novinrank.ir/' => 'سرور ایران 1',
			'https://npMail.ir'      => 'سرور ایران 2',
			'https://novinpapi.ir/'  => 'سرور ایران 3',
		);
	}
}
