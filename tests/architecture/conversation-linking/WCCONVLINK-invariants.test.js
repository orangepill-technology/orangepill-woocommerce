/**
 * WCCONVLINK Architecture Invariant Tests
 * (PR-WC-WEBCHAT-CONVERSATION-LINKING-V1)
 *
 * Verifies the conversation-linking implementation upholds plugin doctrine:
 *   - Conversation ID read from widget JS API via try-catch helper (never throws)
 *   - All 5 checkout paths capture and propagate conversation ID
 *   - Order creation never blocks on conversation ID retrieval
 *   - Platform attribution outcome stored on the order (not trusted from browser)
 *   - Attribution failures never affect order completion
 *   - Conversation ID not used for business logic before platform verification
 *   - Rejection reasons follow canonical platform vocabulary
 *   - Order metabox surfaces attribution status visibly (RULE 6)
 *   - No analytics aggregation introduced (RULE 7 / ADR-096)
 *   - No retroactive linking (RULE 4 — creation-time only)
 *   - Helper loaded for both classic and Blocks checkout contexts
 *   - Anonymous conversation rejection handled gracefully
 *
 * Tests are static file analysis (grep-based) — no runtime WP environment needed.
 */

'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );

const ROOT   = path.join( __dirname, '../../..' );
const read   = f => fs.readFileSync( path.join( ROOT, f ), 'utf8' );
const exists = f => fs.existsSync( path.join( ROOT, f ) );

// ── WCCONVLINK-001 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-001: OrangepillConversationHelper.getActiveConversationId() has try-catch and null returns', () => {
    const helper = read( 'assets/js/conversation-helper.js' );

    // Must be wrapped in try-catch
    expect( helper ).toContain( 'try' );
    expect( helper ).toContain( 'catch' );

    // Must return null for all error/empty cases
    expect( helper ).toMatch( /return null/ );

    // Must expose stable global
    expect( helper ).toContain( 'OrangepillConversationHelper' );
    expect( helper ).toContain( 'getActiveConversationId' );

    // ES module mirror for Blocks must have same structure
    const blocksHelper = read( 'assets/js/blocks/conversation-helper.js' );
    expect( blocksHelper ).toContain( 'try' );
    expect( blocksHelper ).toContain( 'catch' );
    expect( blocksHelper ).toMatch( /return null/ );
    expect( blocksHelper ).toContain( 'getActiveConversationId' );
    expect( blocksHelper ).toContain( 'export function' );
} );

// ── WCCONVLINK-002 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-002: all checkout paths call getActiveConversationId and propagate conversation_id', () => {
    const shell   = read( 'assets/js/native-payment-shell.js' );
    const content = read( 'assets/js/blocks/content.jsx' );
    const gateway = read( 'includes/class-op-payment-gateway.php' );

    // Classic checkout (native + hosted paths): native shell calls helper
    expect( shell ).toContain( 'getActiveConversationId' );
    // Conversation ID set in form hidden field (propagates to process_payment for all classic paths)
    expect( shell ).toContain( '_orangepill_conversation_id' );
    expect( shell ).toContain( 'conversation_id' ); // sent to create_intent AJAX

    // Blocks checkout (native + hosted + wallet-only paths): content.jsx calls helper
    expect( content ).toContain( 'getActiveConversationId' );
    // All paymentMethodData objects must include conversation_id
    const convMatches = content.match( /_orangepill_conversation_id/g );
    expect( convMatches ).not.toBeNull();
    // 5 paths: wallet-only, redirect, processing, completed, payment_request_required(completed)
    expect( convMatches.length ).toBeGreaterThanOrEqual( 5 );

    // PHP reads conversation_id from POST in all paths
    expect( gateway ).toContain( "'_orangepill_conversation_id'" );
    // Stored on order via update_meta_data
    expect( gateway ).toContain( "'_orangepill_conversation_id', $conversation_id" );
} );

