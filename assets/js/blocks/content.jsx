/**
 * OrangepillContent — Blocks payment method content component.
 * (PR-WC-BLOCKS-COMPATIBILITY-V1 / PR-WC-BLOCKS-WALLET-V1)
 *
 * Implements the same execution-type dispatch as native-payment-shell.js but
 * as a React component for WC Blocks checkout. WCBLOCKS-006 invariant: all 4
 * execution types are handled (redirect / processing / completed /
 * payment_request_required).
 *
 * Flow (mirrors native-payment-shell.js):
 *   1. Mount  → load payment options via AJAX
 *   2. Submit → onPaymentSetup fires:
 *       W. [wallet] if walletApplied.remainingPayable === 0:
 *           → pass _orangepill_wallet_only=true; PHP completes locally, no provider
 *       a. createIntent for remaining amount (cartTotal − wallet applied, or full cartTotal)
 *       b. executeIntent → POST /v4/payment-intents/{id}/execute
 *          (server registers async callback = webhook safety net)
 *       c. Dispatch on execution_type:
 *          redirect   → pass intent_id to process_payment(), PHP fetches redirect from transient
 *          processing → pass intent_id, order goes on-hold, webhook completes
 *          completed  → pass intent_id, process_payment() verifies via API
 *          payment_request_required
 *                     → render QR/key inline, poll until succeeded, then pass as completed
 *
 * Server authority: process_payment() reads $_POST fields set by WC Blocks from
 * paymentMethodData — identical to classic shortcode checkout. PHP is unchanged
 * for non-wallet flows; wallet-only path is a minimal new branch in process_payment().
 *
 * Wallet: PR-WC-BLOCKS-WALLET-V1. Option A (display-only cart totals).
 * Partial apply: intent created for remainingPayable. Zero-payable: no intent, wallet-only path.
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { PaymentMethodSelector } from './components/PaymentMethodSelector';
import { QRCodeDisplay }         from './components/QRCodeDisplay';
import { WalletSection }         from './components/WalletSection';
import { SubForm }               from './components/SubForm';
import { getPaymentOptions, createIntent, executeIntent, pollPaymentStatus } from './api';
import { getActiveConversationId } from './conversation-helper';

const config = window.orangepillBlocksConfig || {};
const i18n   = config.i18n || {};

// Methods that require extra sub-form data before intent creation.
const SUBFORM_METHODS = new Set( [ 'wallet.nequi', 'wallet.daviplata', 'card' ] );

/**
 * Tokenize card data via Wompi (SAQ-A: PAN never touches our server).
 * Returns the token ID string (e.g. "tok_test_...").
 */
async function tokenizeCard( cardData ) {
    const wompiUrl = config.wompiTokenizeUrl;
    const pubKey   = config.wompiPublicKey;
    if ( ! wompiUrl || ! pubKey ) {
        throw new Error( i18n.err_card_tokenize || 'Card payment not configured.' );
    }

    const digits    = ( cardData.card_number || '' ).replace( /\D/g, '' );
    const expiryRaw = ( cardData.card_expiry || '' ).replace( /\s/g, '' );
    const parts     = expiryRaw.split( '/' );
    const expMonth  = ( parts[ 0 ] || '' ).padStart( 2, '0' );
    const expYear   = ( parts[ 1 ] || '' );

    const resp = await fetch( wompiUrl, {
        method:  'POST',
        headers: {
            'Content-Type':  'application/json',
            'Authorization': 'Bearer ' + pubKey,
        },
        body: JSON.stringify( {
            number:      digits,
            exp_month:   expMonth,
            exp_year:    expYear,
            cvc:         cardData.card_cvc || '',
            card_holder: cardData.card_holder || '',
        } ),
    } );

    const json = await resp.json();
    const tokenId = json?.data?.id;
    if ( ! resp.ok || ! tokenId ) {
        throw new Error( i18n.err_card_tokenize || 'No se pudo procesar la tarjeta.' );
    }
    return tokenId;
}

