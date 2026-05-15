/**
 * Orangepill Conversation Helper (PR-WC-WEBCHAT-CONVERSATION-LINKING-V1)
 *
 * Single source of truth for reading the active webchat conversation ID.
 * Loaded as an IIFE on classic checkout pages; exposes a stable global.
 *
 * Consumed by:
 *   - native-payment-shell.js (classic shortcode checkout)
 *   - blocks/conversation-helper.js (Blocks checkout — ES module mirror)
 *
 * Trust model (ADR-100): the conversation ID is a client-supplied CLAIM.
 * Platform verifies attribution via canonical customer identity match.
 * This helper makes the claim; it never verifies it.
 *
 * WCCONVLINK-001: try-catch wrapping ensures getActiveConversationId() never throws.
 * WCCONVLINK-003: synchronous — never awaited, never blocks order creation.
 */
( function ( window ) {
    'use strict';

    var ConversationHelper = {
        /**
         * Read the active conversation ID from the webchat widget JS API.
         *
         * Returns null when:
         *   - Widget script not loaded (window.OrangepillWebchat undefined)
         *   - Widget loaded but no active conversation
         *   - getActiveConversationId not a function (API not yet available)
         *   - Any unexpected error from the widget
         *
         * Per RULE 3: never throws — attribution does not block order creation.
         *
         * @return {string|null}
         */
        getActiveConversationId: function () {
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
        },
    };

    window.OrangepillConversationHelper = ConversationHelper;

} )( window );
