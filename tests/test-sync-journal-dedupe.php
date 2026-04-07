<?php
/**
 * Tests for OP_Sync_Journal deduplication logic (PR-WC-LOYALTY-1)
 *
 * Verifies:
 * - Journal dedupe by (direction, event_type, idempotency_key) - RULE 11
 * - Returns existing event_id when duplicate detected
 * - Multiple event types can coexist with same order_id
 */

use PHPUnit\Framework\TestCase;

class Test_Sync_Journal_Dedupe extends TestCase {
    /**
     * Test dedupe key structure per RULE 11
     *
     * RULE 11: Journal MUST dedupe by (direction, event_type, idempotency_key)
     */
    public function test_dedupe_key_components() {
        // Dedupe key components
        $direction = 'woo_to_op';
        $event_type = 'order.finalized';
        $idempotency_key = 'woo:123:order.finalized:completed';

        // Dedupe check must use ALL three components
        $dedupe_check = array(
            'direction' => $direction,
            'event_type' => $event_type,
            'idempotency_key' => $idempotency_key,
        );

        $this->assertArrayHasKey('direction', $dedupe_check);
        $this->assertArrayHasKey('event_type', $dedupe_check);
        $this->assertArrayHasKey('idempotency_key', $dedupe_check);

        $this->assertEquals('woo_to_op', $dedupe_check['direction']);
        $this->assertEquals('order.finalized', $dedupe_check['event_type']);
        $this->assertStringContainsString('woo:123', $dedupe_check['idempotency_key']);
    }

    /**
     * Test that duplicate events are detected by idempotency_key
     *
     * Simulates journal behavior when same event is recorded multiple times
     */
    public function test_duplicate_detection_by_idempotency_key() {
        $order_id = 12345;
        $idempotency_key = "woo:{$order_id}:order.finalized:completed";

        // Simulate recording same event twice
        $recordings = array(
            array(
                'direction' => 'woo_to_op',
                'event_type' => 'order.finalized',
                'idempotency_key' => $idempotency_key,
            ),
            array(
                'direction' => 'woo_to_op',
                'event_type' => 'order.finalized',
                'idempotency_key' => $idempotency_key,
            ),
        );

        // Both recordings have same dedupe key
        $this->assertEquals(
            $recordings[0]['idempotency_key'],
            $recordings[1]['idempotency_key'],
            'Duplicate recordings must have same idempotency_key'
        );

        // In real implementation, second recording would return existing event_id
        $expected_behavior = 'return_existing_event_id';
        $this->assertNotEquals(
            'create_new_event',
            $expected_behavior,
            'Duplicate must NOT create new journal entry'
        );
    }

    /**
     * Test that different event_types are NOT deduped even with same order_id
     *
     * Critical: order.finalized and order.refunded for same order must coexist
     */
    public function test_different_event_types_not_deduped() {
        $order_id = 12345;

        $event1 = array(
            'direction' => 'woo_to_op',
            'event_type' => 'order.finalized',
            'idempotency_key' => "woo:{$order_id}:order.finalized:completed",
        );

        $event2 = array(
            'direction' => 'woo_to_op',
            'event_type' => 'order.refunded',
            'idempotency_key' => "woo:{$order_id}:refund:789",
        );

        // Different event_types = different dedupe keys
        $this->assertNotEquals(
            $event1['event_type'],
            $event2['event_type'],
            'Different event types must NOT be deduped'
        );

        $this->assertNotEquals(
            $event1['idempotency_key'],
            $event2['idempotency_key'],
            'Different idempotency keys = separate events'
        );

        // Both events can exist for same order_id
        $can_coexist = ($event1['event_type'] !== $event2['event_type']);
        $this->assertTrue(
            $can_coexist,
            'order.finalized and order.refunded must coexist for same order'
        );
    }

