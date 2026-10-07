<?php

namespace Novinwp\Novin_Commerce\Common;

use Novinwp\Novin_Commerce\Plugin;

class Woocommerce
{
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
	 * @since 1.0.0
	 *
	 */
	public function __construct(Plugin $plugin)
	{
		$this->plugin = $plugin;
		$this->hooks();
	}

	private function hooks()
	{
		// add like search to product query
		$this->plugin->get_loader()->add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $this,'addQueryVar', 11, 2 );

		// rest api
		$this->plugin->get_loader()->add_action('woocommerce_rest_api_get_rest_namespaces', $this, 'setNovinNamespace', 11, 1);
		$this->plugin->get_loader()->add_action('woocommerce_rest_insert_product_cat', $this, 'setGuidForProductCat', 11, 3);
		$this->plugin->get_loader()->add_action('woocommerce_rest_prepare_product_cat', $this, 'addGuidForResponseProductCat', 11, 3);
	}

	public function addQueryVar( $query, $query_vars ) {
		if ( isset( $query_vars['like_name'] ) && ! empty( $query_vars['like_name'] ) ) {
			$query['s'] = esc_attr( $query_vars['like_name'] );
		}

		return $query;
	}


	public function setNovinNamespace($controllers)
	{
		$controllers['wc/v3']['novin'] = Novin_REST_Controller::class;

		return $controllers;
	}

	/**
	 * @param $term \WP_Term
	 * @param $request \WP_REST_Request
	 * @param $create boolean
	 */
	public function setGuidForProductCat($term, $request, $create)
	{
		$guid = $request->get_param('guid');
		if ($guid) {
			update_term_meta($term->term_id, 'guid', $guid);
		}

	}

	/**
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Term $item The original term object.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 */
	public function addGuidForResponseProductCat($response, $item, $request)
	{

		$response->data['guid'] = get_term_meta($item->term_id, 'guid', true);
		return $response;
	}
}
