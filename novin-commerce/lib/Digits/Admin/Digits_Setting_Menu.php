<?php

namespace MobinDev\Novin_Commerce\Digits\Admin;

use MobinDev\Novin_Commerce\Digits\Common\Digits_Settings;
use MobinDev\Novin_Commerce\Digits\Common\Otp_Manager;
use MobinDev\Novin_Commerce\Digits\Common\Sms_Log;
use MobinDev\Novin_Commerce\Digits\SmsGateways\Gateway_Registry;
use MobinDev\Novin_Commerce\Plugin;

/**
 * Settings page for the Digits (mobile signup/login) module.
 *
 * Registered as its own submenu item under the main Novin Commerce menu,
 * so this module's settings never mix with (or risk corrupting) the core
 * accounting-sync settings screen.
 */
class Digits_Setting_Menu {

	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function hooks() {
		$this->plugin->get_loader()->add_action( 'admin_menu', $this, 'add_menu' );
		$this->plugin->get_loader()->add_action( 'admin_init', $this, 'save' );
		$this->plugin->get_loader()->add_action( 'wp_ajax_novin_digits_test_sms', $this, 'ajax_test_sms' );
	}

	public function add_menu() {
		add_submenu_page(
			'novin-commerce-products',
			'ورود/ثبت‌نام موبایلی (Digits)',
			'ورود/ثبت‌نام موبایلی',
			'manage_options',
			'novin-commerce-digits',
			[ $this, 'output' ]
		);
	}

