<?php
namespace Novinwp\Novin_Commerce\Admin;

use Novinwp\Novin_Commerce\Models\Sync;

/**
 * Dedicated, lightweight queue table.
 * It does not extend List_Table because queue rows are not WordPress posts
 * and List_Table's row renderer/actions assume post-backed items. Extending
 * \WP_List_Table directly avoids fatal errors and accidental post lookups.
 */
class Sync_List_Table extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'sync',
            'plural'   => 'syncs',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'cb'        => '<input type="checkbox" />',
            'sync_id'   => 'شناسه',
            'item_id'   => 'شناسه مورد',
            'item_type' => 'نوع مورد',
            'priority'  => 'اولویت',
            'created_at'=> 'زمان ورود به صف',
        ];
    }

    public function get_bulk_actions() {
        return [ 'delete' => 'حذف از صف' ];
    }

    public function column_cb( $item ) {
        return sprintf(
            "<input type='checkbox' name='sync_items[]' value='%s' />",
            esc_attr( absint( $item['item_id'] ?? 0 ) . ':' . sanitize_key( $item['item_type'] ?? '' ) )
        );
    }

    public function get_sortable_columns() {
        return [
            'sync_id'    => ['sync_id', true],
            'item_id'    => ['item_id', false],
            'item_type'  => ['item_type', false],
            'priority'   => ['priority', false],
            'created_at' => ['created_at', false],
        ];
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'sync_id':
                $id        = '#' . absint( $item['id'] ?? 0 );
                $item_id   = absint( $item['item_id'] ?? 0 );
                $item_type = sanitize_key( $item['item_type'] ?? '' );
                $delete_url = wp_nonce_url(
                    add_query_arg( [ 'page' => 'novin-commerce-syncs', 'action' => 'delete', 'item_id' => $item_id, 'item_type' => $item_type ], admin_url( 'admin.php' ) ),
                    'novin-sync-row-delete_' . $item_id . '_' . $item_type
                );
                $actions = [ 'delete' => '<a href="' . esc_url( $delete_url ) . '" class="novin-sync-row-delete" onclick="return confirm(\'این مورد از صف تبادل حذف شود؟\');">حذف</a>' ];
                return $id . $this->row_actions( $actions );
            case 'item_id': return absint( $item['item_id'] ?? 0 );
            case 'item_type':
                $labels = array( 'product' => 'کالای اصلی', 'variation' => 'تنوع متغیر', 'order' => 'فاکتور', 'category' => 'دسته‌بندی', 'user' => 'شخص' );
                return esc_html( $labels[ $item['item_type'] ?? '' ] ?? 'مورد تبادل' );
            case 'priority':
                $priority = (int)($item['priority'] ?? 0);
                return $priority > 0 ? '<strong class="novin-sync-priority-high">بالا (' . $priority . ')</strong>' : '<span>عادی</span>';
            case 'created_at': return esc_html( (string)($item['created_at'] ?? '—') );
        }
        return '';
    }

    public function fetchTableData() {
        $rows = Sync::orderBy( 'priority', 'desc' )
            ->orderBy( 'id', 'asc' )
            ->get()
            ->toArray();

        return is_array( $rows ) ? $rows : [];
    }

    private function handleRowDelete() {
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'delete' !== $action || empty( $_GET['item_id'] ) || empty( $_GET['item_type'] ) ) return;
        $item_id   = absint( $_GET['item_id'] );
        $item_type = sanitize_key( wp_unslash( $_GET['item_type'] ) );
        check_admin_referer( 'novin-sync-row-delete_' . $item_id . '_' . $item_type );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
        if ( Sync::removeItem( $item_id, $item_type ) ) {
            AdminNotice::addSuccessDismissible( 'مورد از صف تبادل حذف شد.' );
        }
        wp_safe_redirect( remove_query_arg( [ 'action', 'item_id', 'item_type', '_wpnonce' ] ) );
        exit;
    }

    private function handleBulkDelete() {
        $action  = isset( $_REQUEST['action'] ) && -1 !== (int) $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $action2 = isset( $_REQUEST['action2'] ) && -1 !== (int) $_REQUEST['action2'] ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
        $action  = $action ?: $action2;
        if ( 'delete' !== $action || empty( $_REQUEST['sync_items'] ) || ! is_array( $_REQUEST['sync_items'] ) ) return;
        check_admin_referer( 'bulk-' . $this->_args['plural'] );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'دسترسی غیرمجاز.', 'novin-commerce' ) );
        $done = 0;
        foreach ( wp_unslash( $_REQUEST['sync_items'] ) as $value ) {
            $parts = explode( ':', (string) $value );
            if ( count( $parts ) !== 2 ) continue;
            [ $item_id, $item_type ] = $parts;
            if ( Sync::removeItem( absint( $item_id ), sanitize_key( $item_type ) ) ) $done++;
        }
        if ( $done ) {
            AdminNotice::addSuccessDismissible( sprintf( '%d مورد از صف تبادل حذف شد.', $done ) );
        }
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=novin-commerce-syncs' ) );
        exit;
    }

    public function prepare_items() {
        $this->handleRowDelete();
        $this->handleBulkDelete();

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $data = $this->fetchTableData();

        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'priority';
        $order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

        usort( $data, function ( $a, $b ) use ( $orderby, $order ) {
            $key  = in_array( $orderby, [ 'sync_id', 'item_id', 'item_type', 'priority', 'created_at' ], true ) ? ( 'sync_id' === $orderby ? 'id' : $orderby ) : 'id';
            $valA = $a[ $key ] ?? '';
            $valB = $b[ $key ] ?? '';
            if ( is_numeric( $valA ) && is_numeric( $valB ) ) {
                $cmp = $valA <=> $valB;
            } else {
                $cmp = strcmp( (string) $valA, (string) $valB );
            }
            return 'asc' === $order ? $cmp : -$cmp;
        } );

        $per_page     = $this->get_items_per_page( 'novin_sync_per_page', 20 );
        $current_page = $this->get_pagenum();
        $total_items  = count( $data );

        $this->items = array_slice( $data, ( $current_page - 1 ) * $per_page, $per_page );
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ] );
    }
}
