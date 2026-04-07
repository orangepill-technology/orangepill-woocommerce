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

## Contact

For questions about the test suite or PR-WC-LOYALTY-1 implementation:
- Review the main PR specification
- Check `includes/class-op-order-sync.php` for `order.finalized` logic
- Check `includes/class-op-refund-sync.php` for `order.refunded` logic
- Check `includes/class-op-sync-journal.php` for deduplication logic
