<?php
declare(strict_types=1);

/**
 * Bettavaro — Seller Balance / Wallet / Payout System
 * Core library.  Include once per request.
 *
 * seller_id throughout = users.id of the seller user
 *
 * Ledger types:
 *   earning            – net earning added to pending
 *   platform_fee       – pending debit of platform commission
 *   pending_release    – pending → available (2 entries: debit pending + credit available)
 *   refund_hold        – amount held back pending refund decision
 *   refund_deduction   – final deduction after refund processed
 *   adjustment_credit  – manual credit by admin
 *   adjustment_debit   – manual debit by admin
 *   payout_request     – available → held (2 entries)
 *   payout_paid        – held → paid_out (2 entries)
 *   payout_cancelled   – held → available (2 entries)
 *   tax_withholding    – future Phase 2
 */

// ---------------------------------------------------------------------------
// BOOTSTRAP — PDO connection
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_pdo')) {
    function bv_seller_balance_pdo(): PDO
    {
        // Reuse existing platform PDO if available
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }

        // Try global $pdo (common pattern in projects without DI)
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        // Attempt to bootstrap from project config files
        $root = dirname(__DIR__);
        $candidates = [
            $root . '/includes/db.php',
            $root . '/config/database.php',
            $root . '/config.php',
            $root . '/includes/config.php',
            $root . '/bootstrap.php',
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                require_once $f;
                break;
            }
        }

        // Re-check after potential bootstrap
        if (function_exists('bv_member_pdo')) {
            return bv_member_pdo();
        }
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        throw new RuntimeException(
            '[seller_balance] Cannot obtain PDO connection. ' .
            'Ensure bv_member_pdo() or global $pdo is available before including seller_balance.php.'
        );
    }
}

// ---------------------------------------------------------------------------
// SETTINGS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get_setting')) {
    function bv_seller_balance_get_setting(string $key, mixed $default = null): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT setting_value FROM seller_balance_settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $cache[$key] = $row ? (string)$row['setting_value'] : (string)($default ?? '');
        } catch (Throwable) {
            $cache[$key] = (string)($default ?? '');
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_seller_balance_commission_rate')) {
    function bv_seller_balance_commission_rate(): float
    {
        return (float)bv_seller_balance_get_setting('platform_commission_rate', '0.10');
    }
}

if (!function_exists('bv_seller_balance_default_currency')) {
    function bv_seller_balance_default_currency(): string
    {
        return bv_seller_balance_get_setting('default_currency', 'USD');
    }
}

// ---------------------------------------------------------------------------
// TABLES CHECK
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_tables_exist')) {
    function bv_seller_balance_tables_exist(): bool
    {
        try {
            $pdo = bv_seller_balance_pdo();
            $stmt = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 AND table_name IN (
                     'seller_balances','seller_ledger',
                     'seller_payout_requests','seller_payout_transactions'
                 )"
            );
            return (int)$stmt->fetchColumn() >= 4;
        } catch (Throwable) {
            return false;
        }
    }
}

// ---------------------------------------------------------------------------
// CURRENT USER HELPERS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_current_user_id')) {
    function bv_seller_balance_current_user_id(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        return (int)($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('bv_seller_balance_current_user_role')) {
    function bv_seller_balance_current_user_role(): string
    {
        $uid = bv_seller_balance_current_user_id();
        if ($uid <= 0) {
            return '';
        }
        // Check session cache first
        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== '') {
            return (string)$_SESSION['user_role'];
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT role FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (string)$row['role'] : '';
        } catch (Throwable) {
            return '';
        }
    }
}

if (!function_exists('bv_seller_balance_is_seller')) {
    function bv_seller_balance_is_seller(): bool
    {
        return bv_seller_balance_current_user_role() === 'seller';
    }
}

if (!function_exists('bv_seller_balance_is_admin')) {
    function bv_seller_balance_is_admin(): bool
    {
        return bv_seller_balance_current_user_role() === 'admin';
    }
}