// ── WCCONVLINK-003 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-003: getActiveConversationId() is never awaited — synchronous call only', () => {
    const content = read( 'assets/js/blocks/content.jsx' );
    const shell   = read( 'assets/js/native-payment-shell.js' );

    // Must NOT have "await getActiveConversationId" or "await ... getActiveConversationId"
    expect( content ).not.toMatch( /await\s+getActiveConversationId/ );
    expect( shell ).not.toMatch( /await\s+getActiveConversationId/ );

    // Call must be synchronous assignment
    expect( content ).toMatch( /const conversationId\s*=\s*getActiveConversationId\(\)/ );
} );

// ── WCCONVLINK-004 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-004: platform attribution outcome stored on order via update_post_meta', () => {
    const sync = read( 'includes/class-op-external-order-sync.php' );

    // Attribution status stored
    expect( sync ).toContain( "'_orangepill_conversation_attribution_status'" );
    expect( sync ).toContain( 'update_post_meta' );

    // Reason also stored
    expect( sync ).toContain( "'_orangepill_conversation_attribution_reason'" );

    // Only set when platform responds (not blindly on every push)
    expect( sync ).toContain( 'conversation_attribution' );
} );

// ── WCCONVLINK-005 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-005: attribution does not block order completion — payment_complete called regardless', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    const sync    = read( 'includes/class-op-external-order-sync.php' );

    // process_payment() calls payment_complete (native path succeeds unconditionally)
    expect( gateway ).toContain( 'payment_complete' );

    // External-orders attribution runs AFTER the push returns (not before order completes)
    // The attribution block is inside the push() method, not in process_payment
    // Verify attribution storage is in sync class, NOT wrapping payment_complete in gateway
    expect( sync ).toContain( "'_orangepill_conversation_attribution_status'" );

    // Gateway must NOT block payment_complete on attribution status
    expect( gateway ).not.toMatch(
        /_orangepill_conversation_attribution_status[\s\S]{0,200}payment_complete/
    );
} );

// ── WCCONVLINK-006 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-006: conversation_id not used for plugin-side business logic before platform verification', () => {
    const gateway  = read( 'includes/class-op-payment-gateway.php' );
    const metabox  = read( 'includes/class-op-order-metabox.php' );
    const sync     = read( 'includes/class-op-external-order-sync.php' );

    // Conversation_id is STORED on order — not used for routing/discount/etc.
    // Any read of _orangepill_conversation_id must be in the metabox (display) or sync (payload) only.

    // Gateway reads conversation_id from POST to STORE it — never routes on it
    expect( gateway ).not.toMatch(
        /get_post_meta[\s\S]{0,50}_orangepill_conversation_id[\s\S]{0,100}if\s*\(/
    );

    // Metabox reads it for DISPLAY alongside attribution_status — not for business logic
    expect( metabox ).toContain( '_orangepill_conversation_id' );
    expect( metabox ).toContain( '_orangepill_conversation_attribution_status' );

    // Sync reads it to include in payload and check attribution — no business routing
    expect( sync ).toContain( '_orangepill_conversation_id' );
} );

// ── WCCONVLINK-007 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-007: rejection reasons follow canonical vocabulary in metabox display', () => {
    const metabox = read( 'includes/class-op-order-metabox.php' );

    // Canonical platform rejection reason strings referenced in display code:
    expect( metabox ).toContain( 'conversation_not_found' );
    expect( metabox ).toContain( 'customer_mismatch' );
    expect( metabox ).toContain( 'conversation_anonymous' );

    // Must NOT display unverified conversation_id as "verified" without checking status
    expect( metabox ).toContain( "'verified'" );
    expect( metabox ).toContain( "'rejected'" );
} );

