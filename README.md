# Orangepill for WooCommerce

Accept payments via Orangepill - embedded finance infrastructure for modern commerce platforms.

## Description

This plugin integrates Orangepill's embedded finance platform into your WooCommerce store, providing seamless payment processing through a secure hosted checkout experience.

## Features

- **Seamless Checkout**: Redirect customers to Orangepill's hosted checkout for a secure payment experience
- **Customer Sync**: Automatic customer deduplication via external IDs
- **Webhook Support**: Real-time payment confirmations via webhook callbacks
- **Admin Dashboard**: Overview page with payment statistics and connection status
- **Event Logging**: Comprehensive sync log for debugging (50 entry limit)
- **Order Metadata**: View Orangepill payment details on order edit screens
- **Connection Testing**: Test your API credentials with one click

## Requirements

- PHP 7.4 or higher
- WordPress 6.0 or higher
- WooCommerce 7.0 or higher
- Orangepill merchant account with API credentials

## Installation

1. Upload the plugin files to `/wp-content/plugins/orangepill-woocommerce/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → Orangepill Settings to configure your API credentials
4. Enable the payment gateway in WooCommerce → Settings → Payments

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

### Order Metabox
**Location**: Order edit screen (sidebar)

Displays for Orangepill orders:
- Session ID
- Payment ID
- Customer ID
- Payment status
- Payment confirmation timestamp
- Last sync timestamp

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
├── includes/                         # Core classes
│   ├── class-op-api-client.php      # API HTTP client
│   ├── class-op-payment-gateway.php # WC_Payment_Gateway
│   ├── class-op-webhook-handler.php # Webhook processor
│   ├── class-op-order-sync.php      # Order sync logic
│   ├── class-op-customer-sync.php   # Customer sync
│   └── class-op-logger.php          # Logging system
├── admin/                            # Admin UI
│   ├── class-op-admin-menu.php      # Menu registration
│   ├── class-op-settings-page.php   # Settings + test
│   ├── class-op-overview-page.php   # Dashboard
│   ├── class-op-sync-log-page.php   # Log viewer
│   └── class-op-order-metabox.php   # Order metabox
└── assets/                           # CSS/JS
    ├── css/admin.css
    └── js/admin.js
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

## Support

For issues or questions:
- Check the sync log first (≤10 second debugging goal)
- Review recent activity in overview dashboard
- View specific order metadata in order metabox
- Contact Orangepill support with session_id or payment_id

## Changelog

### 1.0.0
- Initial release
- Payment gateway implementation
- Webhook handling
- Customer sync with deduplication
- Admin dashboard
- Event logging system
- Connection testing
- Order metabox

## License

GPL-2.0+

## Credits

Developed by Orangepill (https://orangepill.technology)
