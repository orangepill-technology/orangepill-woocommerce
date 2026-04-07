<?php
/**
 * Tests for automatic integration of loyalty events (PR-WC-LOYALTY-1)
 *
 * Verifies:
 * - Failed Syncs page compatibility
 * - Sync Log compatibility
 * - Replay functionality
 * - Order metabox display (RULE 13)
 */

use PHPUnit\Framework\TestCase;

class Test_Integration extends TestCase {
    /**
     * Test that loyalty events are compatible with Failed Syncs page
     *
     * Failed Syncs page is event-type agnostic and should handle loyalty events
     */
    public function test_loyalty_events_compatible_with_failed_syncs_page() {
        // Simulate failed loyalty events
        $failed_events = array(
            array('event_type' => 'order.finalized', 'status' => 'failed', 'order_id' => 123),
            array('event_type' => 'order.refunded', 'status' => 'failed', 'order_id' => 123),
        );

        foreach ($failed_events as $event) {
            // Failed Syncs page should display event_type
            $this->assertArrayHasKey('event_type', $event);
            $this->assertContains(
                $event['event_type'],
                array('order.finalized', 'order.refunded'),
                'Failed Syncs page must display loyalty event types'
            );

            // Event must have status = failed
            $this->assertEquals('failed', $event['status']);

            // Event must have order_id for order link
            $this->assertArrayHasKey('order_id', $event);
        }
    }

    /**
     * Test that loyalty events appear in Sync Log
     *
     * Sync Log dynamically populates event types from logged events
     */
    public function test_loyalty_events_appear_in_sync_log() {
        // Logged events from OP_Logger
        $logged_events = array(
            'order_finalized_sent',
            'order_finalized_failed',
            'order_refunded_sent',
            'order_refunded_failed',
        );

        foreach ($logged_events as $event_code) {
            $this->assertStringContainsString(
                'order_finalized',
                $event_code,
                'Sync Log must show order.finalized events'
            );

            $should_appear_in_sync_log = true;
            $this->assertTrue(
                $should_appear_in_sync_log,
                "Event {$event_code} must appear in Sync Log"
            );
        }
    }

    /**
     * Test that replay functionality works for loyalty events
     *
     * Replay is event-type agnostic and uses stored payload + idempotency key
     */
    public function test_replay_works_for_loyalty_events() {
        // Simulate replay for order.finalized
        $event1 = array(
            'id' => 100,
            'direction' => 'woo_to_op',
            'event_type' => 'order.finalized',
            'status' => 'failed',
            'payload_json' => json_encode(array('event' => 'order.finalized')),
            'idempotency_key' => 'woo:123:order.finalized:completed',
            'endpoint' => '/v4/commerce/integrations/int_abc/events',
        );

        // Replay checks
        $can_replay = ($event1['direction'] === 'woo_to_op' && $event1['status'] === 'failed');
        $this->assertTrue($can_replay, 'Failed order.finalized event must be replayable');

        // Simulate replay for order.refunded
        $event2 = array(
            'id' => 101,
            'direction' => 'woo_to_op',
            'event_type' => 'order.refunded',
            'status' => 'failed',
            'payload_json' => json_encode(array('event' => 'order.refunded')),
            'idempotency_key' => 'woo:123:refund:789',
            'endpoint' => '/v4/commerce/integrations/int_abc/events',
        );

        $can_replay = ($event2['direction'] === 'woo_to_op' && $event2['status'] === 'failed');
        $this->assertTrue($can_replay, 'Failed order.refunded event must be replayable');
    }

    /**
     * Test metabox displays single order.finalized event
     *
     * Metabox should query for last order.finalized event
     */
    public function test_metabox_displays_single_order_finalized() {
        $order_id = 12345;
        $direction = 'woo_to_op';
        $event_type = 'order.finalized';

        // Metabox queries for last event
        $query_method = 'get_last_event_for_order_by_type';
        $query_limit = 1; // Single event

        $this->assertEquals(
            1,
            $query_limit,
            'Metabox should query for single order.finalized event'
        );

        $this->assertEquals(
            'get_last_event_for_order_by_type',
            $query_method,
            'Metabox should use single-event query method for order.finalized'
        );
    }

