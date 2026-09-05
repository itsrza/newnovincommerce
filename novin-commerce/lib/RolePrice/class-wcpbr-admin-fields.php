<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NovinCommerce_RolePrice_Admin_Fields {

	public function __construct() {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_product_fields' ) );

		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );

		add_action( 'woocommerce_process_product_meta', array( $this, 'save_simple_product_fields' ) );

		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		add_action( 'admin_head', array( $this, 'admin_styles' ) );
	}

	public function admin_styles() {
		echo '<style>
			.wcpbr_field_wrapper { border-top: 1px dashed #ddd; padding-top: 10px; margin-top: 10px; }
			.wcpbr_field_wrapper .wcpbr_role_title { font-weight: 600; padding: 8px 12px 0; color: #2271b1; }
		</style>';
	}

	public function render_simple_product_fields() {
		global $post;

		if ( ! current_user_can( 'edit_product', $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'wcpbr_save_simple_' . $post->ID, 'wcpbr_simple_nonce' );

		echo '<div class="wcpbr_field_wrapper options_group">';
		echo '<p class="wcpbr_role_title">' . esc_html__( 'قیمت بر اساس نقش کاربر', 'novin-commerce' ) . '</p>';

		foreach ( NovinCommerce_RolePrice_Roles::get_roles() as $role_key => $role_label ) {
			$regular_key = NovinCommerce_RolePrice_Roles::regular_meta_key( $role_key );
			$sale_key    = NovinCommerce_RolePrice_Roles::sale_meta_key( $role_key );

			echo '<p class="wcpbr_role_title" style="margin-top:15px;">' . esc_html( $role_label ) . '</p>';

			woocommerce_wp_text_input( array(
				'id'                => $regular_key,
				'label'             => sprintf( __( 'قیمت عادی (%s)', 'novin-commerce' ), $role_label ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value'             => get_post_meta( $post->ID, $regular_key, true ),
				'data_type'         => 'price',
				'desc_tip'          => true,
				'description'       => sprintf( __( 'اگر خالی باشد، پیام «تماس بگیرید» برای این نقش نمایش داده می‌شود.', 'novin-commerce' ) ),
			) );

			woocommerce_wp_text_input( array(
				'id'                => $sale_key,
				'label'             => sprintf( __( 'قیمت حراج (%s)', 'novin-commerce' ), $role_label ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value'             => get_post_meta( $post->ID, $sale_key, true ),
				'data_type'         => 'price',
				'desc_tip'          => true,
				'description'       => __( 'اختیاری - در صورت پر بودن به عنوان قیمت نهایی این نقش در نظر گرفته می‌شود.', 'novin-commerce' ),
			) );
		}

		echo '</div>';
	}

	
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		if ( ! current_user_can( 'edit_product', $variation->ID ) ) {
			return;
		}

		if ( 0 === $loop ) {
			wp_nonce_field( 'wcpbr_save_variations', 'wcpbr_variation_nonce' );
		}

		echo '<div class="wcpbr_field_wrapper form-row form-row-full">';
		echo '<p class="wcpbr_role_title">' . esc_html__( 'قیمت بر اساس نقش کاربر (این تنوع)', 'novin-commerce' ) . '</p>';

		foreach ( NovinCommerce_RolePrice_Roles::get_roles() as $role_key => $role_label ) {
			$regular_key = NovinCommerce_RolePrice_Roles::regular_meta_key( $role_key );
			$sale_key    = NovinCommerce_RolePrice_Roles::sale_meta_key( $role_key );

			echo '<p class="wcpbr_role_title" style="margin-top:10px;">' . esc_html( $role_label ) . '</p>';

			woocommerce_wp_text_input( array(
				'id'            => $regular_key . '_' . $loop,
				'name'          => $regular_key . '[' . $loop . ']',
				'label'         => sprintf( __( 'قیمت عادی (%s)', 'novin-commerce' ), $role_label ),
				'value'         => get_post_meta( $variation->ID, $regular_key, true ),
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-first',
			) );

			woocommerce_wp_text_input( array(
				'id'            => $sale_key . '_' . $loop,
				'name'          => $sale_key . '[' . $loop . ']',
				'label'         => sprintf( __( 'قیمت حراج (%s)', 'novin-commerce' ), $role_label ),
				'value'         => get_post_meta( $variation->ID, $sale_key, true ),
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-last',
			) );
		}

		echo '</div>';
	}

	private function sanitize_price_input( $raw_value ) {
		$clean = wc_clean( wp_unslash( $raw_value ) );

		if ( '' === $clean ) {
			return '';
		}

		$formatted = wc_format_decimal( $clean );

		if ( '' === $formatted || ! is_numeric( $formatted ) || (float) $formatted < 0 ) {
			return '';
		}

		return $formatted;
	}

	public function save_simple_product_fields( $post_id ) {
		if ( ! isset( $_POST['wcpbr_simple_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpbr_simple_nonce'] ) ), 'wcpbr_save_simple_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		foreach ( NovinCommerce_RolePrice_Roles::get_roles() as $role_key => $role_label ) {
			$regular_key = NovinCommerce_RolePrice_Roles::regular_meta_key( $role_key );
			$sale_key    = NovinCommerce_RolePrice_Roles::sale_meta_key( $role_key );

			$regular_value = isset( $_POST[ $regular_key ] ) ? $this->sanitize_price_input( $_POST[ $regular_key ] ) : '';
			$sale_value    = isset( $_POST[ $sale_key ] ) ? $this->sanitize_price_input( $_POST[ $sale_key ] ) : '';

			update_post_meta( $post_id, $regular_key, $regular_value );
			update_post_meta( $post_id, $sale_key, $sale_value );
		}
	}


	public function save_variation_fields( $variation_id, $loop ) {
		if ( ! isset( $_POST['wcpbr_variation_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpbr_variation_nonce'] ) ), 'wcpbr_save_variations' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		foreach ( NovinCommerce_RolePrice_Roles::get_roles() as $role_key => $role_label ) {
			$regular_key = NovinCommerce_RolePrice_Roles::regular_meta_key( $role_key );
			$sale_key    = NovinCommerce_RolePrice_Roles::sale_meta_key( $role_key );

			$regular_value = isset( $_POST[ $regular_key ][ $loop ] ) ? $this->sanitize_price_input( $_POST[ $regular_key ][ $loop ] ) : '';
			$sale_value    = isset( $_POST[ $sale_key ][ $loop ] ) ? $this->sanitize_price_input( $_POST[ $sale_key ][ $loop ] ) : '';

			update_post_meta( $variation_id, $regular_key, $regular_value );
			update_post_meta( $variation_id, $sale_key, $sale_value );
		}
	}
}
