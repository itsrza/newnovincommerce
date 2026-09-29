<?php

namespace MobinDev\Novin_Commerce\Digits\Admin;

use MobinDev\Novin_Commerce\Digits\Common\Digits_Settings;
use MobinDev\Novin_Commerce\Digits\Common\Otp_Manager;
use MobinDev\Novin_Commerce\Digits\Common\Sms_Log;
use MobinDev\Novin_Commerce\Digits\SmsGateways\Gateway_Registry;
use MobinDev\Novin_Commerce\Common\Text_Encoding;
use MobinDev\Novin_Commerce\Plugin;

/**
 * Settings page for the Digits (mobile signup/login) module.
 *
 * Registered as its own submenu item under the main Novin Commerce menu,
 * so this module's settings never mix with (or risk corrupting) the core
 * accounting-sync settings screen.
 */
class Digits_Setting_Menu {

	const PASSWORD_MASK = '********';

	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function hooks() {
		$this->plugin->get_loader()->add_action( 'admin_menu', $this, 'add_menu' );
		$this->plugin->get_loader()->add_action( 'admin_init', $this, 'save' );
		$this->plugin->get_loader()->add_action( 'wp_ajax_novin_digits_test_sms', $this, 'ajax_test_sms' );
		$this->plugin->get_loader()->add_action( 'wp_ajax_novin_digits_autosave', $this, 'ajax_autosave' );
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

		if ( in_array( $tab, [ 'general', 'sms', 'woocommerce', 'logs' ], true ) ) {
			// All settings tabs are rendered in the same form. Save the complete
			// form together so values entered on another tab are not lost.
			$this->save_general_tab( false );
			$this->save_sms_tab( false );
			$this->save_woocommerce_tab( false );
			\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات ذخیره شد.', 2 );
		}

		wp_safe_redirect( add_query_arg( 'tab', in_array( $tab, [ 'general', 'sms', 'woocommerce', 'logs' ], true ) ? $tab : 'general', admin_url( 'admin.php?page=novin-commerce-digits' ) ) );
		exit;
	}

	private function save_general_tab( $show_notice = true ) {
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

		if ( $show_notice ) {
			\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات عمومی ذخیره شد.', 2 );
		}
	}