	public function save() {
		if ( ! isset( $_POST['novin_digits_settings_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'novin-digits-settings' );

		$tab = isset( $_POST['novin_digits_tab'] ) ? sanitize_key( wp_unslash( $_POST['novin_digits_tab'] ) ) : 'general';

		if ( 'general' === $tab ) {
			$this->save_general_tab();
		} elseif ( 'sms' === $tab ) {
			$this->save_sms_tab();
		} elseif ( 'woocommerce' === $tab ) {
			$this->save_woocommerce_tab();
		}

		wp_safe_redirect( add_query_arg( 'tab', $tab, admin_url( 'admin.php?page=novin-commerce-digits' ) ) );
		exit;
	}

	private function save_general_tab() {
		$module_enabled = isset( $_POST['digits_enabled'] ) ? 'on' : 'off';
		Digits_Settings::set( 'enabled', $module_enabled );

		$login_mode = isset( $_POST['login_mode'] ) ? sanitize_key( wp_unslash( $_POST['login_mode'] ) ) : 'otp_only';
		if ( in_array( $login_mode, [ 'otp_only', 'otp_and_password', 'password_optional_otp', 'unified_form' ], true ) ) {
			Digits_Settings::set( 'login_mode', $login_mode );
		}

		Digits_Settings::set( 'registration_enabled', isset( $_POST['registration_enabled'] ) ? 'on' : 'off' );
		Digits_Settings::set( 'require_strong_password', isset( $_POST['require_strong_password'] ) ? 'on' : 'off' );
		Digits_Settings::set( 'captcha_enabled', isset( $_POST['captcha_enabled'] ) ? 'on' : 'off' );

		if ( isset( $_POST['default_country_code'] ) ) {
			$cc = preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['default_country_code'] ) );
			Digits_Settings::set( 'default_country_code', $cc ? $cc : '98' );
		}

		if ( isset( $_POST['otp_ttl_seconds'] ) ) {
			Digits_Settings::set( 'otp_ttl_seconds', max( 60, absint( $_POST['otp_ttl_seconds'] ) ) );
		}
		if ( isset( $_POST['otp_max_resends_per_hour'] ) ) {
			Digits_Settings::set( 'otp_max_resends_per_hour', max( 1, absint( $_POST['otp_max_resends_per_hour'] ) ) );
		}
		if ( isset( $_POST['otp_max_wrong_attempts'] ) ) {
			Digits_Settings::set( 'otp_max_wrong_attempts', max( 1, absint( $_POST['otp_max_wrong_attempts'] ) ) );
		}

		if ( isset( $_POST['login_redirect'] ) ) {
			Digits_Settings::set( 'login_redirect', esc_url_raw( wp_unslash( $_POST['login_redirect'] ) ) );
		}
		if ( isset( $_POST['register_redirect'] ) ) {
			Digits_Settings::set( 'register_redirect', esc_url_raw( wp_unslash( $_POST['register_redirect'] ) ) );
		}

		\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات عمومی ذخیره شد.', 2 );
	}

	private function save_sms_tab() {
		if ( isset( $_POST['active_sms_gateway'] ) ) {
			$slug = sanitize_key( wp_unslash( $_POST['active_sms_gateway'] ) );
			if ( Gateway_Registry::get( $slug ) ) {
				Digits_Settings::set( 'active_sms_gateway', $slug );
			}
		}

		if ( isset( $_POST['otp_message_template'] ) ) {
			Digits_Settings::set( 'otp_message_template', sanitize_textarea_field( wp_unslash( $_POST['otp_message_template'] ) ) );
		}

		foreach ( Gateway_Registry::all() as $slug => $gateway ) {
			$field_values = [];
			foreach ( $gateway->get_settings_fields() as $field ) {
				$post_key = 'gateway_' . $slug . '_' . $field['key'];
				if ( ! isset( $_POST[ $post_key ] ) ) {
					continue;
				}
				$raw = wp_unslash( $_POST[ $post_key ] );

				if ( 'password' === $field['type'] ) {
					// Leave the stored (encrypted) password untouched if the
					// admin left the field blank on this save (so they are
					// not forced to re-enter it every time they change an
					// unrelated setting on this tab).
					if ( '' === trim( (string) $raw ) ) {
						$existing = Digits_Settings::get_gateway_settings( $slug );
						$field_values[ $field['key'] ] = $existing[ $field['key'] ] ?? '';
						continue;
					}
				}

				$field_values[ $field['key'] ] = sanitize_text_field( $raw );
			}

			if ( ! empty( $field_values ) ) {
				Digits_Settings::set_gateway_settings( $slug, $field_values );
			}
		}

		\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات پیامک ذخیره شد.', 2 );
	}

	private function save_woocommerce_tab() {
		Digits_Settings::set( 'wc_autofill_checkout_phone', isset( $_POST['wc_autofill_checkout_phone'] ) ? 'on' : 'off' );
		Digits_Settings::set( 'wc_guest_checkout_signup', isset( $_POST['wc_guest_checkout_signup'] ) ? 'on' : 'off' );

		if ( isset( $_POST['wc_invoice_phone_format'] ) ) {
			$format = sanitize_key( wp_unslash( $_POST['wc_invoice_phone_format'] ) );
			if ( in_array( $format, [ 'local', 'international', 'international_no_plus' ], true ) ) {
				Digits_Settings::set( 'wc_invoice_phone_format', $format );
			}
		}

		\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات ووکامرس ذخیره شد.', 2 );
	}

	/**
	 * AJAX handler for the "test SMS send" button on the SMS tab. Sends a
	 * real message through the currently-saved settings for the selected
	 * gateway, using whatever the admin typed into the test number field
	 * — without requiring the settings form itself to be submitted first.
	 */
	public function ajax_test_sms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ] );
		}
		check_ajax_referer( 'novin-digits-test-sms', 'nonce' );

		$gateway_slug = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		$test_phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$gateway = Gateway_Registry::get( $gateway_slug );
		if ( ! $gateway || '' === $test_phone ) {
			wp_send_json_error( [ 'message' => 'درگاه یا شماره تست نامعتبر است.' ] );
		}

		$phone    = Otp_Manager::normalize_phone( $test_phone );
		$settings = Digits_Settings::get_gateway_settings( $gateway_slug );
		$message  = 'تست';

		$result = $gateway->send( $phone, $message, $settings );

		\MobinDev\Novin_Commerce\Digits\Common\Sms_Log::add(
			$gateway_slug,
			$phone,
			$result['success'] ? 'success' : 'error',
			$result['message'],
			$result['raw'] ?? ''
		);

		if ( $result['success'] ) {
			wp_send_json_success( [ 'message' => $result['message'], 'raw' => $result['raw'] ?? '' ] );
		}

		wp_send_json_error( [ 'message' => $result['message'], 'raw' => $result['raw'] ?? '' ] );
	}

	public function output() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
		}

		$active   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$allowed  = [ 'general', 'sms', 'woocommerce', 'logs' ];
		if ( ! in_array( $active, $allowed, true ) ) {
			$active = 'general';
		}

		$gateways      = Gateway_Registry::all();
		$active_gateway = Digits_Settings::get( 'active_sms_gateway', '' );
		?>
		<div class="wrap novin-settings-page" dir="rtl">
			<div class="novin-settings-header">
				<div>
					<span class="novin-kicker">NOVIN COMMERCE</span>
					<h1>ورود/ثبت‌نام با شماره موبایل</h1>
					<p>تنظیمات ماژول ورود و ثبت‌نام مبتنی بر شماره موبایل و کد تأیید پیامکی (OTP)، مستقل از تنظیمات همگام‌سازی حسابداری.</p>
				</div>
			</div>

			<nav class="nav-tab-wrapper novin-settings-tabs" aria-label="تنظیمات ورود/ثبت‌نام موبایلی">
				<?php
				foreach (
					[
						'general'     => 'عمومی',
						'sms'         => 'پیامک و درگاه',
						'woocommerce' => 'ووکامرس',
						'logs'        => 'گزارش ارسال پیامک',
					] as $tab => $label
				) :
					?>
					<a class="nav-tab <?php echo $active === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'novin-commerce-digits', 'tab' => $tab ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'logs' === $active ) : ?>
				<div class="novin-settings-panel">
					<h2>گزارش ارسال پیامک</h2>
					<?php $counts = Sms_Log::counts_since( 86400 ); ?>
					<p class="description">در ۲۴ ساعت گذشته: <strong style="color:#16a34a"><?php echo esc_html( $counts['success'] ); ?> موفق</strong> — <strong style="color:#dc2626"><?php echo esc_html( $counts['error'] ); ?> ناموفق</strong></p>
					<table class="widefat striped" style="margin-top:14px">
						<thead>
							<tr>
								<th>تاریخ</th>
								<th>درگاه</th>
								<th>شماره</th>
								<th>وضعیت</th>
								<th>پیام</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( Sms_Log::recent( 30 ) as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row->created_at ); ?></td>
									<td><?php echo esc_html( $row->gateway ); ?></td>
									<td><?php echo esc_html( $row->phone ); ?></td>
									<td><?php echo 'success' === $row->status ? '<span style="color:#16a34a">موفق</span>' : '<span style="color:#dc2626">ناموفق</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( $row->message ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<form method="post" class="novin-settings-panel">
					<?php wp_nonce_field( 'novin-digits-settings' ); ?>
					<input type="hidden" name="novin_digits_settings_submit" value="1">
					<input type="hidden" name="novin_digits_tab" value="<?php echo esc_attr( $active ); ?>">

					<?php if ( 'general' === $active ) : ?>
						<h2>تنظیمات عمومی</h2>
						<p class="description">فعال‌سازی ماژول، حالت ورود/ثبت‌نام و پارامترهای امنیتی کد تأیید.</p>
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="digits_enabled">فعال‌سازی ماژول</label></th>
								<td><label><input type="checkbox" id="digits_enabled" name="digits_enabled" <?php checked( Digits_Settings::get( 'enabled', 'off' ), 'on' ); ?>> فعال باشد</label></td>
							</tr>
							<tr>
								<th><label for="login_mode">حالت ورود/ثبت‌نام</label></th>
								<td>
									<select name="login_mode" id="login_mode">
										<option value="otp_only" <?php selected( Digits_Settings::get( 'login_mode', 'otp_only' ), 'otp_only' ); ?>>فقط کد پیامکی (OTP)</option>
										<option value="otp_and_password" <?php selected( Digits_Settings::get( 'login_mode' ), 'otp_and_password' ); ?>>کد پیامکی + رمز عبور</option>
										<option value="password_optional_otp" <?php selected( Digits_Settings::get( 'login_mode' ), 'password_optional_otp' ); ?>>رمز عبور + کد پیامکی اختیاری</option>
										<option value="unified_form" <?php selected( Digits_Settings::get( 'login_mode' ), 'unified_form' ); ?>>فرم مشترک ورود و ثبت‌نام (تک فرم)</option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="registration_enabled">امکان ثبت‌نام</label></th>
								<td><label><input type="checkbox" id="registration_enabled" name="registration_enabled" <?php checked( Digits_Settings::get( 'registration_enabled', 'on' ), 'on' ); ?>> عضویت کاربران جدید فعال باشد</label></td>
							</tr>
							<tr>
								<th><label for="require_strong_password">الزام رمز عبور قوی</label></th>
								<td><label><input type="checkbox" id="require_strong_password" name="require_strong_password" <?php checked( Digits_Settings::get( 'require_strong_password', 'off' ), 'on' ); ?>> فعال باشد</label></td>
							</tr>
							<tr>
								<th><label for="captcha_enabled">کپچا در فرم‌ها</label></th>
								<td><label><input type="checkbox" id="captcha_enabled" name="captcha_enabled" <?php checked( Digits_Settings::get( 'captcha_enabled', 'off' ), 'on' ); ?>> فعال باشد</label></td>
							</tr>
							<tr>
								<th><label for="default_country_code">کد کشور پیش‌فرض</label></th>
								<td><input class="regular-text" type="text" id="default_country_code" name="default_country_code" value="<?php echo esc_attr( Digits_Settings::get( 'default_country_code', '98' ) ); ?>"><p class="description">بدون علامت +. برای ایران: 98</p></td>
							</tr>
							<tr>
								<th><label for="otp_ttl_seconds">مدت اعتبار کد OTP (ثانیه)</label></th>
								<td><input class="small-text" type="number" min="60" id="otp_ttl_seconds" name="otp_ttl_seconds" value="<?php echo esc_attr( Digits_Settings::get( 'otp_ttl_seconds', 120 ) ); ?>"></td>
							</tr>
							<tr>
								<th><label for="otp_max_resends_per_hour">حداکثر ارسال مجدد کد (در هر ساعت)</label></th>
								<td><input class="small-text" type="number" min="1" id="otp_max_resends_per_hour" name="otp_max_resends_per_hour" value="<?php echo esc_attr( Digits_Settings::get( 'otp_max_resends_per_hour', 5 ) ); ?>"></td>
							</tr>
							<tr>
								<th><label for="otp_max_wrong_attempts">حداکثر تلاش اشتباه مجاز</label></th>
								<td><input class="small-text" type="number" min="1" id="otp_max_wrong_attempts" name="otp_max_wrong_attempts" value="<?php echo esc_attr( Digits_Settings::get( 'otp_max_wrong_attempts', 5 ) ); ?>"></td>
							</tr>
							<tr>
								<th><label for="login_redirect">آدرس بازگشت بعد از ورود</label></th>
								<td><input class="regular-text" type="url" id="login_redirect" name="login_redirect" value="<?php echo esc_attr( Digits_Settings::get( 'login_redirect', '' ) ); ?>" placeholder="خالی = صفحه پیش‌فرض وردپرس"></td>
							</tr>
							<tr>
								<th><label for="register_redirect">آدرس بازگشت بعد از ثبت‌نام</label></th>
								<td><input class="regular-text" type="url" id="register_redirect" name="register_redirect" value="<?php echo esc_attr( Digits_Settings::get( 'register_redirect', '' ) ); ?>" placeholder="خالی = صفحه پیش‌فرض وردپرس"></td>
							</tr>
						</table>

					<?php elseif ( 'sms' === $active ) : ?>
						<h2>پیامک و درگاه</h2>
						<p class="description">انتخاب سرویس‌دهنده پیامکی فعال و تنظیمات اختصاصی هر درگاه.</p>
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="active_sms_gateway">سرویس‌دهنده فعال</label></th>
								<td>
									<select name="active_sms_gateway" id="active_sms_gateway">
										<option value="">— انتخاب کنید —</option>
										<?php foreach ( $gateways as $slug => $gateway ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $active_gateway, $slug ); ?>><?php echo esc_html( $gateway->get_label() ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label for="otp_message_template">قالب متن پیامک OTP</label></th>
								<td>
									<textarea class="large-text" rows="2" id="otp_message_template" name="otp_message_template"><?php echo esc_textarea( Digits_Settings::get( 'otp_message_template', 'کد تأیید شما: {code}' ) ); ?></textarea>
									<p class="description">از عبارت <code>{code}</code> برای جای‌گذاری خودکار کد استفاده کنید.</p>
								</td>
							</tr>
						</table>

						<?php foreach ( $gateways as $slug => $gateway ) : ?>
							<h3><?php echo esc_html( $gateway->get_label() ); ?></h3>
							<table class="form-table" role="presentation">
								<?php
								$gw_settings = Digits_Settings::get_gateway_settings( $slug );
								foreach ( $gateway->get_settings_fields() as $field ) :
									$field_id  = 'gateway_' . $slug . '_' . $field['key'];
									$value     = $gw_settings[ $field['key'] ] ?? $field['default'];
									$input_type = 'password' === $field['type'] ? 'password' : 'text';
									?>
									<tr>
										<th><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
										<td>
											<input class="regular-text" type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo 'password' === $field['type'] ? '' : esc_attr( $value ); ?>" autocomplete="off" placeholder="<?php echo 'password' === $field['type'] ? 'برای تغییر وارد کنید؛ برای حفظ مقدار فعلی خالی بگذارید' : ''; ?>">
											<?php if ( ! empty( $field['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</table>

							<div class="novin-digits-test-sms" data-gateway="<?php echo esc_attr( $slug ); ?>" style="margin:10px 0 26px">
								<input type="text" class="regular-text novin-digits-test-phone" placeholder="شماره موبایل تستی (مثال: 09123456789)">
								<button type="button" class="button novin-digits-test-btn">تست ارسال پیامک</button>
								<span class="novin-digits-test-result" style="margin-right:10px"></span>
							</div>
						<?php endforeach; ?>

					<?php elseif ( 'woocommerce' === $active ) : ?>
						<h2>یکپارچگی با ووکامرس</h2>
						<table class="form-table" role="presentation">
							<tr>
								<th><label for="wc_autofill_checkout_phone">پرکردن خودکار شماره در Checkout</label></th>
								<td><label><input type="checkbox" id="wc_autofill_checkout_phone" name="wc_autofill_checkout_phone" <?php checked( Digits_Settings::get( 'wc_autofill_checkout_phone', 'on' ), 'on' ); ?>> فیلد شماره تلفن ووکامرس با شماره ثبت‌نامی کاربر پر شود</label></td>
							</tr>
							<tr>
								<th><label for="wc_guest_checkout_signup">ثبت‌نام حین خرید (مهمان)</label></th>
								<td><label><input type="checkbox" id="wc_guest_checkout_signup" name="wc_guest_checkout_signup" <?php checked( Digits_Settings::get( 'wc_guest_checkout_signup', 'off' ), 'on' ); ?>> برای کاربران مهمان فعال باشد</label></td>
							</tr>
							<tr>
								<th><label for="wc_invoice_phone_format">فرمت شماره در فاکتور</label></th>
								<td>
									<select name="wc_invoice_phone_format" id="wc_invoice_phone_format">
										<option value="local" <?php selected( Digits_Settings::get( 'wc_invoice_phone_format', 'local' ), 'local' ); ?>>محلی (09123456789)</option>
										<option value="international" <?php selected( Digits_Settings::get( 'wc_invoice_phone_format' ), 'international' ); ?>>بین‌المللی (+989123456789)</option>
										<option value="international_no_plus" <?php selected( Digits_Settings::get( 'wc_invoice_phone_format' ), 'international_no_plus' ); ?>>بین‌المللی بدون + (989123456789)</option>
									</select>
								</td>
							</tr>
						</table>
					<?php endif; ?>

					<p class="submit"><button type="submit" class="button button-primary">ذخیره تغییرات</button></p>
				</form>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			document.querySelectorAll('.novin-digits-test-sms').forEach(function(box){
				var btn = box.querySelector('.novin-digits-test-btn');
				var phoneInput = box.querySelector('.novin-digits-test-phone');
				var resultEl = box.querySelector('.novin-digits-test-result');
				var gateway = box.getAttribute('data-gateway');
				btn.addEventListener('click', function(){
					var phone = phoneInput.value.trim();
					if (!phone) { resultEl.textContent = 'لطفاً شماره تستی را وارد کنید.'; resultEl.style.color = '#dc2626'; return; }
					resultEl.textContent = 'در حال ارسال...';
					resultEl.style.color = '#64748b';
					var body = new URLSearchParams();
					body.append('action', 'novin_digits_test_sms');
					body.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'novin-digits-test-sms' ) ); ?>);
					body.append('gateway', gateway);
					body.append('phone', phone);
					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function(r){ return r.json(); })
						.then(function(res){
							if (res.success) {
								resultEl.textContent = res.data.message;
								resultEl.style.color = '#16a34a';
							} else {
								resultEl.textContent = (res.data && res.data.message) ? res.data.message : 'خطای نامشخص.';
								resultEl.style.color = '#dc2626';
							}
						})
						.catch(function(){
							resultEl.textContent = 'خطا در ارتباط با سرور.';
							resultEl.style.color = '#dc2626';
						});
				});
			});
		})();
		</script>
		<?php
	}
}
