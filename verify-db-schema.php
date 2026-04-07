<?php
/**
 * Database Schema Verification Script
 *
 * Run this script to verify the UNIQUE constraint is properly applied.
 * Usage: wp eval-file verify-db-schema.php
 */

// Verify WordPress is loaded
if (!defined('ABSPATH')) {
    die('This script must be run via WP-CLI: wp eval-file verify-db-schema.php');
}

global $wpdb;
$table = $wpdb->prefix . 'orangepill_sync_events';

echo "Checking Orangepill database schema...\n\n";

// Check if table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

if (!$table_exists) {
    echo "❌ ERROR: Table $table does not exist.\n";
    echo "   Run: wp plugin activate orangepill-woocommerce\n";
    exit(1);
}

echo "✅ Table exists: $table\n\n";

// Check for UNIQUE constraint
$indexes = $wpdb->get_results("SHOW INDEX FROM $table");

echo "Indexes found:\n";
foreach ($indexes as $index) {
    $unique_flag = $index->Non_unique == 0 ? ' (UNIQUE)' : '';
    echo "  - {$index->Key_name} on {$index->Column_name}{$unique_flag}\n";
}

echo "\n";

// Verify idx_dedupe exists and is UNIQUE
$dedupe_index = array_filter($indexes, function($idx) {
    return $idx->Key_name === 'idx_dedupe';
});

if (empty($dedupe_index)) {
    echo "❌ CRITICAL: UNIQUE constraint 'idx_dedupe' NOT FOUND!\n";
    echo "   This means concurrent requests can create duplicate events.\n";
    echo "   Action: Deactivate and reactivate the plugin to trigger migration.\n";
    exit(1);
}

// Verify it's actually UNIQUE
$dedupe_columns = array();
$is_unique = true;
foreach ($indexes as $idx) {
    if ($idx->Key_name === 'idx_dedupe') {
        $dedupe_columns[] = $idx->Column_name;
        if ($idx->Non_unique != 0) {
            $is_unique = false;
        }
    }
}

if (!$is_unique) {
    echo "❌ CRITICAL: idx_dedupe exists but is NOT UNIQUE!\n";
    echo "   Action: Manually run ALTER TABLE to add UNIQUE constraint.\n";
    exit(1);
}

echo "✅ UNIQUE constraint 'idx_dedupe' exists\n";
echo "   Columns: " . implode(', ', $dedupe_columns) . "\n";

// Verify correct columns
$expected_columns = array('direction', 'event_type', 'idempotency_key');
$missing_columns = array_diff($expected_columns, $dedupe_columns);

if (!empty($missing_columns)) {
    echo "❌ ERROR: UNIQUE constraint missing columns: " . implode(', ', $missing_columns) . "\n";
    exit(1);
}

echo "   ✅ All required columns present\n\n";

// Check DB version
$db_version = get_option('orangepill_wc_db_version');
echo "Database version: " . ($db_version ?: 'not set') . "\n";
if ($db_version < 2) {
    echo "⚠️  WARNING: DB version is less than 2. Migration may not have run.\n";
} else {
    echo "✅ DB version is current\n";
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ ALL CHECKS PASSED - Database schema is correct!\n";
echo "   Concurrent safety: ENABLED\n";
echo "   Duplicate prevention: ACTIVE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
