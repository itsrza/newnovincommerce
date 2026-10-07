<?php
namespace Novinwp\Novin_Commerce\Admin;

use Novinwp\Novin_Commerce\Plugin;

class Sync_Menu {
    protected $plugin;
    protected $item_list_table;
    public function __construct( Plugin $plugin ) { $this->plugin = $plugin; }

    public function load() {
        add_screen_option( 'per_page', [ 'label'=>'تعداد در هر صفحه', 'default'=>20, 'option'=>'novin_sync_per_page' ] );
        $this->item_list_table = new Sync_List_Table();
    }

    public function output() {
        if ( ! $this->item_list_table ) $this->item_list_table = new Sync_List_Table();
        wp_enqueue_style( $this->plugin->get_plugin_name() . '-admin-table', $this->plugin->getAdminStyleUrl() . 'table.min.css', [], $this->plugin->get_version() );
        $this->item_list_table->prepare_items();
        echo '<div class="wrap novin-sync-page" dir="rtl">';
        echo '<div class="novin-sync-header"><div><span class="novin-kicker">NOVIN COMMERCE</span><h1>همگام‌سازی‌ها</h1><p>مواردی که برای تبادل اطلاعات در صف قرار گرفته‌اند.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=novin-commerce-dashboard')).'">بازگشت به داشبورد</a></div>';
        if ( ! $this->item_list_table->items ) echo '<div class="novin-sync-empty">در حال حاضر موردی در صف تبادل نیست.</div>';
        echo '<form method="post"><input type="hidden" name="page" value="novin-commerce-syncs">';
        $this->item_list_table->display();
        echo '</form></div>';
    }
}