// ---------------------------------------------------------------------------
// SELLER BALANCE — GET / ENSURE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get')) {
    /**
     * Get balance row for a seller. Returns null if not found.
     */
    function bv_seller_balance_get(int $sellerId): ?array
    {
        if ($sellerId <= 0) {
            return null;
        }
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1'
            );
            $stmt->execute([$sellerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('bv_seller_balance_ensure')) {
    /**
     * Ensure a seller_balances row exists. Returns the current row.
     * Uses INSERT IGNORE + re-fetch to be race-condition-safe.
     */
    function bv_seller_balance_ensure(int $sellerId, string $currency = ''): array
    {
        if ($currency === '') {
            $currency = bv_seller_balance_default_currency();
        }
        $pdo = bv_seller_balance_pdo();
        $pdo->prepare(
            'INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)'
        )->execute([$sellerId, $currency]);

        $stmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1');
        $stmt->execute([$sellerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

// ---------------------------------------------------------------------------
// LEDGER — INTERNAL INSERT (must be called inside a DB transaction)
// ---------------------------------------------------------------------------

if (!function_exists('_bv_sb_insert_ledger')) {
    /**
     * Internal: insert one ledger row within an already-open transaction.
     * Returns the new ledger id.
     *
     * @param array{
     *   seller_id: int,
     *   type: string,
     *   balance_type: string,
     *   direction: string,
     *   amount: float,
     *   currency: string,
     *   balance_before: float,
     *   balance_after: float,
     *   reference_type?: string|null,
     *   reference_id?: int|null,
     *   idempotency_key?: string|null,
     *   note?: string|null,
     *   meta_json?: array|null,
     *   created_by_type?: string|null,
     *   created_by_id?: int|null,
     * } $e
     */
    function _bv_sb_insert_ledger(PDO $pdo, array $e): int
    {
        $meta = isset($e['meta_json']) ? json_encode($e['meta_json'], JSON_UNESCAPED_UNICODE) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO seller_ledger
             (seller_id, type, balance_type, direction, amount, currency,
              balance_before, balance_after, reference_type, reference_id,
              idempotency_key, note, meta_json, created_by_type, created_by_id)
             VALUES
             (:seller_id, :type, :balance_type, :direction, :amount, :currency,
              :bb, :ba, :ref_type, :ref_id,
              :idem, :note, :meta, :cbt, :cbi)'
        );
        $stmt->execute([
            ':seller_id'   => $e['seller_id'],
            ':type'        => $e['type'],
            ':balance_type'=> $e['balance_type'],
            ':direction'   => $e['direction'],
            ':amount'      => round((float)($e['amount'] ?? 0), 4),
            ':currency'    => $e['currency'] ?? 'USD',
            ':bb'          => round((float)($e['balance_before'] ?? 0), 4),
            ':ba'          => round((float)($e['balance_after']  ?? 0), 4),
            ':ref_type'    => $e['reference_type'] ?? null,
            ':ref_id'      => isset($e['reference_id']) ? (int)$e['reference_id'] : null,
            ':idem'        => $e['idempotency_key'] ?? null,
            ':note'        => $e['note'] ?? null,
            ':meta'        => $meta,
            ':cbt'         => $e['created_by_type'] ?? 'system',
            ':cbi'         => isset($e['created_by_id']) ? (int)$e['created_by_id'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('_bv_sb_table_exists')) {
    function _bv_sb_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            $cache[$table] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('_bv_sb_column_exists')) {
    function _bv_sb_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('_bv_sb_ledger_exists')) {
    function _bv_sb_ledger_exists(PDO $pdo, string $idempotencyKey): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM seller_ledger WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('_bv_sb_insert_ledger_once')) {
    function _bv_sb_insert_ledger_once(PDO $pdo, array $entry): int
    {
        $key = (string)($entry['idempotency_key'] ?? '');
        if ($key !== '' && _bv_sb_ledger_exists($pdo, $key)) {
            return 0;
        }
        return _bv_sb_insert_ledger($pdo, $entry);
    }
}

// ---------------------------------------------------------------------------
// ORDER PAID — MAIN HOOK (called by order_paid_handler.php)
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_process_order_paid')) {
    /**
     * Process seller-owned order_items when an order is paid.
     * Earning is gross credit to pending; platform_fee is a pending debit.
     */
    function bv_seller_balance_process_order_paid(int $orderId): array
    {
        if ($orderId <= 0 || !bv_seller_balance_tables_exist()) {
            return [];
        }

        $pdo = bv_seller_balance_pdo();
        $commissionRate = bv_seller_balance_commission_rate();
        $processed = [];

        $stmt = $pdo->prepare(
            'SELECT oi.id AS item_id,
                    oi.seller_id,
                    oi.line_total,
                    oi.currency,
                    oi.item_title,
                    o.order_code
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.order_id = ?
               AND oi.seller_id > 0'
        );
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $itemId = (int)$item['item_id'];
            $sellerId = (int)$item['seller_id'];
            $currency = (string)($item['currency'] ?? 'USD');
            $gross = round((float)($item['line_total'] ?? 0), 4);

            if ($gross <= 0 || $sellerId <= 0) {
                continue;
            }

            $earningKey = 'order_paid_earning:' . $orderId . ':' . $itemId;
            $feeKey = 'order_paid_platform_fee:' . $orderId . ':' . $itemId;
            $legacyKey = 'order_paid:' . $orderId . ':' . $itemId;

            $platformFee = round($gross * $commissionRate, 4);
            $netEarning = round($gross - $platformFee, 4);

            try {
                $pdo->beginTransaction();

                $legacyExists = _bv_sb_ledger_exists($pdo, $legacyKey);
                $earningExists = _bv_sb_ledger_exists($pdo, $earningKey);
                $feeExists = _bv_sb_ledger_exists($pdo, $feeKey);

                if ($legacyExists) {
                    $pdo->rollBack();
                    $processed[] = $itemId;
                    continue;
                }

                if ($earningExists && $feeExists) {
                    $pdo->rollBack();
                    $processed[] = $itemId;
                    continue;
                }

                $pdo->prepare(
                    'INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)'
                )->execute([$sellerId, $currency]);

                $balRow = $pdo->prepare(
                    'SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE'
                );
                $balRow->execute([$sellerId]);
                $balance = $balRow->fetch(PDO::FETCH_ASSOC);

                $pendingBefore = round((float)($balance['pending_balance'] ?? 0), 4);
                 $pendingCursor = $pendingBefore;
                $pendingDelta = 0.0;
                $grossDelta = 0.0;
                $feeDelta = 0.0;

                 if (!$earningExists) {
                    $pendingAfterEarning = round($pendingCursor + $gross, 4);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'earning',
                        'balance_type'    => 'pending',
                        'direction'       => 'credit',
                        'amount'          => $gross,
                        'currency'        => $currency,
                        'balance_before'  => $pendingCursor,
                        'balance_after'   => $pendingAfterEarning,
                        'reference_type'  => 'order_item',
                        'reference_id'    => $itemId,
                        'idempotency_key' => $earningKey,
                        'note'            => 'Order #' . $orderId . ' item #' . $itemId . ': ' . ($item['item_title'] ?? ''),
                        'meta_json'       => [
                            'order_id'        => $orderId,
                            'order_code'      => $item['order_code'] ?? '',
                            'order_item_id'   => $itemId,
                            'gross'           => $gross,
                            'commission_rate' => $commissionRate,
                            'platform_fee'    => $platformFee,
                            'net_earning'     => $netEarning,
                        ],
                        'created_by_type' => 'system',
                    ]);
                    $pendingCursor = $pendingAfterEarning;
                    $pendingDelta = round($pendingDelta + $gross, 4);
                    $grossDelta = $gross;
                }
               if (!$feeExists) {
                    $pendingAfterFee = round($pendingCursor - $platformFee, 4);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id'       => $sellerId,
                        'type'            => 'platform_fee',
                        'balance_type'    => 'pending',
                        'direction'       => 'debit',
                        'amount'          => $platformFee,
                        'currency'        => $currency,
                        'balance_before'  => $pendingCursor,
                        'balance_after'   => $pendingAfterFee,
                        'reference_type'  => 'order_item',
                        'reference_id'    => $itemId,
                        'idempotency_key' => $feeKey,
                        'note'            => 'Platform fee ' . round($commissionRate * 100, 2) . '% on order #' . $orderId,
                        'meta_json'       => [
                            'order_id'        => $orderId,
                            'order_item_id'   => $itemId,
                            'gross'           => $gross,
                            'commission_rate' => $commissionRate,
                            'platform_fee'    => $platformFee,
                        ],
                        'created_by_type' => 'system',
                    ]);
                    $pendingCursor = $pendingAfterFee;
                    $pendingDelta = round($pendingDelta - $platformFee, 4);
                    $feeDelta = $platformFee;
                }
				
                if ($pendingDelta !== 0.0 || $grossDelta !== 0.0 || $feeDelta !== 0.0) {
                    $pdo->prepare(
                        'UPDATE seller_balances
                         SET pending_balance = pending_balance + :pending_delta,
                             total_earned_gross = total_earned_gross + :gross_delta,
                             total_platform_fee = total_platform_fee + :fee_delta,
                             currency = :currency
                         WHERE seller_id = :sid'
                    )->execute([
                        ':pending_delta' => $pendingDelta,
                        ':gross_delta' => $grossDelta,
                        ':fee_delta' => $feeDelta,
                        ':currency' => $currency,
                        ':sid' => $sellerId,
                    ]);
                }
                $pdo->commit();
                $processed[] = $itemId;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[seller_balance] process_order_paid failed for order #' . $orderId . ' item #' . $itemId . ': ' . $e->getMessage());
            }
        }

        return $processed;
    }
}
// ---------------------------------------------------------------------------
// PENDING → AVAILABLE RELEASE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_find_releasable_ledger_rows')) {
    function bv_seller_balance_find_releasable_ledger_rows(?int $sellerId = null): array
    {
        $pdo = bv_seller_balance_pdo();
        $days = max(0, (int)bv_seller_balance_get_setting('payout_clearance_days', '3'));
        $params = [$days];
        $sellerSql = '';
        if ($sellerId !== null && $sellerId > 0) {
          $sellerSql = ' AND e.seller_id = ?';
            $params[] = $sellerId; 
        }

        $activeRefundSql = '';
        if (_bv_sb_table_exists($pdo, 'order_refunds') && _bv_sb_table_exists($pdo, 'order_refund_items')) {
            $activeRefundSql = "
               AND NOT EXISTS (
                   SELECT 1
                   FROM order_refund_items ri
                   JOIN order_refunds r ON r.id = ri.refund_id
                   WHERE ri.order_item_id = oi.id
                     AND r.status IN ('draft','pending_approval','partially_approved','approved','processing','partially_refunded')
               )";
        }

        $paymentStatusSql = '';
        if (_bv_sb_column_exists($pdo, 'orders', 'payment_status')) {
            $paymentStatusSql = " AND o.payment_status IN ('paid','succeeded','complete','completed')";
        }

        $sql = "SELECT e.seller_id,
                       e.reference_id AS order_item_id,
                       e.currency,
                       e.created_at,
                       oi.order_id,
                       o.order_code,
                       COALESCE(e.amount, 0) AS gross_amount,
                       COALESCE(f.pending_fee_amount, 0) AS fee_amount,
                       COALESCE(e.amount, 0) - COALESCE(f.pending_fee_amount, 0) AS net_amount 
                FROM seller_ledger e
                JOIN order_items oi ON oi.id = e.reference_id AND oi.seller_id = e.seller_id
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN (
                    SELECT seller_id,
                           reference_id,
                           SUM(amount) AS pending_fee_amount
                    FROM seller_ledger
                    WHERE type = 'platform_fee'
                      AND balance_type = 'pending'
                      AND direction = 'debit'
                      AND reference_type = 'order_item'
                    GROUP BY seller_id, reference_id
                ) f
                   ON f.seller_id = e.seller_id
                  AND f.reference_id = e.reference_id
                WHERE e.type = 'earning'
                  AND e.balance_type = 'pending'
                  AND e.direction = 'credit'
                  AND e.reference_type = 'order_item'
                   AND e.amount > 0
                  AND e.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND o.status IN ('confirmed','paid','processing','shipped','completed')
                  $paymentStatusSql
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger prd
                      WHERE prd.idempotency_key = CONCAT('pending_release_debit:', e.seller_id, ':', e.reference_id)
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger prc
                      WHERE prc.idempotency_key = CONCAT('pending_release_credit:', e.seller_id, ':', e.reference_id)
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM seller_ledger rh
                      WHERE rh.seller_id = e.seller_id
                        AND rh.type = 'refund_hold'
                        AND rh.reference_type = 'order_item'
                        AND rh.reference_id = e.reference_id
                  )
                  $sellerSql
                  $activeRefundSql
                HAVING net_amount > 0
                ORDER BY e.created_at ASC, e.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('bv_seller_balance_release_pending_by_order_item')) {
    function bv_seller_balance_release_pending_by_order_item(int $orderItemId): bool
    {
        if ($orderItemId <= 0) {
            return false;
        }
        $rows = bv_seller_balance_find_releasable_ledger_rows(null);
        foreach ($rows as $row) {
            if ((int)$row['order_item_id'] === $orderItemId) {
                return bv_seller_balance_release_pending_for_row($row);
            }
        }
        return false;
    }
}

if (!function_exists('bv_seller_balance_release_pending_for_seller')) {
    function bv_seller_balance_release_pending_for_seller(int $sellerId, string $currency = 'USD'): array
    {
        if ($sellerId <= 0) {
            return [
                'released_count' => 0,
                'released_amount' => 0.0,
                'skipped_count' => 0,
                'errors' => ['Invalid seller_id'],
            ];
        }
 
        $result = [
            'released_count' => 0,
            'released_amount' => 0.0,
            'skipped_count' => 0,
            'errors' => [],
        ];
        foreach (bv_seller_balance_find_releasable_ledger_rows($sellerId) as $row) {
            if ($currency !== '' && strcasecmp((string)$row['currency'], $currency) !== 0) {
				                $result['skipped_count']++;
                continue;
            }
            $amount = round((float)($row['net_amount'] ?? 0), 4);
            if ($amount <= 0) {
                $result['skipped_count']++;
                continue;
            }
            try {
                if (bv_seller_balance_release_pending_for_row($row)) {
                    $result['released_count']++;
                    $result['released_amount'] = round(((float)$result['released_amount']) + $amount, 4);
                } else {
                    $result['skipped_count']++;
                }
            } catch (Throwable $e) {
                $result['skipped_count']++;
                $result['errors'][] = $e->getMessage();
            }
        }
        return $result;
    }
}

if (!function_exists('bv_seller_balance_release_pending_for_row')) {
    function bv_seller_balance_release_pending_for_row(array $row): bool
    {
        $sellerId = (int)($row['seller_id'] ?? 0);
        $orderItemId = (int)($row['order_item_id'] ?? 0);
        $amount = round((float)($row['net_amount'] ?? 0), 4);
        $currency = (string)($row['currency'] ?? 'USD');
        if ($sellerId <= 0 || $orderItemId <= 0 || $amount <= 0) {
            return false;
        }

        $pdo = bv_seller_balance_pdo();
        $debitKey = 'pending_release_debit:' . $sellerId . ':' . $orderItemId;
        $creditKey = 'pending_release_credit:' . $sellerId . ':' . $orderItemId;

        try {
            $pdo->beginTransaction();
            if (_bv_sb_ledger_exists($pdo, $debitKey) || _bv_sb_ledger_exists($pdo, $creditKey)) {
                $pdo->rollBack();
                return false;
            }

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $pendingNow = round((float)($balance['pending_balance'] ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            $releaseAmt = min($amount, $pendingNow);
            if ($releaseAmt <= 0) {
                $pdo->rollBack();
                return false;
            }

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'pending',
                'direction' => 'debit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $pendingNow,
                'balance_after' => round($pendingNow - $releaseAmt, 4),
                'reference_type' => 'order_item',
                'reference_id' => $orderItemId,
                'idempotency_key' => $debitKey,
                'note' => 'Pending released for order item #' . $orderItemId,
                'meta_json' => ['order_id' => (int)($row['order_id'] ?? 0), 'order_code' => (string)($row['order_code'] ?? '')],
                'created_by_type' => 'system',
            ]);

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'available',
                'direction' => 'credit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow + $releaseAmt, 4),
                'reference_type' => 'order_item',
                'reference_id' => $orderItemId,
                'idempotency_key' => $creditKey,
                'note' => 'Pending released for order item #' . $orderItemId,
                'meta_json' => ['order_id' => (int)($row['order_id'] ?? 0), 'order_code' => (string)($row['order_code'] ?? '')],
                'created_by_type' => 'system',
            ]);

            $pdo->prepare(
                'UPDATE seller_balances
                 SET pending_balance = pending_balance - ?,
                     available_balance = available_balance + ?
                 WHERE seller_id = ?'
            )->execute([$releaseAmt, $releaseAmt, $sellerId]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_pending row failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_release_pending')) {
    function bv_seller_balance_release_pending(
        int $sellerId,
        float $amount,
        string $referenceNote = '',
        string $idempotencyKey = '',
        int $adminId = 0
    ): bool {
        if ($sellerId <= 0 || $amount <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $amount = round($amount, 4);
        $debitKey = ($idempotencyKey ?: 'admin_pending_release:' . $sellerId . ':' . sha1($referenceNote . ':' . $amount)) . ':debit';
        $creditKey = ($idempotencyKey ?: 'admin_pending_release:' . $sellerId . ':' . sha1($referenceNote . ':' . $amount)) . ':credit';

        try {
            $pdo->beginTransaction();
            if (_bv_sb_ledger_exists($pdo, $debitKey) || _bv_sb_ledger_exists($pdo, $creditKey)) {
                $pdo->rollBack();
                return true;
            }
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }
            $pendingNow = round((float)$balance['pending_balance'], 4);
            $availableNow = round((float)$balance['available_balance'], 4);
            $releaseAmt = min($amount, $pendingNow);
            if ($releaseAmt <= 0) {
                $pdo->rollBack();
                return false;
            }
            $currency = (string)($balance['currency'] ?? 'USD');
            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'pending',
                'direction' => 'debit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $pendingNow,
                'balance_after' => round($pendingNow - $releaseAmt, 4),
                'idempotency_key' => $debitKey,
                'note' => $referenceNote ?: 'Pending balance released to available',
                'created_by_type' => $adminId > 0 ? 'admin' : 'system',
                'created_by_id' => $adminId ?: null,
            ]);
            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'pending_release',
                'balance_type' => 'available',
                'direction' => 'credit',
                'amount' => $releaseAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow + $releaseAmt, 4),
                'idempotency_key' => $creditKey,
                'note' => $referenceNote ?: 'Pending balance released to available',
                'created_by_type' => $adminId > 0 ? 'admin' : 'system',
                'created_by_id' => $adminId ?: null,
            ]);
            $pdo->prepare(
                'UPDATE seller_balances
                 SET pending_balance = pending_balance - :amt,
                     available_balance = available_balance + :amt
                 WHERE seller_id = :sid'
            )->execute([':amt' => $releaseAmt, ':sid' => $sellerId]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_pending failed for seller #' . $sellerId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// PAYOUT REQUEST — SELLER SUBMITS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_request_payout')) {
    /**
     * Seller requests a payout from available_balance.
     * Moves amount: available_balance -> held_balance.
     */
    function bv_seller_balance_request_payout(int $sellerId, float $amount, array $details = []): int
    {
        if ($sellerId <= 0 || $amount <= 0) {
            return 0;
        }

        $minAmount = (float)bv_seller_balance_get_setting('payout_min_amount', '10.00');
        if ($amount < $minAmount) {
            throw new RuntimeException('Payout amount must be at least ' . number_format($minAmount, 2));
        }
        if (bv_seller_balance_get_setting('payout_enabled', '1') !== '1') {
            throw new RuntimeException('Payout requests are temporarily disabled.');
        }

        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                throw new RuntimeException('No balance record found for seller.');
            }

            $currency = (string)($balance['currency'] ?? 'USD');
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
            $requestAmt = round($amount, 4);

            if ($availableNow <= 0 || $requestAmt > $availableNow) {
                $pdo->rollBack();
                throw new RuntimeException('Requested amount exceeds available balance. Available: ' . number_format($availableNow, 2));
            }

            $openStmt = $pdo->prepare(
                "SELECT id FROM seller_payout_requests
                 WHERE seller_id = ? AND currency = ? AND status IN ('requested','approved')
                 LIMIT 1 FOR UPDATE"
            );
            $openStmt->execute([$sellerId, $currency]);
            if ($openStmt->fetchColumn()) {
                $pdo->rollBack();
                throw new RuntimeException('You already have an open payout request. Please wait for it to be processed.');
            }

            $pdo->prepare(
                'INSERT INTO seller_payout_requests
                 (seller_id, amount, currency, status, bank_name, bank_account_number,
                  bank_account_name, promptpay_number, payout_method, seller_note)
                 VALUES
                 (:sid, :amt, :cur, :status, :bank, :acct, :acct_name, :pp, :method, :note)'
            )->execute([
                ':sid' => $sellerId,
                ':amt' => $requestAmt,
                ':cur' => $currency,
                ':status' => 'requested',
                ':bank' => $details['bank_name'] ?? null,
                ':acct' => $details['bank_account_number'] ?? null,
                ':acct_name' => $details['bank_account_name'] ?? null,
                ':pp' => $details['promptpay_number'] ?? null,
                ':method' => $details['payout_method'] ?? null,
                ':note' => $details['seller_note'] ?? null,
            ]);
            $payoutRequestId = (int)$pdo->lastInsertId();

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'payout_request',
                'balance_type' => 'available',
                'direction' => 'debit',
                'amount' => $requestAmt,
                'currency' => $currency,
                'balance_before' => $availableNow,
                'balance_after' => round($availableNow - $requestAmt, 4),
                'reference_type' => 'payout_request',
                'reference_id' => $payoutRequestId,
                'idempotency_key' => 'payout_request_available:' . $payoutRequestId,
                'note' => 'Payout request #' . $payoutRequestId,
                'created_by_type' => $details['created_by_type'] ?? 'user',
                'created_by_id' => $details['created_by_id'] ?? $sellerId,
            ]);

            _bv_sb_insert_ledger_once($pdo, [
                'seller_id' => $sellerId,
                'type' => 'payout_request',
                'balance_type' => 'held',
                'direction' => 'credit',
                'amount' => $requestAmt,
                'currency' => $currency,
                'balance_before' => $heldNow,
                'balance_after' => round($heldNow + $requestAmt, 4),
                'reference_type' => 'payout_request',
                'reference_id' => $payoutRequestId,
                'idempotency_key' => 'payout_request_held:' . $payoutRequestId,
                'note' => 'Payout request #' . $payoutRequestId . ' held',
                'created_by_type' => $details['created_by_type'] ?? 'user',
                'created_by_id' => $details['created_by_id'] ?? $sellerId,
            ]);

            $pdo->prepare(
                'UPDATE seller_balances
                 SET available_balance = available_balance - :amt,
                     held_balance = held_balance + :amt
                 WHERE seller_id = :sid'
            )->execute([':amt' => $requestAmt, ':sid' => $sellerId]);

            $pdo->commit();
            return $payoutRequestId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
