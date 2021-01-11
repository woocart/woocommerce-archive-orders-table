# WooCommerce Archive Orders Table

This plugin can be used to archive orders older than 30 days to a separate orders table which helps to improve store's performance. There is absolutely no difference in how archived orders are displayed or accessed. However, the plugins relying on orders meta data won't be able to access data for the archived orders.

This plugin is based on [LiquidWeb's Custom Orders Table](https://github.com/liquidweb/woocommerce-custom-orders-table).

## Requirements

WooCommerce Custom Orders Table requires [WooCommerce 3.5.1 or newer](https://wordpress.org/plugins/woocommerce/).

If you're looking to migrate existing order data, [you'll need to have the ability to run WP-CLI commands in your WooCommerce environment](http://wp-cli.org/).

## Migrating order data

After installing and activating the plugin, you'll need to migrate orders from post meta into the newly-created orders table.

The easiest way to accomplish this is via [WP-CLI](http://wp-cli.org/), and the plugin ships with five commands to help:

### Counting the orders to be migrated

If you'd like to see the number of orders that have yet to be moved into the orders table, you can quickly retrieve this value with the `count` command:

```
$ wp wc orders-table count
```

### Analysing extra meta keys added by plugins

The first step in the migration process is to analyse the extra meta keys added by the plugins based on which additional columns are created in the orders table.

```
$ wp wc orders-table optimize
```

### Creating columns in the table for additional meta keys

The `populate` command creates the additional columns in the database to accomodate data for the additional meta keys associated with the orders data.

```
$ wp wc orders-table populate
```

### Migrate order data from post meta to the orders table

The `migrate` command will flatten all post meta values for WooCommerce orders into a flat database table, optimized for performance.

```
$ wp wc orders-table migrate
```

Orders are queried in batches (determined via the `--batch-size` option) in order to reduce the memory footprint of the command (e.g. "only retrieve `$size` orders at a time"). Some environments may require a lower value than the default of 100.

**Please note** that `migrate` will delete the original order post meta rows after a successful migration. If you want to preserve these, include the `--save-post-meta` flag!

#### Options

<dl>
	<dt>--batch-size=&lt;size&gt;</dt>
	<dd>The number of orders to process in each batch. Default is 100 orders per batch.</dd>
	<dd>Passing `--batch-size=0` will disable batching.</dd>
	<dt>--save-post-meta</dt>
	<dd>Preserve the original post meta after a successful migration. Default behavior is to clean up post meta.</dd>
</dl>


### Copying data from the orders table into post meta

If you require the post meta fields to be present (or are removing the custom orders table plugin), you may rollback the migration at any time with the `backfill` command.

```
$ wp wc orders-table backfill
```

This command does the opposite of `migrate`, looping through the orders table and saving each column into the corresponding post meta key. Be aware that this may dramatically increase the size of your post meta table!

#### Options

<dl>
	<dt>--batch-size=&lt;size&gt;</dt>
	<dd>The number of orders to process in each batch. Default is 100 orders per batch.</dd>
	<dd>Passing `--batch-size=0` will disable batching.</dd>
</dl>