/**
 * Validate sub-form data for the given method.
 * Returns null on success, or an error message string on failure.
 */
function validateSubForm( methodKey, formData ) {
    if ( methodKey === 'wallet.nequi' || methodKey === 'wallet.daviplata' ) {
        const phone = ( formData.phone_number || '' ).replace( /\D/g, '' );
        if ( ! phone ) return i18n.err_phone_required || 'Ingresa tu número de celular.';
        if ( phone.length !== 10 ) return i18n.err_phone_invalid || 'El número de celular debe tener 10 dígitos.';
    }

    if ( methodKey === 'wallet.daviplata' ) {
        if ( ! formData.user_legal_id_type ) return i18n.err_id_type_required || 'Selecciona el tipo de documento.';
        if ( ! ( formData.user_legal_id || '' ).trim() ) return i18n.err_id_number_required || 'Ingresa tu número de documento.';
    }

    if ( methodKey === 'card' ) {
        const digits = ( formData.card_number || '' ).replace( /\D/g, '' );
        if ( digits.length < 13 ) return i18n.err_card_number || 'Ingresa el número de tarjeta completo.';
        const expiryDigits = ( formData.card_expiry || '' ).replace( /\D/g, '' );
        if ( expiryDigits.length < 4 ) return i18n.err_card_expiry || 'Ingresa la fecha de vencimiento (MM/AA).';
        if ( ! ( formData.card_holder || '' ).trim() ) return i18n.err_card_holder || 'Ingresa el nombre del titular.';
        if ( ! ( formData.card_cvc || '' ).trim() ) return i18n.err_card_cvc || 'Ingresa el código CVC.';
    }

    return null;
}

