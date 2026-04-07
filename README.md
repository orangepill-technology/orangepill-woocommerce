# Orangepill for WooCommerce

Accept payments via Orangepill - embedded finance infrastructure for modern commerce platforms.

## Description

This plugin integrates Orangepill's embedded finance platform into your WooCommerce store, providing seamless payment processing through a secure hosted checkout experience.

## Features

### Payment Processing
- **Seamless Checkout**: Redirect customers to Orangepill's hosted checkout for a secure payment experience
- **Customer Sync**: Automatic customer deduplication via external IDs
- **Webhook Support**: Real-time payment confirmations via webhook callbacks

### Loyalty Integration (PR-WC-LOYALTY-1)
- **Loyalty Earn Triggers**: Automatic `order.finalized` events when orders are completed
- **Loyalty Reversal Triggers**: Automatic `order.refunded` events for each refund created
- **Durable Event Persistence**: Database-backed event journal with replay capability
- **Idempotency Guarantees**: Stable keys prevent duplicate processing across replays
- **Loyalty Activity Metabox**: View earn and reversal status directly on order edit screens

### Admin Tools
- **Admin Dashboard**: Overview page with payment statistics and connection status
- **Event Logging**: Comprehensive sync log for debugging (50 entry limit)
- **Failed Syncs Page**: View and replay failed outbound events with one click
- **Order Metadata**: View Orangepill payment details and loyalty activity on order edit screens
- **Connection Testing**: Test your API credentials with one click

## Requirements

- PHP 7.4 or higher
- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- Orangepill merchant account with API credentials

## Development & Testing

### Running Tests

The plugin includes a comprehensive PHPUnit test suite for loyalty functionality.

**Install dependencies:**
```bash
composer install
```

**Run all tests:**
```bash
composer test
```

**Run specific test file:**
```bash
vendor/bin/phpunit tests/test-order-finalized.php
vendor/bin/phpunit tests/test-order-refunded.php
```

**Run with verbose output:**
```bash
composer test:verbose
```

**Generate code coverage (requires Xdebug):**
```bash
composer test:coverage
```

See `tests/README.md` for detailed test documentation.

## Installation

1. Upload the plugin files to `/wp-content/plugins/orangepill-woocommerce/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Orangepill Settings to configure your API credentials
4. Enable the payment gateway in WooCommerce → Settings → Payments

### Post-Installation Verification (CRITICAL)

After installation or upgrade, verify the UNIQUE constraint is properly applied:

```bash
wp eval-file wp-content/plugins/orangepill-woocommerce/verify-db-schema.php
```

**Expected output:**
```
✅ ALL CHECKS PASSED - Database schema is correct!
   Concurrent safety: ENABLED
   Duplicate prevention: ACTIVE
```

**If verification fails:**
1. Deactivate the plugin
2. Reactivate the plugin
3. Run verification again

**Why this matters:** The UNIQUE constraint prevents duplicate events during concurrent order processing. Without it, race conditions can create duplicate loyalty triggers.

## Configuration

### Required Settings

1. **API Key**: Your Orangepill integration API key
2. **Integration ID**: Your Orangepill integration ID
3. **Merchant ID**: Your Orangepill merchant ID
4. **Webhook Secret**: Your webhook signing secret (for signature verification)

### Webhook Setup

1. Copy the webhook URL from the settings page: `https://yoursite.com/wc-api/orangepill-webhook`
2. Configure this URL in your Orangepill dashboard
3. Webhooks are secured with HMAC-SHA256 signature verification

**Webhook Signature Spec:**
- Header: `X-Orangepill-Signature`
- Algorithm: HMAC-SHA256
- Payload: Raw request body
- Encoding: Hex

**Idempotency (Financial-Grade):**
- Event IDs tracked per order (last 100 events)
- Payload hash verification (SHA256)
- Detects event_id reuse with different payloads (provider bugs)
- Duplicate webhooks return immediately without reprocessing
- Protects against: double status updates, duplicate notes, repeated logs
- Hash mismatch logged as critical anomaly but still returns 200 OK

## Admin Pages

### Overview
**Location**: WooCommerce → Orangepill

Displays:
- Connection status
- Recent payments (24h) - Source: WooCommerce orders
- Pending orders count - Source: WooCommerce orders
- Sync errors (24h) - Source: OP_Logger events
- Recent activity log (last 20 events) - Source: OP_Logger

