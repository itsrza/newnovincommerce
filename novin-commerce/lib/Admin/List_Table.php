<?php
namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Admin\AdminNotice;
use As247\WpEloquent\Database\Eloquent\Collection;
use MobinDev\Novin_Commerce\Models\Sync;
use Morilog\Jalali\Jalalian;

abstract class List_Table extends \WP_List_Table {
	protected $name;
	protected $syncs;

	public function __construct() {
		parent::__construct([
			'singular' => 'item',
			'plural'   => 'items',
			'ajax'     => false,
		]);
	}

	// ستون انتخاب (checkbox)
	final function column_cb($item) {
		$item_type = $this->getItemType( $item );
		return sprintf(
			"<input type='checkbox' name='%s[]' value='%s' />",
			esc_attr($item_type),
			esc_attr($this->getID($item))
		);
	}

	public function getItemType( $item ) {
		return $this->name;
	}

	public function getEditUrl( $item ) {
		return admin_url( 'post.php?post=' . absint( $this->getID( $item ) ) . '&action=edit' );
	}

	// ستون نام محصول
	final function column_name($item) {
		$admin_url = $this->getEditUrl( $item );
		$image     = get_the_post_thumbnail($this->getID($item), [50, 50], ['style' => 'border-radius:8px;']);
		if ($image) {
			$image = "<a href='{$admin_url}' target='_blank'>{$image}</a><br>";
		}

		// ✅ نام محصول را هم لینک‌دار کن
    $row_value = sprintf(
        '<strong><a href="%s" target="_blank" style="text-decoration:none;">%s</a></strong>',
        esc_url($admin_url),
        esc_html($this->getName($item))
    );

		$actions = [];
		if ( in_array( $this->getItemType( $item ), [ 'product', 'variation' ], true ) ) {
			$actions['detail'] = sprintf('<a href="%s">جزئیات اتصال</a>', esc_url( add_query_arg([ 'page' => 'novin-commerce-dashboard', 'item_id' => absint( $this->getID( $item ) ), 'item_type' => $this->getItemType( $item ) ], admin_url( 'admin.php' ) ) ));
		}
		$actions['sync'] = sprintf('<a href="%s">همگام‌سازی</a>', esc_url(add_query_arg([
				'page'     => wp_unslash($_REQUEST['page']),
				'action'   => 'sync',
				$this->getItemType( $item ) . 's' => absint( $this->getID( $item ) ),
				'_wpnonce' => wp_create_nonce('bulk-' . $this->_args['plural']),
			], admin_url('admin.php'))));

		return $image . $row_value . $this->row_actions($actions);
	}

	final function column_guid($item) {
    $guid       = $this->getGUID($item);
    $post_id    = $this->getID($item);
	$is_syncing = ($this->syncs && $this->syncs->where('item_id', $post_id)->count())
    ? $this->syncs->where('item_id', $post_id)->count()
    : 0;

    $output = '';

    if ($guid) {
        $output .= '<code>' . esc_html( $guid ) . '</code>';
        if ( method_exists( $this, 'getSourceGUID' ) ) {
            $source_guid = trim( (string) $this->getSourceGUID( $item ) );
            if ( $source_guid && $source_guid !== (string) $guid ) {
                $output .= '<br><small style="color:#b45309;">GUID حسابداری: <code>' . esc_html( $source_guid ) . '</code></small>';
            }
        }
    } else {
        $output .= "<span class='no-guid'>GUID موجود نیست</span>";
    }

    // برچسب حذف در صورت داشتن guid
    if (!empty($guid)) {
        $nonce = wp_create_nonce('remove_guid_' . $post_id);
        $item_type = method_exists( $this, 'getItemType' ) ? $this->getItemType( $item ) : $this->name;
        $output .= sprintf(
            '<br><a href="#" class="remove-guid" data-id="%d" data-type="%s" data-nonce="%s" style="color:#d63638;text-decoration:none;">❌ قطع ارتباط با حسابداری</a>',
            $post_id,
            esc_attr( $item_type ),
            $nonce
        );
    }

    if ($is_syncing) {
        $output .= "<br><span class='syncing'>در انتظار همگام‌سازی</span>";
    }

    return $output;
	}