	private function save_sms_tab( $show_notice = true ) {
		if ( isset( $_POST['active_sms_gateway'] ) ) {
				$slug = sanitize_key( wp_unslash( $_POST['active_sms_gateway'] ) );
				if ( '' === $slug || Gateway_Registry::get( $slug ) ) {
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
									if ( '' === trim( (string) $raw ) || self::PASSWORD_MASK === trim( (string) $raw ) ) {
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

		if ( $show_notice ) {
			\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات پیامک ذخیره شد.', 2 );
		}
	}

	private function save_woocommerce_tab( $show_notice = true ) {
		Digits_Settings::set( 'wc_autofill_checkout_phone', isset( $_POST['wc_autofill_checkout_phone'] ) ? 'on' : 'off' );
		Digits_Settings::set( 'wc_guest_checkout_signup', isset( $_POST['wc_guest_checkout_signup'] ) ? 'on' : 'off' );

		if ( isset( $_POST['wc_invoice_phone_format'] ) ) {
			$format = sanitize_key( wp_unslash( $_POST['wc_invoice_phone_format'] ) );
			if ( in_array( $format, [ 'local', 'international', 'international_no_plus' ], true ) ) {
				Digits_Settings::set( 'wc_invoice_phone_format', $format );
			}
		}

		if ( $show_notice ) {
			\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( 'تنظیمات ووکامرس ذخیره شد.', 2 );
		}
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

	/**
	 * Save one settings field when the admin leaves it. This keeps the page
	 * usable with long gateway forms and avoids losing a value when another
	 * field is edited afterwards.
	 */
	public function ajax_autosave() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ], 403 );
		}
		if ( ! check_ajax_referer( 'novin-digits-autosave', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'درخواست امنیتی نامعتبر است.' ], 403 );
		}

		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		if ( 'sms' !== $tab ) {
			// Autosave is intentionally limited to the SMS/gateway tab. The
			// general and WooCommerce tabs are saved only by the shared form.
			wp_send_json_error( [ 'message' => 'ذخیره خودکار فقط برای پیامک و درگاه فعال است.' ], 400 );
		}
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) ? (string) wp_unslash( $_POST['value'] ) : '';
		$result = $this->save_single_field( $tab, $field, $value );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}

		wp_send_json_success( array_merge( [ 'message' => 'ذخیره شد.' ], $result ) );
	}

	private function save_single_field( $tab, $field, $value ) {
		if ( 'general' === $tab ) {
			switch ( $field ) {
				case 'digits_enabled':
					Digits_Settings::set( 'enabled', 'on' === $value ? 'on' : 'off' );
					break;
				case 'login_mode':
					if ( ! in_array( $value, [ 'otp_only', 'otp_and_password', 'password_optional_otp', 'unified_form' ], true ) ) {
						return new \WP_Error( 'invalid_login_mode', 'حالت ورود انتخاب‌شده معتبر نیست.' );
					}
					Digits_Settings::set( 'login_mode', $value );
					break;
				case 'registration_enabled':
				case 'require_strong_password':
				case 'captcha_enabled':
					Digits_Settings::set( $field, 'on' === $value ? 'on' : 'off' );
					break;
				case 'default_country_code':
					$cc = preg_replace( '/[^0-9]/', '', $value );
					Digits_Settings::set( 'default_country_code', $cc ? $cc : '98' );
					break;
				case 'otp_ttl_seconds':
					Digits_Settings::set( 'otp_ttl_seconds', max( 60, absint( $value ) ) );
					break;
				case 'otp_max_resends_per_hour':
					Digits_Settings::set( 'otp_max_resends_per_hour', max( 1, absint( $value ) ) );
					break;
				case 'otp_max_wrong_attempts':
					Digits_Settings::set( 'otp_max_wrong_attempts', max( 1, absint( $value ) ) );
					break;
				case 'login_redirect':
				case 'register_redirect':
					Digits_Settings::set( $field, esc_url_raw( $value ) );
					break;
				default:
					return new \WP_Error( 'invalid_field', 'فیلد تنظیمات عمومی معتبر نیست.' );
			}
			return [];
		}

		if ( 'sms' === $tab ) {
			if ( 'active_sms_gateway' === $field ) {
				if ( '' !== $value && ! Gateway_Registry::get( sanitize_key( $value ) ) ) {
					return new \WP_Error( 'invalid_gateway', 'درگاه پیامکی انتخاب‌شده معتبر نیست.' );
				}
				Digits_Settings::set( 'active_sms_gateway', sanitize_key( $value ) );
				return [];
			}
			if ( 'otp_message_template' === $field ) {
				Digits_Settings::set( 'otp_message_template', sanitize_textarea_field( $value ) );
				return [];
			}

			if ( preg_match( '/^gateway_([a-z0-9_-]+)_([a-z0-9_-]+)$/', $field, $matches ) ) {
				$slug      = sanitize_key( $matches[1] );
				$field_key = sanitize_key( $matches[2] );
				$gateway   = Gateway_Registry::get( $slug );
				if ( ! $gateway ) {
					return new \WP_Error( 'invalid_gateway', 'درگاه پیامکی معتبر نیست.' );
				}

				$field_definition = null;
				foreach ( $gateway->get_settings_fields() as $definition ) {
					if ( $field_key === $definition['key'] ) {
						$field_definition = $definition;
						break;
					}
				}
				if ( ! $field_definition ) {
					return new \WP_Error( 'invalid_field', 'فیلد درگاه پیامکی معتبر نیست.' );
				}

				$settings = Digits_Settings::get_gateway_settings( $slug );
					if ( 'password' === $field_definition['type'] && ( '' === trim( $value ) || self::PASSWORD_MASK === trim( $value ) ) ) {
						return [
							'password_stored'    => Digits_Settings::gateway_has_password( $slug ),
							'password_preserved' => true,
						];
					}

					$settings[ $field_key ] = sanitize_text_field( $value );
					Digits_Settings::set_gateway_settings( $slug, $settings );
					return [
						'password_stored'    => 'password' === $field_definition['type'],
						'password_preserved' => false,
					];
			}

			return new \WP_Error( 'invalid_field', 'فیلد پیامکی معتبر نیست.' );
		}

		if ( 'woocommerce' === $tab ) {
			if ( in_array( $field, [ 'wc_autofill_checkout_phone', 'wc_guest_checkout_signup' ], true ) ) {
				Digits_Settings::set( $field, 'on' === $value ? 'on' : 'off' );
				return [];
			}
			if ( 'wc_invoice_phone_format' === $field && in_array( $value, [ 'local', 'international', 'international_no_plus' ], true ) ) {
				Digits_Settings::set( $field, $value );
				return [];
			}
			return new \WP_Error( 'invalid_field', 'فیلد ووکامرس معتبر نیست.' );
		}

		return new \WP_Error( 'invalid_tab', 'بخش تنظیمات معتبر نیست.' );
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
				<style>
					.novin-digits-save-toast{position:fixed;left:20px;bottom:20px;z-index:100000;opacity:0;transform:translateY(8px);pointer-events:none;background:#16a34a;color:#fff;border-radius:8px;padding:10px 16px;box-shadow:0 6px 18px rgba(15,23,42,.2);transition:opacity .2s ease,transform .2s ease;font-size:13px}
					.novin-digits-save-toast.is-visible{opacity:1;transform:translateY(0)}
					.novin-digits-save-toast.is-error{background:#dc2626}
					.novin-digits-saving{opacity:.65}
					.novin-digits-tab-panel[hidden],form[data-settings-form="1"][hidden]{display:none!important}
				</style>
				<div id="novin-digits-save-toast" class="novin-digits-save-toast" role="status" aria-live="polite"></div>
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
						<a class="nav-tab <?php echo $active === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => 'novin-commerce-digits', 'tab' => $tab ], admin_url( 'admin.php' ) ) ); ?>" data-novin-tab="<?php echo esc_attr( $tab ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

				<div class="novin-settings-panel novin-digits-tab-panel" data-tab-panel="logs" <?php echo 'logs' === $active ? '' : 'hidden'; ?>>
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
									<td><?php echo esc_html( Text_Encoding::normalize( $row->message ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

					<form method="post" class="novin-settings-panel" data-settings-form="1" data-autosave-nonce="<?php echo esc_attr( wp_create_nonce( 'novin-digits-autosave' ) ); ?>" <?php echo 'logs' === $active ? 'hidden' : ''; ?>>
						<?php wp_nonce_field( 'novin-digits-settings' ); ?>
					<input type="hidden" name="novin_digits_settings_submit" value="1">
					<input type="hidden" name="novin_digits_tab" value="<?php echo esc_attr( $active ); ?>">

					<div class="novin-digits-tab-panel" data-tab-panel="general" <?php echo 'general' === $active ? '' : 'hidden'; ?>>
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

						</div>

					<div class="novin-digits-tab-panel" data-tab-panel="sms" <?php echo 'sms' === $active ? '' : 'hidden'; ?>>
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
											// This field is intentionally plain text: the administrator
											// requested seeing the exact gateway password while entering
											// it and after the settings page is refreshed.
											$display_value = $value;
											$input_type = 'text';
									?>
									<tr>
										<th><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
										<td>
													<input class="regular-text" type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $display_value ); ?>" data-password-field="<?php echo 'password' === $field['type'] ? '1' : '0'; ?>" data-password-masked="0" autocomplete="off" placeholder="<?php echo 'password' === $field['type'] ? 'برای تغییر وارد کنید؛ برای حفظ مقدار فعلی خالی بگذارید' : ''; ?>">
											<?php if ( ! empty( $field['description'] ) && 'password' !== $field['type'] ) : ?>
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

						</div>

					<div class="novin-digits-tab-panel" data-tab-panel="woocommerce" <?php echo 'woocommerce' === $active ? '' : 'hidden'; ?>>
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
					</div>

						<p class="submit"><button type="submit" class="button button-primary">ذخیره تغییرات</button></p>
					</form>
			</div>

			<script>
			(function(){
				var tabs = document.querySelectorAll('[data-novin-tab]');
				var panels = document.querySelectorAll('[data-tab-panel]');
				var settingsForm = document.querySelector('form[data-settings-form="1"]');
				var tabInput = settingsForm ? settingsForm.querySelector('input[name="novin_digits_tab"]') : null;
				var allowedTabs = ['general', 'sms', 'woocommerce', 'logs'];

				function setTab(tab, updateUrl) {
					if (allowedTabs.indexOf(tab) === -1) tab = 'general';
					tabs.forEach(function(link){
						link.classList.toggle('nav-tab-active', link.getAttribute('data-novin-tab') === tab);
					});
					panels.forEach(function(panel){
						panel.hidden = panel.getAttribute('data-tab-panel') !== tab;
					});
					if (settingsForm) settingsForm.hidden = 'logs' === tab;
					if (tabInput) tabInput.value = tab;
					if (updateUrl && window.history && window.history.replaceState) {
						var url = new URL(window.location.href);
						url.searchParams.set('tab', tab);
						window.history.replaceState({}, document.title, url.toString());
					}
				}

				tabs.forEach(function(link){
					link.addEventListener('click', function(event){
						event.preventDefault();
						setTab(link.getAttribute('data-novin-tab'), true);
					});
				});

				setTab(<?php echo wp_json_encode( $active ); ?>, false);
			})();

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

			// Save each setting as soon as its field loses focus. The password
			// field intentionally stays as plain text so the administrator can
			// verify exactly what was entered.
			(function(){
				var toast = document.getElementById('novin-digits-save-toast');
				var toastTimer = null;

				function showToast(message, isError) {
					if (!toast) return;
					toast.textContent = message;
					toast.classList.toggle('is-error', !!isError);
					toast.classList.add('is-visible');
					if (toastTimer) window.clearTimeout(toastTimer);
					toastTimer = window.setTimeout(function(){ toast.classList.remove('is-visible'); }, 2200);
				}

				function fieldValue(field) {
					if ('checkbox' === field.type) return field.checked ? 'on' : 'off';
					return field.value;
				}

					function fieldAutosaveValue(field) {
						if ('checkbox' === field.type) return field.checked ? 'on' : 'off';
						return field.value;
					}

					function autosaveField(field) {
						var value = fieldAutosaveValue(field);
						if (field.dataset.novinAutosaveValue === value) return;
						field.dataset.novinAutosaveValue = value;
						saveField(field);
					}

					function saveField(field) {
						var form = field.form;
						if (!form || !field.name || !form.getAttribute('data-autosave-nonce')) return;
						var body = new URLSearchParams();
					body.append('action', 'novin_digits_autosave');
					body.append('nonce', form.getAttribute('data-autosave-nonce'));
						body.append('tab', 'sms');
					body.append('field', field.name);
					body.append('value', fieldValue(field));

						field.classList.add('novin-digits-saving');
						fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', keepalive: true, body: body })
						.then(function(response){ return response.json(); })
						.then(function(response){
							field.classList.remove('novin-digits-saving');
								if (!response || !response.success) {
									if (field.dataset.novinAutosaveValue === fieldAutosaveValue(field)) delete field.dataset.novinAutosaveValue;
									showToast(response && response.data && response.data.message ? response.data.message : 'ذخیره تنظیمات ناموفق بود.', true);
									return;
								}
								if ('1' === field.getAttribute('data-password-field') && response.data && response.data.password_stored) {
									// Keep the exact text in the field after an autosave.
									// The field is deliberately type=text by user request.
									field.setAttribute('data-password-masked', '0');
								}
							showToast('ذخیره شد.', false);
					})
							.catch(function(){
								field.classList.remove('novin-digits-saving');
								if (field.dataset.novinAutosaveValue === fieldAutosaveValue(field)) delete field.dataset.novinAutosaveValue;
								showToast('خطا در ارتباط برای ذخیره تنظیمات.', true);
							});
				}

				document.querySelectorAll('form.novin-settings-panel[data-autosave-nonce]').forEach(function(form){
						var smsPanel = form.querySelector('[data-tab-panel="sms"]');
						if (!smsPanel) return;
						smsPanel.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function(field){
							if ('1' === field.getAttribute('data-password-field')) {
									field.addEventListener('focus', function(){
										if ('1' === field.getAttribute('data-password-masked')) {
											field.value = '';
											field.setAttribute('data-password-masked', '0');
										}
									});
							}
						field.addEventListener('change', function(){ autosaveField(field); });
						field.addEventListener('blur', function(){ autosaveField(field); });
					});
				});
			})();
			</script>
			<?php
		}
}