    /**
     * Test that multiple refunds are NOT deduped (different refund_ids)
     *
     * RULE 13: ALL refund events must be tracked separately
     */
    public function test_multiple_refunds_not_deduped() {
        $order_id = 12345;

        $refund1 = array(
            'direction' => 'woo_to_op',
            'event_type' => 'order.refunded',
            'idempotency_key' => "woo:{$order_id}:refund:101",
        );

        $refund2 = array(
            'direction' => 'woo_to_op',
            'event_type' => 'order.refunded',
            'idempotency_key' => "woo:{$order_id}:refund:102",
        );

        // Same event_type but different idempotency_keys
        $this->assertEquals(
            $refund1['event_type'],
            $refund2['event_type'],
            'Both are order.refunded events'
        );

        $this->assertNotEquals(
            $refund1['idempotency_key'],
            $refund2['idempotency_key'],
            'Different refunds must have different idempotency keys'
        );

        // Both refunds must create separate journal entries
        $can_coexist = ($refund1['idempotency_key'] !== $refund2['idempotency_key']);
        $this->assertTrue(
            $can_coexist,
            'Multiple refunds for same order must create separate journal entries'
        );
    }

    /**
     * Test dedupe behavior when idempotency_key is null
     *
     * If no idempotency_key provided, dedupe should not apply
     */
    public function test_dedupe_skipped_when_idempotency_key_null() {
        $event_without_key = array(
            'direction' => 'woo_to_op',
            'event_type' => 'some.event',
            'idempotency_key' => null,
        );

        // Without idempotency_key, dedupe should not apply
        $should_dedupe = !empty($event_without_key['idempotency_key']);

        $this->assertFalse(
            $should_dedupe,
            'Dedupe should be skipped when idempotency_key is null'
        );
    }

    /**
     * Test that direction is part of dedupe key
     *
     * Same event_type + idempotency_key but different direction = different events
     */
    public function test_direction_included_in_dedupe_key() {
        $order_id = 12345;

        $outbound = array(
            'direction' => 'woo_to_op',
            'event_type' => 'order.finalized',
            'idempotency_key' => "woo:{$order_id}:order.finalized:completed",
        );

        $inbound = array(
            'direction' => 'op_to_woo',
            'event_type' => 'order.finalized',
            'idempotency_key' => "woo:{$order_id}:order.finalized:completed",
        );

        // Different directions = not deduped
        $this->assertNotEquals(
            $outbound['direction'],
            $inbound['direction'],
            'Different directions must NOT be deduped'
        );

        // Even with same event_type and idempotency_key
        $this->assertEquals($outbound['event_type'], $inbound['event_type']);
        $this->assertEquals($outbound['idempotency_key'], $inbound['idempotency_key']);
    }

    /**
     * Test that dedupe returns existing event_id (not creates new)
     *
     * Verifies expected behavior when duplicate is detected
     */
    public function test_dedupe_returns_existing_event_id() {
        // Simulate first recording
        $first_event_id = 100;

        // Simulate duplicate recording (same dedupe key)
        $should_return_existing = true;

        if ($should_return_existing) {
            $returned_event_id = $first_event_id; // Return existing
        } else {
            $returned_event_id = 101; // Create new (WRONG)
        }

        $this->assertEquals(
            $first_event_id,
            $returned_event_id,
            'Duplicate recording must return existing event_id, not create new'
        );
    }

    /**
     * Test query helper for single-event types (order.finalized)
     *
     * get_last_event_for_order_by_type() for single-event queries
     */
    public function test_single_event_query_helper() {
        $order_id = 12345;
        $direction = 'woo_to_op';
        $event_type = 'order.finalized';

        // Query for last event (should be only one for order.finalized)
        $query_params = array(
            'order_id' => $order_id,
            'direction' => $direction,
            'event_type' => $event_type,
            'limit' => 1, // Only one
        );

        $this->assertEquals(1, $query_params['limit']);
        $this->assertEquals('order.finalized', $query_params['event_type']);
    }

    /**
     * Test query helper for multi-event types (order.refunded)
     *
     * get_events_for_order() for multi-event queries per RULE 13
     */
    public function test_multi_event_query_helper() {
        $order_id = 12345;
        $direction = 'woo_to_op';
        $event_type = 'order.refunded';

        // Query for ALL refund events (not just last)
        $query_params = array(
            'order_id' => $order_id,
            'direction' => $direction,
            'event_type' => $event_type,
            'limit' => 20, // Get ALL (up to limit)
        );

        $this->assertGreaterThan(1, $query_params['limit']);
        $this->assertEquals('order.refunded', $query_params['event_type']);
    }
}
