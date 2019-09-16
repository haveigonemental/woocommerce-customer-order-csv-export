<?php
/**
 * WooCommerce Customer/Order CSV Export
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Customer/Order CSV Export to newer
 * versions in the future. If you wish to customize WooCommerce Customer/Order CSV Export for your
 * needs please refer to http://docs.woocommerce.com/document/ordercustomer-csv-exporter/
 *
 * @author      SkyVerge
 * @copyright   Copyright (c) 2015-2019, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

use SkyVerge\WooCommerce\PluginFramework\v5_4_1 as Framework;

/**
 * Customer/Order CSV Export Compatibility
 *
 * Class that handles compatibility with all the export formats.
 * Mainly handles getting and formatting order/customer data, but may also
 * be used to further adjust CSV headers in ways that are beyond the scope for
 * the Formats class.
 *
 * @since 3.0.0
 */
class WC_Customer_Order_CSV_Export_Compatibility {


	/** @var string order export format */
	private $orders_format;

	/** @var string customer export format */
	private $customers_format;

	/** @var string coupon export format */
	private $coupons_format;

	/**
	 * Constructor
	 *
	 * Check if using import or legacy export format and add filters as needed
	 *
	 * @since 3.0.0
	 */
	public function __construct() {

		$this->orders_format    = get_option( 'wc_customer_order_csv_export_orders_format' );
		$this->customers_format = get_option( 'wc_customer_order_csv_export_customers_format' );
		$this->coupons_format   = get_option( 'wc_customer_order_csv_export_coupons_format' );

		if ( 'default' !== $this->orders_format ) {

			add_filter( 'wc_customer_order_csv_export_order_headers', [ $this, 'modify_order_headers' ], 0, 2 );
			add_filter( 'wc_customer_order_csv_export_order_row', [ $this, 'modify_order_row' ], 0, 2 );
		}

		if ( 'default' !== $this->customers_format ) {

			add_filter( 'wc_customer_order_csv_export_customer_row', [ $this, 'modify_customer_row' ], 0, 2 );
		}


		if ( 'default' !== $this->coupons_format ) {

			add_filter( 'wc_customer_order_csv_export_coupon_row', [ $this, 'modify_coupon_row' ], 0, 2 );
		}
	}


	/**
	 * Modify the order export column headers to match the chosen format
	 *
	 * @since 3.0.0
	 * @param array $headers Original, unmodified headers
	 * @param \WC_Customer_Order_CSV_Export_Generator the generator instance
	 * @return array modified column headers
	 */
	public function modify_order_headers( $headers, $generator ) {

		// at the time when formats are defined in the Formats class, there is no way to
		// know how many order items or shipping items any order will have. For this reason,
		// the individual headers for each line/shipping item are added here.
		if ( 'legacy_import' === $this->orders_format ) {

			$order_item_headers = $shipping_headers = [];

			$max_line_items     = $this->get_max_line_items( $generator->ids );
			$max_shipping_items = $this->get_max_shipping_line_items( $generator->ids );

			// line items
			for ( $i = 1; $i <= $max_line_items; $i++ ) {

				$order_item_headers[ "order_item_{$i}" ] = "order_item_{$i}";
			}

			// shipping line items
			for ( $i = 1; $i <= $max_shipping_items; $i++ ) {

				$shipping_headers[ "shipping_method_{$i}" ] = "shipping_method_{$i}";
				$shipping_headers[ "shipping_cost_{$i}" ]   = "shipping_cost_{$i}";
			}

			$headers = Framework\SV_WC_Helper::array_insert_after( $headers, 'order_item_[i]', $order_item_headers );
			$headers = Framework\SV_WC_Helper::array_insert_after( $headers, 'shipping_method_[i]', $shipping_headers );

			// remove placeholder headers
			unset( $headers['order_item_[i]'], $headers['shipping_method_[i]'] );

		}

		return $headers;
	}


	/**
	 * Modify the order export row to match the chosen format
	 *
	 * @since 3.0.0
	 * @param array $order_data an array of order data for the given order
	 * @param WC_Order $order the WC_Order object
	 * @return array modified order data
	 */
	public function modify_order_row( $order_data, $order ) {

		if ( 'custom' === $this->orders_format ) {

			return $this->get_custom_order_columns( $order_data, $order );

		} elseif ( 'import' === $this->orders_format ) {

			return $this->get_import_columns( $order_data, $order );

		} elseif ( 'legacy_import' === $this->orders_format ) {

			return $this->get_legacy_import_one_column_per_line_item( $order_data, $order );

		} elseif ( 'legacy_one_row_per_item' === $this->orders_format ) {

			return $this->get_legacy_one_row_per_line_item( $order_data, $order );

		} elseif ( 'legacy_single_column' === $this->orders_format ) {

			return $this->get_legacy_single_column_line_item( $order_data, $order );
		}

		return $order_data;
	}


