<?php
/**
 * Orangepill Order Metabox (PR-WC-WEBCHAT-CONVERSATION-LINKING-V1)
 *
 * Renders Orangepill order metadata on the WooCommerce order edit screen:
 *   - Canonical customer ID, session, intent, payment status (existing)
 *   - Conversation linkage and platform-side attribution status (new)
 *
 * Attribution status vocab (from platform, per ADR-100):
 *   verified           — conversation belongs to the same canonical customer as the order
 *   rejected           — attribution failed (see reason field)
 *   none               — no conversation ID was sent with this order
 *
 * Rejection reasons (per WCCONVLINK-007 canonical vocabulary):
 *   conversation_not_found  — conversation UUID unknown to platform
 *   customer_mismatch       — conversation's customer ≠ order's customer
 *   conversation_anonymous  — conversation had no identity binding (PR #1373 anonymous flow)
 *
 * RULE 6: attribution failures are visible to operators — no silent black holes.
 * RULE 7: no conversion analytics, no funnel aggregation — operational attribution only.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OP_Order_Metabox {

    /**
     * Register the metabox with WordPress.
     *
     * Called from orangepill_wc_init(). Hooks into both classic order edit
     * (post.php) and the WC HPOS order edit screen.
     */
    public function register() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
    }

    /**
     * Register add_meta_box for WC order screens (classic + HPOS).
     */
    public function add_meta_box() {
        $screens = array( 'shop_order' );

        // WooCommerce HPOS uses a separate screen identifier.
        if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }

        foreach ( $screens as $screen ) {
            add_meta_box(
                'orangepill-order-meta',
                __( 'Orangepill', 'orangepill-wc' ),
                array( $this, 'render' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the metabox.
     *
     * @param WP_Post|WC_Order $post_or_order
     */
    public function render( $post_or_order ) {
        $order = ( $post_or_order instanceof WC_Order )
            ? $post_or_order
            : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            return;
        }

        $order_id = $order->get_id();

        $customer_id    = $order->get_meta( '_orangepill_customer_id',          true );
        $session_id     = $order->get_meta( '_orangepill_session_id',           true );
        $intent_id      = $order->get_meta( '_orangepill_intent_id',            true );
        $payment_status = $order->get_meta( '_orangepill_payment_status',       true );
        $channel        = $order->get_meta( '_orangepill_channel',              true );
        $wallet_applied = $order->get_meta( '_orangepill_wallet_applied',       true );

        // PR-WC-WEBCHAT-CONVERSATION-LINKING-V1
        $conversation_id    = $order->get_meta( '_orangepill_conversation_id',                true );
        $attribution_status = $order->get_meta( '_orangepill_conversation_attribution_status', true );
        $attribution_reason = $order->get_meta( '_orangepill_conversation_attribution_reason', true );

        ?>
        <div class="orangepill-order-meta">
            <style>
                .orangepill-order-meta table { width: 100%; border-collapse: collapse; font-size: 12px; }
                .orangepill-order-meta td { padding: 3px 0; vertical-align: top; }
                .orangepill-order-meta td:first-child { color: #555; width: 45%; }
                .orangepill-order-meta code { font-size: 11px; word-break: break-all; }
                .op-attr-verified { color: #00a32a; font-weight: 600; }
                .op-attr-rejected { color: #d63638; }
                .op-attr-none     { color: #72777c; }
            </style>
            <table>
                <?php if ( $customer_id ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Customer ID', 'orangepill-wc' ); ?></td>
                    <td><code><?php echo esc_html( $customer_id ); ?></code></td>
                </tr>
                <?php endif; ?>

                <?php if ( $channel ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Channel', 'orangepill-wc' ); ?></td>
                    <td><?php echo esc_html( $channel ); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ( $payment_status ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Payment', 'orangepill-wc' ); ?></td>
                    <td><?php echo esc_html( $payment_status ); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ( $wallet_applied ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Wallet applied', 'orangepill-wc' ); ?></td>
                    <td><?php echo esc_html( $wallet_applied ); ?></td>
                </tr>
                <?php endif; ?>

                <?php if ( $session_id ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Session', 'orangepill-wc' ); ?></td>
                    <td><code><?php echo esc_html( $session_id ); ?></code></td>
                </tr>
                <?php endif; ?>

                <?php if ( $intent_id ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Intent', 'orangepill-wc' ); ?></td>
                    <td><code><?php echo esc_html( $intent_id ); ?></code></td>
                </tr>
                <?php endif; ?>

                <?php if ( $conversation_id ) : ?>
                <tr>
                    <td><?php esc_html_e( 'Conversation', 'orangepill-wc' ); ?></td>
                    <td>
                        <code><?php echo esc_html( $conversation_id ); ?></code>
                        <?php if ( $attribution_status === 'verified' ) : ?>
                            <br><span class="op-attr-verified">
                                <?php esc_html_e( '(Linked)', 'orangepill-wc' ); ?>
                            </span>
                        <?php elseif ( $attribution_status === 'rejected' ) : ?>
                            <br><span class="op-attr-rejected">
                                <?php
                                // Canonical rejection reasons per WCCONVLINK-007:
                                //   conversation_not_found | customer_mismatch | conversation_anonymous
                                $display_reason = $attribution_reason ?: 'rejected';
                                echo esc_html(
                                    sprintf(
                                        /* translators: %s: rejection reason */
                                        __( '(Not linked: %s)', 'orangepill-wc' ),
                                        $display_reason
                                    )
                                );
                                ?>
                            </span>
                        <?php elseif ( $attribution_status === 'none' ) : ?>
                            <br><span class="op-attr-none">
                                <?php esc_html_e( '(Not linked)', 'orangepill-wc' ); ?>
                            </span>
                        <?php elseif ( empty( $attribution_status ) ) : ?>
                            <br><span class="op-attr-none">
                                <?php esc_html_e( '(Pending verification)', 'orangepill-wc' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }
}
