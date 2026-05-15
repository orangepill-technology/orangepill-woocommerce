/**
 * QRCodeDisplay — renders the payment_request_required inline UI.
 *
 * Shows the QR image (dynamic_qr mode) or copyable dynamic key (reference mode),
 * a countdown to expiry, and a "waiting" spinner. Mirrors the renderPaymentRequest()
 * logic from native-payment-shell.js.
 *
 * The parent component (OrangepillContent) holds this visible while the
 * onPaymentSetup Promise is polling — z-index ensures it stays above
 * WC Blocks' processing overlay.
 */

import { useState, useEffect } from '@wordpress/element';

const config = window.orangepillBlocksConfig || {};
const i18n   = config.i18n || {};

export function QRCodeDisplay( { paymentRequest, onExpired } ) {
    const [ countdown, setCountdown ] = useState( '' );
    const [ copied,    setCopied    ] = useState( false );

    const rendering = paymentRequest?.rendering || {};
    const expiresAt = paymentRequest?.expires_at || null;
    const mode      = paymentRequest?.mode || 'dynamic_key';

    // Expiry countdown — mirrors startExpiryCountdown() in native-payment-shell.js
    useEffect( () => {
        if ( ! expiresAt ) return;
        const expiry = new Date( expiresAt ).getTime();

        const tick = () => {
            const remaining = Math.max( 0, expiry - Date.now() );
            const mins      = Math.floor( remaining / 60000 );
            const secs      = Math.floor( ( remaining % 60000 ) / 1000 );
            setCountdown(
                ( mins < 10 ? '0' : '' ) + mins + ':' + ( secs < 10 ? '0' : '' ) + secs
            );
            if ( remaining <= 0 ) {
                clearInterval( timer );
                if ( onExpired ) onExpired();
            }
        };

        tick();
        const timer = setInterval( tick, 1000 );
        return () => clearInterval( timer );
    }, [ expiresAt, onExpired ] );

    const keyValue = rendering.key_text
        || rendering.display_text
        || rendering.key_alias
        || rendering.instrument_id
        || '';

    function handleCopy() {
        if ( navigator.clipboard ) {
            navigator.clipboard.writeText( String( keyValue ) ).then( () => {
                setCopied( true );
                setTimeout( () => setCopied( false ), 2000 );
            } );
        }
    }

    return (
        <div className="op-payment-request" style={ { position: 'relative', zIndex: 100 } }>
            { mode === 'dynamic_qr' && rendering.qr_image_base64 && (
                <div className="op-pr-qr">
                    <img
                        src={ rendering.qr_image_base64.startsWith( 'data:' )
                            ? rendering.qr_image_base64
                            : `data:image/png;base64,${ rendering.qr_image_base64 }` }
                        alt="QR de pago"
                        className="op-qr-image"
                    />
                </div>
            ) }

            { keyValue && (
                <div className="op-pr-key">
                    <span className="op-pr-key-label">{ i18n.payment_key || 'Clave de pago' }</span>
                    <div className="op-pr-key-value-row">
                        <span className="op-pr-key-value">{ keyValue }</span>
                        <button
                            type="button"
                            className="op-copy-btn"
                            onClick={ handleCopy }
                        >
                            { copied ? ( i18n.copied || 'Copiado' ) : ( i18n.copy || 'Copiar' ) }
                        </button>
                    </div>
                    { rendering.instructions && (
                        <p className="op-pr-instructions">{ rendering.instructions }</p>
                    ) }
                </div>
            ) }

            { expiresAt && countdown && (
                <div className="op-pr-expiry">
                    <span className="op-pr-expiry-label">{ i18n.expires_in || 'Expira en' }: </span>
                    <span className="op-pr-expiry-countdown">{ countdown }</span>
                </div>
            ) }

            <div className="op-pr-waiting">
                <span className="op-spinner" aria-hidden="true" />
                <span>{ i18n.waiting_payment || 'Esperando confirmación del pago...' }</span>
            </div>
        </div>
    );
}