	/**
	 * Get the order data format for the custom order export format
	 *
	 * @since 4.0.0
	 * @param array $order_data an array of order data for the given order
	 * @param WC_Order $order the WC_Order object
	 * @return array modified order data
	 */
	private function get_custom_order_columns( $order_data, WC_Order $order ) {

		// data can be an array of arrays when each line item is it's own row
		$one_row_per_item = is_array( reset( $order_data ) );
		$meta_columns     = $this->get_custom_format_meta_keys( 'orders' );
		$static_columns   = $this->get_custom_format_static_columns( 'orders' );

		// add meta columns
		if ( ! empty( $meta_columns ) ) {

			foreach ( $meta_columns as $meta_key ) {

				$data_key   = 'meta:' . $meta_key;
				$meta_value = maybe_serialize( get_post_meta( Framework\SV_WC_Order_Compatibility::get_prop( $order, 'id' ), $meta_key, true ) );

				if ( $one_row_per_item ) {

					foreach ( $order_data as $key => $data ) {
						$order_data[ $key ][ $data_key ] = $meta_value;
					}

				} else {
					$order_data[ $data_key ] = $meta_value;
				}

			}

		}

		// add static columns
		if ( ! empty( $static_columns ) ) {
			if ( $one_row_per_item ) {

				foreach ( $order_data as $key => $data ) {
					$order_data[ $key ] = array_merge( $order_data[ $key ], $static_columns );
				}

			} else {
				$order_data = array_merge( $order_data, $static_columns );
			}
		}

		return $order_data;
	}


	/**
	 * Get the order data format for CSV Import JSON format
	 *
	 * @since 3.12.0
	 * @param array $order_data an array of order data for the given order
	 * @param WC_Order $order the WC_Order object
	 * @return array modified order data
	 */
	private function get_import_columns( $order_data, WC_Order $order ) {

		// customer_id will be the customer email
		$user                      = get_user_by( 'id', $order_data['customer_id'] );
		$order_data['customer_id'] = $user ? $user->user_email : '';

		return $order_data;
	}


	/**
	 * Get the order data format for a single column per line item, compatible with the CSV Import Suite plugin
	 *
	 * @since 3.0.0
	 * @param array $order_data an array of order data for the given order
	 * @param WC_Order $order the WC_Order object
	 * @return array modified order data
	 */
	private function get_legacy_import_one_column_per_line_item( $order_data, WC_Order $order ) {

		$count = 1;

		// add line items
		foreach ( $order->get_items() as $_ => $item ) {

			// sku/qty/price
			$product = $order->get_product_from_item( $item );

			if ( ! is_object( $product ) ) {
				$product = new WC_Product( 0 );
			}

			$sku = $product->get_sku();

			// note that product ID must be prefixed with `product_id:` so the importer can properly parse it vs. the SKU
			$product_id = Framework\SV_WC_Plugin_Compatibility::product_get_id( $product );

			$line_item = [
				$sku ? $sku : "product_id:{$product_id}",
				$item['qty'],
				$order->get_line_total( $item )
			];

			// Add item meta with WC 3.1 Compatibility
			$formatted_meta = Framework\SV_WC_Order_Compatibility::get_item_formatted_meta_data( $item );

			if ( ! empty( $formatted_meta ) ) {

				foreach ( $formatted_meta as $meta_key => $meta ) {

					// remove newlines
					$label = str_replace( [ "\r", "\r\n", "\n" ], '', $meta['label'] );
					$value = str_replace( [ "\r", "\r\n", "\n" ], '', $meta['value'] );

					// escape reserved chars (:;|)
					$label = str_replace( [ ': ', ':', ';', '|' ], [ '\: ', '\:', '\;', '\|' ], $label );
					$value = str_replace( [ ': ', ':', ';', '|' ], [ '\: ', '\:', '\;', '\|' ], $value );

					$line_item[] = wp_kses_post( $label . ': ' . $value );
				}
			}

			$order_data[ "order_item_{$count}" ] = implode( '|', $line_item );

			$count++;
		}

		$count = 1;

		foreach ( $order->get_items( 'shipping' ) as $_ => $shipping_item ) {

			$order_data[ "shipping_method_{$count}" ] = $shipping_item['method_id'];
			$order_data[ "shipping_cost_{$count}" ]   = wc_format_decimal( $shipping_item['cost'], 2 );

			$count++;
		}

		// fix customer user
		$user                      = get_user_by( 'id', $order_data['customer_id'] );
		$order_data['customer_id'] = $user ? $user->user_email : '';

		return $order_data;
	}