	// ستون تاریخ همگام‌سازی
	protected function column_sync_date($item) {
		$sync_date_string = $this->getSyncDate($item);
		if ($sync_date_string) {
			try {
				$sync_date = Jalalian::fromDateTime($sync_date_string, wp_timezone());
				return $sync_date->format('Y-m-d H:i:s');
			} catch (\Throwable $e) {
				return esc_html($sync_date_string);
			}
		}
		return '';
	}

	public function get_columns() {
    return [
        'cb'             => '<input type="checkbox" />',
        'name'           => 'نام محصول',
        'slug'           => 'Slug',          // ✅ اضافه شد
        'sku'            => 'SKU',           // ✅ اضافه شد
        'category'       => 'دسته‌بندی',
        'price'          => 'قیمت',
        'stock_quantity' => 'موجودی',
        'id'             => 'ID',
        'guid'           => 'GUID',
        'sync_date'      => 'زمان همگام‌سازی',
    ];
}


	// ستون‌ها
	//public function get_columns() {
	//	return [
	//		'cb'        => '<input type="checkbox" />',
	//		'name'      => 'نام محصول',
	//		'guid'      => 'GUID',
	//		'slug'      => 'Slug',
	//		'sku'       => 'SKU',
	//		'sync_date' => 'زمان همگام‌سازی',
	//	];
	//}

	// ستون‌های قابل مرتب‌سازی
	public function get_sortable_columns() {
		return [
			'guid'      => ['guid', false],
			'name'      => ['name', false],
			'sync_date' => ['sync_date', false],
		];
	}

