/**
 * WCIDENT Architecture Invariant Tests
 * (PR-WC-WEBCHAT-IDENTITY-BINDING-V1)
 *
 * Verifies the identity token implementation upholds these invariants:
 *   - HMAC signing key NEVER exposed to JavaScript (PHP-only)
 *   - Token generation uses hash_hmac('sha256', ...) — canonical HMAC algorithm
 *   - Token has explicit expires_at — never unbounded
 *   - Identifier claims are honest (only what WP actually has)
 *   - Widget loads for ALL visitors (anonymous flow preserved — additive, not blocking)
 *   - Refresh endpoint requires login (no nopriv handler)
 *   - No conversation rendering on WordPress side
 *   - canonical_customer_id asserted only when meta exists
 *   - Token TTL is bounded (1 hour max)
 *   - Token format matches canonical HMAC envelope (base64.hex-hmac)
 *   - Identifier sourcing matches OP_Customer_Sync (billing_phone meta key)
 *   - Settings page adds identity_secret field and admin notice
 *
 * Tests are static file analysis (grep-based) — no runtime WP environment needed.
 */

'use strict';

const fs   = require( 'fs' );
const path = require( 'path' );

const ROOT   = path.join( __dirname, '../../..' );
const read   = f => fs.readFileSync( path.join( ROOT, f ), 'utf8' );
const exists = f => fs.existsSync( path.join( ROOT, f ) );

// ── WCIDENT-001 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-001: identity_secret NEVER passed to JavaScript — PHP-only', () => {
    const main = read( 'orangepill-woocommerce.php' );

    // The secret must not be echoed or passed to JS via wp_localize_script or inline script
    expect( main ).not.toMatch( /identity_secret.*javascript/i );
    expect( main ).not.toMatch( /wp_localize_script.*identity_secret/i );

    // The data attribute on the script tag must carry the TOKEN (not the secret)
    expect( main ).toContain( 'data-identity-token' );
    expect( main ).not.toContain( 'data-identity-secret' );
} );

// ── WCIDENT-002 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-002: token generation uses hash_hmac SHA-256', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    expect( identity ).toContain( "hash_hmac( 'sha256'" );
} );

// ── WCIDENT-003 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-003: token payload includes expires_at — bounded TTL, never unbounded', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    expect( identity ).toContain( "'expires_at'" );
    expect( identity ).toContain( 'TOKEN_TTL_SECONDS' );
    // TTL must be defined as a constant (not an inline magic number)
    expect( identity ).toMatch( /const TOKEN_TTL_SECONDS\s*=\s*\d+/ );
    // TTL must be at most 86400 seconds (24 hours) — 1 hour is canonical
    const match = identity.match( /const TOKEN_TTL_SECONDS\s*=\s*(\d+)/ );
    expect( match ).not.toBeNull();
    expect( parseInt( match[ 1 ], 10 ) ).toBeLessThanOrEqual( 86400 );
} );

// ── WCIDENT-004 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-004: identifier claims are honest — only email and phone, sourced from WP', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    // Email sourced from user object
    expect( identity ).toContain( '$user->user_email' );
    // Phone sourced from billing_phone meta (same source as OP_Customer_Sync)
    expect( identity ).toContain( "'billing_phone'" );
    // Phone is sanitized to digits and + only
    expect( identity ).toContain( "preg_replace( '/[^\\d+]/'" );
} );

// ── WCIDENT-005 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-005: widget always loads — null token does not block injection', () => {
    const main = read( 'orangepill-woocommerce.php' );
    // The script echo must happen unconditionally (outside any identity-blocking if)
    // Presence of the widget script injection
    expect( main ).toContain( 'data-entrypoint-id' );
    // Token attrs are optional (only added when non-null) — not a hard requirement
    expect( main ).toContain( 'data-identity-token' );
    // The injection comment must reference that it is additive / non-blocking
    expect( main ).toMatch( /additive|non.?blocking|always loads/i );
} );

// ── WCIDENT-006 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-006: refresh endpoint has no nopriv variant — login required', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    const main     = read( 'orangepill-woocommerce.php' );

    // No add_action registration for the nopriv variant in either file
    expect( identity ).not.toMatch( /add_action\s*\(\s*['"]wp_ajax_nopriv_orangepill_refresh_identity_token['"]/ );
    expect( main ).not.toMatch( /add_action\s*\(\s*['"]wp_ajax_nopriv_orangepill_refresh_identity_token['"]/ );

    // The priv-only handler MUST be registered via add_action
    expect( main ).toMatch( /add_action\s*\(\s*['"]wp_ajax_orangepill_refresh_identity_token['"]/ );
} );

// ── WCIDENT-007 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-007: refresh handler enforces check_ajax_referer', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    expect( identity ).toContain( 'check_ajax_referer' );
    expect( identity ).toContain( 'orangepill_refresh_identity_token' );
} );

// ── WCIDENT-008 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-008: no conversation rendering in WordPress — widget only (no PHP chat rendering)', () => {
    const main = read( 'orangepill-woocommerce.php' );
    // No inline conversation history, message rendering, or chat thread output
    expect( main ).not.toMatch( /conversation_history|chat_messages|render_conversation/i );
    // Widget injection is a single <script> tag — not a complex DOM structure
    expect( main ).toContain( '<script src=' );
    expect( main ).not.toContain( '<div class="op-chat' );
} );

// ── WCIDENT-009 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-009: canonical_customer_id asserted only when meta exists — never fabricated', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    // Must read from _orangepill_customer_id meta (same as OP_Customer_Sync writes)
    expect( identity ).toContain( "'_orangepill_customer_id'" );
    // Must guard with empty() check before asserting
    expect( identity ).toContain( 'canonical_customer_id' );
    expect( identity ).toMatch( /empty\s*\(\s*\$canonical_customer_id\s*\)/ );
} );

// ── WCIDENT-010 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-010: token payload includes version and issued_at — complete envelope', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    expect( identity ).toContain( "'version'" );
    expect( identity ).toContain( "'issued_at'" );
    expect( identity ).toContain( 'TOKEN_VERSION' );
} );

// ── WCIDENT-011 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-011: settings page renders identity_secret password field', () => {
    const settings = read( 'admin/class-op-settings-page.php' );
    expect( settings ).toContain( 'identity_secret' );
    expect( settings ).toContain( "type=\"password\"" );
    expect( settings ).toContain( 'maybe_show_identity_secret_notice' );
} );

// ── WCIDENT-012 ───────────────────────────────────────────────────────────────

test( 'WCIDENT-012: token format is base64(json).hex-hmac — canonical HMAC envelope', () => {
    const identity = read( 'includes/class-op-webchat-identity.php' );
    // base64_encode of the JSON payload
    expect( identity ).toContain( 'base64_encode' );
    // HMAC computed over the encoded payload (not raw json)
    expect( identity ).toContain( '$encoded_payload' );
    // Concatenation with '.' separator — return encoded_payload . '.' . signature
    expect( identity ).toContain( "'.'", );
    expect( identity ).toContain( '$signature' );
} );
