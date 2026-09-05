<?php

namespace MobinDev\Novin_Commerce\Common;

use MobinDev\Novin_Commerce\Models\Sync as SyncModel;
use MobinDev\Novin_Commerce\Plugin;

class Sync {
	/**
	 * The plugin's instance.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    Plugin $plugin This plugin's instance.
	 */
	private $plugin;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Plugin $plugin This plugin's instance.
	 *
	 * @since 1.0.0
	 *
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	private function hooks() {
		//Products
		// $post_ID, $post, $update
		$this->plugin->get_loader()->add_action( 'save_post_product', $this, 'productAddOrUpdate', 11, 3 );
		//$product_id, $product
		$this->plugin->get_loader()->add_action( 'woocommerce_update_product', $this, 'productUpdate', 11, 2 );

		//Customers
		$this->plugin->get_loader()->add_action( 'user_register', $this, 'userAddOrUpdate', 11, 2 );
		$this->plugin->get_loader()->add_action( 'profile_update', $this, 'userAddOrUpdate', 11, 2 );
		//orders

		$this->plugin->get_loader()->add_action( 'save_post_shop_order', $this, 'orderAddOrUpdate', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_pending', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_failed', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_on-hold', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_processing', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_completed', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_refunded', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_order_status_cancelled', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_update_order', $this, 'orderUpdateStatus', 11, 2 );
		$this->plugin->get_loader()->add_action( 'woocommerce_new_order', $this, 'orderUpdateStatus', 11, 2 );

		//Categories
		$this->plugin->get_loader()->add_action( 'created_product_cat', $this, 'categoryAddOrUpdate', 11, 2 );
		$this->plugin->get_loader()->add_action( 'edited_product_cat', $this, 'categoryAddOrUpdate', 11, 2 );

		//Variations
		// $post_ID, $post, $update
		$this->plugin->get_loader()->add_action( 'save_post_product_variation', $this, 'variationAddOrUpdate', 11, 3 );
		//$product_id, $product
		$this->plugin->get_loader()->add_action( 'woocommerce_update_product_variation', $this, 'variationUpdate', 11, 2 );


	}


	/*** Product ***/

	/**
	 * @param int $post_ID
	 * @param \WP_Post $post
	 * @param boolean $update
	 */
	public function productAddOrUpdate( $post_ID, $post, $update ) {
		SyncModel::insertProduct( $post_ID );
	}

	/**
	 * @param int $product_id
	 * @param \WC_Product $product
	 */
	public function productUpdate( $product_id, $product ) {
		SyncModel::insertProduct( $product_id );
	}

	/*** Order ***/


	/**
	 * @param int $post_ID
	 * @param \WP_Post $post
	 * @param boolean $update
	 */
	public function orderAddOrUpdate( $post_ID, $post, $update ) {
		SyncModel::insertOrder( $post_ID );
	}

	/**
	 * @param int $order_id
	 * @param \WC_Order $order
	 */
	public function orderUpdateStatus( $order_id, $order ) {
		SyncModel::insertOrder( $order_id );
	}

	/*** Category ***/


	/**
	 * @param int $term_id Term ID.
	 * @param int $tt_id Term taxonomy ID.
	 */
	public function categoryAddOrUpdate( $term_id, $tt_id ) {
		SyncModel::insertCategory( $term_id );
	}

	/*** Variation ***/

	/**
	 * @param int $post_ID
	 * @param \WP_Post $post
	 * @param boolean $update
	 */
	public function variationAddOrUpdate( $post_ID, $post, $update ) {
		SyncModel::insertVariation( $post_ID );
	}

	/**
	 * @param int $variation_id
	 * @param \WC_Product_Variation $variation
	 */
	public function variationUpdate( $variation_id, $variation ) {
		SyncModel::insertVariation( $variation_id );
	}

	/*** user ***/

	/**
	 * @param int $user_id
	 * @param array $userdata
	 */
	public function userAddOrUpdate( $user_id, $userdata ) {
		SyncModel::insertUser( $user_id );
	}

}
