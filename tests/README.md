# Orangepill WooCommerce Plugin - Test Suite

## Overview

This test suite verifies the loyalty trigger functionality implemented in PR-WC-LOYALTY-1:
- `order.finalized` event (loyalty earn trigger)
- `order.refunded` event (loyalty reversal trigger)
- Sync journal deduplication (RULE 11)
- Automatic integration with Failed Syncs page, Sync Log, and Order metabox

## Test Coverage

### Test Files

1. **test-order-finalized.php** - Tests for order.finalized event
   - Transition guard (RULE 12): only fires on transition to `completed`
   - Idempotency key format (RULE 4): `woo:{order_id}:order.finalized:completed`
   - Payload structure validation
   - Uses raw Woo status (not payment sync mapping)
   - Endpoint path verification

2. **test-order-refunded.php** - Tests for order.refunded event
   - Per-refund emission (RULE 3)
   - Multiple refunds per order support
   - Idempotency key format (RULE 4): `woo:{order_id}:refund:{refund_id}`
   - Payload structure validation
   - Refund amount handling (positive conversion)

3. **test-sync-journal-dedupe.php** - Tests for deduplication logic
   - Dedupe key components (RULE 11): `(direction, event_type, idempotency_key)`
   - Duplicate detection behavior
   - Different event types can coexist
   - Multiple refunds are NOT deduped
   - Query helpers for single vs multi-event types

4. **test-integration.php** - Tests for automatic integration
   - Failed Syncs page compatibility
   - Sync Log compatibility
   - Replay functionality
   - Order metabox display (RULE 13: shows ALL refunds)
   - Status icons and per-event replay buttons

## Installation

### Requirements

- PHP 7.4+
- Composer

### Setup

1. Install dependencies:
   ```bash
   composer install
   ```

   This will install PHPUnit and required testing tools.

## Running Tests

### Run all tests:
```bash
vendor/bin/phpunit
```

### Run specific test file:
```bash
vendor/bin/phpunit tests/test-order-finalized.php
vendor/bin/phpunit tests/test-order-refunded.php
vendor/bin/phpunit tests/test-sync-journal-dedupe.php
vendor/bin/phpunit tests/test-integration.php
```

### Run with verbose output:
```bash
vendor/bin/phpunit --verbose
```

### Run with code coverage (requires Xdebug):
```bash
vendor/bin/phpunit --coverage-html coverage/
```

Then open `coverage/index.html` in your browser.

## Test Philosophy

### Unit vs Integration Tests

These tests focus on **business logic validation** rather than WordPress/WooCommerce integration:
- **Idempotency key generation** - Pure string formatting
- **Transition guards** - Boolean logic
- **Payload structure** - Array validation
- **Dedupe behavior** - Algorithm verification

This approach allows tests to run **without** a full WordPress installation, making them fast and reliable.

### What These Tests Do NOT Cover

- WordPress hook firing (requires WordPress test environment)
- Database operations (requires wpdb mock)
- WooCommerce order object manipulation (requires WooCommerce test environment)
- API client HTTP requests (requires HTTP mocking)

