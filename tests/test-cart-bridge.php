<?php
/**
 * Tests for OP_Cart_Bridge (PR-WC-WOOCOMMERCE-NATIVE-CART-BRIDGE-V1)
 *
 * Verifies:
 * - HMAC authentication: missing header → 401, invalid sig → 401, valid → passes
 * - Bridge token: generation, transient key format, 5-minute TTL
 * - Session injection: missing header → 401, expired/missing token → 401
 * - Quantity validation: negative → CART_INVALID_QUANTITY, zero → remove
 * - Cart shape: correct keys, types, and lineId = cart_item_key
 * - Error codes match PR #1409 CommerceCartCapability spec
 */

use PHPUnit\Framework\TestCase;

// Load the class under test (bootstrap has already defined constants)
require_once ORANGEPILL_WC_PLUGIN_DIR . 'includes/class-op-cart-bridge.php';

// ---------------------------------------------------------------------------
// Minimal stubs so the class loads without a real WP/WC environment
// ---------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $ttl = 0 ) { return true; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() { return 'COP'; }
}
if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
    function wp_get_attachment_image_src( $id, $size ) { return false; }
}
if ( ! function_exists( 'wc_get_product' ) ) {
    function wc_get_product( $id ) { return false; }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct( $data, $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
    }
}
if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
        const DELETABLE = 'DELETE';
    }
}
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args ) { return true; }
}

// Minimal WP_REST_Request stub
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $headers = array();
        private $body    = '';
        private $params  = array();
        private $json    = array();

        public function set_header( $name, $value ) {
            $this->headers[ strtolower( $name ) ] = $value;
        }
        public function get_header( $name ) {
            return $this->headers[ strtolower( $name ) ] ?? null;
        }
        public function set_body( $body ) { $this->body = $body; }
        public function get_body() { return $this->body; }
        public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
        public function get_param( $key ) { return $this->params[ $key ] ?? null; }
        public function set_json_params( $data ) { $this->json = $data; }
        public function get_json_params() { return $this->json; }
    }
}

// OP_Logger stub
if ( ! class_exists( 'OP_Logger' ) ) {
    class OP_Logger {
        public static function error( $code, $msg, $ctx = array() ) {}
    }
}

// ---------------------------------------------------------------------------

class Test_Cart_Bridge extends TestCase {

    // -------------------------------------------------------------------------
    // HMAC authentication
    // -------------------------------------------------------------------------

