/**
 * WCBLOCKS Architecture Invariant Tests
 * (PR-WC-BLOCKS-COMPATIBILITY-V1 / PR-WC-BLOCKS-WALLET-V1)
 *
 * These tests verify that the Blocks integration does NOT violate the canonical
 * PHP-authority doctrine:
 *   - Canonical AJAX endpoint list (6: 5 payment + 1 wallet)
 *   - No process_payment() modifications beyond the minimal wallet-only path
 *   - No webhook handler modifications
 *   - All 4 execution types handled
 *   - No browser-trusted redirect URLs
 *   - Idempotency and terminal state protection unchanged
 *   - Wallet UI is server-authoritative (balance from config, apply via AJAX)
 *   - Wallet is logged-in-only
 *   - No client-side wallet balance computation
 *   - No browser storage as wallet authority
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

test( 'WCBLOCKS-003: canonical AJAX endpoint list — 5 payment endpoints + 1 wallet endpoint', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );

    const canonicalActions = [
        'orangepill_get_payment_options',
        'orangepill_create_intent',
        'orangepill_execute_intent',
        'orangepill_get_intent_status',
        'orangepill_get_payment_status',
        // PR-WC-BLOCKS-WALLET-V1: wallet apply endpoint (logged-in only, separate nonce)
        'orangepill_apply_wallet',
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

// ── WCBLOCKS-013 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-013: WalletSection component exists', () => {
    expect( exists( 'assets/js/blocks/components/WalletSection.jsx' ) ).toBe( true );
} );

// ── WCBLOCKS-014 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-014: WalletSection returns null when wallet is disabled (guest / no balance)', () => {
    const wallet = read( 'assets/js/blocks/components/WalletSection.jsx' );
    // Must have an early-return null guard on walletConfig.enabled
    expect( wallet ).toContain( 'walletConfig?.enabled' );
    expect( wallet ).toContain( 'return null' );
} );

// ── WCBLOCKS-015 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-015: orangepill_apply_wallet is the only wallet AJAX endpoint', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    // orangepill_apply_wallet must be registered
    expect( gateway ).toContain( 'wp_ajax_orangepill_apply_wallet' );
    // Must NOT have a nopriv variant — wallet is logged-in-only
    expect( gateway ).not.toContain( 'wp_ajax_nopriv_orangepill_apply_wallet' );
    // No second wallet endpoint (e.g. orangepill_get_wallet_balance registered in gateway)
    const walletMatches = [ ...gateway.matchAll( /wp_ajax_(?:nopriv_)?orangepill_\w*wallet\w*/g ) ];
    expect( walletMatches.length ).toBe( 1 );
} );

// ── WCBLOCKS-016 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-016: process_payment() handles _orangepill_wallet_only path', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    // Wallet-only detection guard
    expect( gateway ).toContain( '_orangepill_wallet_only' );
    expect( gateway ).toContain( 'process_wallet_only_payment' );
    // Transient-based verification (not just trusting POST)
    expect( gateway ).toContain( 'op_wallet_zero_payable_' );
} );

// ── WCBLOCKS-017 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-017: WalletSection reads balance from walletConfig.balance — no local computation', () => {
    const wallet = read( 'assets/js/blocks/components/WalletSection.jsx' );
    // Balance display must come from walletConfig.balance (server-rendered config)
    expect( wallet ).toContain( 'walletConfig.balance' );
    // Must NOT derive balance from local computation (e.g. no `let balance =` or `const balance =`)
    expect( wallet ).not.toMatch( /(?:const|let|var)\s+balance\s*=/ );
} );

// ── WCBLOCKS-018 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-018: WalletSection cleans up in-flight requests on unmount (AbortController)', () => {
    const wallet = read( 'assets/js/blocks/components/WalletSection.jsx' );
    expect( wallet ).toContain( 'abortControllerRef' );
    expect( wallet ).toContain( 'abort()' );
    // Cleanup in useEffect return
    expect( wallet ).toContain( 'return () =>' );
} );

// ── WCBLOCKS-019 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-019: wallet apply is idempotent — PHP handler caps amount server-side', () => {
    const gateway = read( 'includes/class-op-payment-gateway.php' );
    // Server re-fetches authoritative balance and caps the amount
    expect( gateway ).toContain( 'get_spendable_wallet_for_current_user' );
    expect( gateway ).toContain( 'min($amount,' );
} );

// ── WCBLOCKS-020 ─────────────────────────────────────────────────────────────

test( 'WCBLOCKS-020: WalletSection does not use browser storage as wallet authority', () => {
    const wallet = read( 'assets/js/blocks/components/WalletSection.jsx' );
    expect( wallet ).not.toContain( 'sessionStorage' );
    expect( wallet ).not.toContain( 'localStorage' );
} );
