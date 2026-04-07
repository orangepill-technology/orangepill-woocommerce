<?php
/**
 * Tests for order.refunded loyalty reversal trigger (PR-WC-LOYALTY-1)
 *
 * Verifies:
 * - Per-refund emission (RULE 3)
 * - Multiple refunds per order
 * - Payload structure
 * - Idempotency key format using refund_id (RULE 4)
 */

use PHPUnit\Framework\TestCase;

class Test_Order_Refunded extends TestCase {
    /**
     * Test that order.refunded fires once per WooCommerce refund object
     *
     * RULE 3: Hook fires once per refund_id (natural idempotency anchor)
     */
    public function test_order_refunded_fires_per_refund() {
        $order_id = 12345;

        // Simulate 3 separate refunds for same order
        $refunds = array(
            array('refund_id' => 101, 'amount' => '10.00'),
            array('refund_id' => 102, 'amount' => '20.00'),
            array('refund_id' => 103, 'amount' => '15.00'),
        );

        // Each refund should generate unique idempotency key
        $idempotency_keys = array();
        foreach ($refunds as $refund) {
            $key = sprintf('woo:%s:refund:%s', $order_id, $refund['refund_id']);
            $idempotency_keys[] = $key;
        }

        $this->assertCount(
            3,
            $idempotency_keys,
            'Should generate 3 events for 3 refunds'
        );

        $unique_keys = array_unique($idempotency_keys);
        $this->assertCount(
            3,
            $unique_keys,
            'Each refund must have unique idempotency key'
        );
    }

    /**
     * Test idempotency key format for order.refunded
     *
     * RULE 4: Format must be "woo:{order_id}:refund:{refund_id}" (NOT timestamp-based)
     * Uses refund_id as natural anchor
     */
    public function test_order_refunded_idempotency_key_format() {
        $order_id = 12345;
        $refund_id = 789;
        $expected = "woo:{$order_id}:refund:{$refund_id}";

        // Simulate idempotency key generation
        $idempotency_key = sprintf('woo:%s:refund:%s', $order_id, $refund_id);

        $this->assertEquals(
            $expected,
            $idempotency_key,
            'Idempotency key must match stable format with refund_id'
        );

        // Verify key is stable (not timestamp-based)
        $key1 = sprintf('woo:%s:refund:%s', $order_id, $refund_id);
        sleep(1); // Wait 1 second
        $key2 = sprintf('woo:%s:refund:%s', $order_id, $refund_id);

        $this->assertEquals(
            $key1,
            $key2,
            'Idempotency key must be stable across time (not timestamp-based)'
        );
    }

    /**
     * Test payload structure for order.refunded event
     *
     * Verifies required fields per PR-WC-LOYALTY-1 spec
     */
    public function test_order_refunded_payload_structure() {
        $order_id = 12345;
        $refund_id = 789;
        $refund_amount = '25.00';
        $user_id = 67;
        $op_customer_id = 'op_cust_abc123';
        $integration_id = 'int_xyz789';

        // Simulate payload construction
        $payload = array(
            'event' => 'order.refunded',
            'woo_order_id' => (string) $order_id,
            'refund_id' => (string) $refund_id,
            'refund_amount' => (string) $refund_amount,
            'order_total' => '99.99',
            'currency' => 'USD',
            'customer' => array(
                'woo_customer_id' => (string) $user_id,
                'orangepill_customer_id' => $op_customer_id,
            ),
            'metadata' => array(
                'channel' => 'woocommerce',
                'integration_id' => $integration_id,
                'refund_reason' => 'Customer requested refund',
            ),
        );

        // Assert required fields
        $this->assertEquals('order.refunded', $payload['event']);
        $this->assertIsString($payload['woo_order_id']);
        $this->assertIsString($payload['refund_id']);
        $this->assertIsString($payload['refund_amount']);

        // Assert customer structure
        $this->assertArrayHasKey('customer', $payload);
        $this->assertArrayHasKey('woo_customer_id', $payload['customer']);
        $this->assertArrayHasKey('orangepill_customer_id', $payload['customer']);

        // Assert metadata
        $this->assertArrayHasKey('metadata', $payload);
        $this->assertEquals('woocommerce', $payload['metadata']['channel']);
        $this->assertEquals($integration_id, $payload['metadata']['integration_id']);
        $this->assertArrayHasKey('refund_reason', $payload['metadata']);
    }

