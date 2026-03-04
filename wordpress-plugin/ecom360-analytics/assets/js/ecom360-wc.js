/**
 * Ecom360 Analytics — WooCommerce Integration (client-side)
 *
 * Hooks into WooCommerce AJAX events on the frontend so that cart
 * interactions on AJAX-driven themes (which don't trigger full page
 * reloads) are captured.
 *
 * Depends on: ecom360-tracker.js (window.ecom360)
 *
 * @version 1.0.0
 */
;
(function($) {
    'use strict';

    if (!window.ecom360) return;

    var track = window.ecom360.track;

    /* ═══════════════════════ Add to Cart (AJAX) ══════════════════════ */

    $(document.body).on('added_to_cart', function(e, fragments, cartHash, $btn) {
        var $form = $btn.closest('form.cart, .product');
        var prodId = $btn.data('product_id') || $form.find('[name=add-to-cart]').val() || '';
        var qty = $form.find('input.qty').val() || 1;

        // Try to get product name from page/card context
        var name = '';
        var $title = $form.closest('.product').find('.woocommerce-loop-product__title, .product_title');
        if ($title.length) name = $title.first().text().trim();

        // Try to get price
        var price = '';
        var $price = $form.closest('.product').find('.price ins .amount, .price > .amount');
        if ($price.length) price = $price.first().text().replace(/[^0-9.]/g, '');

        track('add_to_cart', {
            product_id: String(prodId),
            product_name: name,
            quantity: parseInt(qty, 10),
            price: parseFloat(price) || 0,
            source: 'ajax',
        });
    });

    /* ═══════════════════════ Remove from Cart (AJAX) ════════════════ */

    $(document.body).on('removed_from_cart', function(e, fragments, cartHash, $btn) {
        var href = $btn.attr('href') || '';
        // WC remove URL contains remove_item=<key> and sometimes product info in data attrs
        var productName = $btn.closest('tr, .cart_item').find('.product-name a').text().trim() || '';

        track('remove_from_cart', {
            product_name: productName,
            source: 'ajax',
        });
    });

    /* ═══════════════════════ Cart Updated ════════════════════════════ */

    $(document.body).on('updated_cart_totals', function() {
        var items = [];
        $('table.cart .cart_item, .woocommerce-cart-form .cart_item').each(function() {
            var $row = $(this);
            items.push({
                name: $row.find('.product-name a').text().trim(),
                qty: parseInt($row.find('input.qty').val(), 10) || 0,
            });
        });

        var totalText = $('.order-total .amount, .cart-subtotal .amount').first().text();
        var total = parseFloat(totalText.replace(/[^0-9.]/g, '')) || 0;

        track('cart_update', {
            item_count: items.length,
            total: total,
            items: items.slice(0, 20), // cap to avoid huge payloads
        });
    });

    /* ═══════════════════════ Checkout Step Tracking ══════════════════ */

    // Track when checkout form fields are interacted with (checkout steps)
    if ($('form.checkout').length) {
        var checkoutSteps = {};

        $('form.checkout').on('change', '#billing_email', function() {
            if (!checkoutSteps.email) {
                checkoutSteps.email = true;
                track('checkout_step', { step: 'email' });
            }
        });

        $('form.checkout').on('change', '#billing_address_1, #shipping_address_1', function() {
            if (!checkoutSteps.address) {
                checkoutSteps.address = true;
                track('checkout_step', { step: 'address' });
            }
        });

        $('form.checkout').on('change', 'input[name=payment_method]', function() {
            if (!checkoutSteps.payment) {
                checkoutSteps.payment = true;
                track('checkout_step', {
                    step: 'payment',
                    method: $(this).val(),
                });
            }
        });

        // Place order click
        $('form.checkout').on('checkout_place_order', function() {
            track('place_order', { source: 'checkout_form' });
        });
    }

    /* ═══════════════════════ Order Confirmation ══════════════════════ */

    // WooCommerce order-received page
    (function() {
        var $received = $('.woocommerce-order-received, .woocommerce-thankyou-order-received');
        if (!$received.length) return;

        // Try to extract order details from the page
        var order = {};
        var $overview = $('.woocommerce-order-overview');
        if ($overview.length) {
            $overview.find('li').each(function() {
                var $li = $(this);
                var text = $li.text().trim();
                if (text.match(/order.+number/i)) {
                    order.order_id = $li.find('strong').text().trim();
                } else if (text.match(/total/i)) {
                    order.total = $li.find('strong .amount, strong').text().replace(/[^0-9.]/g, '');
                } else if (text.match(/payment/i)) {
                    order.payment_method = $li.find('strong').text().trim();
                }
            });
        }

        track('purchase_confirmed', order);
    })();

    /* ═══════════════════════ Product List Clicks ═════════════════════ */

    // Track when users click on products from listing / category pages
    $(document).on('click', 'a.woocommerce-LoopProduct-link, .products .product a:first-child', function() {
        var $product = $(this).closest('.product');
        var name = $product.find('.woocommerce-loop-product__title').text().trim();
        var price = $product.find('.price ins .amount, .price > .amount').first().text().replace(/[^0-9.]/g, '');

        track('product_click', {
            product_name: name,
            price: parseFloat(price) || 0,
            list: document.title,
        });
    });

    /* ═══════════════════════ Wishlist ════════════════════════════════ */

    // YITH WooCommerce Wishlist and TI Wishlist support
    $(document).on('click', '.add_to_wishlist, .tinvwl_add_to_wishlist_button', function() {
        var $btn = $(this);
        var prodId = $btn.data('product-id') || $btn.data('product_id') || '';
        var $product = $btn.closest('.product');
        var name = $product.find('.product_title, .woocommerce-loop-product__title').first().text().trim();

        track('add_to_wishlist', {
            product_id: String(prodId),
            product_name: name,
        });
    });

    /* ═══════════════════════ Coupon ══════════════════════════════════ */

    $(document).on('click', '.checkout_coupon .button, .coupon .button', function() {
        var code = $(this).closest('form').find('input[name=coupon_code]').val();
        if (code) {
            track('apply_coupon', { code: code.trim() });
        }
    });

})(jQuery);