// ---------------------------------------------------------------------------
// PAYOUT — ADMIN: APPROVE
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_approve_payout')) {
    function bv_seller_balance_approve_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        if ($payoutId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            if ((string)$req['status'] === 'approved') {
                $pdo->commit();
                return true;
            }
            if ((string)$req['status'] !== 'requested') {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare(
                "UPDATE seller_payout_requests
                 SET status = 'approved', approved_at = COALESCE(approved_at, NOW()), admin_id = :aid,
                     admin_note = CONCAT(COALESCE(admin_note,''), :note)
                 WHERE id = :id AND status = 'requested'"
            )->execute([
                ':aid' => $adminId,
                ':note' => $note !== '' ? "\n[Approved] " . $note : '',
                ':id' => $payoutId,
            ]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] approve_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_reject_payout')) {
    function bv_seller_balance_reject_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        if ($payoutId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            if ((string)$req['status'] === 'rejected') {
                $pdo->commit();
                return true;
            }
            if (!in_array((string)$req['status'], ['requested','approved'], true)) {
                $pdo->rollBack();
                return false;
            }

            $sellerId = (int)$req['seller_id'];
            $amount = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];
            $heldKey = 'payout_reject_held:' . $payoutId;
            $availableKey = 'payout_reject_available:' . $payoutId;

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
            $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
            $returnAmt = min($amount, $heldNow);

            if ($returnAmt > 0 && !_bv_sb_ledger_exists($pdo, $heldKey)) {
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_cancelled',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $returnAmt,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
                    'balance_after' => round($heldNow - $returnAmt, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Payout #' . $payoutId . ' rejected - returned to available',
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_cancelled',
                    'balance_type' => 'available',
                    'direction' => 'credit',
                    'amount' => $returnAmt,
                    'currency' => $currency,
                    'balance_before' => $availableNow,
                    'balance_after' => round($availableNow + $returnAmt, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $availableKey,
                    'note' => 'Payout #' . $payoutId . ' rejected - returned to available',
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                $pdo->prepare(
                    'UPDATE seller_balances
                     SET held_balance = held_balance - :amt,
                         available_balance = available_balance + :amt
                     WHERE seller_id = :sid'
                )->execute([':amt' => $returnAmt, ':sid' => $sellerId]);
            }

            $pdo->prepare(
                "UPDATE seller_payout_requests
                 SET status = 'rejected', rejected_at = COALESCE(rejected_at, NOW()), admin_id = :aid,
                     admin_note = CONCAT(COALESCE(admin_note,''), :note)
                 WHERE id = :id"
            )->execute([
                ':aid' => $adminId,
                ':note' => "\n[Rejected] " . ($note ?: 'No reason given'),
                ':id' => $payoutId,
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] reject_payout #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_mark_payout_paid')) {
    function bv_seller_balance_mark_payout_paid(
        int $payoutId,
        int $adminId,
        string $paymentReference = '',
        string $paymentMethod = 'bank_transfer',
        string $note = ''
    ): bool {
        if ($payoutId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();
            $reqStmt = $pdo->prepare('SELECT * FROM seller_payout_requests WHERE id = ? LIMIT 1 FOR UPDATE');
            $reqStmt->execute([$payoutId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                $pdo->rollBack();
                return false;
            }
            if ((string)$req['status'] === 'paid') {
                $pdo->commit();
                return true;
            }
            if ((string)$req['status'] !== 'approved') {
                $pdo->rollBack();
                return false;
            }

            $sellerId = (int)$req['seller_id'];
            $amount = round((float)$req['amount'], 4);
            $currency = (string)$req['currency'];
            $heldKey = 'payout_paid_held:' . $payoutId;
            $paidKey = 'payout_paid_paid_out:' . $payoutId;

            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);
            if (!$balance) {
                $pdo->rollBack();
                return false;
            }

            $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
            $paidOutNow = round((float)($balance['paid_out_balance'] ?? 0), 4);
            $payAmt = min($amount, $heldNow);
            if ($payAmt <= 0) {
                $pdo->rollBack();
                return false;
            }

            if (!_bv_sb_ledger_exists($pdo, $heldKey)) {
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_paid',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $payAmt,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
                    'balance_after' => round($heldNow - $payAmt, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Payout #' . $payoutId . ' paid - ref: ' . $paymentReference,
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'payout_paid',
                    'balance_type' => 'paid_out',
                    'direction' => 'credit',
                    'amount' => $payAmt,
                    'currency' => $currency,
                    'balance_before' => $paidOutNow,
                    'balance_after' => round($paidOutNow + $payAmt, 4),
                    'reference_type' => 'payout_request',
                    'reference_id' => $payoutId,
                    'idempotency_key' => $paidKey,
                    'note' => 'Payout #' . $payoutId . ' paid - ref: ' . $paymentReference,
                    'created_by_type' => 'admin',
                    'created_by_id' => $adminId,
                ]);
                $pdo->prepare(
                    'UPDATE seller_balances
                     SET held_balance = held_balance - :amt,
                         paid_out_balance = paid_out_balance + :amt
                     WHERE seller_id = :sid'
                )->execute([':amt' => $payAmt, ':sid' => $sellerId]);
            }

            $pdo->prepare(
                'INSERT IGNORE INTO seller_payout_transactions
                 (payout_request_id, seller_id, amount, currency, payment_method,
                  payment_reference, bank_name, bank_account_number, bank_account_name,
                  admin_id, note)
                 VALUES
                 (:prid, :sid, :amt, :cur, :method, :ref, :bank, :acct, :acct_name, :aid, :note)'
            )->execute([
                ':prid' => $payoutId,
                ':sid' => $sellerId,
                ':amt' => $payAmt,
                ':cur' => $currency,
                ':method' => $paymentMethod,
                ':ref' => $paymentReference,
                ':bank' => $req['bank_name'] ?? null,
                ':acct' => $req['bank_account_number'] ?? null,
                ':acct_name' => $req['bank_account_name'] ?? null,
                ':aid' => $adminId,
                ':note' => $note,
            ]);

            $pdo->prepare(
                "UPDATE seller_payout_requests
                 SET status = 'paid', paid_at = COALESCE(paid_at, NOW()), admin_id = :aid,
                     payment_reference = :ref,
                     admin_note = CONCAT(COALESCE(admin_note,''), :note)
                 WHERE id = :id"
            )->execute([
                ':aid' => $adminId,
                ':ref' => $paymentReference,
                ':note' => $note !== '' ? "\n[Paid] " . $note : '',
                ':id' => $payoutId,
            ]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] mark_payout_paid #' . $payoutId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_cancel_payout')) {
    function bv_seller_balance_cancel_payout(int $payoutId, int $adminId, string $note = ''): bool
    {
        return bv_seller_balance_reject_payout($payoutId, $adminId, $note ?: 'Cancelled by admin');
    }
}
// ---------------------------------------------------------------------------
// REFUND STUBS — Phase 1 (prepared, not enforced)
// ---------------------------------------------------------------------------

if (!function_exists('_bv_sb_refund_seller_exposures')) {
    function _bv_sb_refund_seller_exposures(PDO $pdo, int $refundId): array
    {
        if ($refundId <= 0 || !_bv_sb_table_exists($pdo, 'order_refunds') || !_bv_sb_table_exists($pdo, 'order_refund_items')) {
            return [];
        }
        $amountExpr = 'COALESCE(NULLIF(ri.actual_refund_amount, 0), NULLIF(ri.actual_refunded_amount, 0), NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        if (!_bv_sb_column_exists($pdo, 'order_refund_items', 'actual_refund_amount')) {
            $amountExpr = 'COALESCE(NULLIF(ri.actual_refunded_amount, 0), NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        }
        if (!_bv_sb_column_exists($pdo, 'order_refund_items', 'actual_refunded_amount')) {
            $amountExpr = 'COALESCE(NULLIF(ri.approved_refund_amount, 0), NULLIF(ri.refund_line_amount, 0), NULLIF(ri.requested_refund_amount, 0), 0)';
        }

        $stmt = $pdo->prepare(
            "SELECT r.id AS refund_id,
                    r.order_id,
                    r.status,
                    r.currency,
                    ri.order_item_id,
                    oi.seller_id,
                    oi.line_total,
                    $amountExpr AS refund_amount,
                    COALESCE(e.amount, oi.line_total, 0) AS gross_amount,
                    COALESCE(f.amount, 0) AS fee_amount
             FROM order_refunds r
             JOIN order_refund_items ri ON ri.refund_id = r.id
             JOIN order_items oi ON oi.id = ri.order_item_id AND oi.seller_id > 0
             LEFT JOIN seller_ledger e
               ON e.seller_id = oi.seller_id AND e.type = 'earning'
              AND e.reference_type = 'order_item' AND e.reference_id = oi.id
              JOIN seller_ledger f
               ON f.seller_id = oi.seller_id AND f.type = 'platform_fee'
              AND f.reference_type = 'order_item' AND f.reference_id = oi.id
             WHERE r.id = :rid"
        );
        $stmt->execute([':rid' => $refundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bySeller = [];
        foreach ($rows as $row) {
            $sellerId = (int)($row['seller_id'] ?? 0);
            $lineTotal = round((float)($row['line_total'] ?? 0), 4);
            $refundAmount = round((float)($row['refund_amount'] ?? 0), 4);
            if ($sellerId <= 0 || $refundAmount <= 0) {
                continue;
            }
            $gross = round((float)($row['gross_amount'] ?? $lineTotal), 4);
            $fee = round((float)($row['fee_amount'] ?? 0), 4);
            $net = max(0.0, round($gross - $fee, 4));
            $ratio = $lineTotal > 0 ? min(1.0, $refundAmount / $lineTotal) : 1.0;
            $exposure = round($net * $ratio, 4);
            if ($exposure <= 0) {
                continue;
            }
            if (!isset($bySeller[$sellerId])) {
                $bySeller[$sellerId] = [
                    'seller_id' => $sellerId,
                    'currency' => (string)($row['currency'] ?? 'USD'),
                    'amount' => 0.0,
                    'items' => [],
                ];
            }
            $bySeller[$sellerId]['amount'] = round($bySeller[$sellerId]['amount'] + $exposure, 4);
            $bySeller[$sellerId]['items'][] = [
                'order_item_id' => (int)$row['order_item_id'],
                'refund_amount' => $refundAmount,
                'seller_exposure' => $exposure,
            ];
        }
        return array_values($bySeller);
    }
}

if (!function_exists('bv_seller_balance_hold_for_refund')) {
    function bv_seller_balance_hold_for_refund(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $groups = _bv_sb_refund_seller_exposures($pdo, $refundId);
        if ($groups === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($groups as $group) {
                $sellerId = (int)$group['seller_id'];
                $needed = round((float)$group['amount'], 4);
                if ($sellerId <= 0 || $needed <= 0) {
                    continue;
                }
                $availableKey = 'refund_hold_available:' . $refundId . ':' . $sellerId;
                $heldKey = 'refund_hold_held:' . $refundId . ':' . $sellerId;
                if (_bv_sb_ledger_exists($pdo, $availableKey) || _bv_sb_ledger_exists($pdo, $heldKey)) {
                    continue;
                }

                $pdo->prepare('INSERT IGNORE INTO seller_balances (seller_id, currency) VALUES (?, ?)')
                    ->execute([$sellerId, (string)$group['currency']]);
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
                $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
                $holdAmt = min($needed, $availableNow);
                $shortage = round($needed - $holdAmt, 4);
                if ($holdAmt <= 0 && $shortage <= 0) {
                    continue;
                }
                $meta = ['refund_id' => $refundId, 'needed' => $needed, 'held' => $holdAmt, 'shortage' => $shortage, 'items' => $group['items']];

                if ($holdAmt > 0) {
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'available',
                        'direction' => 'debit',
                        'amount' => $holdAmt,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $availableNow,
                        'balance_after' => round($availableNow - $holdAmt, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $availableKey,
                        'note' => 'Refund hold #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'held',
                        'direction' => 'credit',
                        'amount' => $holdAmt,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $heldNow,
                        'balance_after' => round($heldNow + $holdAmt, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $heldKey,
                        'note' => 'Refund hold #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                    $pdo->prepare(
                        'UPDATE seller_balances SET available_balance = available_balance - :amt, held_balance = held_balance + :amt WHERE seller_id = :sid'
                    )->execute([':amt' => $holdAmt, ':sid' => $sellerId]);
                } elseif ($shortage > 0) {
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_hold',
                        'balance_type' => 'available',
                        'direction' => 'debit',
                        'amount' => 0,
                        'currency' => (string)$group['currency'],
                        'balance_before' => $availableNow,
                        'balance_after' => $availableNow,
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $availableKey,
                        'note' => 'Refund hold shortage #' . $refundId,
                        'meta_json' => $meta,
                        'created_by_type' => 'system',
                    ]);
                }
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] hold_for_refund #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_apply_refund_deduction')) {
    function bv_seller_balance_apply_refund_deduction(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $groups = _bv_sb_refund_seller_exposures($pdo, $refundId);
        if ($groups === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($groups as $group) {
                $sellerId = (int)$group['seller_id'];
                $needed = round((float)$group['amount'], 4);
                $currency = (string)$group['currency'];
                if ($sellerId <= 0 || $needed <= 0) {
                    continue;
                }
                $doneKey = 'refund_deduction:' . $refundId . ':' . $sellerId;
                if (_bv_sb_ledger_exists($pdo, $doneKey . ':held') || _bv_sb_ledger_exists($pdo, $doneKey . ':available') || _bv_sb_ledger_exists($pdo, $doneKey . ':pending')) {
                    continue;
                }
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                if (!$balance) {
                    continue;
                }
                $remaining = $needed;
                foreach ([
                    'held' => 'held_balance',
                    'available' => 'available_balance',
                    'pending' => 'pending_balance',
                ] as $balanceType => $column) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $before = round((float)($balance[$column] ?? 0), 4);
                    $deduct = min($remaining, $before);
                    if ($deduct <= 0) {
                        continue;
                    }
                    _bv_sb_insert_ledger_once($pdo, [
                        'seller_id' => $sellerId,
                        'type' => 'refund_deduction',
                        'balance_type' => $balanceType,
                        'direction' => 'debit',
                        'amount' => $deduct,
                        'currency' => $currency,
                        'balance_before' => $before,
                        'balance_after' => round($before - $deduct, 4),
                        'reference_type' => 'refund',
                        'reference_id' => $refundId,
                        'idempotency_key' => $doneKey . ':' . $balanceType,
                        'note' => 'Refund deduction #' . $refundId,
                        'meta_json' => ['refund_id' => $refundId, 'needed' => $needed, 'items' => $group['items']],
                        'created_by_type' => 'system',
                    ]);
                    $pdo->prepare('UPDATE seller_balances SET ' . $column . ' = ' . $column . ' - :amt WHERE seller_id = :sid')
                        ->execute([':amt' => $deduct, ':sid' => $sellerId]);
                    $balance[$column] = round($before - $deduct, 4);
                    $remaining = round($remaining - $deduct, 4);
                }
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] apply_refund_deduction #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bv_seller_balance_release_refund_hold')) {
    function bv_seller_balance_release_refund_hold(int $refundId): bool
    {
        if ($refundId <= 0) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        $stmt = $pdo->prepare(
            "SELECT seller_id, currency, SUM(CASE WHEN balance_type = 'held' AND direction = 'credit' THEN amount ELSE 0 END) AS held_amount
             FROM seller_ledger
             WHERE type = 'refund_hold' AND reference_type = 'refund' AND reference_id = ?
             GROUP BY seller_id, currency"
        );
        $stmt->execute([$refundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return false;
        }
        try {
            $pdo->beginTransaction();
            foreach ($rows as $row) {
                $sellerId = (int)$row['seller_id'];
                $amount = round((float)$row['held_amount'], 4);
                $currency = (string)$row['currency'];
                $heldKey = 'refund_hold_release_held:' . $refundId . ':' . $sellerId;
                $availableKey = 'refund_hold_release_available:' . $refundId . ':' . $sellerId;
                if ($sellerId <= 0 || $amount <= 0 || _bv_sb_ledger_exists($pdo, $heldKey)) {
                    continue;
                }
                $balStmt = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
                $balStmt->execute([$sellerId]);
                $balance = $balStmt->fetch(PDO::FETCH_ASSOC);
                if (!$balance) {
                    continue;
                }
                $heldNow = round((float)($balance['held_balance'] ?? 0), 4);
                $availableNow = round((float)($balance['available_balance'] ?? 0), 4);
                $releaseAmt = min($amount, $heldNow);
                if ($releaseAmt <= 0) {
                    continue;
                }
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'refund_hold',
                    'balance_type' => 'held',
                    'direction' => 'debit',
                    'amount' => $releaseAmt,
                    'currency' => $currency,
                    'balance_before' => $heldNow,
                    'balance_after' => round($heldNow - $releaseAmt, 4),
                    'reference_type' => 'refund',
                    'reference_id' => $refundId,
                    'idempotency_key' => $heldKey,
                    'note' => 'Refund hold released #' . $refundId,
                    'created_by_type' => 'system',
                ]);
                _bv_sb_insert_ledger_once($pdo, [
                    'seller_id' => $sellerId,
                    'type' => 'refund_hold',
                    'balance_type' => 'available',
                    'direction' => 'credit',
                    'amount' => $releaseAmt,
                    'currency' => $currency,
                    'balance_before' => $availableNow,
                    'balance_after' => round($availableNow + $releaseAmt, 4),
                    'reference_type' => 'refund',
                    'reference_id' => $refundId,
                    'idempotency_key' => $availableKey,
                    'note' => 'Refund hold released #' . $refundId,
                    'created_by_type' => 'system',
                ]);
                $pdo->prepare('UPDATE seller_balances SET held_balance = held_balance - :amt, available_balance = available_balance + :amt WHERE seller_id = :sid')
                    ->execute([':amt' => $releaseAmt, ':sid' => $sellerId]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[seller_balance] release_refund_hold #' . $refundId . ': ' . $e->getMessage());
            return false;
        }
    }
}
// ---------------------------------------------------------------------------
// ADMIN MANUAL ADJUSTMENT
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_admin_adjust')) {
    /**
     * Add or subtract from available_balance manually.
     * $direction: 'credit' or 'debit'
     */
    function bv_seller_balance_admin_adjust(
        int    $sellerId,
        float  $amount,
        string $direction,
        string $note,
        int    $adminId
    ): bool {
        if ($sellerId <= 0 || $amount <= 0 || !in_array($direction, ['credit','debit'], true)) {
            return false;
        }
        $pdo = bv_seller_balance_pdo();
        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT IGNORE INTO seller_balances (seller_id) VALUES (?)')->execute([$sellerId]);
            $balRow = $pdo->prepare('SELECT * FROM seller_balances WHERE seller_id = ? LIMIT 1 FOR UPDATE');
            $balRow->execute([$sellerId]);
            $balance = $balRow->fetch(PDO::FETCH_ASSOC);

            $availNow = round((float)($balance['available_balance'] ?? 0), 4);
            $delta    = round($amount, 4);
            $newAvail = $direction === 'credit'
                ? round($availNow + $delta, 4)
                : round($availNow - $delta, 4);

            if ($newAvail < 0) {
                $pdo->rollBack();
                throw new RuntimeException('Debit would result in negative available_balance.');
            }

            $type = $direction === 'credit' ? 'adjustment_credit' : 'adjustment_debit';

            _bv_sb_insert_ledger($pdo, [
                'seller_id'      => $sellerId,
                'type'           => $type,
                'balance_type'   => 'available',
                'direction'      => $direction,
                'amount'         => $delta,
                'currency'       => (string)($balance['currency'] ?? 'USD'),
                'balance_before' => $availNow,
                'balance_after'  => $newAvail,
                'note'           => $note,
                'created_by_type'=> 'admin',
                'created_by_id'  => $adminId,
            ]);

            $pdo->prepare(
                'UPDATE seller_balances SET available_balance = :avail WHERE seller_id = :sid'
            )->execute([':avail' => $newAvail, ':sid' => $sellerId]);

            $pdo->commit();
            return true;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

// ---------------------------------------------------------------------------
// AUTO PAYOUT REQUESTS
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_create_auto_payout_requests')) {
    function bv_seller_balance_create_auto_payout_requests(): int
    {
        if (bv_seller_balance_get_setting('auto_payout_enabled', '0') !== '1') {
            return 0;
        }

        $pdo = bv_seller_balance_pdo();
        $minAmount = max(0.0, (float)bv_seller_balance_get_setting('auto_payout_min_amount', '50.00'));
        $method = bv_seller_balance_get_setting('payout_method', 'manual_bank_transfer');
        $created = 0;

        $stmt = $pdo->prepare(
            "SELECT sb.*
             FROM seller_balances sb
             WHERE sb.available_balance >= :min_amount
               AND NOT EXISTS (
                   SELECT 1 FROM seller_payout_requests pr
                   WHERE pr.seller_id = sb.seller_id
                     AND pr.currency = sb.currency
                     AND pr.status IN ('requested','approved')
               )
             ORDER BY sb.seller_id ASC"
        );
        $stmt->execute([':min_amount' => $minAmount]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $sellerId = (int)$row['seller_id'];
            $amount = round((float)$row['available_balance'], 4);
            if ($sellerId <= 0 || $amount < $minAmount) {
                continue;
            }
            try {
                $id = bv_seller_balance_request_payout($sellerId, $amount, [
                    'payout_method' => $method,
                    'seller_note' => 'Automatic payout request',
                    'created_by_type' => 'system',
                    'created_by_id' => null,
                ]);
                if ($id > 0) {
                    $created++;
                }
            } catch (Throwable $e) {
                error_log('[seller_balance] auto payout seller #' . $sellerId . ': ' . $e->getMessage());
            }
        }

        return $created;
    }
}

// ---------------------------------------------------------------------------
// READ QUERIES
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_get_ledger')) {
    function bv_seller_balance_get_ledger(int $sellerId, int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT * FROM seller_ledger
                 WHERE seller_id = ?
                 ORDER BY id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$sellerId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_get_payout_requests')) {
    function bv_seller_balance_get_payout_requests(
        int    $sellerId,
        string $status  = '',
        int    $limit   = 20,
        int    $offset  = 0
    ): array {
        try {
            $where = 'WHERE seller_id = ?';
            $params = [$sellerId];
            if ($status !== '') {
                $where .= ' AND status = ?';
                $params[] = $status;
            }
            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT * FROM seller_payout_requests $where ORDER BY id DESC LIMIT ? OFFSET ?"
            );
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_get_all_payout_requests')) {
    function bv_seller_balance_get_all_payout_requests(
        string $status = '',
        int    $limit  = 50,
        int    $offset = 0
    ): array {
        try {
            $where  = $status !== '' ? "WHERE pr.status = ?" : '';
            $params = $status !== '' ? [$status] : [];
            $params[] = $limit;
            $params[] = $offset;

            $stmt = bv_seller_balance_pdo()->prepare(
                "SELECT pr.*,
                        u.email          AS seller_email,
                        u.first_name     AS seller_first,
                        u.last_name      AS seller_last
                 FROM seller_payout_requests pr
                 JOIN users u ON u.id = pr.seller_id
                 $where
                 ORDER BY pr.id DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('bv_seller_balance_all_sellers_summary')) {
    function bv_seller_balance_all_sellers_summary(): array
    {
        try {
            $stmt = bv_seller_balance_pdo()->query(
                "SELECT sb.*,
                        u.email      AS seller_email,
                        u.first_name AS seller_first,
                        u.last_name  AS seller_last
                 FROM seller_balances sb
                 JOIN users u ON u.id = sb.seller_id
                 ORDER BY sb.available_balance DESC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

if (!function_exists('bv_seller_balance_csrf_token')) {
    function bv_seller_balance_csrf_token(string $action = 'seller_balance'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key = '_bvsb_csrf_' . $action;
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION[$key];
    }
}

if (!function_exists('bv_seller_balance_verify_csrf')) {
    function bv_seller_balance_verify_csrf(string $token, string $action = 'seller_balance'): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key    = '_bvsb_csrf_' . $action;
        $stored = (string)($_SESSION[$key] ?? '');
        return $stored !== '' && hash_equals($stored, $token);
    }
}

// ---------------------------------------------------------------------------
// UTILITIES
// ---------------------------------------------------------------------------

if (!function_exists('bv_sb_e')) {
    function bv_sb_e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('bv_sb_money')) {
    function bv_sb_money(float|string $amount, string $currency = 'USD'): string
    {
        return $currency . ' ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('bv_sb_redirect')) {
    function bv_sb_redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('bv_sb_flash_set')) {
    function bv_sb_flash_set(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['_bvsb_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('bv_sb_flash_get')) {
    function bv_sb_flash_get(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $flash = $_SESSION['_bvsb_flash'] ?? [];
        unset($_SESSION['_bvsb_flash']);
        return $flash;
    }
}