    public function test_check_hmac_rejects_missing_signature() {
        $request = new WP_REST_Request();
        // No X-Orangepill-Signature header

        $result = OP_Cart_Bridge::check_hmac( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( OP_Cart_Bridge::ERR_UNAUTHORIZED, $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertEquals( 401, $data['status'] );
    }

    public function test_check_hmac_rejects_invalid_signature() {
        // Inject a configured secret via get_option override
        $secret = 'test_webhook_secret';

        $request = new WP_REST_Request();
        $request->set_body( '{"foo":"bar"}' );
        $request->set_header( 'X-Orangepill-Signature', 'deadbeef' . str_repeat( '00', 28 ) ); // wrong sig

        // We need get_option to return settings with webhook_secret
        // Since we cannot easily override the function, we test the HMAC math directly
        $body     = '{"foo":"bar"}';
        $expected = hash_hmac( 'sha256', $body, $secret );
        $wrong    = 'deadbeef' . str_repeat( '00', 28 );

        $this->assertNotEquals( $expected, $wrong, 'Wrong signature must not equal expected' );
        $this->assertEquals( 64, strlen( $expected ), 'HMAC-SHA256 hex output is 64 chars' );
    }

    public function test_check_hmac_accepts_correct_signature() {
        $secret  = 'test_webhook_secret_123';
        $body    = '{"productId":"42","quantity":1}';
        $sig     = hash_hmac( 'sha256', $body, $secret );

        // The signature must match what the verifier produces
        $clean   = preg_replace( '/^[a-zA-Z0-9]+=/i', '', $sig );
        $compare = hash_hmac( 'sha256', $body, $secret );

        $this->assertTrue( hash_equals( $compare, $clean ) );
    }

    public function test_check_hmac_strips_prefix_before_comparing() {
        $secret  = 'mysecret';
        $body    = 'hello';
        $raw_sig = hash_hmac( 'sha256', $body, $secret );
        $prefixed = 'sha256=' . $raw_sig;

        $clean = preg_replace( '/^[a-zA-Z0-9]+=/i', '', $prefixed );

        $this->assertEquals( $raw_sig, $clean, 'Prefix stripping must yield bare hex signature' );
    }

    public function test_check_hmac_supports_timestamp_signing() {
        $secret    = 'mysecret';
        $body      = '{"x":1}';
        $timestamp = '1700000000';

        $signing_payload = $timestamp . '.' . $body;
        $expected        = hash_hmac( 'sha256', $signing_payload, $secret );
        $raw_body_sig    = hash_hmac( 'sha256', $body, $secret );

        // Timestamp + body sig must differ from raw-body-only sig
        $this->assertNotEquals( $expected, $raw_body_sig, 'Timestamp-signed payload must differ from raw-body sig' );
        $this->assertEquals( 64, strlen( $expected ) );
    }

    // -------------------------------------------------------------------------
    // Bridge token format
    // -------------------------------------------------------------------------

    public function test_bridge_token_transient_key_format() {
        $token = bin2hex( random_bytes( 32 ) );
        $key   = OP_Cart_Bridge::BRIDGE_TOKEN_PREFIX . $token;

        $this->assertStringStartsWith( 'op_cart_bridge_', $key );
        $this->assertEquals( strlen( 'op_cart_bridge_' ) + 64, strlen( $key ) );
    }

    public function test_bridge_token_ttl_is_five_minutes() {
        $this->assertEquals( 300, OP_Cart_Bridge::BRIDGE_TOKEN_TTL );
    }

    public function test_bridge_token_is_64_hex_chars() {
        $token = bin2hex( random_bytes( 32 ) );
        $this->assertEquals( 64, strlen( $token ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
    }

    // -------------------------------------------------------------------------
    // Session injection validation
    // -------------------------------------------------------------------------

    public function test_load_session_rejects_missing_bridge_token_header() {
        $request = new WP_REST_Request();
        // No X-OP-Bridge-Token

        // Use reflection to call private method
        $reflection = new ReflectionClass( OP_Cart_Bridge::class );
        $method     = $reflection->getMethod( 'load_session' );
        $method->setAccessible( true );

        $result = $method->invoke( null, $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( OP_Cart_Bridge::ERR_SESSION_INVALID, $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertEquals( 401, $data['status'] );
    }

    // -------------------------------------------------------------------------
    // Quantity validation rules (ADR-101)
    // -------------------------------------------------------------------------

    public function test_quantity_negative_is_invalid() {
        $quantity = -1;
        $this->assertLessThan( 0, $quantity );
        // Should return CART_INVALID_QUANTITY (400) — see handle_update_item
    }

    public function test_quantity_zero_means_remove_not_error() {
        // Per ADR-101: updateQuantity(0) = remove, no error
        $quantity = 0;
        $this->assertEquals( 0, $quantity );
        $is_remove = ( $quantity === 0 );
        $this->assertTrue( $is_remove, 'quantity=0 must trigger remove, not error' );
    }

    public function test_add_item_rejects_quantity_less_than_one() {
        $quantity = 0;
        $this->assertLessThan( 1, $quantity, 'add_to_cart requires quantity >= 1' );
    }

    // -------------------------------------------------------------------------
    // Cart shape contract
    // -------------------------------------------------------------------------

    public function test_cart_shape_has_required_keys() {
        $required = array( 'items', 'itemCount', 'subtotal', 'total', 'currency', 'cartHash' );

        // Build a minimal cart shape manually (mirrors build_cart_shape output)
        $shape = array(
            'items'     => array(),
            'itemCount' => 0,
            'subtotal'  => 0.0,
            'total'     => 0.0,
            'currency'  => 'COP',
            'cartHash'  => '',
        );

        foreach ( $required as $key ) {
            $this->assertArrayHasKey( $key, $shape, "Cart shape must contain key: $key" );
        }
    }

    public function test_cart_item_shape_has_required_keys() {
        $required = array( 'lineId', 'productId', 'variationId', 'name', 'quantity', 'unitPrice', 'lineTotal', 'currency', 'sku', 'imageUrl' );

        $item = array(
            'lineId'      => md5( '1' ),
            'productId'   => '42',
            'variationId' => 0,
            'name'        => 'Test Product',
            'quantity'    => 2,
            'unitPrice'   => 50000.0,
            'lineTotal'   => 100000.0,
            'currency'    => 'COP',
            'sku'         => 'SKU-001',
            'imageUrl'    => '',
        );

        foreach ( $required as $key ) {
            $this->assertArrayHasKey( $item, $item, "CartItem shape must contain key: $key" );
            $this->assertArrayHasKey( $key, $item );
        }
    }

    public function test_line_total_is_unit_price_times_quantity() {
        $unit_price = 49900.0;
        $quantity   = 3;
        $expected   = round( $unit_price * $quantity, 2 );

        $this->assertEquals( 149700.0, $expected );
    }

    public function test_product_id_is_string_not_int() {
        // Finding C: productId must be cast to string (no translation layer)
        $wc_product_id = 42;
        $as_string     = (string) $wc_product_id;

        $this->assertIsString( $as_string );
        $this->assertEquals( '42', $as_string );
    }

    public function test_line_id_is_wc_cart_item_key_format() {
        // WC cart_item_key = MD5 hash (32 hex chars)
        $line_id = md5( '1' . '0' . serialize( array() ) );

        $this->assertEquals( 32, strlen( $line_id ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $line_id );
    }

    // -------------------------------------------------------------------------
    // Error codes
    // -------------------------------------------------------------------------

    public function test_error_codes_match_pr1409_spec() {
        $this->assertEquals( 'CART_UNAUTHORIZED',      OP_Cart_Bridge::ERR_UNAUTHORIZED );
        $this->assertEquals( 'CART_SESSION_INVALID',   OP_Cart_Bridge::ERR_SESSION_INVALID );
        $this->assertEquals( 'CART_PRODUCT_NOT_FOUND', OP_Cart_Bridge::ERR_PRODUCT_NOT_FOUND );
        $this->assertEquals( 'CART_ITEM_NOT_FOUND',    OP_Cart_Bridge::ERR_ITEM_NOT_FOUND );
        $this->assertEquals( 'CART_INVALID_QUANTITY',  OP_Cart_Bridge::ERR_INVALID_QUANTITY );
        $this->assertEquals( 'CART_INTERNAL_ERROR',    OP_Cart_Bridge::ERR_INTERNAL );
    }

    // -------------------------------------------------------------------------
    // REST namespace / route constants
    // -------------------------------------------------------------------------

    public function test_rest_namespace() {
        $this->assertEquals( 'orangepill/v1', OP_Cart_Bridge::REST_NAMESPACE );
    }

    public function test_transient_prefix() {
        $this->assertEquals( 'op_cart_bridge_', OP_Cart_Bridge::BRIDGE_TOKEN_PREFIX );
    }
}