	// منوی فیلتر بالای جدول
function extra_tablenav($which) {
    if ($which === 'top') {
        $current_filter = $_REQUEST['guid_filter'] ?? 'all';
        $search_value   = esc_attr($_REQUEST['s'] ?? '');

        echo '<div class="alignleft actions novin-table-filters">';
        // فیلتر GUID
        echo '<label for="guid_filter">فیلتر:</label>';
        if ( 'product' === $this->name ) {
            $type_filter = isset($_REQUEST['type_filter']) ? sanitize_key(wp_unslash($_REQUEST['type_filter'])) : 'all';
            $sync_filter = isset($_REQUEST['sync_filter']) ? sanitize_key(wp_unslash($_REQUEST['sync_filter'])) : 'all';
            echo '<select name="type_filter"><option value="all">همه نوع‌ها</option><option value="product"'.selected($type_filter,'product',false).'>کالای اصلی</option><option value="variation"'.selected($type_filter,'variation',false).'>Variation</option></select>';
            echo '<select name="sync_filter"><option value="all">همه وضعیت‌ها</option><option value="synced"'.selected($sync_filter,'synced',false).'>همگام‌شده</option><option value="pending"'.selected($sync_filter,'pending',false).'>بدون زمان Sync</option></select>';
        }
        echo '<select id="guid_filter" name="guid_filter">';
        echo '<option value="all"' . selected($current_filter, 'all', false) . '>همه اقلام</option>';
        echo '<option value="no_guid"' . selected($current_filter, 'no_guid', false) . '>کالاهای پیوست‌نشده</option>';
        echo '<option value="has_guid"' . selected($current_filter, 'has_guid', false) . '>کالاهای پیوست‌شده</option>';
        echo '</select>';

        // جستجو — حالا داخل همین فرم
        echo '<input type="search" name="s" value="' . $search_value . '" placeholder="نام، SKU یا GUID..." />';
        submit_button('اعمال', '', 'filter_action', false);
        echo '</div>';

        // ✅ جلوگیری از حذف فیلدهای hidden فرم اصلی هنگام جستجو
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const postsFilterForm = document.querySelector("form#posts-filter");
            if (postsFilterForm) {
                const searchInput = postsFilterForm.querySelector("input[name=s]");
                const guidSelect = postsFilterForm.querySelector("select[name=guid_filter]");
                if (searchInput && guidSelect) {
                    postsFilterForm.addEventListener("submit", function() {
                        let hiddenGuid = postsFilterForm.querySelector("input[type=hidden][name=guid_filter]");
                        if (!hiddenGuid) {
                            hiddenGuid = document.createElement("input");
                            hiddenGuid.type = "hidden";
                            hiddenGuid.name = "guid_filter";
                            postsFilterForm.appendChild(hiddenGuid);
                        }
                        hiddenGuid.value = guidSelect.value;
                    });
                }
            }
        });
        </script>';
    }
}



	// اکشن‌های گروهی
	final function get_bulk_actions() {
		$actions = [ 'sync' => 'همگام‌سازی' ];
		if ( 'product' === $this->name ) {
			$actions['requeue'] = 'ارسال مجدد با اولویت بالا';
			$actions['disconnect'] = 'قطع ارتباط با حسابداری';
		}
		return $actions;
	}

		public function search_box($text, $input_id) {
    if (empty($_REQUEST['s']) && !$this->has_items()) {
        return;
    }

    $input_id = $input_id . '-search-input';

    if (!empty($_REQUEST['orderby'])) {
        echo '<input type="hidden" name="orderby" value="' . esc_attr($_REQUEST['orderby']) . '" />';
    }
    if (!empty($_REQUEST['order'])) {
        echo '<input type="hidden" name="order" value="' . esc_attr($_REQUEST['order']) . '" />';
    }
    if (!empty($_REQUEST['type_filter'])) { echo '<input type="hidden" name="type_filter" value="' . esc_attr($_REQUEST['type_filter']) . '" />'; }
    if (!empty($_REQUEST['sync_filter'])) { echo '<input type="hidden" name="sync_filter" value="' . esc_attr($_REQUEST['sync_filter']) . '" />'; }
    if (!empty($_REQUEST['guid_filter'])) {
        echo '<input type="hidden" name="guid_filter" value="' . esc_attr($_REQUEST['guid_filter']) . '" />';
    }

    echo '<p class="search-box">';
    echo '<label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html($text) . ':</label>';
    echo '<input type="search" id="' . esc_attr($input_id) . '" name="s" value="' . esc_attr($_REQUEST['s'] ?? '') . '" />';
    submit_button(esc_attr($text), '', '', false, ['id' => 'search-submit']);
    echo '</p>';
}

	// هسته‌ی جدول: آماده‌سازی داده‌ها
	final function prepare_items() {

		// Handle actions without loading the complete sync table into memory.
		$this->handleTableActions();

		// ستون‌ها
		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = [$columns, $hidden, $sortable];

		// داده‌ها از تابع فرزند
		$data = $this->fetchTableData() ?? [];

		// Load sync records only for rows visible on this page.
		$this->syncs = new Collection();
		$sync_ids_by_type = [];
		foreach ( $data as $row ) {
			$item_type = $this->getItemType( $row );
			$sync_ids_by_type[ $item_type ][] = absint( $this->getID( $row ) );
		}
		foreach ( $sync_ids_by_type as $item_type => $item_ids ) {
			$item_ids = array_values( array_filter( array_unique( $item_ids ) ) );
			if ( empty( $item_ids ) ) {
				continue;
			}
			$rows = Sync::where( 'item_type', $item_type )->whereIn( 'item_id', $item_ids )->get();
			foreach ( $rows as $row ) {
				$this->syncs->push( $row );
			}
		}

		// مرتب‌سازی
		$orderby = $_REQUEST['orderby'] ?? 'name';
		$order   = $_REQUEST['order'] ?? 'asc';

		usort($data, function ($a, $b) use ($orderby, $order) {
			switch ($orderby) {
				case 'guid':
					$valA = $this->getGUID($a);
					$valB = $this->getGUID($b);
					break;
				case 'sync_date':
					$valA = $this->getSyncDate($a);
					$valB = $this->getSyncDate($b);
					break;
				case 'name':
				default:
					$valA = $this->getName($a);
					$valB = $this->getName($b);
					break;
			}
			return ($order === 'asc') ? strcmp($valA, $valB) : strcmp($valB, $valA);
		});

		// pagination
		$per_page     = $this->get_items_per_page('per_page', 20);
		$current_page = $this->get_pagenum();
		$total_items  = count($data);

		$this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);
		$this->set_pagination_args([
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil($total_items / $per_page),
		]);
	}

	// فیلتر و جستجو بر اساس GUID
	public function fetchTableData() {
		return []; // در کلاس فرزند بازنویسی می‌شود
	}

	// همگام‌سازی
	final function handleTableActions() {
		$action = isset( $_REQUEST['action'] ) && -1 !== (int) $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) && -1 !== (int) $_REQUEST['action2'] ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		$action = $action ?: $action2;
		if ( ! in_array( $action, [ 'sync', 'requeue', 'disconnect' ], true ) ) return;
		if ( ! check_admin_referer( 'bulk-' . $this->_args['plural'] ) ) return;

		$items = [];
		foreach ( [ 'product', 'variation', 'order', 'category', 'user' ] as $item_type ) {
			$posted_key = $item_type . 's';
			$table_items = isset( $_REQUEST[ $posted_key ] ) && is_array( $_REQUEST[ $posted_key ] ) ? wp_unslash( $_REQUEST[ $posted_key ] ) : [];
			foreach ( $table_items as $id ) {
				$id = absint( $id );
				if ( $id ) $items[] = [ 'item_id' => $id, 'item_type' => $item_type ];
			}
		}
		if ( empty( $items ) ) return;

		$done = 0;
		foreach ( $items as $item ) {
			if ( 'disconnect' === $action ) {
				if ( ! in_array( $item['item_type'], [ 'product', 'variation' ], true ) ) continue;
				if ( ! current_user_can( 'edit_post', $item['item_id'] ) ) continue;
				foreach ( [ 'guid', '_np-api-sync-date', 'WebPrd' ] as $meta_key ) delete_post_meta( $item['item_id'], $meta_key );
				\MobinDev\Novin_Commerce\Common\SyncLog::add( 'disconnect', 'success', $item['item_type'], $item['item_id'], 'ارتباط مورد با حسابداری قطع شد.' );
				$done++;
			} elseif ( 'requeue' === $action ) {
				\MobinDev\Novin_Commerce\Models\Sync::requeue( $item['item_id'], $item['item_type'], 10 );
				$done++;
			} else {
				\MobinDev\Novin_Commerce\Models\Sync::queueItem( $item['item_id'], $item['item_type'], 10 );
				$done++;
			}
		}
		delete_transient( 'novin_commerce_health_v1' );
		if ( $done ) {
			\MobinDev\Novin_Commerce\Admin\AdminNotice::addSuccessDismissible( sprintf( '%d مورد با موفقیت پردازش شد.', $done ) );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . rawurlencode( sanitize_key( $_REQUEST['page'] ?? '' ) ) ) );
		exit;
	}

	// ✅ اسکریپت جاوااسکریپت برای کپی slug/sku
