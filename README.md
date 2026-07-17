# WooCommerce Minimum Quantity Plugin

## Description

A flexible WordPress plugin for WooCommerce that enforces a minimum quantity requirement before customers can add items to their cart. Features both global and per-product minimum quantity settings, validation messages, disabled button states, and easy configuration.

## Features

**Global Minimum Quantity** - Set a default minimum for all products
**Per-Product Overrides** - Set custom minimums for individual products
**Disabled Button State** - Add to cart button is grayed out and disabled until minimum quantity is met
**Validation Messages** - Clear, customizable error messages (global or per-product)
**Default Quantity** - Automatically sets the quantity input to the minimum value
**Real-Time Validation** - Live updates as users change the quantity
**Flexible Fallback** - Products without custom settings use global defaults
**Responsive Design** - Works seamlessly on desktop and mobile devices

### Global Settings

Navigate to **WooCommerce > Minimum Quantity** in the WordPress admin panel to:

- **Enable/Disable** the minimum quantity requirement globally
- **Set Minimum Quantity** - Default minimum for all products
- **Customize Message** - Edit the validation message (use `{quantity}` as a placeholder)

### Per-Product Settings

1. Edit any WooCommerce product
2. Scroll down to the **"Minimum Quantity Settings"** metabox
3. Check **"Override Global Settings"** to enable product-specific settings
4. Set the **minimum quantity** for this specific product
5. (Optional) Add a custom message for this product
6. Save the product

### Priority

The plugin follows this priority:
1. **Product-specific settings** (if enabled for the product)
2. **Global settings** (used as fallback if product doesn't have custom settings)
3. **Disabled** (if the plugin is disabled globally)

### Example Configuration

**Global Settings:**
```
Enable Minimum Quantity: ✓ (checked)
Minimum Quantity: 5 items
Validation Message: Please order at least {quantity} items to proceed.
```

**Product A (Override):**
```
Override: ✓ (checked)
Minimum Quantity: 10 items
Custom Message: Bulk order minimum is {quantity} items.
```

**Product B:**
```
Override: ✗ (unchecked)
Uses global minimum: 5 items
Uses global message
```

## How It Works

1. **On Page Load**: The plugin fetches the product's minimum setting via AJAX
2. **Button Disabled State**: The add to cart button is disabled if quantity is below minimum
3. **Real-Time Updates**: As users adjust quantity, button enables/disables automatically
4. **Validation Message**: A clear error message appears when quantity is insufficient
5. **On Valid Entry**: The message disappears and the button becomes clickable

## Customization

### Disable for Specific Products

To disable the minimum quantity for specific products, add a filter in your theme's functions.php:

```php
add_filter('woo_min_qty_exclude_products', function($excluded_ids) {
    $excluded_ids[] = 123; // Product ID
    $excluded_ids[] = 456; // Another Product ID
    return $excluded_ids;
});
```

### Custom CSS

Customize the styling by adding CSS to your theme:

```css
button.woo-min-qty-disabled {
    background-color: #e0e0e0 !important;
    opacity: 0.6 !important;
}

.woo-min-qty-notice .woocommerce-notice--error {
    background-color: #f5e6e8;
    border-left-color: #d32f2f;
    color: #d32f2f;
}
```

## Styling Classes

- `.woo-min-qty-disabled` - Applied to disabled add to cart button
- `.woo-min-qty-notice` - Container for validation messages
- `.woocommerce-notice--error` - Error message styling

## Browser Compatibility

- Chrome, Firefox, Safari, Edge (all modern versions)
- Works with quantity spinners (+ and - buttons)
- Compatible with all WooCommerce product types

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.2+

## Frequently Asked Questions

**Q: Can I set different minimum quantities for different products?**
A: Yes! This is the main feature. Edit any product and enable "Override Global Settings" in the Minimum Quantity Settings metabox to set a custom minimum for that specific product.

**Q: Does this work with variable products?**
A: Yes, the validation applies to all product types supported by WooCommerce.

**Q: Can I disable the feature temporarily?**
A: Yes, simply uncheck "Enable Minimum Quantity" on the global settings page. Individual product overrides will also respect this global setting.

**Q: What happens if I change the minimum quantity?**
A: Changes take effect immediately on all product pages.

**Q: Can a product use the global minimum without overriding?**
A: Yes. Leave "Override Global Settings" unchecked on the product to use the global minimum quantity and message.

**Q: Can I disable the minimum for specific products?**
A: Not through the UI, but you can leave "Override Global Settings" unchecked on those products, then disable the global setting. Or enable the override and set minimum to 1.

## Support

For issues or feature requests, please contact your support team.

## License

This plugin is licensed under the GPL-3.0 License. See the LICENSE file for more information.

## Changelog

### Version 1.0.0
- Initial release
- Minimum quantity enforcement
- Admin settings page
- Real-time validation
- Disabled button state
- Customizable messages