**Data Sources:**
- Payment stats: WooCommerce order table (filtered by payment_method='orangepill')
- Error counts: OP_Logger events (filtered by level='error')
- No derived or cached state

### Settings
**Location**: WooCommerce → Orangepill Settings

- Configure API credentials
- Test connection with validation endpoint
- View webhook URL
- Connection status indicator

### Sync Log
**Location**: WooCommerce → Orangepill Sync Log

- View all logged events (up to 50 entries)
- Filter by level (info, warning, error)
- Filter by event type
- Search log messages
- Clear all logs
- Source: OP_Logger (no derived state)

### Failed Syncs
**Location**: WooCommerce → Failed Syncs

Displays failed outbound sync events:
- Event type (order.finalized, order.refunded, payment sync, etc.)
- Order link
- Error message and attempt count
- Time filters (last 7 days or all time)
- **Replay**: Re-send failed event with original payload and idempotency key
- **Dismiss**: Hide event from list
- Source: `wp_orangepill_sync_events` table (filtered by status='failed')

**Replay Safety:**
- Uses stored payload (exact original data)
- Uses stored idempotency key (prevents duplication)
- Safe to replay multiple times (Orangepill-side dedupe)
- Updates event status to 'sent' on success

### Order Metabox
**Location**: Order edit screen (sidebar)

Displays for Orangepill orders:

**Payment Details:**
- Session ID
- Payment ID
- Customer ID
- Payment status
- Payment confirmation timestamp
- Last sync timestamp

**Sync Health (PR-WC-3b):**
- Last outbound sync status
- Last inbound webhook status
- Failed sync alerts with replay button

**Loyalty Activity (PR-WC-LOYALTY-1):**
- Loyalty Earn status (order.finalized event)
- Loyalty Reversals (ALL order.refunded events)
- Per-event status icons (✅ sent, ❌ failed, ⏳ pending)
- Per-event replay buttons for failures
- Refund count and failed count

## Checkout Flow

1. Customer selects "Orangepill" as payment method
2. Plugin syncs customer to Orangepill (creates or retrieves customer_id)
3. Plugin creates checkout session with order metadata
4. Customer is redirected to Orangepill hosted checkout
5. Customer completes payment
6. Orangepill sends webhook to WooCommerce
7. Plugin verifies signature and updates order status
8. Order marked as "Processing" and customer is notified

## Webhook Events

### payment.succeeded
- Verifies HMAC signature (timing-safe)
- Checks idempotency (event_id tracking)
- Updates order status to "Processing"
- Stores payment_id and event_id in order metadata
- Logs successful payment event

### payment.failed
- Verifies HMAC signature (timing-safe)
- Checks idempotency (event_id tracking)
- Updates order status to "Failed"
- Stores failure reason and event_id in order metadata
- Logs payment failure

**Idempotency Guarantees (Financial-Grade):**
- Each event_id + payload_hash can only be processed once per order
- Event_id reuse with different payload detected and logged as anomaly
- Duplicate delivery returns 200 OK without side effects
- Prevents: double status updates, duplicate notes, repeated logs
- Legacy support: Handles migration from timestamp-only format

## Loyalty Triggers (PR-WC-LOYALTY-1)

The plugin automatically emits loyalty events to Orangepill for earn and reversal processing. **Critical**: Plugin emits triggers ONLY — it never computes loyalty economics (points, tiers, wallets, etc.).

### order.finalized (Loyalty Earn)

**When**: Fires once when WooCommerce order transitions to `completed` status
**Trigger**: `woocommerce_order_status_changed` hook with transition guard
**Idempotency Key**: `woo:{order_id}:order.finalized:completed` (stable, not timestamp-based)
**Endpoint**: `POST /v4/commerce/integrations/{integration_id}/events`

**Payload Example:**
```json
{
  "event": "order.finalized",
  "woo_order_id": "12345",
  "status": "completed",
  "previous_status": "processing",
  "order_total": "99.99",
  "currency": "USD",
  "customer": {
    "woo_customer_id": "67",
    "orangepill_customer_id": "op_cust_abc123",
    "email": "customer@example.com",
    "phone": "+1234567890"
  },
  "metadata": {
    "channel": "woocommerce",
    "integration_id": "int_xyz789"
  }
}
```