For full integration testing with WordPress/WooCommerce, consider setting up:
- [WP-CLI Test Framework](https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/)
- [WooCommerce Core Tests](https://github.com/woocommerce/woocommerce/wiki/How-to-set-up-WooCommerce-development-environment)

## Critical Rules Verified

### RULE 3: Per-Refund Emission
✅ `order.refunded` fires once per WooCommerce refund object
✅ Uses `refund_id` as natural idempotency anchor

### RULE 4: Stable Idempotency Keys
✅ `order.finalized`: `woo:{order_id}:order.finalized:completed`
✅ `order.refunded`: `woo:{order_id}:refund:{refund_id}`
✅ NOT timestamp-based (stable across replays)

### RULE 11: Journal Deduplication
✅ Dedupe by `(direction, event_type, idempotency_key)`
✅ Returns existing `event_id` when duplicate detected
✅ Different event types can coexist for same order

### RULE 12: Transition Guard
✅ `order.finalized` fires only when:
   `old_status !== 'completed' AND new_status === 'completed'`
✅ Prevents double emission on idempotent re-saves

### RULE 13: Multi-Event Display
✅ Metabox shows ALL refund events (not just last)
✅ Uses `get_events_for_order()` for multi-event queries
✅ Per-event replay buttons for failed refunds

## Test Results Interpretation

### All tests pass ✅
Implementation correctly follows PR-WC-LOYALTY-1 specification.

### Test failures ❌
Review the assertion message to identify which rule was violated:
- **Transition guard failures** → Check `sync_order_status()` guard logic
- **Idempotency key failures** → Verify key generation format
- **Dedupe failures** → Check `OP_Sync_Journal::record_outbound_pending()` logic
- **Payload failures** → Verify event payload structure

## Adding New Tests

When adding new loyalty events or modifying existing behavior:

1. Add test file: `tests/test-{feature}.php`
2. Extend relevant test class
3. Verify critical rules (idempotency, dedupe, payload)
4. Run full test suite to ensure no regressions

Example:
```php
<?php
use PHPUnit\Framework\TestCase;

class Test_New_Feature extends TestCase {
    public function test_new_feature_behavior() {
        // Arrange
        $input = 'test';

        // Act
        $result = some_function($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

## Continuous Integration

These tests are designed to run in CI/CD pipelines:
```yaml
# Example GitHub Actions workflow
- name: Run tests
  run: |
    composer install
    vendor/bin/phpunit
```

---

## Manual Test Steps — PR-WC-CHECKOUT-WALLET-UX-1

These steps require a running WooCommerce + Orangepill dev environment.

### Prerequisite

- Logged-in WooCommerce customer with a completed prior order (so `_orangepill_customer_id` is set in user meta)
- Orangepill customer must have a spendable wallet balance > 0

---

### Checkout — wallet widget appears when balance exists

1. Log in as a customer who has an Orangepill customer ID
2. Add a product to cart → go to Checkout
3. Select "Orangepill" as payment method
4. **Expected**: A compact rewards widget appears below the payment description:
   ```
   Rewards balance available: X,XXX COP
   [ ] Apply rewards balance to this purchase
   ```
5. Open browser DevTools → Network → confirm AJAX call to `/?action=orangepill_get_wallet_balance` returns `{ wallet: { ... } }`

---

### Checkout — wallet widget hidden when no balance

1. Log in as a customer with zero spendable balance (or no Orangepill account)
2. Add a product to cart → go to Checkout
3. Select "Orangepill"
4. **Expected**: No rewards widget appears; the payment field shows only the description

---

### Checkout — apply-wallet called after session creation

1. Log in as a customer with spendable balance
2. Checkout → select Orangepill → check "Apply rewards balance to this purchase"
3. Click "Place Order"
4. **Expected** (in WooCommerce → Orangepill Sync Log):
   - `checkout_session_created` event logged ← session created first
   - `wallet_applied` event logged ← apply called after
5. **Expected**: Redirect to Orangepill hosted checkout UI

---

### Checkout — apply-wallet failure does not corrupt checkout flow

1. Temporarily point API to a wrong URL (or simulate backend failure)
2. Check "Apply rewards balance" → Place Order
3. **Expected**:
   - Sync Log shows `wallet_apply_failed` warning (not error)
   - Redirect to Orangepill checkout UI still happens
   - Order created normally in WooCommerce

---

### My Account — Rewards Balance page

1. Log in as a customer with `_orangepill_customer_id` set
2. Navigate to `/my-account/op-loyalty/`
3. **If backend `GET /v4/customers/:id/wallets` is live**:
   - Table shows wallet name, balance, available-to-spend, currency
   - "View Rewards History" button links to `/my-account/op-rewards/`
4. **If backend not yet live**:
   - Friendly placeholder: "Rewards balance is not available yet…"
   - No error message shown to customer

---

### My Account — Rewards History page

1. Navigate to `/my-account/op-rewards/`
2. **If backend `GET /v4/customers/:id/incentives` is live**:
   - Table shows: Date, Type, Description, Amount, Status
   - Pagination links appear if total > 20
3. **If backend not yet live**:
   - Friendly placeholder: "Rewards history is not available yet…"

---

### My Account — Dashboard widget

1. Navigate to `/my-account/` (dashboard)
2. **If balance > 0**:
   - Widget appears: "Rewards Balance: X,XXX COP | View details →"
3. **If balance = 0 or no wallet**:
   - No widget shown (silent)

---

### Integrity checks

| Check | How to verify |
|-------|--------------|
| Woo never computes balances locally | Grep codebase for `spendable_balance =` assignments — there should be none outside API response parsing |
| Woo does not mutate order totals | Confirm order total in WC admin is unchanged after wallet apply |
| Wallet ID not exposed in HTML | Inspect checkout page source — `orangepill_wallet_id` field should be empty until JS populates it |

## Contact

For questions about the test suite or implementation:
- PR-WC-LOYALTY-1: `includes/class-op-order-sync.php`, `includes/class-op-refund-sync.php`
- PR-WC-CHECKOUT-WALLET-UX-1: `includes/class-op-loyalty.php`, `includes/class-op-my-account.php`, `assets/js/checkout.js`
- Sync journal deduplication: `includes/class-op-sync-journal.php`
