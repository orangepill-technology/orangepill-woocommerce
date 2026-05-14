/**
 * Money formatting for Blocks payment UI.
 *
 * Uses a fixed locale (es-CO) for deterministic output — avoids navigator.language
 * which is non-deterministic across environments.
 */

export function formatMoney( amount, currency ) {
    const num = typeof amount === 'number' ? amount : ( parseFloat( amount ) || 0 );
    const formatted = num.toLocaleString( 'es-CO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    } );
    return currency ? formatted + ' ' + currency : formatted;
}
