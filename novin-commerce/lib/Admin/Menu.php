<?php

namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Models\Sync;
use MobinDev\Novin_Commerce\Plugin;

class Menu {
	/**
	 * The plugin's instance.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    Plugin $plugin This plugin's instance.
	 */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	public function hooks() {
		$this->plugin->get_loader()->add_action( 'admin_menu', $this, 'add' );
		$this->plugin->get_loader()->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
	}

	public function enqueue_assets( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$pages = [
			'novin-commerce-products',
			'novin-commerce-dashboard',
			'novin-commerce-categories',
			'novin-commerce-users',
			'novin-commerce-orders',
			'novin-commerce-syncs',
			'novin-commerce-settings',
			'novin-commerce-mismatch',
		];
		if ( ! in_array( $page, $pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'novin-commerce-admin-table',
			$this->plugin->getAdminStyleUrl() . 'table.min.css',
			[],
			$this->plugin->get_version()
		);
	}

	public function add() {
		$product_menu = new Product_Menu( $this->plugin );

		add_menu_page(
			'نوین‌کامرس',
			'نوین‌کامرس',
			'manage_woocommerce',
			'novin-commerce-products',
			[ $product_menu, 'output' ],
			$this->icon(),
			'55',
		);

		$dashboard = add_submenu_page(
			'novin-commerce-products',
			'داشبورد اتصال',
			'داشبورد اتصال',
			'manage_woocommerce',
			'novin-commerce-dashboard',
			[ Connection_Dashboard::class, 'render' ],
			'5'
		);

		// 🟢 کالاها
		$product_prefix  = add_submenu_page(
			'novin-commerce-products',
			'کالاها',
			'کالاها',
			'manage_woocommerce',
			'novin-commerce-products',
			[ $product_menu, 'output' ],
			'10',
		);

		// 🟢 دسته‌بندی‌ها
		$category_menu   = new Category_Menu( $this->plugin );
		$category_prefix = add_submenu_page(
			'novin-commerce-products',
			'دسته‌بندی‌ها',
			'دسته‌بندی‌ها',
			'manage_woocommerce',
			'novin-commerce-categories',
			[ $category_menu, 'output' ],
			'20',
		);

		// 🟢 اشخاص
		$user_menu       = new User_Menu( $this->plugin );
		$user_prefix     = add_submenu_page(
			'novin-commerce-products',
			'اشخاص',
			'اشخاص',
			'manage_woocommerce',
			'novin-commerce-users',
			[ $user_menu, 'output' ],
			'30',
		);

		// 🟢 فاکتورها
		$order_menu      = new Order_Menu( $this->plugin );
		$order_prefix    = add_submenu_page(
			'novin-commerce-products',
			'فاکتورها',
			'فاکتورها',
			'manage_woocommerce',
			'novin-commerce-orders',
			[ $order_menu, 'output' ],
			'40',
		);

		// 🟢 همگام‌سازی‌ها
		$sync_menu       = new Sync_Menu( $this->plugin );
		$syncs_count     = Sync::count();
		$bubble          = $syncs_count ? '<span class="awaiting-mod">' . $syncs_count . '</span>' : '';
		$sync_prefix     = add_submenu_page(
			'novin-commerce-products',
			'همگام‌سازی‌ها',
			'همگام‌سازی‌ها' . $bubble,
			'manage_woocommerce',
			'novin-commerce-syncs',
			[ $sync_menu, 'output' ],
			'50',
		);

		// 🟣 منوی مغایرت‌گیری حسابداری
		$mismatch_count = class_exists(Mismatch_Page::class) ? Mismatch_Page::getMismatchCount() : 0;
		$mismatch_badge = $mismatch_count ? '<span class="update-plugins count-' . $mismatch_count . '"><span class="plugin-count">' . $mismatch_count . '</span></span>' : '';

		$mismatch_prefix = add_submenu_page(
			'novin-commerce-products',
			'مغایرت‌گیری ارتباطات با نرم‌افزار حسابداری',
			'مغایرت‌گیری' . $mismatch_badge,
			'manage_woocommerce',
			'novin-commerce-mismatch',
			[ \MobinDev\Novin_Commerce\Admin\Mismatch_Page::class, 'renderPage' ],
			'60',
		);

		// 🟢 تنظیمات
		$setting_menu    = new Setting_Menu( $this->plugin );
		$setting_prefix  = add_submenu_page(
			'novin-commerce-products',
			'تنظیمات',
			'تنظیمات',
			'manage_options',
			'novin-commerce-settings',
			[ $setting_menu, 'output' ],
			'70',
		);

		// ✅ بارگذاری اکشن‌ها
		add_action( 'load-' . $product_prefix, [ $product_menu, 'load' ] );
		add_action( 'load-' . $category_prefix, [ $category_menu, 'load' ] );
		add_action( 'load-' . $user_prefix, [ $user_menu, 'load' ] );
		add_action( 'load-' . $order_prefix, [ $order_menu, 'load' ] );
		add_action( 'load-' . $sync_prefix, [ $sync_menu, 'load' ] );
		add_action( 'load-' . $setting_prefix, [ $setting_menu, 'load' ] );

		// The parent menu already opens the product list. Remove the duplicate submenu entry.
		remove_submenu_page( 'novin-commerce-products', 'novin-commerce-products' );
	}

	private function icon() {
		return 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiID8+DQo8IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDEuMS8vRU4iICJodHRwOi8vd3d3LnczLm9yZy9HcmFwaGljcy9TVkcvMS4xL0RURC9zdmcxMS5kdGQiPg0KPHN2ZyB3aWR0aD0iMjgxcHQiIGhlaWdodD0iMjgxcHQiIHZpZXdCb3g9IjAgMCAyODEgMjgxIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+DQo8ZyBpZD0iI2ZmZmZmZmZmIj4NCjxwYXRoIGZpbGw9IiNmZmZmZmYiIGQ9IiBNIDEzOS4zNyAwLjAwIEwgMTQxLjYxIDAuMDAgQyAxNjUuNzEgMC4zMCAxOTAuMDcgMC43NCAyMTMuNjMgNi4zNyBDIDIyOC40MiA5LjkwIDI0My4wNyAxNi4xNCAyNTMuOTYgMjcuMDQgQyAyNjQuODcgMzcuOTMgMjcxLjEwIDUyLjU4IDI3NC42MyA2Ny4zNyBDIDI4MC4yNiA5MC45MiAyODAuNzAgMTE1LjI4IDI4MS4wMCAxMzkuMzcgTCAyODEuMDAgMTQxLjYzIEMgMjgwLjY3IDE2Ny4wNyAyODAuMjQgMTkyLjg3IDI3My42MSAyMTcuNjAgQyAyNjkuOTEgMjMxLjIxIDI2My42NiAyNDQuNTEgMjUzLjQ2IDI1NC40NiBDIDI0My4wMSAyNjQuNjcgMjI5LjIyIDI3MC43MiAyMTUuMjAgMjc0LjI0IEMgMTkyLjQ3IDI3OS45NSAxNjguODggMjgwLjU4IDE0NS41NyAyODEuMDAgTCAxMzUuNDYgMjgxLjAwIEMgMTEwLjI4IDI4MC41MiA4NC42OCAyNzkuODkgNjAuMzQgMjcyLjczIEMgNDcuMTcgMjY4Ljc4IDM0LjQzIDI2Mi4yMSAyNS4xMCAyNTEuOTIgQyAxNS4zNSAyNDEuMjYgOS43MCAyMjcuNTIgNi4zNiAyMTMuNjMgQyAxLjEwIDE5MS42OSAwLjQ0IDE2OS4wMSAwLjAwIDE0Ni41NiBMIDAuMDAgMTM5LjQxIEMgMC4yOSAxMTUuNTggMC43MyA5MS41MCA2LjE3IDY4LjE4IEMgOS41NCA1My43NyAxNS4zNSAzOS40OSAyNS41OCAyOC41NiBDIDM1LjYxIDE3Ljc3IDQ5LjM0IDExLjIxIDYzLjM5IDcuMzkgQyA4OC4xMiAwLjc2IDExMy45MyAwLjMzIDEzOS4zNyAwLjAwIE0gNTkuMjMgNDcuMjAgQyA0OC4zNiA1NC45OSA0Ni45MCA3Mi4zMCA1Ni4xMiA4MS45MSBDIDU4LjMzIDg0Ljg2IDYzLjA0IDg1Ljg3IDYzLjQzIDg5Ljk5IEMgNzEuMDAgMTIxLjc1IDEwMC4zOCAxNDYuODIgMTMzLjAwIDE0OS4wMCBDIDEzOS4zMSAxNTEuMTMgMTQ1LjY5IDE0OC45NCAxNTIuMDQgMTQ4LjQ5IEMgMTgyLjQxIDE0NC4yOCAyMDguOTAgMTIwLjUxIDIxNi40MSA5MC43OSBDIDIxNi43NSA4Ny4xNCAyMjAuNjIgODYuMDkgMjIyLjgzIDgzLjgwIEMgMjI3Ljk1IDc5LjQyIDIzMS4wNiA3Mi43NyAyMzAuNzUgNjYuMDAgQyAyMzEuNDAgNTMuNzEgMjIwLjM5IDQyLjEzIDIwOC4wNSA0Mi4zNSBDIDE5OS43NiA0MS43NCAxOTEuNDkgNDYuMDkgMTg3LjA4IDUzLjA4IEMgMTc5LjkxIDYzLjcwIDE4My40NSA3OS45MCAxOTQuNjggODYuMjcgQyAxODcuODggMTA5LjkxIDE2NC43MiAxMjcuNjcgMTQwLjAwIDEyNy4yNyBDIDExNS40MyAxMjcuNjcgOTIuNTMgMTEwLjExIDg1LjQ0IDg2Ljc2IEMgOTYuOTAgODAuMDYgMTAxLjI0IDYzLjcwIDkzLjI4IDUyLjczIEMgODYuMjAgNDEuNTggNjkuNDcgMzguODMgNTkuMjMgNDcuMjAgWiIvPg0KPC9nPg0KPC9zdmc+DQo=';
	}
}
