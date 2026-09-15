jQuery(document).ready(function ($) {
    let currentMinQty = 0;
    let currentMessage = '';
    let currentStep = 1;

    function fetchProductMinQty() {
        $.ajax({
            url: wooMinQtyData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_min_qty_get_product_min',
                nonce: wooMinQtyData.nonce,
                product_id: wooMinQtyData.productId,
            },
            success: function (response) {
                if (!response.success) return;

                const data = response.data;
                if (data.disabled || data.min_qty <= 0) return;

                currentMinQty = data.min_qty;
                currentMessage = data.message;
                currentStep = wooMinQtyData.stepperMode === 'multiple' ? currentMinQty : 1;

                updateQuantityInput();
                updateButtonState();
            },
        });
    }

    function updateQuantityInput() {
        const $qty = $('input.qty, input[name="quantity"]');
        if (!$qty.length || currentMinQty <= 0) return;

        $qty.attr('min', currentMinQty);
        $qty.attr('step', currentStep);

        const currentValue = parseInt($qty.val(), 10);
        if (Number.isNaN(currentValue) || currentValue < currentMinQty) {
            $qty.val(currentMinQty);
        }
    }

    function updateButtonState() {
        if (currentMinQty <= 0) return;

        const $btn = $('button[name="add-to-cart"], button.single_add_to_cart_button');
        const qty  = Math.max(0, parseInt($('input.qty, input[name="quantity"]').val(), 10) || 0);

        if (qty < currentMinQty) {
            $btn.prop('disabled', true).addClass('woo-min-qty-disabled');
            showValidationMessage(currentMessage);
        } else {
            $btn.prop('disabled', false).removeClass('woo-min-qty-disabled');
            hideValidationMessage();
        }
    }

    function showValidationMessage(msg) {
        let $notice = $('.woo-min-qty-notice');
        const $form = $('input.qty, input[name="quantity"]').closest('form.cart, .product-quantity-container');

        if ($notice.length === 0) {
            $notice = $('<div class="woo-min-qty-notice woocommerce-notices-wrapper"></div>');
            $notice.insertBefore($form);
        }

        const $message = $('<div class="woocommerce-notice woocommerce-notice--error" role="alert"></div>').text(msg || '');
        $notice.empty().append($message);
    }

    function hideValidationMessage() {
        $('.woo-min-qty-notice').remove();
    }

    fetchProductMinQty();

    $(document).on('change keyup', 'input.qty, input[name="quantity"]', updateButtonState);
    $(document).on('click', '.plus, .minus', function () {
        setTimeout(updateButtonState, 100);
    });
});
