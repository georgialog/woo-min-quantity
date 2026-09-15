<?php

/**
 * Uninstall handler for WooCommerce Minimum Quantity plugin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete all plugin options
delete_option('woo_min_qty_category_rules');
delete_option('woo_min_qty_global_message');
delete_option('woo_min_qty_stepper_mode');

// Legacy options (v1.x)
delete_option('woo_min_qty_enabled');
delete_option('woo_min_qty_value');
delete_option('woo_min_qty_message');
