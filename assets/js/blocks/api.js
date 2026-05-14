/**
 * Orangepill Blocks API helpers (PR-WC-BLOCKS-COMPATIBILITY-V1 / PR-WC-BLOCKS-WALLET-V1)
 *
 * Thin wrappers over WP AJAX endpoints.
 * All payment actions use the shared 'orangepill_wc_checkout' nonce.
 * Wallet apply uses its own 'orangepill_apply_wallet' nonce (config.wallet.applyNonce).
 */

const config = window.orangepillBlocksConfig || {};

const POLL_INTERVAL_MS = 4000;
const POLL_TIMEOUT_MS  = 10 * 60 * 1000;

async function ajaxPost( action, data ) {
    const formData = new FormData();
    formData.append( 'action', action );
    formData.append( 'nonce', config.nonce );
    Object.keys( data ).forEach( key => formData.append( key, data[ key ] ) );

    const response = await fetch( config.ajaxUrl, {
        method:      'POST',
        body:        formData,
        credentials: 'same-origin',
    } );

    if ( ! response.ok ) {
        throw new Error( 'Network error ' + response.status );
    }

    const result = await response.json();
    if ( ! result.success ) {
        throw new Error( ( result.data && result.data.message ) || 'Request failed' );
    }
    return result.data;
}

/**
 * Fetch eligible payment options.
 * Calls: orangepill_get_payment_options (ajax_get_payment_options)
 */
export function getPaymentOptions( currency, amount, country ) {
    return ajaxPost( 'orangepill_get_payment_options', { currency, amount, country } );
}

/**
 * Create a payment intent for the selected method.
 * Calls: orangepill_create_intent (ajax_create_intent)
 */
export function createIntent( methodKey, currency, amount ) {
    return ajaxPost( 'orangepill_create_intent', {
        method_key: methodKey,
        currency,
        amount,
    } );
}

/**
 * Execute a payment intent. Server registers async callback on every call —
 * the webhook safety net fires regardless of browser state after this point.
 * Calls: orangepill_execute_intent (ajax_execute_intent)
 */
export function executeIntent( intentId, methodKey, channel ) {
    const data = { intent_id: intentId, method_key: methodKey };
    if ( channel ) data.channel = channel;
    return ajaxPost( 'orangepill_execute_intent', data );
}

/**
 * Apply wallet balance to a new checkout session.
 * Calls: orangepill_apply_wallet (ajax_apply_wallet) — 6th canonical endpoint.
 * Uses config.wallet.applyNonce (separate from main checkout nonce).
 *
 * Server creates a temporary checkout session, applies the wallet, and returns
 * { sessionId, appliedAmount, remainingPayable }. The caller stores this in state
 * so onPaymentSetup can route zero-payable orders or create an intent for the remainder.
 *
 * @param {number} amount
 * @param {string} walletId
 * @param {string} cartTotal  Cart total in major currency units
 * @param {AbortSignal} [signal]
 */
export async function applyWallet( amount, walletId, cartTotal, signal ) {
    const wallet   = config.wallet || {};
    const formData = new FormData();
    formData.append( 'action',     'orangepill_apply_wallet' );
    formData.append( 'nonce',      wallet.applyNonce || '' );
    formData.append( 'amount',     amount );
    formData.append( 'wallet_id',  walletId || '' );
    formData.append( 'cart_total', cartTotal );
    formData.append( 'currency',   config.currency );

    const fetchOptions = { method: 'POST', body: formData, credentials: 'same-origin' };
    if ( signal ) fetchOptions.signal = signal;

    const response = await fetch( config.ajaxUrl, fetchOptions );
    if ( ! response.ok ) throw new Error( 'Network error ' + response.status );

    const result = await response.json();
    if ( ! result.success ) {
        throw new Error( ( result.data && result.data.message ) || 'Wallet apply failed' );
    }
    return result.data; // { sessionId, appliedAmount, remainingPayable }
}

/**
 * Poll GET /v4/payments/{id}/status until terminal state or timeout.
 * Mirrors native-payment-shell.js startPolling() — 4s interval, 10 min max.
 * Calls: orangepill_get_payment_status (ajax_get_payment_status)
 *
 * @param {string} paymentId
 * @param {AbortSignal} [signal] - optional AbortSignal to cancel on unmount
 * @returns {Promise<{status: 'succeeded'|'failed'|'timeout'}>}
 */
export async function pollPaymentStatus( paymentId, signal ) {
    const startTime = Date.now();

    while ( Date.now() - startTime < POLL_TIMEOUT_MS ) {
        if ( signal && signal.aborted ) {
            return { status: 'aborted' };
        }

        await new Promise( resolve => setTimeout( resolve, POLL_INTERVAL_MS ) );

        if ( signal && signal.aborted ) {
            return { status: 'aborted' };
        }

        const result = await ajaxPost( 'orangepill_get_payment_status', { payment_id: paymentId } );
        const { status } = result;

        if ( status === 'succeeded' || status === 'completed' ) return { status: 'succeeded' };
        if ( status === 'failed' || status === 'cancelled' || status === 'expired' ) return { status: 'failed' };
        // pending / processing → keep polling
    }

    return { status: 'timeout' };
}
