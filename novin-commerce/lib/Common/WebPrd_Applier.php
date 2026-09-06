<?php

namespace MobinDev\Novin_Commerce\Common;

/**
 * Applies accounting data found in the product "WebPrd" meta onto the
 * WooCommerce product automatically — no admin confirmation is required,
 * the accounting side always wins:
 *
 *  1. Stock        = sum of Amount inside PrdAnbarRelation (the live payload
 *                    stores the sellable stock there, e.g. Amount:7.0).
 *  2. Price        = Sell1 (regular) + active DiscountPercent window (sale).
 *  3. Structure    = when WebPrd lists AttributeRelations with several
 *                    ProductGuid children, the product is turned into a
 *                    variable product, the global attribute (pa_*) + its
 *                    terms are attached, the DefaultVariable is set, and the
 *                    existing child products (found by their guid meta) are
 *                    linked as variations with their attribute + own price.
 *
 * Every change is written once through the WC API, guarded against
 * re-entrancy, and recorded in the sync log (auto_stock / auto_price /
 * auto_structure / auto_variation) so the dashboard "auto sync report"
 * shows exactly what the engine did.
 */
class WebPrd_Applier {

	/** Re-entrancy guard: product ids currently being processed. */
	private static $busy = array();

	/**
	 * @return int number of products changed (0 when nothing to do)
	 */
	public static function maybe( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) return 0;
		if ( ! metadata_exists( 'post', $product_id, 'WebPrd' ) ) return 0;
		if ( isset( self::$busy[ $product_id ] ) ) return 0;
		self::$busy[ $product_id ] = 1;
		try {
			$changed = self::apply( $product_id );
		} catch ( \Throwable $e ) {
			$changed = 0;
		}
		unset( self::$busy[ $product_id ] );
		return $changed;
	}

	/**
	 * Sweep: re-check the newest published products carrying WebPrd and apply
	 * everything that differs. Guarded by a 6-hour option so the dashboard
	 * page load stays cheap. Returns the number of products changed.
	 */
	public static function catchup() {
		if ( ! function_exists( 'wc_get_product' ) ) return 0;
		$last = (int) get_option( 'novin_applier_catchup_last', 0 );
		if ( $last && ( time() - $last ) < 6 * HOUR_IN_SECONDS ) return 0;
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} w ON w.post_id=p.ID AND w.meta_key='WebPrd' AND w.meta_value<>'' WHERE p.post_type IN ('product','product_variation') AND p.post_status='publish' ORDER BY p.ID DESC LIMIT 150" );
		update_option( 'novin_applier_catchup_last', time(), false );
		$changed = 0;
		foreach ( (array) $ids as $id ) {
			$changed += self::maybe( (int) $id );
		}
		return $changed;
	}

	/**
	 * Read the accounting stock from a decoded WebPrd array.
	 *
	 * @param array $d
	 * @return float|null null when the payload carries no Amount entries
	 */
	public static function accounting_stock_from( array $d ) {
		if ( empty( $d['PrdAnbarRelation'] ) || ! is_array( $d['PrdAnbarRelation'] ) ) return null;
		$sum = 0.0; $has = false;
		foreach ( $d['PrdAnbarRelation'] as $rel ) {
			if ( is_array( $rel ) && isset( $rel['Amount'] ) && is_numeric( $rel['Amount'] ) ) {
				$sum += (float) $rel['Amount'];
				$has = true;
			}
		}
		return $has ? $sum : null;
	}

	/** @return array decoded WebPrd for a product, or array() */
	private static function webprd_of( $product_id ) {
		$raw = get_metadata( 'post', $product_id, 'WebPrd', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) return array();
		$d = json_decode( $raw, true, 16 );
		return is_array( $d ) ? $d : array();
	}

	private static function type_of( $product_id ) {
		$p = wc_get_product( $product_id );
		return $p ? $p->get_type() : '';
	}

	/**
	 * Apply everything for one product. Returns number of changed areas.
	 */
	private static function apply( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) return 0;
		$d = self::webprd_of( $product_id );
		if ( ! $d ) return 0;
		$type = self::type_of( $product_id );
		if ( ! $type ) return 0;
		$changed = 0;
		$item_type = ( 'product_variation' === get_post_type( $product_id ) || 'variation' === $type ) ? 'variation' : 'product';

		// 1) Stock — accounting always wins.
		$amount = self::accounting_stock_from( $d );
		if ( null !== $amount ) {
			$p = wc_get_product( $product_id );
			if ( $p ) {
				$current = $p->get_stock_quantity();
				if ( $current === null || abs( (float) $current - $amount ) >= 0.005 ) {
					if ( ! $p->get_manage_stock() && method_exists( $p, 'set_manage_stock' ) ) {
						$p->set_manage_stock( true );
						$p->save();
					}
					if ( function_exists( 'wc_update_product_stock' ) ) {
						wc_update_product_stock( $p, $amount );
					} else {
						update_post_meta( $product_id, '_stock', wc_stock_amount( $amount ) );
						update_post_meta( $product_id, '_stock_status', $amount > 0 ? 'instock' : 'outofstock' );
					}
					SyncLog::add( 'auto_stock', 'success', $item_type, $product_id, 'موجودی حسابداری به‌صورت خودکار اعمال شد: ' . number_format_i18n( $amount ) . ' عدد.' );
					$changed++;
				}
			}
		}

		// 2) Price (simple products only — variable pricing lives on children).
		if ( 'simple' === $type && isset( $d['Sell1'] ) && is_numeric( $d['Sell1'] ) && (float) $d['Sell1'] > 0 ) {
			$p = wc_get_product( $product_id );
			if ( $p && method_exists( $p, 'set_regular_price' ) ) {
				$regular = round( (float) $d['Sell1'] );
				$did = false;
				if ( (string) $p->get_regular_price() !== (string) $regular ) {
					$p->set_regular_price( $regular );
					$did = true;
				}
				$sale = '';
				$ds = ! empty( $d['DiscountStartDate'] ) ? strtotime( (string) $d['DiscountStartDate'] ) : 0;
				$de = ! empty( $d['DiscountEndDate'] ) ? strtotime( (string) $d['DiscountEndDate'] ) : 0;
				$dp = ! empty( $d['DiscountPercent'] ) ? (float) $d['DiscountPercent'] : 0;
				$now = time();
				if ( $ds && $de && $dp > 0 && $ds <= $now && $now <= $de ) {
					$sale = (string) max( 0, round( $regular * ( 100 - $dp ) / 100 ) );
				}
				if ( (string) $p->get_sale_price() !== $sale ) {
					$p->set_sale_price( $sale );
					$did = true;
				}
				if ( $did ) {
					$p->save();
					SyncLog::add( 'auto_price', 'success', $item_type, $product_id, 'قیمت فروش از WebPrd اعمال شد: ' . number_format_i18n( $regular ) . ( $sale !== '' ? ' (تخفیف: ' . number_format_i18n( (float) $sale ) . ')' : '' ) . '.' );
					$changed++;
				}
			}
		}

		// 3) Variable structure when WebPrd lists attribute relations.
		if ( 'product' === get_post_type( $product_id ) ) {
			$changed += self::apply_variable_structure( $product_id, $d );
		}

		return $changed;
	}

	/**
	 * Build/link the variable structure described by WebPrd AttributeRelations.
	 * Every child guid maps to an existing product (created by the accounting
	 * client) that carries the same guid in its meta — we find it and link it
	 * as a variation under this parent.
	 */
	private static function apply_variable_structure( $product_id, array $d ) {
		if ( empty( $d['AttributeRelations'] ) || ! is_array( $d['AttributeRelations'] ) ) return 0;
		$rels = $d['AttributeRelations'];
		if ( count( $rels ) < 2 ) return 0;

		$type = self::type_of( $product_id );
		if ( 'variable' === $type || 'variation' === $type ) {
			// Already a variable parent: only link children that are missing.
			return self::link_children( $product_id, $rels, null, $d );
		}

		// Convert simple -> variable (WooCommerce product_type term swap).
		if ( 'simple' === $type ) {
			wp_set_object_terms( $product_id, array( 'variable' ), 'product_type' );
			$type = 'variable';
		}
		if ( 'variable' !== $type ) return 0;

		// Collect attribute slugs used by the relations.
		$tax_by_slug = array();
		foreach ( $rels as $rel ) {
			if ( ! is_array( $rel ) || empty( $rel['AttributeSlug'] ) ) continue;
			$slug = sanitize_key( strpos( (string) $rel['AttributeSlug'], 'pa_' ) === 0 ? $rel['AttributeSlug'] : 'pa_' . $rel['AttributeSlug'] );
			if ( '' === $slug ) continue;
			$title = isset( $rel['AttributeTitle'] ) && '' !== (string) $rel['AttributeTitle'] ? (string) $rel['AttributeTitle'] : $slug;
			$tax_by_slug[ $slug ] = $title;
		}
		if ( ! $tax_by_slug ) return 0;

		$term_slugs = array();          // taxonomy => list of term slugs
		$term_ids   = array();          // taxonomy => list of term ids
		foreach ( $tax_by_slug as $tax => $title ) {
			if ( ! taxonomy_exists( $tax ) && function_exists( 'wc_create_attribute' ) ) {
				$id = wc_create_attribute( array(
					'name'   => $title,
					'slug'   => str_replace( 'pa_', '', $tax ),
					'type'   => 'select',
					'order_by' => 'menu_order',
				) );
				if ( is_wp_error( $id ) || ! $id ) return 0;
				// WC registers wc-attribute taxonomies lazily; try again.
			}
			if ( ! taxonomy_exists( $tax ) ) return 0;
			$values = array();
			foreach ( $rels as $rel ) {
				if ( ! is_array( $rel ) || ( isset( $rel['AttributeSlug'] ) && ( 'pa_' . ltrim( (string) $rel['AttributeSlug'], 'pa_' ) ) !== $tax ) ) continue;
				if ( empty( $rel['AttributeValue'] ) ) continue;
				$values[ (string) $rel['AttributeValue'] ] = 1;
			}
			foreach ( array_keys( $values ) as $value ) {
				$term = term_exists( $value, $tax );
				if ( ! $term ) {
					$term = wp_insert_term( $value, $tax );
				}
				if ( is_wp_error( $term ) || ! is_array( $term ) ) continue;
				$tt = get_term( (int) $term['term_id'], $tax );
				if ( ! $tt || is_wp_error( $tt ) ) continue;
				$term_slugs[ $tax ][] = $tt->slug;
				$term_ids[ $tax ][]   = (int) $tt->term_id;
			}
		}
		if ( ! $term_slugs ) return 0;

		// Attach terms to the product and rebuild its attributes object.
		foreach ( $term_ids as $tax => $ids ) {
			wp_set_object_terms( $product_id, $ids, $tax, false );
		}
		$p = wc_get_product( $product_id );
		if ( ! $p || ! method_exists( $p, 'set_attributes' ) || ! class_exists( '\WC_Product_Attribute' ) ) return 0;
		$attrs = $p->get_attributes();
		foreach ( $tax_by_slug as $tax => $title ) {
			if ( empty( $term_slugs[ $tax ] ) ) continue;
			$new = new \WC_Product_Attribute();
			$new->set_name( $tax );
			$new->set_options( $term_slugs[ $tax ] );
			$new->set_visible( true );
			$new->set_variation( true );
			$found = false;
			foreach ( $attrs as $k => $old ) {
				if ( is_object( $old ) && method_exists( $old, 'get_name' ) && (string) $old->get_name() === $tax ) {
					$attrs[ $k ] = $new;
					$found       = true;
					break;
				}
			}
			if ( ! $found ) $attrs[] = $new;
		}
		$p->set_attributes( $attrs );

		// Default variable (DefaultVariable guid -> its attribute value).
		$default = array();
		if ( ! empty( $d['DefaultVariable'] ) ) {
			foreach ( $rels as $rel ) {
				if ( is_array( $rel ) && isset( $rel['ProductGuid'] ) && (string) $rel['ProductGuid'] === (string) $d['DefaultVariable'] && ! empty( $rel['AttributeValue'] ) && ! empty( $rel['AttributeSlug'] ) ) {
					$tax = sanitize_key( strpos( (string) $rel['AttributeSlug'], 'pa_' ) === 0 ? $rel['AttributeSlug'] : 'pa_' . $rel['AttributeSlug'] );
					if ( isset( $term_slugs[ $tax ] ) ) {
						$term = term_exists( (string) $rel['AttributeValue'], $tax );
						if ( $term && ! is_wp_error( $term ) ) {
							$tt = get_term( (int) $term['term_id'], $tax );
							if ( $tt && ! is_wp_error( $tt ) ) $default[ $tax ] = $tt->slug;
						}
					}
					break;
				}
			}
		}
		if ( $default && method_exists( $p, 'set_default_attributes' ) ) {
			$p->set_default_attributes( $default );
		}
		$p->save();
		SyncLog::add( 'auto_structure', 'success', 'product', $product_id, 'کالا به متغیر تبدیل شد و ویژگی(های) آن از WebPrd پیوند شد.' );
		return 1 + self::link_children( $product_id, $rels, $term_slugs, $d );
	}

	/**
	 * Link existing child products (same guid) under $parent as variations and
	 * give each child its attribute value + own price (child WebPrd Sell1).
	 */
	private static function link_children( $parent, array $rels, $term_slugs, array $d ) {
		if ( ! function_exists( 'wc_get_product' ) ) return 0;
		global $wpdb;
		$map = array();
		foreach ( $rels as $rel ) {
			if ( ! is_array( $rel ) || empty( $rel['ProductGuid'] ) ) continue;
			$guid = (string) $rel['ProductGuid'];
			if ( isset( $map[ $guid ] ) ) continue;
			$map[ $guid ] = array(
				'tax'  => isset( $rel['AttributeSlug'] ) ? sanitize_key( strpos( (string) $rel['AttributeSlug'], 'pa_' ) === 0 ? $rel['AttributeSlug'] : 'pa_' . $rel['AttributeSlug'] ) : '',
				'name' => isset( $rel['AttributeValue'] ) ? (string) $rel['AttributeValue'] : '',
			);
		}
		if ( ! $map ) return 0;
		$linked = 0;
		foreach ( $map as $guid => $info ) {
			$child = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='guid' AND meta_value=%s LIMIT 1", $guid ) );
			if ( ! $child || $child === (int) $parent ) continue;
			$post = get_post( $child );
			if ( ! $post ) continue;
			if ( 'product' === $post->post_type && 0 === (int) $post->post_parent ) {
				wp_update_post( array(
					'ID'         => $child,
					'post_type'  => 'product_variation',
					'post_parent'=> (int) $parent,
				) );
			} elseif ( 'product_variation' === $post->post_type && (int) $post->post_parent !== (int) $parent ) {
				wp_update_post( array( 'ID' => $child, 'post_parent' => (int) $parent ) );
			}
			if ( 'product_variation' === $post->post_type || 'product' === $post->post_type ) {
				if ( '' !== $info['tax'] && '' !== $info['name'] ) {
					$slug = $info['name'];
					if ( isset( $term_slugs[ $info['tax'] ] ) && is_array( $term_slugs[ $info['tax'] ] ) && in_array( $info['name'], $term_slugs[ $info['tax'] ], true ) ) {
						$slug = $info['name'];
					}
					update_post_meta( $child, 'attribute_' . $info['tax'], sanitize_title( $slug ) );
				}
				$cd = self::webprd_of( $child );
				if ( ! empty( $cd['Sell1'] ) && is_numeric( $cd['Sell1'] ) && (float) $cd['Sell1'] > 0 ) {
					update_post_meta( $child, '_regular_price', (string) round( (float) $cd['Sell1'] ) );
					update_post_meta( $child, '_price', (string) round( (float) $cd['Sell1'] ) );
				}
				$linked++;
			}
		}
		if ( $linked ) {
			SyncLog::add( 'auto_variation', 'success', 'product', (int) $parent, number_format_i18n( $linked ) . ' متغیر به کالای اصلی پیوند داده شد.' );
		}
		return $linked;
	}
}
