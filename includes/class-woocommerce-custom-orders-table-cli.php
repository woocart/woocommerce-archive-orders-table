<?php
/**
 * CLI Tool for migrating order data to/from custom table.
 *
 * @package WooCommerce_Custom_Orders_Table
 * |author  Liquid Web
 */

use LiquidWeb\WooCommerceCustomOrdersTable\Util\QueryIterator;

/**
 * Manages the contents of the WooCommerce orders table.
 */
class WooCommerce_Custom_Orders_Table_CLI extends WP_CLI_Command {

	/**
	 * Contains IDs of any orders that have been skipped during the migration.
	 *
	 * @var array
	 */
	protected $skipped_ids = array();

	/**
	 * Bootstrap the WP-CLI command.
	 */
	public function __construct() {
		add_action( 'woocommerce_caught_exception', 'self::handle_exceptions' );

		// Ensure the custom table has been installed.
		WooCommerce_Custom_Orders_Table_Install::activate();
	}

	/**
	 * Count how many orders have yet to be migrated into the custom orders table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc orders-table count
	 *
	 * ## OPTIONS
	 *
	 * [--duration=<no-of-days>]
	 * : Set the duration of days, starting today, to skip archiving of orders.
	 * For Ex: Setting this value to 45 will skip orders for the last 45 days from archiving.
	 * ---
	 * default: 30
	 * ---

	 * @global $wpdb
	 *
	 * @param array $args       Positional arguments passed to the command.
	 * @param array $assoc_args Associative arguments (options) passed to the command.
	 * @return int The number of orders to be migrated.
	 */
	public function count( $args = array(), $assoc_args = array() ) {
		global $wpdb;

		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'duration' => 30,
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order_count = $wpdb->get_var( $this->get_migration_query( 'COUNT(*)', 0, 0, $assoc_args['duration'] ) );

		WP_CLI::log(
			sprintf(
				/* Translators: %1$d is the number of orders to be migrated. */
				_n( 'There is %1$d order to be migrated.', 'There are %1$d orders to be migrated.', $order_count, 'woocommerce-custom-orders-table' ),
				$order_count
			)
		);

		return (int) $order_count;
	}

