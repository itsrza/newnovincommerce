<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کنترل دسترسی به پیشخوان و نوار مدیریت وردپرس.
 *
 * فقط نقش administrator اجازه استفاده از wp-admin و Admin Bar را دارد.
 * تمام نقش‌های دیگر (از جمله Author, Editor, Contributor, Subscriber و نقش‌های سفارشی)
 * از پیشخوان خارج می‌شوند و Admin Bar را نمی‌بینند.
 */
class NovinCommerce_RolePrice_Access_Control {

	const ADMIN_ROLE = 'administrator';

	public function __construct() {
		// کنترل استاندارد Admin Bar.
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_non_admins' ), PHP_INT_MAX );

		// کنترل مستقیم wp-admin.
		add_action( 'admin_init', array( $this, 'block_dashboard_for_non_admins' ), 1 );

		// جلوگیری از نمایش Admin Bar حتی در صورت فراخوانی غیرمعمول توسط قالب/افزونه.
		add_action( 'init', array( $this, 'enforce_frontend_admin_bar_restriction' ), 1 );

		// Redirect بعد از Login.
		add_filter( 'login_redirect', array( $this, 'login_redirect_for_non_admins' ), 10, 3 );
	}

	/**
	 * فقط نقش administrator مجاز است.
	 * بررسی بر اساس role انجام می‌شود، نه capability.
	 */
	public static function is_administrator( $user = null ) {
		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		if ( ! ( $user instanceof WP_User ) || empty( $user->roles ) ) {
			return false;
		}

		return in_array( self::ADMIN_ROLE, (array) $user->roles, true );
	}

	/**
	 * Admin Bar برای تمام کاربران لاگین‌شده غیر Administrator خاموش می‌شود.
	 */
	public function hide_admin_bar_for_non_admins( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		if ( self::is_administrator() ) return $show;
		$user = wp_get_current_user();
		foreach ( (array) $user->roles as $role ) { if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::role_can_access_admin( $role ) ) return $show; }
		return false;
	}

	/**
	 * محدود کردن کامل wp-admin برای نقش‌های غیر Administrator.
	 * AJAX و admin-post.php آزاد هستند تا عملکرد ووکامرس و فرم‌های عمومی مختل نشود.
	 */
	public function block_dashboard_for_non_admins() {
		if ( ! is_user_logged_in() || self::is_administrator() ) {
			return;
		}
		$user = wp_get_current_user();
		foreach ( (array) $user->roles as $role ) { if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::role_can_access_admin( $role ) ) return; }

		// admin-ajax.php باید برای درخواست‌های AJAX عمومی و ووکامرس قابل استفاده باشد.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		// admin-post.php برای پردازش فرم‌ها و اکشن‌های عمومی استفاده می‌شود.
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}

		wp_safe_redirect( $this->get_redirect_target(), 302 );
		exit;
	}

	/**
	 * لایه دوم برای جلوگیری از Admin Bar در Frontend.
	 * این بخش فقط روی کاربران غیر Administrator اعمال می‌شود.
	 */
	public function enforce_frontend_admin_bar_restriction() {
		if ( ! is_user_logged_in() || self::is_administrator() ) {
			return;
		}
		$user = wp_get_current_user();
		foreach ( (array) $user->roles as $role ) { if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::role_can_access_admin( $role ) ) return; }

		// مقدار WordPress را به صورت قطعی false نگه می‌داریم.
		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
	}

	/**
	 * بعد از Login، کاربر غیر Administrator به حساب کاربری ووکامرس یا خانه سایت می‌رود.
	 */
	public function login_redirect_for_non_admins( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $redirect_to;
		}

		if ( self::is_administrator( $user ) ) return $redirect_to;
		foreach ( (array) $user->roles as $role ) { if ( class_exists( 'NovinCommerce_RolePrice_Settings' ) && NovinCommerce_RolePrice_Settings::role_can_access_admin( $role ) ) return $redirect_to; }
		return $this->get_redirect_target();
	}

	/**
	 * مقصد Redirect.
	 */
	private function get_redirect_target() {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'myaccount' );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}
}