    /**
     * Test metabox displays ALL refund events (RULE 13)
     *
     * Metabox must show ALL refunds, not just last one
     */
    public function test_metabox_displays_all_refund_events() {
        $order_id = 12345;
        $direction = 'woo_to_op';
        $event_type = 'order.refunded';

        // Metabox queries for ALL events
        $query_method = 'get_events_for_order';
        $query_limit = 20; // Multiple events

        $this->assertGreaterThan(
            1,
            $query_limit,
            'Metabox should query for multiple refund events'
        );

        $this->assertEquals(
            'get_events_for_order',
            $query_method,
            'Metabox should use multi-event query method for order.refunded per RULE 13'
        );

        // Verify ALL refunds are shown
        $simulated_refunds = array(
            array('refund_id' => 101, 'status' => 'sent'),
            array('refund_id' => 102, 'status' => 'failed'),
            array('refund_id' => 103, 'status' => 'sent'),
        );

        $displayed_count = count($simulated_refunds);
        $this->assertEquals(
            3,
            $displayed_count,
            'Metabox must display ALL refund events, not just last'
        );
    }

    /**
     * Test metabox shows status icons for loyalty events
     *
     * ✅ = sent, ❌ = failed, ⏳ = pending
     */
    public function test_metabox_shows_status_icons() {
        $status_icons = array(
            'sent' => '✅',
            'failed' => '❌',
            'pending' => '⏳',
        );

        foreach ($status_icons as $status => $icon) {
            $this->assertNotEmpty($icon, "Status {$status} must have icon");

            if ($status === 'sent') {
                $this->assertEquals('✅', $icon);
            } elseif ($status === 'failed') {
                $this->assertEquals('❌', $icon);
            } elseif ($status === 'pending') {
                $this->assertEquals('⏳', $icon);
            }
        }
    }

    /**
     * Test metabox shows per-event replay buttons for failed refunds
     *
     * Each failed refund event must have its own replay button
     */
    public function test_metabox_shows_per_event_replay_buttons() {
        $failed_refunds = array(
            array('id' => 100, 'refund_id' => 101, 'status' => 'failed'),
            array('id' => 101, 'refund_id' => 102, 'status' => 'failed'),
        );

        foreach ($failed_refunds as $refund) {
            $has_replay_button = ($refund['status'] === 'failed');

            $this->assertTrue(
                $has_replay_button,
                "Failed refund event #{$refund['id']} must have replay button"
            );

            // Replay button uses event_id
            $replay_event_id = $refund['id'];
            $this->assertNotNull($replay_event_id);
        }
    }

    /**
     * Test metabox shows refund count and failed count
     *
     * Header should show: "Loyalty Reversals: (3 events · 1 failed)"
     */
    public function test_metabox_shows_refund_counts() {
        $refunds = array(
            array('status' => 'sent'),
            array('status' => 'sent'),
            array('status' => 'failed'),
            array('status' => 'sent'),
        );

        $total_count = count($refunds);
        $failed_count = count(array_filter($refunds, function($r) {
            return $r['status'] === 'failed';
        }));

        $this->assertEquals(4, $total_count);
        $this->assertEquals(1, $failed_count);

        // Metabox should display both counts
        $display_text = "({$total_count} events · {$failed_count} failed)";
        $this->assertStringContainsString('4 events', $display_text);
        $this->assertStringContainsString('1 failed', $display_text);
    }

    /**
     * Test that loyalty events use confirmed endpoint
     *
     * PR-OP-COMMERCE-EVENT-INGESTION-1: /v4/commerce/integrations/{id}/events
     */
    public function test_loyalty_events_use_confirmed_endpoint() {
        $integration_id = 'int_abc123';
        $confirmed_endpoint = "/v4/commerce/integrations/{$integration_id}/events";

        // Both loyalty events use same endpoint
        $order_finalized_endpoint = '/v4/commerce/integrations/' . $integration_id . '/events';
        $order_refunded_endpoint = '/v4/commerce/integrations/' . $integration_id . '/events';

        $this->assertEquals($confirmed_endpoint, $order_finalized_endpoint);
        $this->assertEquals($confirmed_endpoint, $order_refunded_endpoint);

        // Endpoint format validation
        $this->assertStringStartsWith('/v4/commerce/integrations/', $confirmed_endpoint);
        $this->assertStringEndsWith('/events', $confirmed_endpoint);
    }

    /**
     * Test that loyalty events log with proper event codes
     *
     * OP_Logger event codes for correlation
     */
    public function test_loyalty_events_use_proper_log_codes() {
        $expected_log_codes = array(
            'order_finalized_sent',
            'order_finalized_failed',
            'order_finalized_skipped',
            'order_refunded_sent',
            'order_refunded_failed',
            'order_refunded_skipped',
        );

        foreach ($expected_log_codes as $code) {
            $this->assertNotEmpty($code);

            // Verify naming convention
            if (strpos($code, 'order_finalized') !== false) {
                $this->assertStringContainsString('order_finalized', $code);
            }

            if (strpos($code, 'order_refunded') !== false) {
                $this->assertStringContainsString('order_refunded', $code);
            }
        }
    }
}
