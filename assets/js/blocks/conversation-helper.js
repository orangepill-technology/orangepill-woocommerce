/**
 * Orangepill Conversation Helper — ES module mirror for Blocks checkout.
 * (PR-WC-WEBCHAT-CONVERSATION-LINKING-V1)
 *
 * Logic is identical to assets/js/conversation-helper.js (IIFE variant).
 * Keep the two in sync — single purpose, different module format.
 *
 * WCCONVLINK-001: try-catch wrapping ensures getActiveConversationId() never throws.
 * WCCONVLINK-003: synchronous — never awaited, never blocks order creation.
 */

/**
 * Read the active conversation ID from the webchat widget JS API.
 * Returns null when widget not loaded, no active conversation, or any error.
 *
 * @return {string|null}
 */
export function getActiveConversationId() {
    try {
        if ( typeof window.OrangepillWebchat === 'undefined' ) {
            return null;
        }
        if ( typeof window.OrangepillWebchat.getActiveConversationId !== 'function' ) {
            return null;
        }
        var id = window.OrangepillWebchat.getActiveConversationId();
        if ( typeof id !== 'string' || id.length === 0 ) {
            return null;
        }
        return id;
    } catch ( err ) {
        // Per RULE 3: warn but do not propagate — order creation must not block.
        if ( window.console && console.warn ) {
            console.warn( 'Orangepill: failed to read conversation ID', err );
        }
        return null;
    }
}
