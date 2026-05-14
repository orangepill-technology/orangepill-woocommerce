/**
 * WalletSection — Blocks checkout wallet apply UI (PR-WC-BLOCKS-WALLET-V1)
 *
 * Renders the logged-in customer's spendable wallet balance and lets them apply
 * an amount before submitting the order.
 *
 * Architecture rules (same as native-payment-shell.js):
 *  - Balance comes from server-rendered config (walletConfig.balance) — never computed locally
 *  - Apply triggers AJAX orangepill_apply_wallet — no client-side wallet authority
 *  - Cart totals are Option A (display-only) — WC cart store not modified
 *  - Zero-payable path communicated to content.jsx via onApplied({ remainingPayable: 0 })
 *  - Guest checkout: walletConfig.enabled === false → returns null
 *  - AbortController cleanup on unmount (PR-WC-BLOCKS-COMPATIBILITY-V1 pattern)
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { applyWallet } from '../api';
import { formatMoney } from '../utils/format';

const config = window.orangepillBlocksConfig || {};
const i18n   = config.i18n || {};

export function WalletSection( { walletConfig, cartTotal, onApplied, walletApplied } ) {
    const [ applyAmount,    setApplyAmount    ] = useState( '' );
    const [ loading,        setLoading        ] = useState( false );
    const [ error,          setError          ] = useState( null );
    const abortControllerRef                    = useRef( null );

    // WCBLOCKS-018: cleanup in-flight request on unmount.
    useEffect( () => {
        return () => {
            if ( abortControllerRef.current ) {
                abortControllerRef.current.abort();
            }
        };
    }, [] );

    // WCBLOCKS-014: hide wallet for guests or when no spendable balance.
    if ( ! walletConfig?.enabled || ! ( walletConfig.balance > 0 ) ) {
        return null;
    }

    const cartTotalNum = parseFloat( cartTotal ) || 0;
    const maxApply     = Math.min( walletConfig.balance, cartTotalNum );

    // Applied state — show summary.
    if ( walletApplied ) {
        return (
            <div className="op-wallet-applied">
                <p className="op-wallet-applied-amount">
                    { i18n.wallet_applied || 'Wallet applied:' }
                    { ' ' }
                    <strong>{ formatMoney( walletApplied.appliedAmount, walletConfig.currency ) }</strong>
                </p>
                <p className="op-wallet-remaining">
                    { i18n.wallet_remaining || 'Remaining to pay:' }
                    { ' ' }
                    <strong>{ formatMoney( walletApplied.remainingPayable, walletConfig.currency ) }</strong>
                    { ' ' }
                    <em style={ { fontSize: '11px', color: '#888' } }>*</em>
                </p>
                { walletApplied.remainingPayable === 0 && (
                    <p className="op-wallet-zero-payable" style={ { color: '#2a7a2a', fontWeight: 'bold' } }>
                        { i18n.wallet_full_cover || 'Your wallet covers the full order total. No additional payment needed.' }
                    </p>
                ) }
                <p style={ { fontSize: '11px', color: '#888', margin: '2px 0 0' } }>
                    * { i18n.wallet_final_note || 'Final amount confirmed by Orangepill' }
                </p>
            </div>
        );
    }

    const handleApplyMax = () => {
        setApplyAmount( String( Math.floor( maxApply ) ) );
    };

    const handleApply = async () => {
        setError( null );

        const amount = parseFloat( applyAmount );

        // Client-side guard — server validates authoritatively.
        if ( isNaN( amount ) || amount <= 0 ) {
            setError( i18n.wallet_invalid_amount || 'Please enter a valid amount.' );
            return;
        }
        if ( amount > walletConfig.balance ) {
            setError( i18n.wallet_exceeds_balance || 'Amount exceeds wallet balance.' );
            return;
        }
        if ( amount > cartTotalNum ) {
            setError( i18n.wallet_exceeds_total || 'Amount exceeds order total.' );
            return;
        }

        if ( abortControllerRef.current ) {
            abortControllerRef.current.abort();
        }
        abortControllerRef.current = new AbortController();

        setLoading( true );
        try {
            const result = await applyWallet(
                amount,
                walletConfig.walletId || '',
                cartTotal,
                abortControllerRef.current.signal
            );
            // result: { sessionId, appliedAmount, remainingPayable }
            onApplied( result );
        } catch ( err ) {
            if ( err.name !== 'AbortError' ) {
                setError( err.message || ( i18n.wallet_apply_error || 'Wallet apply failed. Please try again.' ) );
            }
        } finally {
            setLoading( false );
        }
    };

    return (
        <div className="op-wallet-section" style={ { marginTop: '12px' } }>
            <p className="op-wallet-balance">
                { i18n.wallet_available || 'Rewards balance:' }
                { ' ' }
                <strong>{ formatMoney( walletConfig.balance, walletConfig.currency ) }</strong>
            </p>

            <div className="op-wallet-controls" style={ { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } }>
                <input
                    type="number"
                    value={ applyAmount }
                    onChange={ ( e ) => setApplyAmount( e.target.value ) }
                    placeholder={ i18n.wallet_amount_placeholder || 'Amount to apply' }
                    min="0"
                    max={ Math.floor( maxApply ) }
                    step="1"
                    disabled={ loading }
                    style={ { width: '110px' } }
                />
                <span>{ walletConfig.currency }</span>
                <button
                    type="button"
                    onClick={ handleApplyMax }
                    disabled={ loading }
                    className="button button-secondary"
                    style={ { padding: '6px 12px' } }
                >
                    { i18n.wallet_apply_max || 'Apply max' }
                </button>
                <button
                    type="button"
                    onClick={ handleApply }
                    disabled={ loading || ! applyAmount }
                    className="button button-primary"
                    style={ { padding: '6px 12px' } }
                >
                    { loading
                        ? ( i18n.wallet_applying || 'Applying...' )
                        : ( i18n.wallet_apply    || 'Apply wallet' ) }
                </button>
            </div>

            { error && (
                <p className="op-wallet-error" role="alert" style={ { color: '#c00', marginTop: '6px' } }>
                    { error }
                </p>
            ) }
        </div>
    );
}