// ── WCCONVLINK-008 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-008: order metabox displays attribution status visibly', () => {
    expect( exists( 'includes/class-op-order-metabox.php' ) ).toBe( true );

    const metabox = read( 'includes/class-op-order-metabox.php' );

    // Metabox registered with add_meta_box
    expect( metabox ).toContain( 'add_meta_box' );

    // Conversation row rendered with status
    expect( metabox ).toContain( '_orangepill_conversation_id' );
    expect( metabox ).toContain( 'Linked' );
    expect( metabox ).toContain( 'Not linked' );

    // Attribution-status CSS classes present for styling
    expect( metabox ).toContain( 'op-attr-verified' );
    expect( metabox ).toContain( 'op-attr-rejected' );
} );

// ── WCCONVLINK-009 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-009: no analytics aggregation introduced — no dashboards, charts, or scoring', () => {
    // Scan the new conversation-linking files for aggregation patterns
    const sync    = read( 'includes/class-op-external-order-sync.php' );
    const metabox = read( 'includes/class-op-order-metabox.php' );
    const helper  = read( 'assets/js/conversation-helper.js' );

    const noAnalyticsIn = ( content, name ) => {
        expect( content ).not.toMatch( /conversion.?rate|attribution.?funnel|scoring|aggregate/i );
        expect( content ).not.toMatch( /<canvas|Chart\.js|chartjs|Recharts/i );
    };

    noAnalyticsIn( sync,    'external-order-sync' );
    noAnalyticsIn( metabox, 'order-metabox' );
    noAnalyticsIn( helper,  'conversation-helper' );
} );

// ── WCCONVLINK-010 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-010: no retroactive linking — conversation ID captured at checkout time only', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    const metabox = read( 'includes/class-op-order-metabox.php' );
    const main    = read( 'orangepill-woocommerce.php' );

    // No admin action to retroactively link an order to a conversation
    expect( gateway ).not.toMatch( /admin_post_orangepill_link_conversation|retroactive|relink/i );
    expect( metabox ).not.toMatch( /admin_post_orangepill_link_conversation|retroactive/i );
    expect( main    ).not.toMatch( /admin_post_orangepill_link_conversation|retroactive/i );

    // Metabox must NOT have a form/input for manual conversation ID entry
    expect( metabox ).not.toContain( '<form' );
    expect( metabox ).not.toMatch( /input.*conversation.*id/i );
} );

// ── WCCONVLINK-011 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-011: conversation helper loaded for both classic checkout and Blocks checkout', () => {
    const main    = read( 'orangepill-woocommerce.php' );
    const content = read( 'assets/js/blocks/content.jsx' );

    // Classic checkout: enqueued via wp_enqueue_script in orangepill-woocommerce.php
    expect( main ).toContain( 'orangepill-conversation-helper' );
    expect( main ).toContain( 'conversation-helper.js' );

    // Must be enqueued before or as a dependency of the native shell
    expect( main ).toMatch( /orangepill-conversation-helper[\s\S]{0,500}orangepill-wc-native-shell/s );

    // Blocks checkout: imported from ./conversation-helper (ES module)
    expect( content ).toContain( "from './conversation-helper'" );
    expect( exists( 'assets/js/blocks/conversation-helper.js' ) ).toBe( true );
} );

// ── WCCONVLINK-012 ────────────────────────────────────────────────────────────

test( 'WCCONVLINK-012: conversation_anonymous rejection reason handled gracefully — no errors, display only', () => {
    const metabox = read( 'includes/class-op-order-metabox.php' );
    const sync    = read( 'includes/class-op-external-order-sync.php' );

    // Anonymous rejection is displayed in metabox without throwing
    // The metabox renders rejection reason from platform as text — no conditional logic that throws
    expect( metabox ).toContain( 'conversation_anonymous' );
    // Displayed under the 'rejected' attribution_status branch (Not linked: ...)
    expect( metabox ).toMatch( /rejected[\s\S]{0,800}Not linked/s );

    // Sync stores reason as-is from platform response — no special-casing
    expect( sync ).toContain( "'reason'" );
    expect( sync ).toContain( 'attribution_reason' );

    // No wp_die() or wc_add_notice() inside attribution-status handling
    expect( sync ).not.toMatch( /wp_die[\s\S]{0,50}attribution/s );
} );