export function OrangepillContent( { eventRegistration, emitResponse, billing } ) {
    // onPaymentSetup is the WC Blocks 8.0+ event (replaced onPaymentProcessing in WC 7.9).
    // Fall back to onPaymentProcessing for any edge case running an older version in range.
    const onPaymentEvent = eventRegistration.onPaymentSetup
        ?? eventRegistration.onPaymentProcessing;

    const [ options,        setOptions        ] = useState( null );
    const [ loading,        setLoading        ] = useState( true );
    const [ loadError,      setLoadError      ] = useState( null );
    const [ selectedMethod,  setSelectedMethod  ] = useState( null );
    const [ selectedChannel, setSelectedChannel ] = useState( null );
    const [ execState,      setExecState      ] = useState( 'idle' ); // idle | awaiting_qr | confirmed
    const [ paymentRequest, setPaymentRequest  ] = useState( null );
    // walletApplied: null | { sessionId, appliedAmount, remainingPayable }
    const [ walletApplied,  setWalletApplied  ] = useState( null );
    // subFormData: phone/ID/card fields — collected before intent creation
    const [ subFormData,    setSubFormData    ] = useState( {} );

    // Refs keep the onPaymentSetup closure current without re-registering it on every method change.
    const selectedMethodRef  = useRef( selectedMethod );
    const selectedChannelRef = useRef( selectedChannel );
    const walletAppliedRef   = useRef( walletApplied );
    const subFormDataRef     = useRef( subFormData );
    useEffect( () => { selectedMethodRef.current  = selectedMethod;  }, [ selectedMethod  ] );
    useEffect( () => { selectedChannelRef.current = selectedChannel; }, [ selectedChannel ] );
    useEffect( () => { walletAppliedRef.current   = walletApplied;   }, [ walletApplied   ] );
    useEffect( () => { subFormDataRef.current     = subFormData;     }, [ subFormData     ] );

    // Cart total — convert from WC Blocks minor units to major units used by the payment API.
    // e.g. COP: 438211 / 10^0 = 438211; USD: 438211 / 10^2 = 4382.11
    const minorUnit  = billing?.cartTotals?.currency_minor_unit ?? 2;
    const rawTotal   = parseInt( billing?.cartTotals?.total_price ?? '0', 10 );
    const cartTotal  = ( rawTotal / Math.pow( 10, minorUnit ) ).toString();
    const currency   = config.currency;
    const country    = config.country;

    // Load payment options when cart total changes (coupon applied, etc.)
    useEffect( () => {
        let cancelled = false;
        setLoading( true );
        setLoadError( null );

        getPaymentOptions( currency, cartTotal, country )
            .then( data => {
                if ( cancelled ) return;
                const eligible = ( data.options || [] ).filter( o => o.eligible );
                setOptions( eligible );
                setLoading( false );
                if ( eligible.length > 0 ) {
                    const first = eligible[ 0 ];
                    const ch    = ( first.channels || [] )[ 0 ] ?? null;
                    setSelectedMethod( first.methodKey );
                    setSelectedChannel( ch );
                }
            } )
            .catch( () => {
                if ( cancelled ) return;
                setLoadError( i18n.options_error || 'Unable to load payment options.' );
                setLoading( false );
            } );

        return () => { cancelled = true; };
    }, [ currency, cartTotal, country ] );

    // Register the payment setup callback.
    // Registered only once (onPaymentEvent is stable from WC Blocks).
    // Reads method/channel from refs so stale closures never mis-fire.
    useEffect( () => {
        if ( ! onPaymentEvent ) return;

        const unsubscribe = onPaymentEvent( async () => {
            const methodKey  = selectedMethodRef.current;
            const channel    = selectedChannelRef.current;
            const wa         = walletAppliedRef.current;

            // PR-WC-WEBCHAT-CONVERSATION-LINKING-V1: capture at checkout-submit time (RULE 4).
            // Synchronous — never awaited. Returns null when widget not loaded or no conversation.
            const conversationId = getActiveConversationId();

            // ── Path W: wallet-only (zero-payable) ───────────────────────────
            // Wallet covers 100% of order total — skip provider entirely.
            // PHP process_payment() verifies via transient and completes locally.
            if ( wa && wa.remainingPayable === 0 ) {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: { paymentMethodData: {
                        _orangepill_wallet_only:          'true',
                        _orangepill_wallet_session_id:     wa.sessionId,
                        _orangepill_wallet_applied_amount: String( wa.appliedAmount ),
                        _orangepill_conversation_id:       conversationId || '',
                    } },
                };
            }

            if ( ! methodKey ) {
                return {
                    type:    emitResponse.responseTypes.ERROR,
                    message: i18n.select_method || 'Please select a payment method.',
                };
            }

            // ── Sub-form validation & card tokenization ───────────────────────
            let extraData = {};
            if ( SUBFORM_METHODS.has( methodKey ) ) {
                const fd  = subFormDataRef.current;
                const err = validateSubForm( methodKey, fd );
                if ( err ) {
                    return { type: emitResponse.responseTypes.ERROR, message: err };
                }

                if ( methodKey === 'wallet.nequi' ) {
                    extraData = { phone_number: fd.phone_number.replace( /\D/g, '' ) };
                } else if ( methodKey === 'wallet.daviplata' ) {
                    extraData = {
                        phone_number:        fd.phone_number.replace( /\D/g, '' ),
                        user_legal_id_type:  fd.user_legal_id_type,
                        user_legal_id:       fd.user_legal_id.trim(),
                    };
                } else if ( methodKey === 'card' ) {
                    try {
                        const tokenId = await tokenizeCard( fd );
                        extraData = { payment_method_id: tokenId };
                    } catch ( tokenErr ) {
                        return {
                            type:    emitResponse.responseTypes.ERROR,
                            message: tokenErr.message || ( i18n.err_card_tokenize || 'Card error.' ),
                        };
                    }
                }
            }

            // AbortController so polling stops cleanly if the component unmounts
            // mid-poll (e.g. user navigates away).
            const ac = new AbortController();

            // Partial wallet apply: create intent for remaining amount only.
            // Full amount if no wallet was applied.
            const intentAmount = wa ? String( wa.remainingPayable ) : cartTotal;

            try {
                // Step 1: create intent (for remaining payable, or full total if no wallet)
                const intentResult = await createIntent( methodKey, currency, intentAmount, extraData );
                const intentId     = intentResult.intentId;
                if ( ! intentId ) throw new Error( 'No intent ID returned' );

                // Step 2: execute intent (server registers payment.succeeded/failed callback here)
                const execResult = await executeIntent( intentId, methodKey, channel );
                const execType   = execResult.execution_type;

                // Step 3: dispatch on execution type
                // Mirrors the switch block in native-payment-shell.js executeIntent().
                // WCBLOCKS-006 invariant: all 4 types handled.
                switch ( execType ) {

                    case 'redirect':
                        // Redirect URL stored server-side in transient by ajax_execute_intent().
                        // process_payment() (Path A) fetches it from the transient — never trusts
                        // browser-supplied URL. WCBLOCKS-011 invariant satisfied.
                        return {
                            type: emitResponse.responseTypes.SUCCESS,
                            meta: { paymentMethodData: {
                                _orangepill_intent_id:       intentId,
                                _orangepill_execution_type:  'redirect',
                                _orangepill_conversation_id: conversationId || '',
                                ...( wa && {
                                    _orangepill_wallet_session_id:     wa.sessionId,
                                    _orangepill_wallet_applied_amount: String( wa.appliedAmount ),
                                } ),
                            } },
                        };

                    case 'processing':
                        // Async payment (e.g. card awaiting 3DS / bank-push approval).
                        // Order goes on-hold; webhook (registered above on execute) completes it
                        // regardless of whether the browser stays open.
                        return {
                            type: emitResponse.responseTypes.SUCCESS,
                            meta: { paymentMethodData: {
                                _orangepill_intent_id:       intentId,
                                _orangepill_execution_type:  'processing',
                                _orangepill_conversation_id: conversationId || '',
                                ...( wa && {
                                    _orangepill_wallet_session_id:     wa.sessionId,
                                    _orangepill_wallet_applied_amount: String( wa.appliedAmount ),
                                } ),
                            } },
                        };

                    case 'completed':
                        // Synchronous success — process_payment() verifies via API and calls
                        // payment_complete().
                        return {
                            type: emitResponse.responseTypes.SUCCESS,
                            meta: { paymentMethodData: {
                                _orangepill_intent_id:       intentId,
                                _orangepill_execution_type:  'completed',
                                _orangepill_conversation_id: conversationId || '',
                                ...( wa && {
                                    _orangepill_wallet_session_id:     wa.sessionId,
                                    _orangepill_wallet_applied_amount: String( wa.appliedAmount ),
                                } ),
                            } },
                        };

                    case 'payment_request_required': {
                        // QR / dynamic-key flow:
                        //   1. Render QR inline (component re-renders with paymentRequest state)
                        //   2. Poll GET /v4/payments/{id}/status (4s interval, 10 min timeout)
                        //   3. On success → submit as 'completed'; process_payment() verifies
                        // The webhook callback registered in step 2 is the async safety net if
                        // the tab closes while the user is scanning.
                        const pr        = execResult.payment_request;
                        const paymentId = pr?.payment_id || intentId;

                        setPaymentRequest( pr );
                        setExecState( 'awaiting_qr' );

                        const pollResult = await pollPaymentStatus( paymentId, ac.signal );

                        if ( pollResult.status === 'succeeded' ) {
                            setExecState( 'confirmed' );
                            return {
                                type: emitResponse.responseTypes.SUCCESS,
                                meta: { paymentMethodData: {
                                    _orangepill_intent_id:       intentId,
                                    _orangepill_execution_type:  'completed',
                                    _orangepill_conversation_id: conversationId || '',
                                    ...( wa && {
                                        _orangepill_wallet_session_id:     wa.sessionId,
                                        _orangepill_wallet_applied_amount: String( wa.appliedAmount ),
                                    } ),
                                } },
                            };
                        }

                        setExecState( 'idle' );
                        const errMsg = pollResult.status === 'timeout'
                            ? ( i18n.payment_timeout || 'Payment timed out. Please try again.' )
                            : ( i18n.payment_failed  || 'Payment was not completed. Please try again.' );
                        return {
                            type:    emitResponse.responseTypes.ERROR,
                            message: errMsg,
                        };
                    }

                    default:
                        return {
                            type:    emitResponse.responseTypes.ERROR,
                            message: i18n.payment_error || 'Unsupported payment method type.',
                        };
                }

            } catch ( err ) {
                setExecState( 'idle' );
                return {
                    type:    emitResponse.responseTypes.ERROR,
                    message: err.message || ( i18n.payment_error || 'Payment failed. Please try again.' ),
                };
            } finally {
                ac.abort();
            }
        } );

        return unsubscribe;
    }, [ onPaymentEvent, emitResponse.responseTypes ] );

    // ── Render ────────────────────────────────────────────────────────────────

    if ( loading ) {
        return (
            <div className="op-blocks-shell" data-state="loading">
                <div className="op-native-loading" aria-live="polite">
                    { i18n.loading_options || 'Loading payment options...' }
                </div>
            </div>
        );
    }

    if ( loadError ) {
        return (
            <div className="op-blocks-shell" data-state="error">
                { config.description && <p>{ decodeEntities( config.description ) }</p> }
                <div className="op-native-error" role="alert">{ loadError }</div>
            </div>
        );
    }

    if ( ! options || ! options.length ) {
        return (
            <div className="op-blocks-shell" data-state="empty">
                { config.description && <p>{ decodeEntities( config.description ) }</p> }
                <p className="op-no-methods">{ i18n.no_methods || 'No payment methods available.' }</p>
            </div>
        );
    }

    return (
        <div className="op-blocks-shell" data-state={ execState }>
            { config.description && <p>{ decodeEntities( config.description ) }</p> }

            <PaymentMethodSelector
                methods={ options }
                selected={ selectedMethod }
                selectedChannel={ selectedChannel }
                onChange={ ( key, ch ) => {
                    setSelectedMethod( key );
                    setSelectedChannel( ch );
                    setPaymentRequest( null );
                    setExecState( 'idle' );
                    setSubFormData( {} );
                } }
            />

            { SUBFORM_METHODS.has( selectedMethod ) && (
                <SubForm
                    methodKey={ selectedMethod }
                    formData={ subFormData }
                    onChange={ setSubFormData }
                />
            ) }

            { /* PR-WC-BLOCKS-WALLET-V1: Wallet apply UI (logged-in only).
                 Option A (display-only cart totals) — WC cart store unchanged.
                 Zero-payable: wallet-only path skips provider entirely.
                 Partial apply: intent created for remainingPayable in onPaymentSetup. */ }
            { config.isLoggedIn && config.wallet?.enabled && (
                <WalletSection
                    walletConfig={ config.wallet }
                    cartTotal={ cartTotal }
                    onApplied={ setWalletApplied }
                    walletApplied={ walletApplied }
                />
            ) }

            { execState === 'awaiting_qr' && paymentRequest && (
                <QRCodeDisplay
                    paymentRequest={ paymentRequest }
                    onExpired={ () => setExecState( 'idle' ) }
                />
            ) }

            { execState === 'confirmed' && (
                <div className="op-pr-success" role="status" aria-live="polite">
                    <span className="op-pr-success-icon" aria-hidden="true">✓</span>
                    { ' ' }{ i18n.payment_confirmed || '¡Pago confirmado!' }
                </div>
            ) }
        </div>
    );
}
