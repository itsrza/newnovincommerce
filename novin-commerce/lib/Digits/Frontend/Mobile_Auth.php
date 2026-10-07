<?php

namespace MobinDev\Novin_Commerce\Digits\Frontend;

use MobinDev\Novin_Commerce\Digits\Common\Digits_Settings;
use MobinDev\Novin_Commerce\Digits\Common\Otp_Manager;
use MobinDev\Novin_Commerce\Plugin;

/**
 * Replaces the default WordPress and WooCommerce login/registration forms
 * with one mobile-number/OTP form when the Digits module is enabled.
 *
 * The authentication flow deliberately uses WordPress AJAX rather than the
 * default password form, so the same UI works on wp-login.php, the
 * WooCommerce My Account page, and the [novin_digits_form] shortcode.
 */
class Mobile_Auth {

	private $plugin;
	private $woo_buffer_level = null;
	private $full_page_buffer_level = null;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function hooks() {
		add_action( 'wp_ajax_nopriv_novin_digits_request_otp', [ $this, 'ajax_request_otp' ] );
		add_action( 'wp_ajax_novin_digits_request_otp', [ $this, 'ajax_request_otp' ] );
		add_action( 'wp_ajax_nopriv_novin_digits_verify_otp', [ $this, 'ajax_verify_otp' ] );
		add_action( 'wp_ajax_novin_digits_verify_otp', [ $this, 'ajax_verify_otp' ] );

		add_shortcode( 'novin_digits_form', [ $this, 'shortcode' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_assets' ] );
		add_filter( 'login_body_class', [ $this, 'login_body_class' ] );
		add_filter( 'login_message', [ $this, 'login_message' ] );
		// Some themes replace the WooCommerce account template with a block or
		// a custom page builder. In that case the two WooCommerce form actions
		// below are never fired; the content fallback keeps the account URL a
		// usable login/register page instead of showing an unrelated cart.
		add_filter( 'the_content', [ $this, 'replace_account_login_content' ], 999 );
		// Woodmart Plus and similar builders can print the account template
		// outside the_content and outside WooCommerce's classic form hooks. Keep
		// this page-scoped safety net as the final fallback for that case.
		add_action( 'template_redirect', [ $this, 'start_full_page_capture' ], 0 );
		add_action( 'shutdown', [ $this, 'finish_full_page_capture' ], 0 );

		// The WooCommerce template renders its default forms between these two
		// actions. Buffer that output and replace it only when the module is on.
		add_action( 'woocommerce_before_customer_login_form', [ $this, 'start_woocommerce_capture' ], 1 );
		add_action( 'woocommerce_after_customer_login_form', [ $this, 'finish_woocommerce_capture' ], 9999 );
	}

	public function enqueue_login_assets() {
		if ( $this->should_replace_wp_login() ) {
			$this->enqueue_assets();
		}
	}

	public function enqueue_assets() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$version = $this->plugin->get_version();
		wp_enqueue_style(
			'novin-commerce-digits-auth',
			$this->plugin->getFrontStyleUrl() . 'digits.css',
			[],
			$version
		);
		wp_enqueue_script(
			'novin-commerce-digits-auth',
			$this->plugin->getFrontScriptUrl() . 'digits.js',
			[],
			$version,
			true
		);
		wp_localize_script(
			'novin-commerce-digits-auth',
			'NovinDigitsAuth',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'novin-digits-auth' ),
			]
		);
	}

	public function shortcode( $atts = [] ) {
		if ( is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts(
			[
				'redirect_to' => '',
			],
			$atts,
			'novin_digits_form'
		);

		return $this->render_form( 'shortcode', (string) $atts['redirect_to'] );
	}

	public function login_body_class( $classes ) {
		if ( $this->should_replace_wp_login() ) {
			$classes[] = 'novin-digits-login-active';
		}

		return $classes;
	}

	public function login_message( $message ) {
		if ( ! $this->should_replace_wp_login() ) {
			return $message;
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		return $message . $this->render_form( 'wp-login', $redirect_to );
	}

	public function start_woocommerce_capture() {
		if ( ! $this->is_enabled() || is_user_logged_in() || null !== $this->woo_buffer_level ) {
			return;
		}

		$this->woo_buffer_level = ob_get_level();
		ob_start();
	}

	public function finish_woocommerce_capture() {
		if ( null === $this->woo_buffer_level || ob_get_level() <= $this->woo_buffer_level ) {
			return;
		}

		ob_end_clean();
		$this->woo_buffer_level = null;

		$redirect_to = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
		echo $this->render_form( 'woocommerce', $redirect_to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Start a final, page-level capture for account pages rendered outside the
	 * normal WordPress/WooCommerce content hooks.
	 *
	 * @return void
	 */
	public function start_full_page_capture() {
		if ( ! $this->is_enabled() || is_user_logged_in() || null !== $this->full_page_buffer_level ) {
			return;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		// Enqueue before wp_head/wp_footer render. This is idempotent when the
		// normal wp_enqueue_scripts hook already queued the Digits assets.
		$this->enqueue_assets();
		$this->full_page_buffer_level = ob_get_level();
		ob_start();
	}

	/**
	 * Finish the page-level capture and replace an account page that never
	 * rendered the Digits form. This covers builders such as Woodmart Plus
	 * that print an empty-cart template directly during page rendering.
	 *
	 * @return void
	 */
	public function finish_full_page_capture() {
		if ( null === $this->full_page_buffer_level ) {
			return;
		}

		$html = '';
		while ( ob_get_level() > $this->full_page_buffer_level ) {
			$chunk = ob_get_clean();
			if ( false === $chunk ) {
				break;
			}
			// Inner buffers contain the later part of the document; prepend
			// each outer buffer so the original output order is preserved.
			$html = $chunk . $html;
		}
		$this->full_page_buffer_level = null;

		if ( false !== strpos( $html, 'novin-digits-auth' ) ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$redirect_to = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		$form         = $this->render_form( 'woocommerce-fallback', $redirect_to );
		$form        .= $this->render_full_page_assets();
		$body_matches = 0;
		$updated_html  = preg_replace_callback(
			'~(<body\b[^>]*>).*?(</body\s*>)~is',
			static function ( $matches ) use ( $form ) {
				return $matches[1] . $form . $matches[2];
			},
			$html,
			1,
			$body_matches
		);

		if ( 1 === $body_matches && is_string( $updated_html ) ) {
			echo $updated_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// Last-resort fallback for a non-standard response without body tags.
		echo $form . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Re-emit the assets because the full-page fallback replaces the body and
	 * can therefore remove scripts normally printed by wp_footer.
	 *
	 * @return string
	 */
	private function render_full_page_assets() {
		$style_url  = add_query_arg( 'ver', $this->plugin->get_version(), $this->plugin->getFrontStyleUrl() . 'digits.css' );
		$script_url = add_query_arg( 'ver', $this->plugin->get_version(), $this->plugin->getFrontScriptUrl() . 'digits.js' );
		$config     = wp_json_encode(
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'novin-digits-auth' ),
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		return '<link rel="stylesheet" id="novin-commerce-digits-auth-full-page-css" href="' . esc_url( $style_url ) . '" type="text/css" media="all" />' .
			'<script id="novin-commerce-digits-auth-full-page-config">window.NovinDigitsAuth=' . $config . ';</script>' .
			'<script id="novin-commerce-digits-auth-full-page-js" src="' . esc_url( $script_url ) . '"></script>';
	}

	/**
	 * Fallback for block-based/custom My Account pages.
	 *
	 * Older themes use the classic WooCommerce template and are handled by the
	 * before/after actions above. Newer themes can render a block or even leave
	 * a cart shortcode on the page; in that case the visitor sees the empty-cart
	 * message and never gets a login form. Only replace the content of the
	 * configured My Account page for logged-out visitors, and leave all logged-
	 * in account content (including a real cart) untouched.
	 *
	 * @param string $content Rendered page content.
	 * @return string
	 */
	public function replace_account_login_content( $content ) {
		if ( ! $this->is_enabled() || is_admin() || is_user_logged_in() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return $content;
		}
		// The classic WooCommerce hooks may already have inserted the form.
		if ( false !== strpos( (string) $content, 'novin-digits-auth' ) ) {
			return $content;
		}

		$redirect_to = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : get_permalink();
		return $this->render_form( 'woocommerce-fallback', $redirect_to );
	}

	public function ajax_request_otp() {
		$this->verify_ajax_request();

		$phone = $this->get_phone_from_request();
		if ( ! $this->is_valid_phone( $phone ) ) {
			wp_send_json_error( [ 'message' => 'لطفاً یک شماره موبایل معتبر وارد کنید.' ], 400 );
		}

		$existing_user = $this->find_user_by_phone( $phone );
		if ( is_wp_error( $existing_user ) ) {
			wp_send_json_error( [ 'message' => $existing_user->get_error_message() ], 409 );
		}
		if ( 'on' !== Digits_Settings::get( 'registration_enabled', 'on' ) && ! $existing_user ) {
			wp_send_json_error( [ 'message' => 'ثبت‌نام کاربران جدید در حال حاضر فعال نیست.' ], 403 );
		}

		$result = Otp_Manager::request_otp( $phone, 'login' );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ?? 'ارسال کد تأیید ناموفق بود.' ], 400 );
		}

		wp_send_json_success(
			[
				'message' => $result['message'] ?? 'کد تأیید ارسال شد.',
				'ttl'     => max( 60, (int) Digits_Settings::get( 'otp_ttl_seconds', 120 ) ),
			]
		);
	}

	public function ajax_verify_otp() {
		$this->verify_ajax_request();

		$phone = $this->get_phone_from_request();
		$code  = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
		$mode  = sanitize_key( Digits_Settings::get( 'login_mode', 'otp_only' ) );
		$mode  = in_array( $mode, [ 'otp_only', 'otp_and_password', 'password_optional_otp', 'unified_form' ], true ) ? $mode : 'otp_only';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( ! $this->is_valid_phone( $phone ) || ! preg_match( '/^[0-9]{4,8}$/', $code ) ) {
			wp_send_json_error( [ 'message' => 'شماره موبایل یا کد تأیید معتبر نیست.' ], 400 );
		}

		$user = $this->find_user_by_phone( $phone );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( [ 'message' => $user->get_error_message() ], 409 );
		}

		// In password-enabled modes, validate the OTP but defer deleting it
		// until the password (and any registration validation) succeeds.
		// A wrong password therefore does not burn a valid SMS code.
		$defer_otp_consumption = in_array( $mode, [ 'otp_and_password', 'password_optional_otp' ], true );
		$result = Otp_Manager::verify_otp( $phone, $code, 'login', ! $defer_otp_consumption );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ?? 'کد تأیید صحیح نیست.' ], 400 );
		}

		if ( $user ) {
			if ( 'otp_and_password' === $mode && ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) ) {
				wp_send_json_error( [ 'message' => 'رمز عبور این حساب صحیح نیست.' ], 403 );
			}
			if ( 'password_optional_otp' === $mode && '' !== $password && ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
				wp_send_json_error( [ 'message' => 'رمز عبور این حساب صحیح نیست.' ], 403 );
			}
			if ( $defer_otp_consumption && ! Otp_Manager::consume_otp( $result['otp_id'] ?? 0 ) ) {
				wp_send_json_error( [ 'message' => 'کد تأیید قبلاً مصرف شده است. لطفاً کد جدیدی درخواست کنید.' ], 409 );
			}

			$this->log_user_in( $user );
			wp_send_json_success(
				[
					'message'  => 'ورود با موفقیت انجام شد.',
					'redirect' => $this->get_redirect_url( false ),
				]
			);
		}

		if ( 'on' !== Digits_Settings::get( 'registration_enabled', 'on' ) ) {
			wp_send_json_error( [ 'message' => 'ثبت‌نام کاربران جدید در حال حاضر فعال نیست.' ], 403 );
		}

		if ( 'otp_and_password' === $mode && '' === $password ) {
			wp_send_json_error( [ 'message' => 'برای ثبت‌نام، وارد کردن رمز عبور الزامی است.' ], 400 );
		}
		if ( '' !== $password && ! $this->is_strong_password_allowed( $password ) ) {
			wp_send_json_error( [ 'message' => 'رمز عبور باید حداقل ۸ کاراکتر و شامل حروف و عدد باشد.' ], 400 );
		}

		// Consume before creating a new account. This makes the OTP DELETE the
		// race winner: two concurrent registration requests cannot both create
		// accounts from the same validated code.
		if ( $defer_otp_consumption && 1 !== Otp_Manager::consume_otp( $result['otp_id'] ?? 0 ) ) {
			wp_send_json_error( [ 'message' => 'کد تأیید قبلاً مصرف شده است. لطفاً کد جدیدی درخواست کنید.' ], 409 );
		}

		$user_id = $this->create_user( $phone, $password );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( [ 'message' => $user_id->get_error_message() ], 400 );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			wp_send_json_error( [ 'message' => 'ساخت حساب کاربری ناموفق بود.' ], 500 );
		}

		$this->log_user_in( $user );
		wp_send_json_success(
			[
				'message'  => 'ثبت‌نام و ورود با موفقیت انجام شد.',
				'redirect' => $this->get_redirect_url( true ),
			]
		);
	}

	private function verify_ajax_request() {
		if ( ! check_ajax_referer( 'novin-digits-auth', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'درخواست امنیتی نامعتبر است.' ], 403 );
		}
		$honeypot = isset( $_POST['novin_website'] ) ? trim( (string) wp_unslash( $_POST['novin_website'] ) ) : '';
		if ( '' !== $honeypot ) {
			wp_send_json_error( [ 'message' => 'درخواست نامعتبر است.' ], 400 );
		}
		if ( ! $this->is_enabled() ) {
			wp_send_json_error( [ 'message' => 'ورود با شماره موبایل فعال نیست.' ], 403 );
		}
	}

	private function get_phone_from_request() {
		$raw = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		return Otp_Manager::normalize_phone( $raw );
	}

	private function is_valid_phone( $phone ) {
		$country_code = preg_replace( '/[^0-9]/', '', (string) Digits_Settings::get( 'default_country_code', '98' ) );
		$country_code = $country_code ? $country_code : '98';

		// Otp_Manager normalizes local input to the configured country code.
		// Accept a mobile subscriber portion between 8 and 14 digits so the
		// selected country setting is respected instead of hard-coding Iran.
		$pattern = '/^' . preg_quote( $country_code, '/' ) . '[1-9][0-9]{7,13}$/';
		return (bool) preg_match( $pattern, (string) $phone );
	}

	private function find_user_by_phone( $phone ) {
		$local  = $this->local_phone( $phone );
		$values = array_unique( [ (string) $phone, '+' . (string) $phone, (string) $local ] );
		$meta_query = [ 'relation' => 'OR' ];
		foreach ( $values as $value ) {
			// digits_phone_no is the canonical, normalized Digits identity.
			// Do not trust billing/shipping fields: users can edit them.
			$meta_query[] = [ 'key' => 'digits_phone_no', 'value' => $value, 'compare' => '=' ];
		}

		$users = get_users(
			[
				'number'     => 2,
				'fields'     => 'all',
				'meta_query' => $meta_query,
			]
		);

		if ( count( $users ) > 1 ) {
			return new \WP_Error( 'novin_duplicate_phone', 'این شماره به بیش از یک حساب کاربری متصل است. لطفاً با مدیر سایت تماس بگیرید.' );
		}

		return ! empty( $users ) && $users[0] instanceof \WP_User ? $users[0] : false;
	}

	private function local_phone( $phone ) {
		$country_code = preg_replace( '/[^0-9]/', '', (string) Digits_Settings::get( 'default_country_code', '98' ) );
		$country_code = $country_code ? $country_code : '98';
		$phone = (string) $phone;
		return 0 === strpos( $phone, $country_code ) ? '0' . substr( $phone, strlen( $country_code ) ) : $phone;
	}

	private function create_user( $phone, $password = '' ) {
		$local = $this->local_phone( $phone );
		$base  = sanitize_user( 'mobile_' . substr( $phone, -10 ), true );
		$login = $base;
		$suffix = 1;
		while ( username_exists( $login ) ) {
			$login = $base . '_' . $suffix;
			$suffix++;
		}

		if ( '' === $password ) {
			$password = wp_generate_password( 32, true, true );
		}

		$role = get_role( 'customer' ) ? 'customer' : 'subscriber';
		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => $local,
			]
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'digits_phone', '+' . $phone );
		update_user_meta( $user_id, 'digits_phone_no', $local );
		update_user_meta( $user_id, 'billing_phone', $local );
		update_user_meta( $user_id, 'shipping_phone', $local );

		return $user_id;
	}

	private function is_strong_password_allowed( $password ) {
		if ( 'on' !== Digits_Settings::get( 'require_strong_password', 'off' ) ) {
			return true;
		}
		return strlen( $password ) >= 8 && preg_match( '/[A-Za-z]/', $password ) && preg_match( '/[0-9]/', $password );
	}

	private function log_user_in( \WP_User $user ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'wp_login', $user->user_login, $user );
	}

	private function get_redirect_url( $is_registration ) {
		$key = $is_registration ? 'register_redirect' : 'login_redirect';
		$configured = Digits_Settings::get( $key, '' );
		$requested  = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$default    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		$target     = $configured ? $configured : ( $requested ? $requested : $default );

		return wp_validate_redirect( $target, $default );
	}

	private function is_enabled() {
		return 'on' === Digits_Settings::get( 'enabled', 'off' );
	}

	private function should_replace_wp_login() {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( 'wp-login.php' !== $script ) {
			return false;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
		return in_array( $action, [ '', 'login', 'register' ], true );
	}

	private function render_form( $context, $redirect_to = '' ) {
		if ( ! $this->is_enabled() ) {
			return '';
		}
		$this->enqueue_assets();

		static $counter = 0;
		$counter++;
		$id = 'novin-digits-auth-' . $counter;
		$mode = sanitize_key( Digits_Settings::get( 'login_mode', 'otp_only' ) );
		$mode = in_array( $mode, [ 'otp_only', 'otp_and_password', 'password_optional_otp', 'unified_form' ], true ) ? $mode : 'otp_only';
		$redirect_to = $redirect_to ? esc_url_raw( $redirect_to ) : '';
		$password_mode = in_array( $mode, [ 'otp_and_password', 'password_optional_otp' ], true );
		$registration_enabled = 'on' === Digits_Settings::get( 'registration_enabled', 'on' );
		$mode_label = 'unified_form' === $mode ? 'ورود و ثبت‌نام با شماره موبایل' : 'ورود با شماره موبایل';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="novin-digits-auth" dir="rtl"
			data-login-mode="<?php echo esc_attr( $mode ); ?>"
			data-redirect-to="<?php echo esc_attr( $redirect_to ); ?>"
			data-registration-enabled="<?php echo $registration_enabled ? '1' : '0'; ?>">
			<div class="novin-digits-auth-card">
				<h2><?php echo esc_html( $mode_label ); ?></h2>
				<p class="novin-digits-auth-description">شماره موبایل خود را وارد کنید تا کد تأیید برای شما ارسال شود.</p>
				<form class="novin-digits-auth-form" novalidate>
					<div class="novin-digits-honeypot" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">
						<label for="<?php echo esc_attr( $id . '-website' ); ?>">وب‌سایت</label>
						<input id="<?php echo esc_attr( $id . '-website' ); ?>" name="novin_website" type="text" tabindex="-1" autocomplete="off">
					</div>
					<div class="novin-digits-auth-phone-step">
						<label for="<?php echo esc_attr( $id . '-phone' ); ?>">شماره موبایل</label>
						<input id="<?php echo esc_attr( $id . '-phone' ); ?>" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="09123456789" required>
						<?php if ( $password_mode ) : ?>
							<label for="<?php echo esc_attr( $id . '-password' ); ?>">رمز عبور<?php echo 'password_optional_otp' === $mode ? ' (اختیاری)' : ''; ?></label>
							<input id="<?php echo esc_attr( $id . '-password' ); ?>" name="password" type="password" autocomplete="current-password" dir="ltr" <?php echo 'otp_and_password' === $mode ? 'required' : ''; ?>>
						<?php endif; ?>
						<button type="submit" class="button button-primary novin-digits-request-button">دریافت کد تأیید</button>
					</div>
					<div class="novin-digits-auth-otp-step" hidden>
						<label for="<?php echo esc_attr( $id . '-code' ); ?>">کد تأیید پیامک‌شده</label>
						<input id="<?php echo esc_attr( $id . '-code' ); ?>" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="8" pattern="[0-9]{4,8}">
						<button type="submit" class="button button-primary novin-digits-verify-button">تأیید و ادامه</button>
						<button type="button" class="button-link novin-digits-resend-button">ارسال مجدد</button>
						<span class="novin-digits-countdown" aria-live="polite"></span>
					</div>
					<div class="novin-digits-auth-message" role="alert" aria-live="polite"></div>
				</form>
				<noscript>برای استفاده از ورود با شماره موبایل، اجرای JavaScript باید فعال باشد.</noscript>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
