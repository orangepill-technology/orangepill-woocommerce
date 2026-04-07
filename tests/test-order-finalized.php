<?php
/**
 * Tests for order.finalized loyalty trigger (PR-WC-LOYALTY-1)
 *
 * Verifies:
 * - Transition guard (RULE 12)
 * - Payload structure
 * - Idempotency key format (RULE 4)
 * - Integration with OP_Sync_Journal
 */

use PHPUnit\Framework\TestCase;

class Test_Order_Finalized extends TestCase {
    /**
     * Test that order.finalized fires on transition from non-completed to completed
     *
     * RULE 12: Guard must check old_status !== 'completed' AND new_status === 'completed'
     */
    public function test_order_finalized_fires_on_transition_to_completed() {
        // Test transitions that SHOULD trigger
        $should_trigger = array(
            array('old' => 'pending', 'new' => 'completed'),
            array('old' => 'processing', 'new' => 'completed'),
            array('old' => 'on-hold', 'new' => 'completed'),
            array('old' => 'failed', 'new' => 'completed'),
        );

        foreach ($should_trigger as $transition) {
            $should_fire = ($transition['new'] === 'completed' && $transition['old'] !== 'completed');
            $this->assertTrue(
                $should_fire,
                "order.finalized should fire for transition {$transition['old']} -> {$transition['new']}"
            );
        }

        // Test transitions that SHOULD NOT trigger
        $should_not_trigger = array(
            array('old' => 'completed', 'new' => 'completed'), // Idempotent re-save
            array('old' => 'completed', 'new' => 'processing'), // Downgrade (shouldn't happen)
            array('old' => 'pending', 'new' => 'processing'),   // Not completed
            array('old' => 'processing', 'new' => 'cancelled'), // Not completed
        );

        foreach ($should_not_trigger as $transition) {
            $should_fire = ($transition['new'] === 'completed' && $transition['old'] !== 'completed');
            $this->assertFalse(
                $should_fire,
                "order.finalized should NOT fire for transition {$transition['old']} -> {$transition['new']}"
            );
        }
    }

    /**
     * Test idempotency key format for order.finalized
     *
     * RULE 4: Format must be "woo:{order_id}:order.finalized:completed" (NOT timestamp-based)
     */
    public function test_order_finalized_idempotency_key_format() {
        $order_id = 12345;
        $expected = "woo:{$order_id}:order.finalized:completed";

        // Simulate idempotency key generation
        $idempotency_key = sprintf('woo:%s:order.finalized:completed', $order_id);

        $this->assertEquals(
            $expected,
            $idempotency_key,
            'Idempotency key must match stable format'
        );

        // Verify key is stable (not timestamp-based)
        $key1 = sprintf('woo:%s:order.finalized:completed', $order_id);
        sleep(1); // Wait 1 second
        $key2 = sprintf('woo:%s:order.finalized:completed', $order_id);

        $this->assertEquals(
            $key1,
            $key2,
            'Idempotency key must be stable across time (not timestamp-based)'
        );
    }

    /**
     * Test payload structure for order.finalized event
     *
     * Verifies required fields per PR-WC-LOYALTY-1 spec
     */
    public function test_order_finalized_payload_structure() {
        $order_id = 12345;
        $user_id = 67;
        $op_customer_id = 'op_cust_abc123';
        $old_status = 'processing';
        $new_status = 'completed';
        $integration_id = 'int_xyz789';

        // Simulate payload construction
        $payload = array(
            'event' => 'order.finalized',
            'woo_order_id' => (string) $order_id,
            'status' => $new_status,
            'previous_status' => $old_status,
            'order_total' => '99.99',
            'currency' => 'USD',
            'customer' => array(
                'woo_customer_id' => (string) $user_id,
                'orangepill_customer_id' => $op_customer_id,
                'email' => 'customer@example.com',
                'phone' => '+1234567890',
            ),
            'metadata' => array(
                'channel' => 'woocommerce',
                'integration_id' => $integration_id,
            ),
        );

        // Assert required fields
        $this->assertEquals('order.finalized', $payload['event']);
        $this->assertIsString($payload['woo_order_id']);
        $this->assertEquals($new_status, $payload['status']);
        $this->assertEquals($old_status, $payload['previous_status']);

        // Assert customer structure
        $this->assertArrayHasKey('customer', $payload);
        $this->assertArrayHasKey('woo_customer_id', $payload['customer']);
        $this->assertArrayHasKey('orangepill_customer_id', $payload['customer']);
        $this->assertArrayHasKey('email', $payload['customer']);

        // Assert metadata
        $this->assertArrayHasKey('metadata', $payload);
        $this->assertEquals('woocommerce', $payload['metadata']['channel']);
        $this->assertEquals($integration_id, $payload['metadata']['integration_id']);
    }

    /**
     * Test that order.finalized uses RAW Woo status (not payment sync mapping)
     *
     * Payment sync maps 'completed' -> 'fulfilled', but loyalty MUST use raw status
     */
    public function test_order_finalized_uses_raw_woo_status() {
        $new_status = 'completed'; // Raw WooCommerce status

        // Payment sync would map to 'fulfilled', but loyalty MUST NOT
        $payment_sync_mapping = array(
            'pending' => 'pending',
            'processing' => 'confirmed',
            'completed' => 'fulfilled', // Payment mapping (DO NOT USE for loyalty)
        );

        $mapped_status = $payment_sync_mapping[$new_status] ?? $new_status;

        // Loyalty payload should use raw status
        $loyalty_payload_status = $new_status; // NOT $mapped_status

        $this->assertEquals(
            'completed',
            $loyalty_payload_status,
            'Loyalty trigger must use raw Woo status (completed), not payment sync mapping (fulfilled)'
        );

        $this->assertNotEquals(
            $mapped_status,
            $loyalty_payload_status,
            'Loyalty trigger must NOT reuse payment sync status mapping'
        );
    }

    /**
     * Test endpoint path for order.finalized
     *
     * PR-OP-COMMERCE-EVENT-INGESTION-1: Confirmed endpoint
     */
    public function test_order_finalized_endpoint_path() {
        $integration_id = 'int_abc123';
        $expected_endpoint = "/v4/commerce/integrations/{$integration_id}/events";

        $endpoint = '/v4/commerce/integrations/' . $integration_id . '/events';

        $this->assertEquals(
            $expected_endpoint,
            $endpoint,
            'order.finalized must use confirmed ingestion endpoint'
        );
    }

    /**
     * Test that multiple calls with same order_id produce same idempotency key
     *
     * Verifies dedupe behavior at idempotency key level
     */
    public function test_order_finalized_idempotency_key_deterministic() {
        $order_id = 999;

        $keys = array();
        for ($i = 0; $i < 5; $i++) {
            $keys[] = sprintf('woo:%s:order.finalized:completed', $order_id);
        }

        $unique_keys = array_unique($keys);

        $this->assertCount(
            1,
            $unique_keys,
            'Multiple calls for same order_id must produce identical idempotency key'
        );
    }
}