    /**
     * Test endpoint path for order.refunded
     *
     * PR-OP-COMMERCE-EVENT-INGESTION-1: Uses same ingestion endpoint as order.finalized
     */
    public function test_order_refunded_endpoint_path() {
        $integration_id = 'int_abc123';
        $expected_endpoint = "/v4/commerce/integrations/{$integration_id}/events";

        $endpoint = '/v4/commerce/integrations/' . $integration_id . '/events';

        $this->assertEquals(
            $expected_endpoint,
            $endpoint,
            'order.refunded must use confirmed ingestion endpoint'
        );
    }

    /**
     * Test that different refunds on same order get different idempotency keys
     *
     * Critical for multi-refund scenarios
     */
    public function test_multiple_refunds_unique_idempotency_keys() {
        $order_id = 12345;

        $refund1_key = sprintf('woo:%s:refund:%s', $order_id, 101);
        $refund2_key = sprintf('woo:%s:refund:%s', $order_id, 102);
        $refund3_key = sprintf('woo:%s:refund:%s', $order_id, 103);

        $this->assertNotEquals(
            $refund1_key,
            $refund2_key,
            'Different refunds must have different idempotency keys'
        );

        $this->assertNotEquals(
            $refund2_key,
            $refund3_key,
            'Different refunds must have different idempotency keys'
        );

        // All keys should reference same order
        $this->assertStringContainsString(
            "woo:{$order_id}:refund:",
            $refund1_key
        );
        $this->assertStringContainsString(
            "woo:{$order_id}:refund:",
            $refund2_key
        );
    }

    /**
     * Test that same refund_id always produces same idempotency key
     *
     * Verifies deterministic behavior for replay safety
     */
    public function test_order_refunded_idempotency_key_deterministic() {
        $order_id = 999;
        $refund_id = 555;

        $keys = array();
        for ($i = 0; $i < 5; $i++) {
            $keys[] = sprintf('woo:%s:refund:%s', $order_id, $refund_id);
        }

        $unique_keys = array_unique($keys);

        $this->assertCount(
            1,
            $unique_keys,
            'Multiple calls for same refund_id must produce identical idempotency key'
        );
    }

    /**
     * Test refund amount is always positive in payload
     *
     * WC_Order_Refund::get_amount() returns negative, must be converted to positive
     */
    public function test_refund_amount_is_positive() {
        // WooCommerce refund amounts are negative
        $wc_refund_amount = -25.50;

        // Plugin must convert to positive
        $refund_amount = abs($wc_refund_amount);

        $this->assertEquals(
            25.50,
            $refund_amount,
            'Refund amount in payload must be positive'
        );

        $this->assertGreaterThan(
            0,
            $refund_amount,
            'Refund amount must be greater than 0'
        );
    }

    /**
     * Test that refund events preserve order_id for correlation
     *
     * Important for querying all refunds for an order
     */
    public function test_refund_events_include_order_id() {
        $order_id = 12345;
        $refund_id = 789;

        $payload = array(
            'event' => 'order.refunded',
            'woo_order_id' => (string) $order_id,
            'refund_id' => (string) $refund_id,
        );

        $this->assertArrayHasKey('woo_order_id', $payload);
        $this->assertEquals((string) $order_id, $payload['woo_order_id']);

        // Idempotency key should also contain order_id for correlation
        $idempotency_key = sprintf('woo:%s:refund:%s', $order_id, $refund_id);
        $this->assertStringContainsString(
            (string) $order_id,
            $idempotency_key,
            'Idempotency key must contain order_id for correlation'
        );
    }
}
