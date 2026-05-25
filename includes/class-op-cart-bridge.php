<?php
/**
 * Orangepill WooCommerce Cart Bridge (PR-WC-WOOCOMMERCE-NATIVE-CART-BRIDGE-V1)
 *
 * WP REST API for platform-to-WooCommerce cart synchronisation.
 * Two-phase auth (ADR-100 / ADR-101):
 *
 *   Phase 1 — Bootstrap (browser, no HMAC):
 *     GET /wp-json/orangepill/v1/context
 *     Browser calls with WC session cookie → returns short-lived bridge token (5 min).
 *     WC session cookie never leaves the browser (Finding B: HttpOnly confirmed).
 *
 *   Phase 2 — Cart operations (server-to-server, HMAC):
 *     Platform sends X-OP-Bridge-Token + X-Orangepill-Signature.
 *     HMAC-SHA256 over raw body, hex-encoded, shared webhook_secret.
 *     Same algorithm as OP_Webhook_Handler::verify_signature().
 *
 * Session injection: customer_id stored in bridge-token transient; session data
 * loaded from WC DB via get_session($customer_id) — never needs the HttpOnly cookie.
 *
 * Cart shape mirrors PR #1409 CommerceCartCapability (Cart / CartItem interfaces).
 * lineId = WC cart_item_key = MD5(product_id + variation_id + variation_data).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OP_Cart_Bridge {

    const REST_NAMESPACE           = 'orangepill/v1';
    const BRIDGE_TOKEN_TTL         = 300; // 5 minutes
    const BRIDGE_TOKEN_PREFIX      = 'op_cart_bridge_';

    // Error codes (mirror PR #1409 CommerceCartCapability error set)
    const ERR_UNAUTHORIZED      = 'CART_UNAUTHORIZED';
    const ERR_SESSION_INVALID   = 'CART_SESSION_INVALID';
    const ERR_PRODUCT_NOT_FOUND = 'CART_PRODUCT_NOT_FOUND';
    const ERR_ITEM_NOT_FOUND    = 'CART_ITEM_NOT_FOUND';
    const ERR_INVALID_QUANTITY  = 'CART_INVALID_QUANTITY';
    const ERR_INTERNAL          = 'CART_INTERNAL_ERROR';

    /**
     * Register WP REST API routes.
     * Called from `rest_api_init` hook in the main plugin file.
     */
    public static function register_routes() {
        // Phase 1: browser bootstrap — no HMAC, WC session cookie provides identity
        register_rest_route( self::REST_NAMESPACE, '/context', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'handle_context' ),
            'permission_callback' => '__return_true',
        ) );

        // Phase 2: cart read + clear
        register_rest_route( self::REST_NAMESPACE, '/cart', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'handle_get_cart' ),
                'permission_callback' => array( __CLASS__, 'check_hmac' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( __CLASS__, 'handle_clear_cart' ),
                'permission_callback' => array( __CLASS__, 'check_hmac' ),
            ),
        ) );

        // Phase 2: add item
        register_rest_route( self::REST_NAMESPACE, '/cart/items', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'handle_add_item' ),
            'permission_callback' => array( __CLASS__, 'check_hmac' ),
        ) );

        // Phase 2: update / remove single item
        register_rest_route( self::REST_NAMESPACE, '/cart/items/(?P<lineId>[a-f0-9]{32})', array(
            array(
                'methods'             => 'PATCH',
                'callback'            => array( __CLASS__, 'handle_update_item' ),
                'permission_callback' => array( __CLASS__, 'check_hmac' ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( __CLASS__, 'handle_remove_item' ),
                'permission_callback' => array( __CLASS__, 'check_hmac' ),
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Permission callback
    // -------------------------------------------------------------------------

    /**
     * Verify HMAC signature on server-to-server requests.
     *
     * Mirrors OP_Webhook_Handler::verify_signature() exactly:
     * - Header:    X-Orangepill-Signature
     * - Algorithm: HMAC-SHA256, hex-encoded
     * - Payload:   "{timestamp}.{body}" when X-Orangepill-Timestamp present, else raw body
     * - Secret:    webhook_secret from plugin settings (ADR-100)
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_hmac( WP_REST_Request $request ) {
        $signature = $request->get_header( 'X-Orangepill-Signature' );
        if ( empty( $signature ) ) {
            return new WP_Error( self::ERR_UNAUTHORIZED, 'Missing X-Orangepill-Signature.', array( 'status' => 401 ) );
        }

        $settings = get_option( 'woocommerce_orangepill_settings', array() );
        $secret   = $settings['webhook_secret'] ?? '';
        if ( empty( $secret ) ) {
            OP_Logger::error( 'cart_bridge_no_secret', 'webhook_secret not configured — cannot authenticate cart bridge request.' );
            return new WP_Error( self::ERR_UNAUTHORIZED, 'Service not configured.', array( 'status' => 401 ) );
        }

        $clean_sig = preg_replace( '/^[a-zA-Z0-9]+=/i', '', $signature );

        $timestamp       = $request->get_header( 'X-Orangepill-Timestamp' );
        $signing_payload = $timestamp ? ( $timestamp . '.' . $request->get_body() ) : $request->get_body();

        $expected = hash_hmac( 'sha256', $signing_payload, $secret );

        if ( strlen( $clean_sig ) !== strlen( $expected ) || ! hash_equals( $expected, $clean_sig ) ) {
            return new WP_Error( self::ERR_UNAUTHORIZED, 'Invalid signature.', array( 'status' => 401 ) );
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Phase 1: Bootstrap
    // -------------------------------------------------------------------------

    /**
     * GET /wp-json/orangepill/v1/context
     *
     * Browser endpoint. Returns a bridge token the platform can use server-side.
     * Returns 204 when no active WC session exists (nothing to bridge).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_context( WP_REST_Request $request ) {
        if ( ! WC()->session || ! WC()->session->has_session() ) {
            return new WP_REST_Response( null, 204 );
        }

        $customer_id = WC()->session->get_customer_id();
        if ( empty( $customer_id ) ) {
            return new WP_REST_Response( null, 204 );
        }

        $token = bin2hex( random_bytes( 32 ) );

        set_transient(
            self::BRIDGE_TOKEN_PREFIX . $token,
            array(
                'customer_id' => $customer_id,
                'expires'     => time() + self::BRIDGE_TOKEN_TTL,
            ),
            self::BRIDGE_TOKEN_TTL
        );

        return new WP_REST_Response( array(
            'bridgeToken' => $token,
            'expiresIn'   => self::BRIDGE_TOKEN_TTL,
        ), 200 );
    }

    // -------------------------------------------------------------------------
    // Session injection
    // -------------------------------------------------------------------------

    /**
     * Load the WC session for the user identified by the bridge token.
     *
     * Reads customer_id from the stored transient, loads session data from WC DB,
     * injects it into WC()->session, then reloads WC()->cart from session.
     * The HttpOnly WC cookie never participates (server-to-server context).
     *
     * @param WP_REST_Request $request
     * @return string|WP_Error customer_id on success, WP_Error on failure
     */
    private static function load_session( WP_REST_Request $request ) {
        $bridge_token = $request->get_header( 'X-OP-Bridge-Token' );
        if ( empty( $bridge_token ) ) {
            return new WP_Error( self::ERR_SESSION_INVALID, 'Missing X-OP-Bridge-Token header.', array( 'status' => 401 ) );
        }

        $transient_key = self::BRIDGE_TOKEN_PREFIX . sanitize_key( $bridge_token );
        $transient     = get_transient( $transient_key );

        if ( ! $transient || empty( $transient['customer_id'] ) ) {
            return new WP_Error( self::ERR_SESSION_INVALID, 'Bridge token not found or expired.', array( 'status' => 401 ) );
        }

        $customer_id  = $transient['customer_id'];
        $session_data = WC()->session->get_session( $customer_id );

        if ( false === $session_data || null === $session_data ) {
            return new WP_Error( self::ERR_SESSION_INVALID, 'WC session not found for bridge token.', array( 'status' => 401 ) );
        }

        // Inject session data into the already-initialised (but empty) WC session object.
        WC()->session->set_customer_id( $customer_id );
        foreach ( $session_data as $key => $value ) {
            WC()->session->set( $key, $value );
        }

        WC()->cart->get_cart_from_session();
        WC()->cart->calculate_totals();

        return $customer_id;
    }

    // -------------------------------------------------------------------------
    // Phase 2: Cart operations
    // -------------------------------------------------------------------------

    /**
     * GET /wp-json/orangepill/v1/cart
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_get_cart( WP_REST_Request $request ) {
        $result = self::load_session( $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( self::build_cart_shape(), 200 );
    }

    /**
     * POST /wp-json/orangepill/v1/cart/items
     *
     * Body: { "productId": "123", "variationId": 0, "quantity": 1 }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_add_item( WP_REST_Request $request ) {
        $result = self::load_session( $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $params       = $request->get_json_params() ?: array();
        $product_id   = isset( $params['productId'] )   ? absint( $params['productId'] )   : 0;
        $variation_id = isset( $params['variationId'] ) ? absint( $params['variationId'] ) : 0;
        $quantity     = isset( $params['quantity'] )    ? (int) $params['quantity']         : 1;

        if ( $quantity < 1 ) {
            return new WP_Error( self::ERR_INVALID_QUANTITY, 'quantity must be >= 1.', array( 'status' => 400 ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( self::ERR_PRODUCT_NOT_FOUND, 'Product not found.', array( 'status' => 404 ) );
        }

        $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
        if ( false === $cart_item_key ) {
            return new WP_Error( self::ERR_INTERNAL, 'Failed to add item to cart.', array( 'status' => 500 ) );
        }

        WC()->cart->calculate_totals();
        WC()->session->save_data();

        return new WP_REST_Response( self::build_cart_shape(), 201 );
    }

    /**
     * PATCH /wp-json/orangepill/v1/cart/items/{lineId}
     *
     * Body: { "quantity": 3 }
     * quantity=0 → remove (ADR-101: mirrors updateQuantity(0) no-error spec).
     * quantity<0 → CART_INVALID_QUANTITY (400).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_update_item( WP_REST_Request $request ) {
        $result = self::load_session( $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $line_id  = $request->get_param( 'lineId' );
        $params   = $request->get_json_params() ?: array();
        $quantity = array_key_exists( 'quantity', $params ) ? (int) $params['quantity'] : null;

        if ( null === $quantity || $quantity < 0 ) {
            return new WP_Error( self::ERR_INVALID_QUANTITY, 'quantity must be a non-negative integer.', array( 'status' => 400 ) );
        }

        $cart = WC()->cart->get_cart();
        if ( ! isset( $cart[ $line_id ] ) ) {
            return new WP_Error( self::ERR_ITEM_NOT_FOUND, 'Cart item not found.', array( 'status' => 404 ) );
        }

        if ( 0 === $quantity ) {
            WC()->cart->remove_cart_item( $line_id );
        } else {
            WC()->cart->set_quantity( $line_id, $quantity );
        }

        WC()->cart->calculate_totals();
        WC()->session->save_data();

        return new WP_REST_Response( self::build_cart_shape(), 200 );
    }

    /**
     * DELETE /wp-json/orangepill/v1/cart/items/{lineId}
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_remove_item( WP_REST_Request $request ) {
        $result = self::load_session( $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $line_id = $request->get_param( 'lineId' );
        $cart    = WC()->cart->get_cart();

        if ( ! isset( $cart[ $line_id ] ) ) {
            return new WP_Error( self::ERR_ITEM_NOT_FOUND, 'Cart item not found.', array( 'status' => 404 ) );
        }

        WC()->cart->remove_cart_item( $line_id );
        WC()->cart->calculate_totals();
        WC()->session->save_data();

        return new WP_REST_Response( self::build_cart_shape(), 200 );
    }

    /**
     * DELETE /wp-json/orangepill/v1/cart
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_clear_cart( WP_REST_Request $request ) {
        $result = self::load_session( $request );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        WC()->cart->empty_cart();
        WC()->session->save_data();

        return new WP_REST_Response( self::build_cart_shape(), 200 );
    }

    // -------------------------------------------------------------------------
    // Cart shape
    // -------------------------------------------------------------------------

    /**
     * Build the canonical Cart shape from current WC cart state.
     *
     * Matches PR #1409 CommerceCartCapability Cart / CartItem interfaces:
     *   items[], itemCount, subtotal, total, currency, cartHash
     *
     * lineId   = WC cart_item_key (MD5 of product_id + variation_id + variation_data)
     * productId = string (WC native product ID — no translation layer, Finding C)
     *
     * @return array
     */
    private static function build_cart_shape() {
        $cart     = WC()->cart;
        $currency = get_woocommerce_currency();
        $items    = array();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( ! ( $product instanceof WC_Product ) ) {
                continue;
            }

            $unit_price = (float) $product->get_price();
            $quantity   = (int) $cart_item['quantity'];

            $image_url     = '';
            $attachment_id = $product->get_image_id();
            if ( $attachment_id ) {
                $image_src = wp_get_attachment_image_src( $attachment_id, 'woocommerce_thumbnail' );
                if ( $image_src ) {
                    $image_url = (string) $image_src[0];
                }
            }

            $items[] = array(
                'lineId'      => $cart_item_key,
                'productId'   => (string) $cart_item['product_id'],
                'variationId' => (int) ( $cart_item['variation_id'] ?? 0 ),
                'name'        => $product->get_name(),
                'quantity'    => $quantity,
                'unitPrice'   => $unit_price,
                'lineTotal'   => round( $unit_price * $quantity, 2 ),
                'currency'    => $currency,
                'sku'         => $product->get_sku() ?: '',
                'imageUrl'    => $image_url,
            );
        }

        return array(
            'items'     => $items,
            'itemCount' => (int) $cart->get_cart_contents_count(),
            'subtotal'  => (float) $cart->get_subtotal(),
            'total'     => (float) $cart->get_total( 'edit' ),
            'currency'  => $currency,
            'cartHash'  => $cart->get_cart_hash(),
        );
    }
}
