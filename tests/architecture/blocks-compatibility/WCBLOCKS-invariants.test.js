/**
 * WCBLOCKS Architecture Invariant Tests (PR-WC-BLOCKS-COMPATIBILITY-V1)
 *
 * These tests verify that the Blocks integration does NOT violate the canonical
 * PHP-authority doctrine:
 *   - No new AJAX endpoints
 *   - No process_payment() modifications
 *   - No webhook handler modifications
 *   - All 4 execution types handled
 *   - No browser-trusted redirect URLs
 *   - Idempotency and terminal state protection unchanged
 *
 * Tests are static file analysis (grep-based) — no runtime WP environment needed.
 */

'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );

const ROOT    = path.join( __dirname, '../../..' );
const read    = f => fs.readFileSync( path.join( ROOT, f ), 'utf8' );
const exists  = f => fs.existsSync( path.join( ROOT, f ) );

// ── WCBLOCKS-001 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-001: PHP registration hook and method exist in payment gateway', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    expect( gateway ).toContain( 'woocommerce_blocks_payment_method_type_registration' );
    expect( gateway ).toContain( 'register_blocks_payment_method' );
} );

// ── WCBLOCKS-002 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-002: OP_Blocks_Integration implements required methods', () => {
    const integration = read( 'includes/class-op-blocks-integration.php' );
    expect( integration ).toContain( 'AbstractPaymentMethodType' );
    expect( integration ).toContain( 'function initialize' );
    expect( integration ).toContain( 'function is_active' );
    expect( integration ).toContain( 'function get_payment_method_script_handles' );
    expect( integration ).toContain( 'function get_payment_method_data' );
} );

// ── WCBLOCKS-003 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-003: no new AJAX endpoints introduced — only existing 5 are present', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );

    const canonicalActions = [
        'orangepill_get_payment_options',
        'orangepill_create_intent',
        'orangepill_execute_intent',
        'orangepill_get_intent_status',
        'orangepill_get_payment_status',
    ];

    // Extract all wp_ajax_ registrations, stripping the nopriv_ variant prefix
    const matches = [ ...gateway.matchAll( /wp_ajax_(?:nopriv_)?([a-z_]+)/g ) ].map( m => m[ 1 ] );
    const unique  = [ ...new Set( matches ) ];

    unique.forEach( action => {
        expect( canonicalActions ).toContain( action );
    } );

    expect( unique.length ).toBe( canonicalActions.length );
} );

// ── WCBLOCKS-004 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-004: webhook handler has no Blocks-specific code', () => {
    const handler = read( 'includes/class-op-webhook-handler.php' );
    expect( handler ).not.toContain( 'blocks' );
    expect( handler ).not.toContain( 'Blocks' );
    expect( handler ).not.toContain( 'wc-blocks' );
} );

// ── WCBLOCKS-005 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-005: checkout return handler has no Blocks-specific code', () => {
    const returnHandler = read( 'includes/class-op-checkout-return-handler.php' );
    expect( returnHandler ).not.toContain( 'blocks' );
    expect( returnHandler ).not.toContain( 'Blocks' );
} );

// ── WCBLOCKS-006 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-006: React content component handles all 4 execution types', () => {
    const content = read( 'assets/js/blocks/content.jsx' );
    expect( content ).toContain( "'redirect'" );
    expect( content ).toContain( "'processing'" );
    expect( content ).toContain( "'completed'" );
    expect( content ).toContain( "'payment_request_required'" );
} );

// ── WCBLOCKS-007 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-007: Blocks content component references native shell as canonical source for dispatch logic', () => {
    const content = read( 'assets/js/blocks/content.jsx' );
    // Must contain a doctrine reference comment pointing to native-payment-shell.js
    expect( content ).toContain( 'native-payment-shell.js' );
} );

// ── WCBLOCKS-008 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-008: customer_id guard is preserved in PHP process_payment flow', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    // The guard: if customer sync fails for logged-in user, return failure (no silent null)
    expect( gateway ).toContain( 'checkout_customer_sync_failed' );
    expect( gateway ).toContain( "return array('result' => 'failure')" );
} );

// ── WCBLOCKS-009 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-009: asset version derives from blocks.asset.php in OP_Blocks_Integration', () => {
    const integration = read( 'includes/class-op-blocks-integration.php' );
    expect( integration ).toContain( 'blocks.asset.php' );
    expect( integration ).toContain( "asset_data['version']" );
} );

// ── WCBLOCKS-010 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-010: build/ and node_modules/ are in .gitignore', () => {
    const gitignore = read( '.gitignore' );
    expect( gitignore ).toContain( 'node_modules' );
    expect( gitignore ).toContain( 'build/' );
} );

// ── WCBLOCKS-011 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-011: Blocks content component does not assign window.location directly', () => {
    const content = read( 'assets/js/blocks/content.jsx' );
    // All redirects must come from server-returned execution.url via process_payment(),
    // not from browser-computed or user-input URLs.
    expect( content ).not.toContain( 'window.location' );
    expect( content ).not.toContain( 'location.href' );
    expect( content ).not.toContain( 'location.assign' );
    expect( content ).not.toContain( 'location.replace' );
} );

// ── WCBLOCKS-012 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-012: Blocks JS passes _orangepill_intent_id as paymentMethodData (idempotency entry point)', () => {
    const content = read( 'assets/js/blocks/content.jsx' );
    // The intent_id must be in paymentMethodData so process_payment() can look it up
    // via the existing idempotency guard on the server side.
    expect( content ).toContain( '_orangepill_intent_id' );
    expect( content ).toContain( 'paymentMethodData' );
} );
