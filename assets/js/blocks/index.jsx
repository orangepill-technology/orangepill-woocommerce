/**
 * Orangepill WooCommerce Blocks — payment method registration entry point.
 * (PR-WC-BLOCKS-COMPATIBILITY-V1)
 *
 * Registers the Orangepill payment method with the WC Blocks payment registry
 * so it appears on the Cart and Checkout Blocks (WC 8.0+).
 *
 * paymentMethodData returned by OrangepillContent (via onPaymentSetup) is:
 *   { _orangepill_intent_id: string, _orangepill_execution_type: string }
 *
 * WC Blocks passes these to process_payment() via $_POST — zero PHP changes needed.
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { OrangepillContent }     from './content';
import { OrangepillEdit }        from './edit';

const config = window.orangepillBlocksConfig || {};

/**
 * Guard: gateway is available when the server confirmed it via is_active().
 * A missing config means the script loaded on a page where the gateway is disabled.
 */
const canMakePayment = () => !! ( config.title && config.ajaxUrl && config.nonce );

registerPaymentMethod( {
    name:          'orangepill',
    label:         config.title || 'Orangepill',
    ariaLabel:     config.title || 'Orangepill',
    canMakePayment,
    content:       <OrangepillContent />,
    edit:          <OrangepillEdit />,
    paymentMethodId: 'orangepill',
    supports: {
        features:       [ 'products', 'refunds' ],
        showSavedCards: false,
        showSaveOption: false,
    },
} );
