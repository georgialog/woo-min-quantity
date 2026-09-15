<?php

/**
 * Plugin Name: Minimum Quantity for WooCommerce
 * Plugin URI: https://geocreates.me/woo-minimum-quantity
 * Description: Enforce per-category and per-product minimum quantity rules in WooCommerce. Supports multiple category rules with different minimums running simultaneously.
 * Version: 2.2.0
 * Author: Georgia Log
 * Author URI: https://geocreates.me
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woo-minimum-quantity
 * Domain Path: /languages/
 *
 * @package WooCommerce_Minimum_Quantity
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooCommerce_Minimum_Quantity {
    private static $instance = null;

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        define('WOO_MIN_QTY_VERSION', '2.2.0');
        define('WOO_MIN_QTY_PLUGIN_DIR', plugin_dir_path(__FILE__));
        define('WOO_MIN_QTY_PLUGIN_URL', plugin_dir_url(__FILE__));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_filter('woocommerce_quantity_input_args', array($this, 'set_quantity_input_args'), 10, 2);
        add_action('add_meta_boxes', array($this, 'add_product_metabox'));
        add_action('save_post_product', array($this, 'save_product_metabox'));
        add_action('wp_ajax_woo_min_qty_get_product_min', array($this, 'ajax_get_product_min_qty'));
        add_action('wp_ajax_nopriv_woo_min_qty_get_product_min', array($this, 'ajax_get_product_min_qty'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 3);
        add_action('woocommerce_check_cart_items', array($this, 'validate_cart_items'));
    }

    // =========================================================================
    // Core resolution logic
    // =========================================================================

    /**
     * Get all saved category rules.
     *
     * @return array  Array of ['cat_id' => int, 'min_qty' => int, 'message' => string]
     */
    private function get_category_rules() {
        $rules = get_option('woo_min_qty_category_rules', array());
        return is_array($rules) ? $rules : array();
    }

    /**
     * Resolve the effective minimum for a product.
     *
     * Priority:
     *  1. Product-level override (if enabled on this product)
     *  2. Highest matching category rule across all product categories (incl. ancestors)
     *  3. null — no restriction
     *
     * @param  int        $product_id
     * @return array|null ['min_qty' => int, 'message' => string, 'source' => string] or null
     */
    public function get_min_for_product($product_id) {
        // 1. Product-level override
        if (get_post_meta($product_id, 'woo_min_qty_product_enabled', true) === '1') {
            $min_qty = max(1, intval(get_post_meta($product_id, 'woo_min_qty_product_min', true)));
            $message = get_post_meta($product_id, 'woo_min_qty_product_message', true);
            $message = $message
               ? $this->sanitize_notice_message(str_replace('{quantity}', $min_qty, $message), $min_qty)
                : $this->build_message($min_qty);
            return array('min_qty' => $min_qty, 'message' => $message, 'source' => 'product');
        }

        // 2. Category rules
        $rules = $this->get_category_rules();
        if (empty($rules)) {
            return null;
        }

        $product_cat_ids = wc_get_product_term_ids($product_id, 'product_cat');
        if (empty($product_cat_ids)) {
            return null;
        }

        // Expand to include ancestor categories for hierarchical matching
        $all_cat_ids = $product_cat_ids;
        foreach ($product_cat_ids as $cat_id) {
            $ancestors    = get_ancestors($cat_id, 'product_cat', 'taxonomy');
            $all_cat_ids  = array_merge($all_cat_ids, $ancestors);
        }
        $all_cat_ids = array_unique($all_cat_ids);

        // Use the highest (most restrictive) minimum from all matching rules
        $matched_min     = 0;
        $matched_message = '';
        foreach ($rules as $rule) {
            if (in_array(intval($rule['cat_id']), $all_cat_ids, true)) {
                $rule_min = intval($rule['min_qty']);
                if ($rule_min > $matched_min) {
                    $matched_min     = $rule_min;
                    $matched_message = isset($rule['message']) ? $rule['message'] : '';
                }
            }
        }

        if ($matched_min > 0) {
            $matched_message = $matched_message
               ? $this->sanitize_notice_message(str_replace('{quantity}', $matched_min, $matched_message), $matched_min)
                : $this->build_message($matched_min);
            return array('min_qty' => $matched_min, 'message' => $matched_message, 'source' => 'category');
        }

        return null; // No restriction
    }

    /**
     * Strip unsafe HTML from notices and ensure a plain-text message is returned.
     *
     * @param  string $message
     * @param  int    $min_qty
     * @return string
     */
    private function sanitize_notice_message($message, $min_qty) {
       $safe = trim(wp_strip_all_tags((string) $message));
       if ($safe === '') {
           return $this->build_message(max(1, intval($min_qty)));
       }
       return $safe;
    }

    /**
     * Build a message from the global template, substituting {quantity}.
     */
    private function build_message($min_qty) {
       $template = get_option('woo_min_qty_global_message', 'Minimum quantity of {quantity} items required.');
       $message = str_replace('{quantity}', (string) max(1, intval($min_qty)), $template);
       return $this->sanitize_notice_message($message, $min_qty);
    }

    // =========================================================================
    // Admin settings page
    // =========================================================================

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Minimum Quantity Settings',
            'Minimum Quantity',
            'manage_options',
            'woo-minimum-quantity',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings() {
        register_setting(
            'woo_min_qty_settings_group',
            'woo_min_qty_category_rules',
            array($this, 'sanitize_category_rules')
        );
        register_setting(
            'woo_min_qty_settings_group',
            'woo_min_qty_global_message',
            'sanitize_textarea_field'
        );
        register_setting(
            'woo_min_qty_settings_group',
            'woo_min_qty_stepper_mode',
            array($this, 'sanitize_stepper_mode')
        );
    }

    public function sanitize_category_rules($input) {
        $sanitized = array();
        if (!is_array($input)) {
            return $sanitized;
        }
        foreach ($input as $rule) {
            if (empty($rule['cat_id']) || empty($rule['min_qty'])) {
                continue;
            }
            $sanitized[] = array(
                'cat_id'  => intval($rule['cat_id']),
                'min_qty' => max(1, intval($rule['min_qty'])),
                'message' => isset($rule['message']) ? sanitize_textarea_field($rule['message']) : '',
            );
        }
        return $sanitized;
    }

    public function sanitize_stepper_mode($mode) {
        return in_array($mode, array('single', 'multiple'), true) ? $mode : 'single';
    }

    private function get_stepper_mode() {
        return get_option('woo_min_qty_stepper_mode', 'single');
    }

    private function get_step_size($min_qty) {
        return $this->get_stepper_mode() === 'multiple' ? max(1, intval($min_qty)) : 1;
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_woo-minimum-quantity') {
            return;
        }
        wp_enqueue_script(
            'woo-min-qty-admin',
            WOO_MIN_QTY_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WOO_MIN_QTY_VERSION,
            true
        );
        wp_enqueue_style(
            'woo-min-qty-admin-style',
            WOO_MIN_QTY_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WOO_MIN_QTY_VERSION
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $rules          = $this->get_category_rules();
        $global_message = get_option('woo_min_qty_global_message', 'Minimum quantity of {quantity} items required.');
        $stepper_mode   = $this->get_stepper_mode();

        $categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        if (is_wp_error($categories)) {
            $categories = array();
        }
        ?>
        <div class="wrap woo-min-qty-admin">
            <h1 class="wp-heading-inline">
                Minimum Quantity Rules
            </h1>
            <a href="https://geocreates.me" target="_blank" class="woo-min-qty-brand">by geo</a>
            <hr class="wp-header-end">

            <div class="notice notice-info inline" style="margin-top:16px">
                <p>
                    Define minimum order quantities per product category.
                    Products in a configured category must meet that minimum before they can be added to cart.<br>
                    <strong>Categories without a rule have no minimum restriction.</strong>
                    When a product belongs to multiple categories, the <em>highest</em> applicable minimum wins.
                </p>
            </div>

            <form method="post" action="options.php" id="woo-min-qty-form">
                <?php settings_fields('woo_min_qty_settings_group'); ?>

                <h2>Category Rules</h2>

                <table class="woo-min-qty-rules-table wp-list-table widefat fixed striped" id="woo-min-qty-rules">
                    <thead>
                        <tr>
                            <th class="col-category">Category</th>
                            <th class="col-qty">Min Qty</th>
                            <th class="col-message">Custom Message <small>(optional &mdash; use <code>{quantity}</code> as placeholder)</small></th>
                            <th class="col-actions"></th>
                        </tr>
                    </thead>
                    <tbody id="woo-min-qty-rules-body">
                        <?php if (empty($rules)): ?>
                            <tr class="woo-min-qty-no-rules">
                                <td colspan="4" class="no-rules-placeholder">
                                    No rules yet &mdash; click <strong>Add Rule</strong> to get started.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rules as $i => $rule): ?>
                                <?php $this->render_rule_row($i, $rule, $categories); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p class="woo-min-qty-add-wrap">
                    <button type="button" id="woo-min-qty-add-rule" class="button button-secondary">
                        <span class="dashicons dashicons-plus-alt2"></span> Add Rule
                    </button>
                </p>

                <hr>

                <h2>Stepper Behavior</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Quantity Step Mode</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="woo_min_qty_stepper_mode" value="single" <?php checked($stepper_mode, 'single'); ?> />
                                    Increase normally after MOQ (20, 21, 22, 23...)
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="woo_min_qty_stepper_mode" value="multiple" <?php checked($stepper_mode, 'multiple'); ?> />
                                    Increase by MOQ multiples (20, 40, 60, 80...)
                                </label>
                            </fieldset>
                            <p class="description">
                                Example MOQ above is 20. Applies to product pages with a minimum quantity rule. The minimum remains the floor in both modes.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Default Message Template</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="woo_min_qty_global_message">Fallback Message</label></th>
                        <td>
                            <textarea id="woo_min_qty_global_message" name="woo_min_qty_global_message"
                                      rows="3" cols="60"><?php echo esc_textarea($global_message); ?></textarea>
                            <p class="description">
                                Used when a rule doesn&rsquo;t have its own custom message.
                                Use <code>{quantity}</code> as a placeholder for the minimum number.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Rules'); ?>
            </form>

            <!-- Hidden template row cloned by JS when "Add Rule" is clicked -->
            <table id="woo-min-qty-row-template" style="display:none">
                <tbody>
                    <?php $this->render_rule_row('__INDEX__', array('cat_id' => '', 'min_qty' => '', 'message' => ''), $categories); ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function term_depth($term_id) {
        return count(get_ancestors($term_id, 'product_cat', 'taxonomy'));
    }

    private function render_rule_row($index, $rule, $categories) {
        $cat_id  = isset($rule['cat_id'])  ? intval($rule['cat_id'])  : '';
        $min_qty = isset($rule['min_qty']) ? intval($rule['min_qty']) : '';
        $message = isset($rule['message']) ? $rule['message']          : '';
        ?>
        <tr class="woo-min-qty-rule-row">
            <td class="col-category">
                <select name="woo_min_qty_category_rules[<?php echo esc_attr($index); ?>][cat_id]"
                        class="woo-min-qty-cat-select">
                    <option value="">— Select Category —</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php $pad = str_repeat('&nbsp;&nbsp;&nbsp;', $this->term_depth($cat->term_id)); ?>
                        <option value="<?php echo esc_attr($cat->term_id); ?>"
                            <?php selected($cat_id, $cat->term_id); ?>>
                            <?php echo $pad . esc_html($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="col-qty">
                <input type="number"
                       name="woo_min_qty_category_rules[<?php echo esc_attr($index); ?>][min_qty]"
                       value="<?php echo esc_attr($min_qty); ?>"
                       min="1"
                       placeholder="e.g. 5" />
            </td>
            <td class="col-message">
                <input type="text"
                       name="woo_min_qty_category_rules[<?php echo esc_attr($index); ?>][message]"
                       value="<?php echo esc_attr($message); ?>"
                       placeholder="Leave empty to use default message" />
            </td>
            <td class="col-actions">
                <button type="button" class="button-link woo-min-qty-remove-rule" title="Remove rule">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </td>
        </tr>
        <?php
    }

    // =========================================================================
    // Product metabox (per-product override)
    // =========================================================================

    public function add_product_metabox() {
        add_meta_box(
            'woo_min_qty_product',
            'Minimum Quantity',
            array($this, 'render_product_metabox'),
            'product',
            'side',
            'default'
        );
    }

    public function render_product_metabox($post) {
        wp_nonce_field('woo_min_qty_product_nonce_action', 'woo_min_qty_product_nonce');

        $product_min_qty = get_post_meta($post->ID, 'woo_min_qty_product_min', true);
        $product_enabled = get_post_meta($post->ID, 'woo_min_qty_product_enabled', true);
        $product_message = get_post_meta($post->ID, 'woo_min_qty_product_message', true);

        // Show active rule source (category rule preview, before any product override)
        $cat_resolved = null;
        if ($product_enabled !== '1') {
            $cat_resolved = $this->get_min_for_product($post->ID);
        }
        ?>
        <div class="woo-min-qty-metabox">

            <?php if ($product_enabled === '1'): ?>
                <div class="wmq-status wmq-status--product">
                    <span class="dashicons dashicons-edit"></span> Product override active
                </div>
            <?php elseif ($cat_resolved): ?>
                <div class="wmq-status wmq-status--category">
                    <span class="dashicons dashicons-category"></span>
                    Category rule: <strong><?php echo intval($cat_resolved['min_qty']); ?> items</strong>
                </div>
            <?php else: ?>
                <div class="wmq-status wmq-status--none">
                    <span class="dashicons dashicons-minus"></span> No minimum restriction
                </div>
            <?php endif; ?>

            <p>
                <label>
                    <input type="checkbox" name="woo_min_qty_product_enabled" value="1"
                           id="woo_min_qty_product_enabled"
                           <?php checked($product_enabled, '1'); ?> />
                    <strong>Set product-level override</strong>
                </label>
            </p>

            <div id="woo_min_qty_product_fields" style="<?php echo $product_enabled ? '' : 'display:none'; ?>">
                <p>
                    <label for="woo_min_qty_product_min"><strong>Minimum Qty:</strong></label>
                    <input type="number" id="woo_min_qty_product_min" name="woo_min_qty_product_min"
                           value="<?php echo esc_attr($product_min_qty ?: '1'); ?>"
                           min="1" style="width:65px" />
                </p>
                <p>
                    <label for="woo_min_qty_product_message"><strong>Custom Message:</strong></label><br>
                    <textarea id="woo_min_qty_product_message" name="woo_min_qty_product_message"
                              rows="2" style="width:100%"><?php echo esc_textarea($product_message); ?></textarea>
                    <span style="font-size:11px;color:#666">Leave empty for default. Use {quantity} as placeholder.</span>
                </p>
            </div>
        </div>

        <script>
        jQuery(function($){
            $('#woo_min_qty_product_enabled').on('change', function(){
                $('#woo_min_qty_product_fields').toggle(this.checked);
            });
        });
        </script>

        <style>
        .woo-min-qty-metabox p{margin-bottom:8px}
        .wmq-status{padding:7px 10px;border-radius:3px;font-size:12px;margin-bottom:12px;display:flex;align-items:center;gap:5px}
        .wmq-status .dashicons{font-size:14px;width:14px;height:14px}
        .wmq-status--product{background:#edf7ed;border-left:3px solid #00a32a;color:#1a6329}
        .wmq-status--category{background:#f0f6fc;border-left:3px solid #2271b1;color:#0a4b78}
        .wmq-status--none{background:#f5f5f5;border-left:3px solid #bbb;color:#666}
        </style>
        <?php
    }

    public function save_product_metabox($post_id) {
        if (!isset($_POST['woo_min_qty_product_nonce'])
            || !wp_verify_nonce($_POST['woo_min_qty_product_nonce'], 'woo_min_qty_product_nonce_action')
        ) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $enabled = isset($_POST['woo_min_qty_product_enabled']) ? '1' : '';
        update_post_meta($post_id, 'woo_min_qty_product_enabled', $enabled);
        update_post_meta($post_id, 'woo_min_qty_product_min',
            isset($_POST['woo_min_qty_product_min']) ? max(1, intval($_POST['woo_min_qty_product_min'])) : 1
        );
        update_post_meta($post_id, 'woo_min_qty_product_message',
            isset($_POST['woo_min_qty_product_message'])
                ? sanitize_textarea_field($_POST['woo_min_qty_product_message'])
                : ''
        );
    }

    // =========================================================================
    // Frontend scripts (product page)
    // =========================================================================

    public function enqueue_frontend_scripts() {
        if (!is_product()) {
            return;
        }
        wp_enqueue_script(
            'woo-min-qty-script',
            WOO_MIN_QTY_PLUGIN_URL . 'assets/js/min-quantity.js',
            array('jquery'),
            WOO_MIN_QTY_VERSION,
            true
        );
        wp_enqueue_style(
            'woo-min-qty-style',
            WOO_MIN_QTY_PLUGIN_URL . 'assets/css/min-quantity.css',
            array(),
            WOO_MIN_QTY_VERSION
        );
        wp_localize_script('woo-min-qty-script', 'wooMinQtyData', array(
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('woo_min_qty_nonce'),
            'productId'   => get_the_ID(),
            'stepperMode' => $this->get_stepper_mode(),
        ));
    }

    public function set_quantity_input_args($args, $product) {
        $resolved = $this->get_min_for_product($product->get_id());
        if ($resolved) {
            $min_qty = $resolved['min_qty'];
            $posted_qty = isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : null;

            $args['min_value'] = $min_qty;
            $args['step']      = $this->get_step_size($min_qty);

            if ($posted_qty !== null) {
                $args['input_value'] = max($posted_qty, $min_qty);
            } elseif (!isset($args['input_value']) || intval($args['input_value']) < $min_qty) {
                $args['input_value'] = $min_qty;
            }
        }
        return $args;
    }

    // =========================================================================
    // AJAX — product minimum lookup (public + logged-in)
    // =========================================================================

    public function ajax_get_product_min_qty() {
        check_ajax_referer('woo_min_qty_nonce', 'nonce');

        if (!isset($_POST['product_id'])) {
            wp_send_json_error('Product ID not provided');
        }

        $resolved = $this->get_min_for_product(intval($_POST['product_id']));

        if ($resolved) {
            wp_send_json_success(array(
                'min_qty' => $resolved['min_qty'],
                'message' => $resolved['message'],
                'source'  => $resolved['source'],
            ));
        } else {
            wp_send_json_success(array(
                'min_qty'  => 0,
                'message'  => '',
                'disabled' => true,
            ));
        }
    }

    // =========================================================================
    // Server-side cart / checkout validation
    // =========================================================================

    /**
     * Block add-to-cart if quantity is below the minimum.
     */
    public function validate_add_to_cart($passed, $product_id, $quantity) {
        $resolved = $this->get_min_for_product($product_id);
        if ($resolved && intval($quantity) < $resolved['min_qty']) {
            wc_add_notice($resolved['message'], 'error');
            return false;
        }
        return $passed;
    }

    /**
     * Re-validate all cart items (shown on cart page & before checkout).
     */
    public function validate_cart_items() {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity   = $cart_item['quantity'];
            $resolved   = $this->get_min_for_product($product_id);

            if ($resolved && intval($quantity) < $resolved['min_qty']) {
                $product = wc_get_product($product_id);
                $name    = $product ? $product->get_name() : "Product #{$product_id}";
                wc_add_notice(
                    sprintf('<strong>%s</strong>: %s', esc_html($name), esc_html($resolved['message'])),
                    'error'
                );
            }
        }
    }
}

WooCommerce_Minimum_Quantity::get_instance();