**Important**: Uses RAW WooCommerce status (`completed`), NOT payment sync mapping (`fulfilled`).

**Guard Logic (RULE 12):**
```php
// Fires only on TRANSITION into completed (prevents double emission)
if ($new_status === 'completed' && $old_status !== 'completed') {
    send_order_finalized();
}
```

### order.refunded (Loyalty Reversal)

**When**: Fires once per WooCommerce refund object creation
**Trigger**: `woocommerce_create_refund` hook (fires once per refund_id)
**Idempotency Key**: `woo:{order_id}:refund:{refund_id}` (uses refund_id as natural anchor)
**Endpoint**: `POST /v4/commerce/integrations/{integration_id}/events`

**Payload Example:**
```json
{
  "event": "order.refunded",
  "woo_order_id": "12345",
  "refund_id": "789",
  "refund_amount": "25.00",
  "order_total": "99.99",
  "currency": "USD",
  "customer": {
    "woo_customer_id": "67",
    "orangepill_customer_id": "op_cust_abc123"
  },
  "metadata": {
    "channel": "woocommerce",
    "integration_id": "int_xyz789",
    "refund_reason": "Customer requested refund"
  }
}
```

**Multi-Refund Support (RULE 13):**
- Each refund generates separate event with unique `refund_id`
- Order metabox displays ALL refund events (not just last)
- Failed refunds have per-event replay buttons

### Durable Event Journal

All outbound loyalty events are persisted in `wp_orangepill_sync_events` table:

**Fields:**
- `direction`: `woo_to_op` (outbound)
- `event_type`: `order.finalized` or `order.refunded`
- `order_id`: WooCommerce order ID (for correlation)
- `payload_json`: Complete event payload (for replay)
- `endpoint`: API endpoint (preserves version across replays)
- `idempotency_key`: Stable replay key
- `status`: `pending`, `sent`, or `failed`
- `attempt_count`: Number of send attempts
- `last_error`: Error message (if failed)

**Deduplication (RULE 11):**
Journal deduplicates by `(direction, event_type, idempotency_key)`:
- Same event recorded multiple times → returns existing `event_id`
- Different event types for same order → both recorded
- Multiple refunds for same order → all recorded separately

### Failed Syncs & Replay

**Location**: WooCommerce → Failed Syncs

Displays all failed outbound events (payment syncs + loyalty triggers):
- Event type (order.finalized, order.refunded, etc.)
- Order link
- Error message
- Attempt count
- **Replay button**: Re-sends EXACT stored payload with ORIGINAL idempotency key

**Replay Safety:**
- Uses stored payload (no reassembly from live state)
- Uses stored idempotency key (prevents duplication)
- Orangepill-side idempotency protects against duplicate processing
- Safe to replay multiple times (OP will dedupe)

**Dismiss**: Hide event from failed list without replaying

### Loyalty Activity Metabox

**Location**: Order edit screen → Orangepill metabox → Loyalty Activity section

**Displays:**

1. **Loyalty Earn** (single event)
   - Status icon: ✅ sent, ❌ failed, ⏳ pending
   - Event details and idempotency key
   - Replay button (if failed)

2. **Loyalty Reversals** (multiple events)
   - Count: "(3 events · 1 failed)"
   - Per-refund cards showing:
     - Refund ID and amount
     - Status and timestamp
     - Error details (if failed)
     - Per-event replay button

**Example:**
```
Loyalty Activity
───────────────
Loyalty Earn:
  ✅ Sent
  Event: order.finalized · 2 hours ago
  Idempotency Key: woo:12345:order.finalized:completed

Loyalty Reversals: (2 events)
  ✅ Sent │ Refund #789 │ $25.00 USD │ 1 hour ago
  ❌ Failed │ Refund #790 │ $15.00 USD │ 30 minutes ago
    Error: Connection timeout
    Attempts: 2 | Last attempt: 25 minutes ago
    [Replay] button
```

### Failure Modes & Non-Blocking Behavior

**RULE 7**: Loyalty trigger failures MUST NOT block WooCommerce admin operations.

**What happens when loyalty events fail:**
- Order completes normally (customer sees success)
- Admin can issue refund normally (refund processes)
- Event logged as `failed` in journal
- Operator alerted via Failed Syncs page
- Replay available when OP connectivity restored

**Failure scenarios:**
- OP API timeout → Retry via replay
- Network error → Retry via replay
- Invalid integration_id → Fix in settings, then replay
- OP service down → Wait for recovery, then replay

