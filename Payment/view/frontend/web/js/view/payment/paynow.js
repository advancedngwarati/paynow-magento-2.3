define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'paynow',
                component: 'Paynow_Payment/js/view/payment/method-renderer/paynow-method'
            }
        );
        return Component.extend({});
    }
);