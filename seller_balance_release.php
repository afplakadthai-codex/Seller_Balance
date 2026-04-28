<?php
declare(strict_types=1);

$sellerBalanceFile = dirname(__DIR__) . '/includes/seller_balance.php';

if (!is_file($sellerBalanceFile)) {
    fwrite(STDERR, "seller_balance.php not found: {$sellerBalanceFile}\n");
    exit(1);
}

require_once $sellerBalanceFile;

if (!bv_seller_balance_tables_exist()) {
    exit("seller_balance tables not ready\n");
}

if (bv_seller_balance_get_setting('auto_release_enabled', '0') !== '1') {
    exit("auto_release_enabled=0\n");
}

$rows = bv_seller_balance_find_releasable_ledger_rows(null);
$released = 0;

foreach ($rows as $row) {
    if (bv_seller_balance_release_pending_for_row($row)) {
        $released++;
    }
}

echo "released_rows={$released}\n";