**What does NOT fail:**
- WooCommerce order completion
- WooCommerce refund processing
- Customer notifications
- Inventory management

### Testing Loyalty Integration

**Manual Test: order.finalized**
1. Create test order and mark as `completed`
2. Check order metabox → Loyalty Activity → Loyalty Earn status
3. If failed: Click replay button
4. Verify event in Sync Log: `order_finalized_sent` or `order_finalized_failed`

**Manual Test: order.refunded**
1. Create refund from completed order
2. Check order metabox → Loyalty Activity → Loyalty Reversals
3. Verify refund event appears with correct amount
4. If failed: Click per-event replay button
5. Create second refund → Verify both refunds shown separately

**Manual Test: Replay**
1. Go to WooCommerce → Failed Syncs
2. Find failed `order.finalized` or `order.refunded` event
3. Click "Replay" button
4. Verify event status changes to `sent`
5. Check Sync Log for replay confirmation

## Security

- API keys stored as password fields (never displayed in UI)
- Webhook signature verification (HMAC-SHA256, timing-safe comparison)
- All sensitive data redacted from logs
- Nonce verification on all admin forms
- Capability checks (`manage_woocommerce`) on all admin pages
- Input sanitization and output escaping throughout

## Customer Sync

The plugin uses `external_id` for customer deduplication:
- **Pattern**: `woo:{user_id}`
- **First Order**: Creates Orangepill customer
- **Subsequent Orders**: Reuses cached customer_id
- **Guest Checkout**: Skips customer sync (customer_id = null)

**Cross-Channel Identity Limitation:**
Guest checkout users are **not deduplicated across channels**. For cross-channel identity (e.g., WhatsApp ↔ WooCommerce), customers must have registered accounts with email or phone-based identity.

## Order Sync

Order status changes are synced and logged:
- **Sync Direction**: WooCommerce → Orangepill (via webhook bridge)
- **Status Mapping**: WooCommerce status → Orangepill status
- **Terminal State Protection**: Prevents downgrades (e.g., completed → pending)
- **Local Logging**: All sync events logged for operator visibility
- **No Direct Mutation**: Plugin does not mutate Orangepill state beyond webhook contract

## Logging

Events are stored in `wp_options` table:
- Maximum 50 entries (FIFO pruning)
- Three levels: info, warning, error
- All API keys/secrets redacted before storage
- Searchable and filterable via admin UI

## Directory Structure

```
orangepill-woocommerce/
├── orangepill-woocommerce.php       # Main plugin file
├── composer.json                     # Dependency management
├── phpunit.xml                       # PHPUnit configuration
├── README.md                         # This file
├── includes/                         # Core classes
│   ├── class-op-api-client.php      # API HTTP client
│   ├── class-op-payment-gateway.php # WC_Payment_Gateway
│   ├── class-op-webhook-handler.php # Webhook processor
│   ├── class-op-order-sync.php      # Order sync + order.finalized
│   ├── class-op-refund-sync.php     # Refund sync + order.refunded (NEW)
│   ├── class-op-customer-sync.php   # Customer sync
│   ├── class-op-sync-journal.php    # Durable event journal (NEW)
│   └── class-op-logger.php          # Logging system
├── admin/                            # Admin UI
│   ├── class-op-admin-menu.php      # Menu registration
│   ├── class-op-settings-page.php   # Settings + test
│   ├── class-op-overview-page.php   # Dashboard
│   ├── class-op-sync-log-page.php   # Log viewer
│   ├── class-op-failed-syncs-page.php # Failed syncs + replay (NEW)
│   └── class-op-order-metabox.php   # Order metabox + loyalty activity
├── assets/                           # CSS/JS
│   ├── css/admin.css
│   └── js/admin.js
└── tests/                            # Test suite (NEW)
    ├── bootstrap.php                 # Test bootstrap
    ├── test-order-finalized.php      # order.finalized tests
    ├── test-order-refunded.php       # order.refunded tests
    ├── test-sync-journal-dedupe.php  # Deduplication tests
    ├── test-integration.php          # Integration tests
    └── README.md                     # Test documentation
```

## Troubleshooting