public function display() {
    parent::display(); // نمایش جدول اصلی

    // اسکریپت برای کپی روی کلیک
    echo "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const els = document.querySelectorAll('.copyable');
        els.forEach(function(el) {
            el.addEventListener('click', function() {
                const text = el.dataset.copy;
                if (!text) return;
                navigator.clipboard.writeText(text).then(() => {
                    el.style.backgroundColor = '#e7f7e7';
                    el.style.transition = 'background-color 0.3s';
                    const oldTitle = el.title;
                    el.title = 'کپی شد ✅';
                    setTimeout(() => {
                        el.style.backgroundColor = '';
                        el.title = oldTitle;
                    }, 1000);
                });
            });
        });
    });
    </script>
    ";

    // اسکریپت قطع ارتباط با حسابداری (هندلر عمومی برای همه‌ی جدول‌ها)
    echo "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('a.remove-guid').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm('آیا از قطع ارتباط این مورد با حسابداری اطمینان دارید؟')) return;

                const id = link.dataset.id;
                const type = link.dataset.type || 'product';
                const nonce = link.dataset.nonce;
                const originalText = link.textContent;
                link.textContent = 'در حال حذف...';
                link.style.pointerEvents = 'none';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({ action: 'remove_guid', id: id, type: type, _wpnonce: nonce })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        const cell = link.closest('td');
                        if (cell) {
                            cell.innerHTML = '<span class=\"novin-no-guid\">بدون اتصال</span>';
                        } else {
                            link.closest('tr') && link.closest('tr').remove();
                        }
                    } else {
                        alert((res.data && res.data.message) || 'خطا در قطع ارتباط');
                        link.textContent = originalText;
                        link.style.pointerEvents = '';
                    }
                })
                .catch(function() {
                    alert('خطا در ارتباط با سرور');
                    link.textContent = originalText;
                    link.style.pointerEvents = '';
                });
            });
        });
    });
    </script>
    ";
}

}
