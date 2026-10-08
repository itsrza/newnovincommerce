<?php

namespace MobinDev\Novin_Commerce\Frontend;

use MobinDev\Novin_Commerce\Plugin;

class Woocommerce_Menu {
	/** @var Plugin */
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	private function hooks() {
		$this->plugin->get_loader()->add_filter( 'woocommerce_account_menu_items', $this, 'addItems', 90 );
		$this->plugin->get_loader()->add_action( 'init', $this, 'addRewriteRules', 90 );
		// Transactions
		$this->plugin->get_loader()->add_action( 'woocommerce_account_novin-commerce-transactions_endpoint', $this, 'transactionsContent' );
		$this->plugin->get_loader()->add_action( 'wp_head', $this, 'TransactionsMenuIcon' );

	}

	public function addItems( $endpoints ) {
		$new_endpoints = [];
		foreach ( $endpoints as $endpoint_slug => $endpoint_label ) {
			$new_endpoints[ $endpoint_slug ] = $endpoint_label;
			if ( $endpoint_slug === 'orders' ) {
				$new_endpoints['novin-commerce-transactions'] = 'مشاهده صورت‌حساب';
			}
		}

		return $new_endpoints;
	}

	public function addRewriteRules() {
		add_rewrite_endpoint( 'novin-commerce-transactions', EP_PAGES );
	}

	public function transactionsContent() {
		echo do_shortcode( '[novincommerce-transactions]' );
	}

	public function TransactionsMenuIcon() {
		?>
		<style>
			.woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--novin-commerce-transactions a:before {
				font-family: dashicons !important;
				content: "\f481" !important;
				color: #8e142e;
			}
		</style><?php
	}
}