### Connection Test Fails
1. Verify API key is correct (no extra spaces)
2. Check integration ID matches your Orangepill account
3. Ensure API base URL is correct (default: https://api.orangepill.dev)
4. Check sync log for detailed error messages

### Webhooks Not Working
1. Verify webhook URL is configured in Orangepill dashboard
2. Check webhook secret matches exactly
3. Review sync log for signature verification failures
4. Ensure site is accessible from internet (not localhost)

### Orders Stuck in Pending
1. Check if webhook is configured correctly
2. Review sync log for webhook events
3. Verify customer completed payment in Orangepill
4. Manually check order for `_orangepill_session_id` metadata

### Customer Sync Errors
1. Check sync log for API error messages
2. Verify user has valid email address
3. Ensure API key has permission to create customers
4. Try clearing customer cache: Delete `_orangepill_customer_id` user meta

### Loyalty Events Not Triggering
1. **order.finalized not firing:**
   - Verify order payment method is 'orangepill'
   - Check order actually transitioned to 'completed' status
   - Review Sync Log for `order_finalized_skipped` or `order_finalized_failed`
   - Verify integration_id is configured in settings

2. **order.refunded not firing:**
   - Verify parent order payment method is 'orangepill'
   - Check WooCommerce refund was actually created (not just order status change)
   - Review Sync Log for `order_refunded_skipped` or `order_refunded_failed`
   - Verify integration_id is configured in settings

3. **Failed loyalty events:**
   - Go to WooCommerce → Failed Syncs
   - Check error message for specific failure reason
   - Common causes: API timeout, invalid integration_id, network error
   - Use Replay button to retry after fixing root cause

4. **Loyalty events missing from metabox:**
   - Check if order payment method is 'orangepill' (only Orangepill orders show loyalty activity)
   - For order.finalized: Verify order status is 'completed'
   - For order.refunded: Verify WooCommerce refund objects exist
   - Check Failed Syncs page for failed events

### Replay Not Working
1. Verify event status is 'failed' (only failed events can be replayed)
2. Check current API credentials are correct (replay uses current settings)
3. Review Sync Log for replay attempt results
4. If replay fails repeatedly: Check OP service status and integration_id validity

## Support

For issues or questions:
- Check the sync log first (≤10 second debugging goal)
- Review recent activity in overview dashboard
- View specific order metadata in order metabox
- Contact Orangepill support with session_id or payment_id

## Changelog

### 1.1.0 (PR-WC-LOYALTY-1)
- **Loyalty Integration**:
  - Added `order.finalized` event (loyalty earn trigger)
  - Added `order.refunded` event (loyalty reversal trigger)
  - Created `OP_Refund_Sync` class for refund event handling
  - Implemented transition guard for order.finalized (RULE 12)
  - Stable idempotency keys (not timestamp-based)
- **Durable Event Journal**:
  - Created `wp_orangepill_sync_events` database table
  - Implemented deduplication by (direction, event_type, idempotency_key)
  - Added query helpers: `get_last_event_for_order_by_type()`, `get_events_for_order()`
- **Failed Syncs & Replay**:
  - Added Failed Syncs admin page
  - Implemented event replay functionality
  - Replay uses stored payload + idempotency key (safe for multiple replays)
  - Added Dismiss functionality for failed events
- **Loyalty Activity Metabox**:
  - Added Loyalty Activity section to order metabox
  - Shows single order.finalized event with status
  - Shows ALL order.refunded events (RULE 13)
  - Per-event replay buttons for failed events
  - Status icons: ✅ sent, ❌ failed, ⏳ pending
- **Testing**:
  - Created comprehensive test suite with PHPUnit
  - Tests for order.finalized, order.refunded, deduplication, integration
  - Added composer.json with test scripts
  - Test coverage documentation in tests/README.md
- **Non-Blocking Behavior**:
  - Loyalty trigger failures do NOT block WooCommerce operations (RULE 7)
  - Orders complete normally even if loyalty events fail
  - Refunds process normally even if loyalty events fail

### 1.0.0
- Initial release
- Payment gateway implementation
- Webhook handling with HMAC-SHA256 signature verification
- Customer sync with deduplication (external_id pattern)
- Admin dashboard with payment statistics
- Event logging system (50 entry limit)
- Connection testing with validation endpoint
- Order metabox with payment details
- Sync Health section in order metabox (PR-WC-3b)

## License

GPL-2.0+

## Credits

Developed by Orangepill (https://orangepill.technology)