	/**
	 * Get the order data format for a new row per line item, compatible with the legacy (pre 3.0) CSV Export format
	 *
	 * Note this code was adapted from the old code to maintain compatibility as close as possible, so it should
	 * not be modified unless absolutely necessary
	 *
	 * {BR 2016-09-26} This function was updated in 4.0.7 as the passed in $order_data is different when the
	 *  format definition is specified as an 'item' row type
	 *
	 * @since 3.0.0
	 * @param array $order_data an array of order data for the given order
	 * @param WC_Order $order the WC_Order object
	 * @return array modified order data
	 */
	private function get_legacy_one_row_per_line_item( $order_data, WC_Order $order ) {

		foreach ( $order_data as $line_item => $data ) {

			// keep the variation format the same as legacy versions
			$variation = str_replace( '=', ': ', $order_data[ $line_item ]['item_meta'] );
			$variation = str_replace( ',', ', ', $variation );

			$order_data[ $line_item ]['line_item_sku']       = $order_data[ $line_item ]['item_sku'];
			$order_data[ $line_item ]['line_item_name']      = $order_data[ $line_item ]['item_name'];
			$order_data[ $line_item ]['line_item_variation'] = $variation;
			$order_data[ $line_item ]['line_item_amount']    = $order_data[ $line_item ]['item_quantity'];
			$order_data[ $line_item ]['line_item_price']     = $order_data[ $line_item ]['item_total'];

			// convert country codes to full name
			if ( isset( WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'billing_country' ) ] ) ) {
				$order_data[ $line_item ]['billing_country'] = WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'billing_country' ) ];
			}

			if ( isset( WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'shipping_country' ) ] ) ) {
				$order_data[ $line_item ]['shipping_country'] = WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'shipping_country' ) ];
			}

			// set order ID to order number
			$order_data[ $line_item ]['order_id'] = ltrim( $order->get_order_number(), _x( '#', 'hash before the order number', 'woocommerce-customer-order-csv-export' ) );
		}

		return $order_data;
	}


	/**
	 * Get the order data format for a single column for all line items, compatible with the legacy (pre 3.0) CSV Export format
	 *
	 * Note this code was adapted from the old code to maintain compatibility as close as possible, so it should
	 * not be modified unless absolutely necessary
	 *
	 * @since 3.0.0
	 * @param array $order_data an array of order data for the given order
	 * @param WC_Order $order the WC_Order object
	 * @return array modified order data
	 */
	private function get_legacy_single_column_line_item( $order_data, WC_Order $order ) {

		$line_items = [];

		foreach ( $order->get_items() as $_ => $item ) {

			$product = $order->get_product_from_item( $item );

			if ( ! is_object( $product ) ) {
				$product = new WC_Product( 0 );
			}

			$line_item = $item['name'];

			if ( $product->get_sku() ) {
				$line_item .= ' (' . $product->get_sku() . ')';
			}

			$line_item .= ' x' . $item['qty'];

			if ( Framework\SV_WC_Plugin_Compatibility::is_wc_version_gte_3_1() ) {

				$variation = wp_strip_all_tags( wc_display_item_meta( $item, [
					'before'    => '',
					'after'     => '',
					'separator' => "\n",
					'echo'      => false,
				] ) );

			} else {

				$item_meta = new WC_Order_Item_Meta( $item );
				$variation = $item_meta->display( true, true );
			}

			if ( $variation ) {
				$line_item .= ' - ' . str_replace( [ "\r", "\r\n", "\n" ], '', $variation );
			}


			$line_items[] = str_replace( [ '&#8220;', '&#8221;' ], '', $line_item );
		}

		$order_data['order_items'] = implode( '; ', $line_items );

		// convert country codes to full name
		if ( isset( WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'billing_country' ) ] ) ) {
			$order_data['billing_country'] = WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'billing_country' ) ];
		}

		if ( isset( WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'shipping_country' ) ] ) ) {
			$order_data['shipping_country'] = WC()->countries->countries[ Framework\SV_WC_Order_Compatibility::get_prop( $order, 'shipping_country' ) ];
		}

		// set order ID to order number
		$order_data['order_id'] = ltrim( $order->get_order_number(), _x( '#', 'hash before the order number', 'woocommerce-customer-order-csv-export' ) );

		return $order_data;
	}


	/**
	 * Get the maximum number of line items for the given set of order IDs in order to generate the proper
	 * number of order line item columns for use with the CSV Import Suite format
	 *
	 * @since 3.0.0
	 * @param array $order_ids
	 * @return int max number of line items
	 */
	private function get_max_line_items( $order_ids ) {

		return $this->get_max_order_items( $order_ids, 'line_item' );
	}


	/**
	 * Get the maximum number of shipping line items for the given set of order IDs in order to generate the proper
	 * number of shipping line item columns for use with the CSV Import Suite format
	 *
	 * @since 3.0.0
	 * @param array $order_ids
	 * @return int max number of line items
	 */
	private function get_max_shipping_line_items( $order_ids ) {

		return $this->get_max_order_items( $order_ids, 'shipping' );
	}


	/**
	 * Gets the maximum number of order items for a set of order IDs.
	 *
	 * @since 4.4.0
	 *
	 * @param array $order_ids order IDs
	 * @param string $type line item type
	 * @return int
	 */
	private function get_max_order_items( array $order_ids, $type = 'line_item' ) {
		global $wpdb;

		$max_items = 0;

		foreach ( $order_ids as $order_id ) {

			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = %s", $order_id, $type ) );

			if ( is_array( $rows ) ) {

				$items_count = count( $rows );

				if ( $items_count >= $max_items ) {
					$max_items = $items_count;
				}
			}
		}

		return $max_items;
	}


	/**
	 * Gets selected format.
	 *
	 * @since 4.7.0
	 *
	 * @param $export_type
	 * @return array|null
	 */
	private function get_selected_format( $export_type ) {

		$format_attribute_name = $export_type . '_format';
		$format_key            = $this->$format_attribute_name;

		return wc_customer_order_csv_export()->get_formats_instance()->get_format( $export_type, $format_key );
	}


	/**
	 * Get meta keys that should be included in the custom export format
	 *
	 * @since 4.0.0
	 * @param string $export_type
	 * @return array
	 */
	private function get_custom_format_meta_keys( $export_type ) {

		$meta = [];

		$custom_format = $this->get_selected_format( $export_type );

		if ( null === $custom_format ) {
			return $meta;
		}

		// Include all meta
		if ( $custom_format['include_all_meta'] ) {

			$all_meta = wc_customer_order_csv_export()->get_formats_instance()->get_all_meta_keys( $export_type );

			if ( ! empty( $all_meta ) ) {

				foreach ( $all_meta as $meta_key ) {

					$meta[] = $meta_key;
				}
			}

		// Include some meta only, if defined
		} else {

			$column_mapping = $custom_format['mapping'];

			foreach ( $column_mapping as $column ) {

				if ( isset( $column['source'] ) && 'meta' === $column['source'] ) {
					$meta[] = $column['meta_key'];
				}
			}

		}

		return $meta;
	}


	/**
	 * Returns static columns that should be included in the custom export format
	 *
	 * @since 4.3.0
	 * @param string $export_type
	 * @return array
	 */
	private function get_custom_format_static_columns( $export_type ) {

		$statics = [];

		$custom_format = $this->get_selected_format( $export_type );

		if ( null === $custom_format ) {
			return $statics;
		}

		$column_mapping = $custom_format['mapping'];

		foreach ( $column_mapping as $column ) {

			if ( isset( $column['source'] ) && 'static' === $column['source'] ) {
				$statics[ $column['name'] ] = $column['static_value'];
			}
		}

		return $statics;
	}


	/**
	 * Modify the order export row to match the chosen format
	 *
	 * @since 3.0.0
	 * @param array $customer_data an array of customer data for the given user
	 * @param WP_User $user the WP User object
	 * @return array modified customer data
	 */
	public function modify_customer_row( $customer_data, $user ) {

		if ( 'custom' === $this->customers_format ) {

			if ( $user->ID ) {
				$meta = $this->get_custom_format_meta_keys( 'customers' );

				// Fetch meta
				if ( ! empty( $meta ) ) {

					foreach ( $meta as $meta_key ) {
						$customer_data[ 'meta:' . $meta_key ] = maybe_serialize( get_user_meta( $user->ID, $meta_key, true ) );
					}
				}
			}

			$customer_data = array_merge( $customer_data, $this->get_custom_format_static_columns( 'customers' ) );
		}

		return $customer_data;
	}


	/**
	 * Modifies the coupon export row to match the chosen format.
	 *
	 * @since 4.6.0
   *
	 * @param array $coupon_data an array of coupon data for the given coupon
	 * @param WC_Coupon $coupon the WC_Coupon object
	 * @return array modified coupon data
	 */
	public function modify_coupon_row( $coupon_data, $coupon ) {

		if ( 'custom' === $this->coupons_format ) {

			$meta = $this->get_custom_format_meta_keys( 'coupons' );

			// Fetch meta columns
			if ( ! empty( $meta ) ) {

				foreach ( $meta as $meta_key ) {
					$coupon_data[ 'meta:' . $meta_key ] = maybe_serialize( get_post_meta( $coupon->get_id(), $meta_key, TRUE ) );
				}
			}

			// fetch static columns
			$coupon_data = array_merge( $coupon_data, $this->get_custom_format_static_columns( 'coupons' ) );
		}

		return $coupon_data;
	}

}
