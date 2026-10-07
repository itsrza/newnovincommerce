<?php

namespace Novinwp\Novin_Commerce\Common;

use Carbon\Carbon;
use Novinwp\Novin_Commerce\Models\Sync;
use Novinwp\Novin_Commerce\Plugin;
use Morilog\Jalali\Jalalian;

class Novin_REST_Controller extends \WC_REST_CRUD_Controller {


	protected $namespace = 'wc/v3';

	protected $rest_base = 'novin';
	/** @var Plugin $plugin */
	protected $plugin;


	public function register_routes() {
		global $_novin_commerce;
		$this->plugin = $_novin_commerce;

		// get plugin data
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getPluginData' ),
					'permission_callback' => array( $this, 'checkPermission' )
				)
			]
		);
		// get plugin version
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/version',
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'getVersion' ],
					'permission_callback' => '__return_true',
				)
			]
		);
		// set sync time
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sync-datetime',
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'getSyncTime' ],
					'permission_callback' => array( $this, 'checkPermission' ),
				)
			]
		);
		// set sync time
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sync-datetime',
			[
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'setSyncTime' ],
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'datetime' => array(
							'description'       => __( 'sync date time' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				)
			]
		);
		// get customers ids and guids
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customers',
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCustomersIds' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_customers_params(),
				)
			]
		);

		//get customer id by guid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customer-by-guid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCustomerGuid' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'guid' => array(
							'description'       => __( 'Get customer id with this guid.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);

		//get customer id by digitNumber
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customer-by-digitNumber',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getDigitNumber' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'digits_phone_no' => array(
							'description'       => __( 'Get customer id with this digitNumber.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);

		//get customer id by username
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customer-by-username',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCustomerUsername' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'username' => array(
							'description'       => __( 'Get customer id with his username.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);

		// get products ids
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/products',
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getProductsIds' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_products_params(),
				)
			]
		);

		//get product id by guid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/product-by-guid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getProductGuid' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'guid' => array(
							'description'       => __( 'Get product id with this guid.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);
		//get post id by guid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post-by-guid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getPostGuid' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'guid' => array(
							'description'       => __( 'Get post id with this guid.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);
		// get orders ids
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/orders',
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getOrdersIds' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_products_params(),
				)
			]
		);
		//get order id by guid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/order-by-guid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getOrderGuid' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'guid' => array(
							'description'       => __( 'Get order id with this guid.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);
		//get variation id by guid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/variation-by-guid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getVariationGuid' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'guid' => array(
							'description'       => __( 'Get variation id with this guid.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);
		// get categories ids
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/categories',
			[
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCategoriesIds' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_products_params(),
				)
			]
		);
		//get category id by guid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/category-by-guid',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCategoryGuid' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => [
						'guid' => array(
							'description'       => __( 'Get category id with this guid.' ),
							'type'              => 'string',
							'validate_callback' => 'rest_validate_request_arg',
							'required'          => true
						)
					],
				),
			)
		);
		// get syncs count enqueue
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSyncs' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);
		// get sync item
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSync' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);
		// delete sync item
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteSync' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);
		// get products syncs enqueue
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/products',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getProductsSyncs' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_syncs_items_params(),
				),
			)
		);
		// get orders syncs enqueue
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/orders',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getOrdersSyncs' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_syncs_items_params(),
				),
			)
		);
		// get users syncs enqueue
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/customers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCustomersSyncs' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_syncs_items_params(),
				),
			)
		);
		// get categories syncs enqueue
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/categories',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCategoriesSyncs' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_syncs_items_params(),
				),
			)
		);
		// get variations syncs enqueue
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/syncs/variations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getVariationsSyncs' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->get_syncs_items_params(),
				),
			)
		);
	}

	public function getPluginData( $request ) {

		return get_plugin_data( $this->plugin->getPluginFile() );
	}

	public function getVersion() {
		return (string) $this->plugin->get_version();
	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_REST_Response
	 */
	public function getCustomersIds( $request ) {
		global $wpdb;

		$limit  = max( 1, (int) $request->get_param( 'per_page' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$offset = ( $page - 1 ) * $limit;
		$by_guid = (bool) $request->get_param( 'guid' );
		$by_phone = (bool) $request->get_param( 'digits_phone_no' );

		if ( $by_guid || $by_phone ) {
			$meta_key = $by_guid ? 'guid' : 'digits_phone_no';
			$select   = 'guid' === $meta_key ? 'meta_value as guid' : 'meta_value as digits_phone_no';
				$data = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT user_id as id, MAX(meta_value) as {$meta_key} FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY user_id ORDER BY MIN(umeta_id) LIMIT %d OFFSET %d",
						$meta_key,
						$limit,
						$offset
					)
				);
				$total = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", $meta_key )
				);
		} else {
			$data = $wpdb->get_col(
				$wpdb->prepare( "SELECT ID FROM {$wpdb->users} ORDER BY ID LIMIT %d OFFSET %d", $limit, $offset )
			);
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed core table identifier.
		}

		return new \WP_REST_Response( $data, 200, [
			'X-WP-Total'      => $total,
			'X-WP-TotalPages' => (int) ceil( $total / $limit ),
		] );
	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getPostMetaGuid( $request, $type ) {
		global $wpdb;

		$guid = $request->get_param( 'guid' );

		$id = $wpdb->get_col( $wpdb->prepare( "
		SELECT post_id
		FROM {$wpdb->postmeta}
		INNER JOIN {$wpdb->posts} ON {$wpdb->postmeta}.post_id={$wpdb->posts}.ID
		WHERE meta_key = 'guid' AND meta_value = %s AND {$wpdb->posts}.post_type = %s
		", $guid, $type ) );

		if ( empty( $id ) ) {
			return new \WP_Error( "{$type}_or_guid_not_found", __( ucwords( $type ) . ' or GUID not found.', 'novin-commerce' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $id );

	}


	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getMetaGuid( $request, $table ) {
		global $wpdb;

		$guid = $request->get_param( 'guid' );

		$type = str_replace( 'meta', '', $table );

		$id = $wpdb->get_col( $wpdb->prepare( "
		SELECT {$type}_id
		FROM {$wpdb->$table}
		WHERE meta_key = 'guid' AND meta_value = %s
		", $guid ) );

		if ( empty( $id ) ) {
			return new \WP_Error( "{$type}_or_guid_not_found", __( ucwords( $type ) . ' or GUID not found.', 'novin-commerce' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $id );

	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getMetaPhoneNumber( $request, $table ) {
		global $wpdb;

		$digits_phone_no = $request->get_param( 'digits_phone_no' );

		$id = $wpdb->get_col( $wpdb->prepare( "
		SELECT user_id
		FROM {$wpdb->$table}
		WHERE meta_key = 'digits_phone_no' AND meta_value = %s
		", $digits_phone_no) );

		if ( empty( $id ) ) {
			return new \WP_Error( 'digits_phone_no_not_found', __( 'شماره موبایل برای هیچ کاربری ثبت نشده است.', 'novin-commerce' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $id );

	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getCustomerGuid( $request ) {
		return $this->getMetaGuid( $request, 'usermeta' );
	}


	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getDigitNumber( $request ) {
		return $this->getMetaPhoneNumber( $request, 'usermeta' );
	}


	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getCustomerUsername( $request ) {
		$username = $request->get_param( 'username' );
		$user     = get_user_by( 'login', $username );
		if ( $user instanceof \WP_User ) {
			return new \WP_REST_Response( $user->ID );
		}

		return new \WP_Error( 'username_not_found', __( 'Username not found.( Username is case sensitive )', 'novin-commerce' ), array( 'status' => 404 ) );

	}


	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getProductsIds( $request ) {
		return $this->getPostsIds( $request, 'product' );
	}


	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getPostGuid( $request ) {
		return $this->getMetaGuid( $request, 'postmeta' );

	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getProductGuid( $request ) {
		return $this->getPostMetaGuid( $request, 'product' );

	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getOrderGuid( $request ) {
		$guid = sanitize_text_field( (string) $request->get_param( 'guid' ) );
		if ( '' === $guid || ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'order_or_guid_not_found', __( 'Order or GUID not found.', 'novin-commerce' ), array( 'status' => 404 ) );
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 2,
				'return'     => 'ids',
				'paginate'   => false,
				'meta_query' => array(
					array(
						'key'     => 'guid',
						'value'   => $guid,
						'compare' => '=',
					),
				),
			)
		);
		$orders = is_array( $orders ) ? array_map( 'absint', $orders ) : array();
		if ( empty( $orders ) ) {
			return new \WP_Error( 'order_or_guid_not_found', __( 'Order or GUID not found.', 'novin-commerce' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $orders );
	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getVariationGuid( $request ) {
		return $this->getPostMetaGuid( $request, 'product_variation' );

	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getCategoryGuid( $request ) {
		return $this->getMetaGuid( $request, 'termmeta' );

	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function getOrdersIds( $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->getPostsIds( $request, 'shop_order' );
		}

		$limit     = max( 1, (int) $request->get_param( 'per_page' ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$date_from = $request->get_param( 'modified_date_from' );
		$date_to   = $request->get_param( 'modified_date_to' );
		$args      = array(
			'limit'    => $limit,
			'paged'    => $page,
			'paginate' => true,
			'return'   => 'objects',
			'orderby'  => 'date_modified',
			'order'    => 'ASC',
		);
		$date_query = array();
		if ( null !== $date_from && '' !== (string) $date_from ) {
			$date_query['after'] = sanitize_text_field( $date_from );
		}
		if ( null !== $date_to && '' !== (string) $date_to ) {
			$date_query['before'] = sanitize_text_field( $date_to );
		}
		if ( ! empty( $date_query ) ) {
			$args['date_modified'] = $date_query;
		}

		$result = wc_get_orders( $args );
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : ( is_array( $result ) ? $result : array() );
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
		$data   = array();
		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_id' ) ) ) {
				continue;
			}
			$modified = is_callable( array( $order, 'get_date_modified' ) ) ? $order->get_date_modified() : null;
			$data[] = (object) array(
				'ID'               => absint( $order->get_id() ),
				'post_modified_gmt' => $modified && is_callable( array( $modified, 'getTimestamp' ) )
					? gmdate( 'Y-m-d H:i:s', $modified->getTimestamp() )
					: '',
			);
		}

		return new \WP_REST_Response( $data, 200, [
			'X-WP-Total'      => $total,
			'X-WP-TotalPages' => (int) ceil( $total / $limit ),
		] );
	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_REST_Response
	 */
	public function getCategoriesIds( $request ) {
		global $wpdb;

		$limit  = max( 1, (int) $request->get_param( 'per_page' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$offset = ( $page - 1 ) * $limit;

		$data = $wpdb->get_col( $wpdb->prepare(
			"
						SELECT term_id
						FROM $wpdb->term_taxonomy
						WHERE taxonomy = %s
						ORDER BY term_id
						LIMIT %d
						OFFSET %d
    				", 'product_cat', $limit, $offset ) );

		$total = $wpdb->get_var( $wpdb->prepare(
				'
					SELECT COUNT(*)
					FROM ' . $wpdb->term_taxonomy . '
					WHERE taxonomy = %s',
				'product_cat'
			) );
		$response = new \WP_REST_Response( $data, 200, [
			'X-WP-Total'      => $total,
			'X-WP-TotalPages' => (int) ceil( $total / $limit ),
		] );

		return $response;
	}

	/**
	 * @param $request \WP_REST_Request
	 * @param $post_type string
	 *
	 * @return \WP_REST_Response
	 */
	public function getPostsIds( $request, $post_type ) {
		global $wpdb;

		$limit     = max( 1, (int) $request->get_param( 'per_page' ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$offset    = ( $page - 1 ) * $limit;
		$date_from = $request->get_param( 'modified_date_from' );
		$date_to   = $request->get_param( 'modified_date_to' );

		$where = 'post_type = %s';
		$args  = [ sanitize_key( $post_type ) ];
		if ( null !== $date_from && '' !== (string) $date_from ) {
			$where .= ' AND post_modified_gmt > %s';
			$args[] = sanitize_text_field( $date_from );
		}
		if ( null !== $date_to && '' !== (string) $date_to ) {
			$where .= ' AND post_modified_gmt < %s';
			$args[] = sanitize_text_field( $date_to );
		}

		$data_args   = $args;
		$data_args[] = $limit;
		$data_args[] = $offset;
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE {$where} ORDER BY post_modified_gmt, ID LIMIT %d OFFSET %d",
				$data_args
			)
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $args )
		);

		return new \WP_REST_Response( $data, 200, [
			'X-WP-Total'      => $total,
			'X-WP-TotalPages' => (int) ceil( $total / $limit ),
		] );
	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_REST_Response
	 */
	public function getSyncTime( $request ) {
		$datetime = SettingAPI::get( 'sync_datetime' );
		if ( ! $datetime ) {
			return new \WP_REST_Response( 'Sync datetime is empty! first fill the field with put or post request.' );
		}

		return new \WP_REST_Response( Carbon::createFromTimestamp( $datetime )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * @param $request \WP_REST_Request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function setSyncTime( $request ) {
		$datetime = $request->get_param( 'datetime' );
		$jalali   = Jalalian::fromDateTime( $datetime );
		if ( $jalali ) {
			$saved = SettingAPI::set( 'sync_datetime', $jalali->getTimestamp() );
			if ( $saved ) {
				\Novinwp\Novin_Commerce\Common\SyncLog::add( 'sync_datetime', 'success', '', 0, 'زمان مرجع Sync از طریق API تغییر کرد.', [ 'datetime' => $datetime ] );
				delete_transient( 'novin_commerce_health_v1' );
			}
			if ( ! $saved ) {
				return new \WP_Error( 406, 'Sync Datetime not saved! Maybe datetime is not changed so check the datetime' );
			}

			return new \WP_REST_Response( [
				'message'  => 'DateTime Saved. Please check is the DateTime is OK or not!',
				'datetime' => $jalali->toCarbon()->format( 'Y-m-d H:i:s' )
			], 201 );
		}

		return new \WP_Error( 400, 'Datetime is not valid!' );
	}

	public function getSyncs( $request ) {
		return [
			'all'       => Sync::count(),
			'product'   => Sync::where( 'item_type', 'product' )->count(),
			'order'     => Sync::where( 'item_type', 'order' )->count(),
			'category'  => Sync::where( 'item_type', 'category' )->count(),
			'user'      => Sync::where( 'item_type', 'user' )->count(),
			'variation' => Sync::where( 'item_type', 'variation' )->count(),
		];

	}

	public function getSync( $request ) {
		$id        = $request->get_param( 'id' );
		$sync_item = Sync::find( $id );
		if ( ! $sync_item ) {
			return new \WP_Error( 404, 'Sync Item not founds' );
		}

		return $sync_item;

	}

	public function deleteSync( $request ) {
		$id        = $request->get_param( 'id' );
		$sync_item = Sync::find( $id );
		if ( ! $sync_item ) {
			return new \WP_Error( 404, 'Sync Item not founds' );
		}
		if ( $sync_item->delete() ) {
			Sync::doDeleteAction();

			return new \WP_REST_Response( 'Sync Item deleted successfully.' );
		}

		return new \WP_Error( 500, 'Something went wrong!' );
	}

	public function getItemsSyncs( $request, $type ) {
		$limit  = max( 1, (int) $request->get_param( 'per_page' ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$offset = ( $page - 1 ) * $limit;
		$data   = Sync::where( 'item_type', $type )->orderBy( 'priority', 'desc' )->orderBy( 'id', 'asc' )->offset( $offset )->limit( $limit )->get();
		$total  = Sync::where( 'item_type', $type )->count();

		return new \WP_REST_Response( $data, 200, [
			'X-WP-Total'      => $total,
			'X-WP-TotalPages' => (int) ceil( $total / $limit ),
		] );

	}

	public function getProductsSyncs( $request ) {
		return $this->getItemsSyncs( $request, 'product' );
	}

	public function getOrdersSyncs( $request ) {
		return $this->getItemsSyncs( $request, 'order' );
	}

	public function getCustomersSyncs( $request ) {
		return $this->getItemsSyncs( $request, 'user' );
	}

	public function getVariationsSyncs( $request ) {
		return $this->getItemsSyncs( $request, 'variation' );
	}

	public function getCategoriesSyncs( $request ) {
		return $this->getItemsSyncs( $request, 'category' );
	}

	public function checkPermission( \WP_REST_Request $request ) {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'woocommerce_rest_cannot_view',
			__( 'Sorry, you cannot list resources.', 'woocommerce' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function get_customers_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 50000,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'guid'            => array(
				'description'       => __( 'Get customers that have a GUID.' ),
				'type'              => 'boolean',
				'default'           => false,
				'validate_callback' => 'rest_validate_request_arg',
			),
			'digits_phone_no' => array(
				'description'       => __( 'Get customers that have a Digits phone number.' ),
				'type'              => 'boolean',
				'default'           => false,
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	public function get_products_params() {
		return array(
			'page'               => array(
				'description'       => __( 'Current page of the collection.' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			),
			'per_page'           => array(
				'description'       => __( 'Maximum number of items to be returned in result set.' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 50000,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'modified_date_from' => array(
				'description'       => __( 'Filter products with modified date from.' ),
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'modified_date_to'   => array(
				'description'       => __( 'Filter products with modified date to.' ),
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
			)
		);
	}

	public function get_categories_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 50000,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	public function get_syncs_items_params() {
		return [
			'page'     => array(
				'description'       => __( 'Current page of the collection.' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
				'minimum'           => 1,
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 50000,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		];
	}


}
