<?php

namespace Novinwp\Novin_Commerce\Admin;

use Novinwp\Novin_Commerce\Plugin;

class Item_Menu {
	/**
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * @var List_Table
	 */
	protected $item_list_table;

	protected $name;
	protected $plural;
	protected $singular;
	protected $search = true;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function load() {
		$arguments = [
			'label'   => __( 'تعداد در هر صفحه', $this->plugin->get_plugin_name() ),
			'default' => 20,
			'option'  => 'per_page'
		];
		add_screen_option( 'per_page', $arguments );

		$class_name            = __NAMESPACE__ . '\\' . ucfirst( $this->name ) . '_List_Table';
		$this->item_list_table = new $class_name( [
			'_plural'   => __( $this->_plural ?? $this->plural, $this->plugin->get_plugin_name() ),
			'_singular' => __( $this->_singular ?? $this->singular, $this->plugin->get_plugin_name() ),
			'plural'    => $this->plural,
			'singular'  => $this->singular,
		] );
	}

	public function enqueueAssets() {
		wp_enqueue_style(
			$this->plugin->get_plugin_name() . '-admin-table',
			$this->plugin->getAdminStyleUrl() . 'table.min.css',
			[],
			$this->plugin->get_version()
		);
	}

	public function output() {
		$this->enqueueAssets();
		$this->item_list_table->prepare_items();

		echo '<div class="wrap" dir="rtl">';
		echo '<div class="novin-item-header"><div><span class="novin-kicker">NOVIN COMMERCE</span><h1>' . esc_html( $this->_plural ?? $this->plural ) . '</h1><p>' . esc_html( $this->_description ?? '' ) . '</p></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=novin-commerce-dashboard' ) ) . '">بازگشت به داشبورد</a></div>';
		echo '<div id="novin-item-table" class="novin-item-table"><div id="novin-item-table-body">';

		// ✅ فرم استاندارد WP_List_Table
		echo '<form id="posts-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $_REQUEST['page'] ) . '" />';

		// اگر پارامترهای دیگری مثل tab، type یا section داری، نگه‌دار
		foreach ( ['post_type', 'tab', 'section'] as $keep ) {
			if ( isset( $_REQUEST[ $keep ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $keep ) . '" value="' . esc_attr( $_REQUEST[ $keep ] ) . '" />';
			}
		}

		// The single search input is rendered by extra_tablenav() beside the
		// filters; do not add WP_List_Table's second search box here.

		// جدول نهایی (فیلترها، اکشن‌ها، pagination و ...)
		$this->item_list_table->display();

		echo '</form></div></div></div>';
	}
}
