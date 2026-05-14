<?php
/**
 * Orangepill Webchat Identity Token Issuer (PR-WC-WEBCHAT-IDENTITY-BINDING-V1)
 *
 * Issues HMAC-SHA256–signed identity tokens for logged-in WP users so the
 * Orangepill webchat backend can bind conversations to canonical customers.
 *
 * Token format (Option B — Simple HMAC envelope, mirrors webhook HMAC pattern):
 *   base64( json_encode( $payload ) ) . '.' . hash_hmac( 'sha256', $encoded_payload, $secret )
 *
 * Trust model:
 *   - WordPress is the trust anchor (is_user_logged_in() + wp_get_current_user())
 *   - HMAC signing key (identity_secret) NEVER exposed to JavaScript
 *   - Token is the capability transport: browser carries it, platform verifies it
 *   - Anonymous flows are ALWAYS preserved: null token → widget loads without binding
 *
 * Platform-side verification spec: PR-PLATFORM-WEBCHAT-IDENTITY-VERIFICATION-V1
 * Identity model: ADR-009 (Identity Membership Boundaries)
 * Identifier semantics: ADR-022 (Identifier Verification Semantics)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OP_Webchat_Identity {

    const TOKEN_VERSION     = 'v1';
    const TOKEN_TTL_SECONDS = 3600; // 1 hour — aligns with redirect URL transient TTL

    /**
     * Generate an HMAC-signed identity token for the current logged-in user.
     *
     * Returns null (anonymous fallback) when:
     *   - User not logged in
     *   - identity_secret not configured
     *   - integration_id or merchant_id missing
     *
     * Callers must handle null gracefully — widget always loads regardless.
     *
     * @return string|null Signed token, or null
     */
    public static function generate_token_for_current_user() {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        $settings = get_option( 'woocommerce_orangepill_settings', array() );

        $identity_secret = $settings['identity_secret'] ?? '';
        $integration_id  = $settings['integration_id']  ?? '';
        $merchant_id     = $settings['merchant_id']     ?? '';

        if ( empty( $identity_secret ) || empty( $integration_id ) || empty( $merchant_id ) ) {
            return null;
        }

        $user    = wp_get_current_user();
        $user_id = $user->ID;

        // Collect identifier claims honestly — only assert what WP actually has.
        // Per ADR-022: identifier verification status is meaningful; never fabricate.
        $identifiers = array();

        if ( ! empty( $user->user_email ) ) {
            $identifiers['email'] = sanitize_email( $user->user_email );
        }

        // billing_phone matches OP_Customer_Sync's source for phone (same meta key).
        $billing_phone = get_user_meta( $user_id, 'billing_phone', true );
        if ( ! empty( $billing_phone ) ) {
            // Strip non-digit / non-plus characters for canonical phone format.
            $identifiers['phone'] = preg_replace( '/[^\d+]/', '', $billing_phone );
        }

        $now     = time();
        $payload = array(
            'version'        => self::TOKEN_VERSION,
            'issued_at'      => $now,
            'expires_at'     => $now + self::TOKEN_TTL_SECONDS,
            'integration_id' => $integration_id,
            'merchant_id'    => $merchant_id,
            'wp_user_id'     => $user_id,
            'identifiers'    => $identifiers,
        );

        // Include canonical_customer_id only if plugin has already synced this user.
        // Never fabricate: assert only what we know is authoritative.
        $canonical_customer_id = get_user_meta( $user_id, '_orangepill_customer_id', true );
        if ( ! empty( $canonical_customer_id ) ) {
            $payload['canonical_customer_id'] = $canonical_customer_id;
        }

        try {
            return self::sign_payload( $payload, $identity_secret );
        } catch ( Exception $e ) {
            OP_Logger::error(
                'webchat_token_generation_failed',
                'Failed to generate identity token: ' . $e->getMessage(),
                array( 'wp_user_id' => $user_id )
            );
            return null;
        }
    }

    /**
     * Sign a payload with HMAC-SHA256.
     *
     * Token format: base64( json( $payload ) ) . '.' . hex( hmac-sha256( encoded_payload, $secret ) )
     *
     * Mirrors the webhook HMAC-SHA256 verification pattern in OP_Webhook_Handler::verify_signature().
     * Platform side verifies using the same scheme: split on '.', re-derive HMAC, compare.
     *
     * @param array  $payload Claims
     * @param string $secret  Shared HMAC-SHA256 secret
     * @return string Signed token
     */
    private static function sign_payload( array $payload, $secret ) {
        $json            = wp_json_encode( $payload );
        $encoded_payload = base64_encode( $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $signature       = hash_hmac( 'sha256', $encoded_payload, $secret );

        return $encoded_payload . '.' . $signature;
    }

    /**
     * AJAX refresh endpoint — issues a fresh token for the current logged-in user.
     *
     * Called by the webchat widget loader when the current token is approaching
     * expiry (platform-side polling). No nopriv variant — refresh requires login.
     *
     * WCIDENT-006: no wp_ajax_nopriv_orangepill_refresh_identity_token registered.
     * WCIDENT-007: check_ajax_referer enforced.
     */
    public static function handle_refresh_request() {
        check_ajax_referer( 'orangepill_refresh_identity_token', 'nonce' );

        $token = self::generate_token_for_current_user();

        wp_send_json_success( array(
            'token'      => $token,
            'expires_at' => $token ? ( time() + self::TOKEN_TTL_SECONDS ) : null,
        ) );
    }
}
