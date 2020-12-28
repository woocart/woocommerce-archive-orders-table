<?php
/**
 * Core plugin functionality.
 *
 * @package WooCommerce_Archive_Orders_Table
 * @author  WooCart
 */

/**
 * Core functionality for WooCommerce Custom Orders Table.
 */
class WooCommerce_Archive_Orders_Table {

	/**
	 * The database table name.
	 *
	 * @var string
	 */
	protected $table_name = null;

	/**
	 * Steps to run on plugin initialization.
	 *
	 * @global $wpdb
	 */
	public function setup() {
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'woocommerce_orders';

		// Use the plugin's custom data stores for customers and orders.
		add_filter( 'woocommerce_customer_data_store', __CLASS__ . '::customer_data_store' );
		add_filter( 'woocommerce_order_data_store', __CLASS__ . '::order_data_store' );
		add_filter( 'woocommerce_order-refund_data_store', __CLASS__ . '::order_refund_data_store' );

		// Filter order report queries.
		add_filter( 'woocommerce_reports_get_order_report_query', 'WooCommerce_Archive_Orders_Table_Filters::filter_order_report_query' );

		// Fill-in after re-indexing of billing/shipping addresses.
		add_action( 'woocommerce_rest_system_status_tool_executed', 'WooCommerce_Archive_Orders_Table_Filters::rest_populate_address_indexes' );

		// When associating previous orders with a customer based on email, update the record.
		add_action( 'woocommerce_update_new_customer_past_order', 'WooCommerce_Archive_Orders_Table_Filters::update_past_customer_order', 10, 2 );

		WC_Customer_Data_Store_Archive_Table::add_hooks();

		// Register the table within WooCommerce.
		add_filter( 'woocommerce_install_get_tables', array( $this, 'register_table_name' ) );

		// If we're in a WP-CLI context, load the WP-CLI command.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wc orders-table', 'WooCommerce_Archive_Orders_Table_CLI' );
		}
	}

	/**
	 * Retrieve the WooCommerce orders table name.
	 *
	 * @return string The database table name.
	 */
	public function get_table_name() {
		/**
		 * Filter the WooCommerce orders table name.
		 *
		 * @param string $table The WooCommerce orders table name.
		 */
		return apply_filters( 'wc_customer_order_table_name', $this->table_name );
	}

	/**
	 * Simple helper method to determine if a row already exists for the given order ID.
	 *
	 * @global $wpdb
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return bool Whether or not a row already exists for this order ID.
	 */
	public function row_exists( $order_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(order_id) FROM ' . esc_sql( $this->get_table_name() ) . ' WHERE order_id = %d',
				$order_id
			)
		);
	}

	/**
	 * Retrieve the database table column => post_meta mapping.
	 *
	 * @return array An array of database columns and their corresponding post_meta keys.
	 */
	public static function get_postmeta_mapping() {
		return array(
			'order_key'            => '_order_key',
			'customer_id'          => '_customer_user',
			'payment_method'       => '_payment_method',
			'payment_method_title' => '_payment_method_title',
			'transaction_id'       => '_transaction_id',
			'customer_ip_address'  => '_customer_ip_address',
			'customer_user_agent'  => '_customer_user_agent',
			'created_via'          => '_created_via',
			'date_completed'       => '_date_completed',
			'date_paid'            => '_date_paid',
			'cart_hash'            => '_cart_hash',

			'billing_index'        => '_billing_address_index',
			'billing_first_name'   => '_billing_first_name',
			'billing_last_name'    => '_billing_last_name',
			'billing_company'      => '_billing_company',
			'billing_address_1'    => '_billing_address_1',
			'billing_address_2'    => '_billing_address_2',
			'billing_city'         => '_billing_city',
			'billing_state'        => '_billing_state',
			'billing_postcode'     => '_billing_postcode',
			'billing_country'      => '_billing_country',
			'billing_email'        => '_billing_email',
			'billing_phone'        => '_billing_phone',

			'shipping_index'       => '_shipping_address_index',
			'shipping_first_name'  => '_shipping_first_name',
			'shipping_last_name'   => '_shipping_last_name',
			'shipping_company'     => '_shipping_company',
			'shipping_address_1'   => '_shipping_address_1',
			'shipping_address_2'   => '_shipping_address_2',
			'shipping_city'        => '_shipping_city',
			'shipping_state'       => '_shipping_state',
			'shipping_postcode'    => '_shipping_postcode',
			'shipping_country'     => '_shipping_country',

			'discount_total'       => '_cart_discount',
			'discount_tax'         => '_cart_discount_tax',
			'shipping_total'       => '_order_shipping',
			'shipping_tax'         => '_order_shipping_tax',
			'cart_tax'             => '_order_tax',
			'total'                => '_order_total',

			'version'              => '_order_version',
			'currency'             => '_order_currency',
			'prices_include_tax'   => '_prices_include_tax',

			'amount'               => '_refund_amount',
			'reason'               => '_refund_reason',
			'refunded_by'          => '_refunded_by',
		);
	}

	/**
	 * Given a WC_Order object, fill its properties from post meta.
	 *
	 * @param WC_Order $order The WC_Order object to populate.
	 *
	 * @return WC_Order The populated WC_Order object.
	 */
	public static function populate_order_from_post_meta( $order ) {
		foreach ( self::get_postmeta_mapping() as $column => $meta_key ) {
			$meta = get_post_meta( $order->get_id(), $meta_key, true );

			$table_data = $order->get_data_store()->get_order_data_from_table( $order );
			if ( empty( $table_data->$column ) && ! empty( $meta ) ) {
				switch ( $column ) {
					case 'billing_index':
					case 'shipping_index':
						break;

					/*
					 * Migration isn't the time to validate (and potentially throw exceptions);
					 * if it was accepted into WooCommerce core, let it persist.
					 *
					 * If we're unable to set an email address due to $order->set_billing_email(),
					 * try to circumvent the check by using reflection to call the protected
					 * $order->set_address_prop() method.
					 */
					case 'billing_email':
						try {
							$order->set_billing_email( $meta );
						} catch ( WC_Data_Exception $e ) {
							$method = new ReflectionMethod( $order, 'set_address_prop' );
							$method->setAccessible( true );
							$method->invoke( $order, 'email', 'billing', $meta );
						}
						break;

					case 'prices_include_tax':
						$order->set_prices_include_tax( 'yes' === $meta );
						break;

					default:
						if ( method_exists( $order, "set_{$column}" ) ) {
							$order->{"set_{$column}"}( $meta );
						}
						break;
				}
			}
		}

		return $order;
	}

	/**
	 * Register the table name within WooCommerce.
	 *
	 * @param array $tables An array of known WooCommerce tables.
	 *
	 * @return array The filtered $tables array.
	 */
	public function register_table_name( $tables ) {
		$table = $this->get_table_name();

		if ( ! in_array( $table, $tables, true ) ) {
			$tables[] = $table;
			sort( $tables );
		}

		return $tables;
	}

	/**
	 * Restore an order's data in the post_meta table.
	 *
	 * @global $wpdb
	 *
	 * @param WC_Abstract_Order $order  The order or refund object, passed by reference.
	 * @param bool              $delete Optional. Should the row in the custom table be deleted?
	 *                                  Default is false.
	 */
	public static function migrate_to_post_meta( &$order, $delete = false ) {
		global $wpdb;

		$data = $order->get_data_store()->get_order_data_from_table( $order );

		if ( is_null( $data ) ) {
			return;
		}

		if ( isset( $data['prices_include_tax'] ) ) {
			$data['prices_include_tax'] = wc_bool_to_string( $data['prices_include_tax'] );
		}

		foreach ( self::get_postmeta_mapping() as $column => $meta_key ) {
			if ( isset( $data[ $column ] ) ) {
				update_post_meta( $order->get_id(), $meta_key, $data[ $column ] );
			}
		}

		// Remove the row from the custom table.
		if ( true === $delete ) {
			$wpdb->delete(
				wc_archive_order_table()->get_table_name(),
				array( 'order_id' => $order->get_id() ),
				array( '%d' )
			);
		}
	}

	/**
	 * Retrieve the class name of the WooCommerce customer data store.
	 *
	 * @return string The data store class name.
	 */
	public static function customer_data_store() {
		return 'WC_Customer_Data_Store_Archive_Table';
	}

	/**
	 * Retrieve the class name of the WooCommerce order data store.
	 *
	 * @return string The data store class name.
	 */
	public static function order_data_store() {
		return 'WC_Order_Data_Store_Archive_Table';
	}

	/**
	 * Retrieve the class name of the WooCommerce order refund data store.
	 *
	 * @return string The data store class name.
	 */
	public static function order_refund_data_store() {
		return 'WC_Order_Refund_Data_Store_Archive_Table';
	}
}