	/**
	 * List and save all additional postmeta keys for `shop_order` post type.
	 *
	 * ## EXAMPLES
	 *      wp wc orders-table optimize
	 *
	 * @global $wpdb
	 *
	 * @return WP_CLI
	 */
	public function optimize() {
		global $wpdb;

		$added       = 0;
		$order_types = wc_get_order_types( 'reports' );
		$meta_keys   = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
				'SELECT DISTINCT meta_key FROM wp_postmeta as pm, wp_posts as p WHERE meta_key NOT LIKE "_oembed%" AND pm.post_id = p.ID AND p.post_type IN (' . implode( ', ', array_fill( 0, count( $order_types ), '%s' ) ) . ')',
				$order_types
			)
		);

		if ( empty( $meta_keys ) ) {
			return WP_CLI::error(
				esc_html__( 'No additional meta keys found.', 'woocommerce-custom-orders-table' )
			);
		}

		// Fetch stored metakeys.
		$metakeys_list = get_option( WC_CUSTOM_ORDER_TABLE_OPTION, array() );

		foreach ( $meta_keys as $meta_key ) {
			if ( in_array( $meta_key, array_values( WooCommerce_Custom_Orders_Table::get_postmeta_mapping() ), true ) ) {
				continue;
			}

			// Check for key within blacklisted keys.
			if ( in_array( $meta_key, WooCommerce_Custom_Orders_Table::get_blacklisted_keys(), true ) ) {
				continue;
			}

			// Check if the key already exists from the DB.
			if ( in_array( $meta_key, $metakeys_list, true ) ) {
				continue;
			}

			array_push( $metakeys_list, $meta_key );

			// Update counter.
			++$added;
		}

		// Check if we have additional meta_keys.
		if ( ! $added > 0 ) {
			return WP_CLI::log(
				esc_html__( 'No additional meta keys were found.', 'woocommerce-custom-orders-table' )
			);
		}

		// Store additional meta_keys in database.
		update_option( WC_CUSTOM_ORDER_TABLE_OPTION, $metakeys_list );

		return WP_CLI::success(
			esc_html__( 'Meta keys list has been updated in the database. Run `wp wc orders-table populate` command to create columns for the additional keys.', 'woocommerce-custom-orders-table' )
		);
	}

	/**
	 * Create columns in the `woocommerce_orders` table for the additional meta keys.
	 *
	 * ## EXAMPLES
	 *      wp wc orders-table populate
	 *
	 * @global $wpdb
	 *
	 * @return WP_CLI
	 */
	public function populate() {
		global $wpdb;

		$cols_added    = 0;
		$metakeys_list = get_option( WC_CUSTOM_ORDER_TABLE_OPTION );

		if ( ! $metakeys_list ) {
			return WP_CLI::error(
				esc_html__( 'No additional meta keys found in the database.', 'woocommerce-custom-orders-table' )
			);
		}

		$order_table = wc_custom_order_table()->get_table_name();

		// Loop over meta keys and check for existing column in the database.
		foreach ( $metakeys_list as $col_name ) {
			$column = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SHOW COLUMNS FROM {$order_table} LIKE %s",
					$col_name
				)
			);

			// Column already exists.
			if ( $column ) {
				WP_CLI::log(
					sprintf(
						/* translators: %s: column name */
						esc_html__( '`%s` column already exists in the database.', 'woocommerce-custom-orders-table' ),
						$col_name
					)
				);

				continue;
			}

			// Alter table to add column.
			$query = $wpdb->query(
				$wpdb->prepare(
					"ALTER TABLE {$order_table} ADD COLUMN `{$col_name}` text"
				)
			);

			if ( ! $query ) {
				WP_CLI::error(
					sprintf(
						/* translators: %s: column name */
						esc_html__( 'There was an error while adding `%s` column to the table.', 'woocommerce-custom-orders-table' ),
						$col_name
					)
				);

				continue;
			}

			++$cols_added;
		}

		// Add a message at the end.
		WP_CLI::success(
			sprintf(
				/* translators: %s: column name */
				esc_html__( '%s columns added to the table.', 'woocommerce-custom-orders-table' ),
				$cols_added
			)
		);
	}

	/**
	 * Migrate order data to the custom orders table.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<batch-size>]
	 * : The number of orders to process in each batch. Passing a value of 0 will disable batching.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--duration=<no-of-days>]
	 * : Set the duration of days, starting today, to skip archiving of orders.
	 * For Ex: Setting this value to 45 will skip orders for the last 45 days from archiving.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--save-post-meta]
	 * : Preserve the original post meta after a successful migration.
	 * Default behavior is to clean up post meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc orders-table migrate --batch-size=100 --save-post-meta
	 *
	 * @global $wpdb
	 *
	 * @param array $args       Positional arguments passed to the command.
	 * @param array $assoc_args Associative arguments (options) passed to the command.
	 */
	public function migrate( $args = array(), $assoc_args = array() ) {
		global $wpdb;

		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'batch-size'     => 100,
				'duration'       => 30,
				'save-post-meta' => false,
			)
		);

		$order_count = $this->count( array(), array( 'duration' => $assoc_args['duration'] ) );

		// Abort if there are no orders to migrate.
		if ( ! $order_count ) {
			return WP_CLI::warning( __( 'There are no orders to migrate, aborting.', 'woocommerce-custom-orders-table' ) );
		}

		$order_table = wc_custom_order_table()->get_table_name();
		$order_types = wc_get_order_types( 'reports' );
		$progress    = WP_CLI\Utils\make_progress_bar( 'Order Data Migration', $order_count );
		$processed   = 0;
		$batch_count = 1;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order_data = $wpdb->get_col( $this->get_migration_query( 'p.ID', $assoc_args['batch-size'], 0, $assoc_args['duration'] ) );

		while ( ! empty( $order_data ) ) {

			// Debug message for batched migrations.
			if ( 0 !== $assoc_args['batch-size'] ) {
				WP_CLI::debug(
					sprintf(
						/* Translators: %1$d is the batch number, %2$d is the batch size. */
						__( 'Beginning batch #%1$d (%2$d orders/batch).', 'woocommerce-custom-orders-table' ),
						$batch_count,
						$assoc_args['batch-size']
					)
				);
			}

			// Iterate over each order in this batch.
			foreach ( $order_data as $order_id ) {
				$order = $this->get_order( $order_id );

				// Either an error occurred or wc_get_order() could not find the order.
				if ( false === $order ) {
					$this->skipped_ids[] = $order_id;

					WP_CLI::warning(
						sprintf(
							/* Translators: %1$d is the order ID. */
							__( 'Unable to retrieve order with ID %1$d, skipping', 'woocommerce-custom-orders-table' ),
							$order_id
						)
					);

				} else {
					$result = $order->get_data_store()->populate_from_meta( $order, ! $assoc_args['save-post-meta'] );

					if ( is_wp_error( $result ) ) {
						$this->skipped_ids[] = $order_id;

						WP_CLI::warning(
							sprintf(
								/* Translators: %1$d is the order ID, %2$s is the error message. */
								__( 'A database error occurred while migrating order %1$d, skipping: %2$s.', 'woocommerce-custom-orders-table' ),
								$order_id,
								$result->get_error_message()
							)
						);
					} else {
						$processed++;

						WP_CLI::debug(
							sprintf(
								/* Translators: %1$d is the migrated order ID. */
								__( 'Order ID %1$d has been migrated.', 'woocommerce-custom-orders-table' ),
								$order_id
							)
						);
					}

					WP_CLI::debug(
						sprintf(
							/* Translators: %1$d is the migrated order ID. */
							__( 'Order ID %1$d has been migrated.', 'woocommerce-custom-orders-table' ),
							$order_id
						)
					);
				}

				$progress->tick();
			}

			$next_batch = array_filter(
				$wpdb->get_col(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$this->get_migration_query( 'p.ID', $assoc_args['batch-size'], count( $this->skipped_ids ), $assoc_args['duration'] )
				)
			);

			if ( $next_batch === $order_data ) {
				return WP_CLI::error( __( 'Infinite loop detected, aborting.', 'woocommerce-custom-orders-table' ) );
			} else {
				$order_data = $next_batch;
				$batch_count++;
			}
		}

		$progress->finish();

		// Issue a warning if no orders were migrated.
		if ( ! $processed ) {
			return WP_CLI::warning( __( 'No orders were migrated.', 'woocommerce-custom-orders-table' ) );
		}

		if ( empty( $this->skipped_ids ) ) {
			return WP_CLI::success(
				sprintf(
					/* Translators: %1$d is the number of migrated orders. */
					_n( '%1$d order was migrated.', '%1$d orders were migrated.', $processed, 'woocommerce-custom-orders-table' ),
					$processed
				)
			);
		} else {
			WP_CLI::warning(
				sprintf(
					/* Translators: %1$d is the number of orders migrated, %2$d is the number of skipped records. */
					_n( '%1$d order was migrated, with %2$d skipped.', '%1$d orders were migrated, with %2$d skipped.', $processed, 'woocommerce-custom-orders-table' ),
					$processed,
					count( $this->skipped_ids )
				)
			);
		}
	}

	/**
	 * Copy order data into the postmeta table.
	 *
	 * Note that this could dramatically increase the size of your postmeta table, but is recommended
	 * if you wish to stop using the custom orders table plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<batch-size>]
	 * : The number of orders to process in each batch. Passing a value of 0 will disable batching.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--delete-custom-orders]
	 * : Delete the custom order after a successful backfill.
	 * Default behavior is to keep custom orders.
	 *
	 * ## EXAMPLES
	 *
	 *     # Copy all order data into the post meta table, 100 posts at a time, delete custom order.
	 *     wp wc orders-table backfill --batch-size=100 --delete-custom-orders
	 *
	 * @global $wpdb
	 *
	 * @param array $args       Positional arguments passed to the command.
	 * @param array $assoc_args Associative arguments (options) passed to the command.
	 */
	public function backfill( $args = array(), $assoc_args = array() ) {
		global $wpdb;

		$assoc_args  = wp_parse_args(
			$assoc_args,
			array(
				'batch-size' => 100,
				'delete-custom-orders' => false,
			)
		);
		$order_table = wc_custom_order_table()->get_table_name();
		$order_count = $wpdb->get_var( 'SELECT COUNT(order_id) FROM ' . esc_sql( $order_table ) ); // WPCS: DB call ok.

		// If batching has been disabled, set the batch size to the total order count (e.g. one batch).
		if ( 0 === $assoc_args['batch-size'] ) {
			$assoc_args['batch-size'] = $order_count;
		}

		$order_query = new QueryIterator( 'SELECT order_id FROM ' . esc_sql( $order_table ), $assoc_args['batch-size'] );
		$progress    = WP_CLI\Utils\make_progress_bar( 'Order Data Migration', $order_count );
		$processed   = 0;

		while ( $order_query->valid() ) {
			$order = $this->get_order( $order_query->current()->order_id );

			if ( $order ) {
				WooCommerce_Custom_Orders_Table::migrate_to_post_meta( $order, $assoc_args['delete-custom-orders'] );
			}

			$processed++;
			$progress->tick();
			$order_query->next();
		}

		$progress->finish();

		// Issue a warning if no orders were migrated.
		if ( ! $processed ) {
			return WP_CLI::warning( __( 'No orders were migrated.', 'woocommerce-custom-orders-table' ) );
		}

		WP_CLI::success(
			sprintf(
				/* Translators: %1$d is the number of migrated orders. */
				_n( '%1$d order was migrated.', '%1$d orders were migrated.', $processed, 'woocommerce-custom-orders-table' ),
				$processed
			)
		);
	}

	/**
	 * Callback function for the "woocommerce_caught_exception" action.
	 *
	 * @throws Exception Re-throw the previously-caught Exception.
	 *
	 * @param Exception $exception The Exception object.
	 */
	public static function handle_exceptions( $exception ) {
		throw $exception;
	}

	/**
	 * Helper function for calling wc_get_order(), with error handling.
	 *
	 * @param int $order_id The order/refund ID.
	 *
	 * @return WC_Abstract_Order|bool Either the WC_Order/WC_Order_Refund object or false if the
	 *                                order object couldn't be loaded.
	 */
	protected function get_order( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
		} catch ( Exception $e ) {
			$order = false;
			WP_CLI::warning(
				sprintf(
					/* Translators: %1$d is the order ID, %2$s is the exception message. */
					__( 'Encountered an error retrieving order #%1$d: %2$s', 'woocommerce-custom-orders-table' ),
					$order_id,
					$e->getMessage()
				)
			);
		}

		return $order;
	}

	/**
	 * Build a SQL query to get posts that require migration.
	 * Orders not marked as "completed" or the ones less than 7 days old
	 * are skipped from migration.
	 *
	 * @global $wpdb
	 *
	 * @param string $select     The contents of the SELECT clause.
	 * @param int    $limit      The maximum number of results to return, or '0' to not limit the number
	 *                                               of results.
	 * @param int    $offset     The offset value. Default is 0.
	 * @param int    $duration Number of days to skip archiving. Default is 30.
	 *
	 * @return string The prepared SQL query.
	 */
	protected function get_migration_query( $select, $limit, $offset = 0, $duration = 30 ) {
		global $wpdb;

		$order_table = wc_custom_order_table()->get_table_name();
		$order_types = wc_get_order_types( 'reports' );
		$query       = "
			SELECT {$select}
			FROM {$wpdb->posts} p
			LEFT JOIN {$order_table} o ON p.ID = o.order_id
			WHERE p.post_type IN (" . implode( ', ', array_fill( 0, count( $order_types ), '%s' ) ) . ')
			AND p.post_status IN ("wc-completed", "wc-processing", "wc-pending", "wc-on-hold", "wc-cancelled", "wc-refunded")
			AND p.post_modified <= DATE_SUB(SYSDATE(), INTERVAL ' . $duration . ' DAY)
			AND o.order_id IS NULL
		';
		$parameters  = $order_types;

		if ( $limit ) {
			$query       .= 'LIMIT %d, %d';
			$parameters[] = $offset;
			$parameters[] = $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->prepare( $query, $parameters );
	}
}
