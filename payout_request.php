<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require_once dirname(__DIR__) . '/includes/seller_balance.php';

// ── Auth ──────────────────────────────────────────────────────────────────
$userId = bv_seller_balance_current_user_id();
if ($userId <= 0) {
    bv_sb_redirect('../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
}
if (!bv_seller_balance_is_seller()) {
    http_response_code(403);
    exit('Access denied: seller account required.');
}
if (!bv_seller_balance_tables_exist()) {
    http_response_code(503);
    exit('Seller balance system is not yet available.');
}

$sellerId   = $userId;
$csrfAction = 'payout_request';
$pdo = bv_seller_balance_pdo();

$openPayoutStmt = $pdo->prepare(
    "SELECT id, amount, currency, status
     FROM seller_payout_requests
     WHERE seller_id = ? AND status IN ('requested','approved')
     ORDER BY id DESC
     LIMIT 1"
);
$openPayoutStmt->execute([$sellerId]);
$openPayout = $openPayoutStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ── POST handler ──────────────────────────────────────────────────────────
$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!bv_seller_balance_verify_csrf($csrfToken, $csrfAction)) {
        $errors['csrf'] = 'Security token mismatch. Refresh the page and try again.';
    }

    $amount       = trim((string)($_POST['amount'] ?? ''));
    $payoutMethod = trim((string)($_POST['payout_method'] ?? ''));
    $bankName     = trim((string)($_POST['bank_name'] ?? ''));
    $bankAcctNum  = trim((string)($_POST['bank_account_number'] ?? ''));
    $bankAcctName = trim((string)($_POST['bank_account_name'] ?? ''));
    $promptpay    = trim((string)($_POST['promptpay_number'] ?? ''));
	$wiseEmail    = trim((string)($_POST['wise_email'] ?? ''));	
    $sellerNote   = trim((string)($_POST['seller_note'] ?? ''));

    $old = compact('amount','payoutMethod','bankName','bankAcctNum','bankAcctName','promptpay','wiseEmail','sellerNote');

    // Validate amount
    if (!is_numeric($amount) || (float)$amount <= 0) {
        $errors['amount'] = 'Please enter a valid payout amount.';
    } else {
        $minAmount = (float)bv_seller_balance_get_setting('payout_min_amount', '10.00');
        if ((float)$amount < $minAmount) {
            $errors['amount'] = 'Minimum payout amount is ' . number_format($minAmount, 2);
        }
        // Check available balance
        $balance = bv_seller_balance_get($sellerId);
        $available = (float)($balance['available_balance'] ?? 0);
        if ((float)$amount > $available) {
            $errors['amount'] = 'Amount exceeds your available balance ('
                . number_format($available, 2) . ').';
        }
    }

    // Validate payout method
    $allowedMethods = ['bank_transfer', 'promptpay', 'wise', 'other'];
    if (!in_array($payoutMethod, $allowedMethods, true)) {
        $errors['payout_method'] = 'Please select a payout method.';
    }

    // Method-specific validation
    if (empty($errors['payout_method'])) {
        if ($payoutMethod === 'bank_transfer') {
            if ($bankName === '') {
                $errors['bank_name'] = 'Bank name is required.';
            }
            if ($bankAcctNum === '') {
                $errors['bank_account_number'] = 'Account number is required.';
            }
            if ($bankAcctName === '') {
                $errors['bank_account_name'] = 'Account holder name is required.';
            }
        }
        if ($payoutMethod === 'promptpay') {
            $promptpayDigits = preg_replace('/\D+/', '', $promptpay);
            if (!in_array(strlen($promptpayDigits), [10, 13], true)) {
                $errors['promptpay_number'] = 'PromptPay number must contain exactly 10 or 13 digits.'; 
            }
           $promptpay = $promptpayDigits;
        }
        if ($payoutMethod === 'wise' && $sellerNote === '' && $wiseEmail === '') {
            $errors['seller_note'] = 'Wise payout requires note or Wise email.';			
        }
    }

    if (empty($errors)) {
        if ($openPayout) {
            $errors['submit'] = 'You already have an active payout request.';
        }
    }

    if (empty($errors)) {
        try {
            $payoutId = bv_seller_balance_request_payout($sellerId, (float)$amount, [
                'bank_name'            => $bankName,
                'bank_account_number'  => $bankAcctNum,
                'bank_account_name'    => $bankAcctName,
                'promptpay_number'     => $promptpay,
                'payout_method'        => $payoutMethod,
                'wise_email'           => $wiseEmail,				
                'seller_note'          => $sellerNote,
                'currency'             => $currency,				
            ]);
            // Rotate CSRF token after successful submit
            unset($_SESSION['_bvsb_csrf_' . $csrfAction]);
            bv_sb_flash_set('success', 'Payout request #' . $payoutId . ' submitted successfully. Admin will review and process your request.');
            bv_sb_redirect('balance.php');
        } catch (Throwable $e) {
            $errors['submit'] = $e->getMessage();
        }
    }
}

// ── GET — load balance for display ────────────────────────────────────────
$balance   = bv_seller_balance_ensure($sellerId);
$available = round((float)($balance['available_balance'] ?? 0), 2);
$currency  = (string)($balance['currency'] ?? 'USD');
$csrfToken = bv_seller_balance_csrf_token($csrfAction);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request Payout — Bettavaro Seller</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a1810;color:#e7ddca;font-size:14px;line-height:1.6}
a{color:#d8b56b;text-decoration:none}
.wrap{max-width:600px;margin:0 auto;padding:32px 16px 60px}
.page-header{margin-bottom:24px}
.page-title{font-size:24px;font-weight:900;color:#f3efe6;margin-bottom:4px}
.page-sub{color:#8ea29a;font-size:13px}
.card{border-radius:20px;border:1px solid rgba(255,255,255,.09);background:rgba(255,255,255,.03);padding:24px}
.avail-bar{border-radius:16px;padding:16px 18px;background:rgba(91,192,138,.07);border:1px solid rgba(91,192,138,.22);margin-bottom:22px;display:flex;justify-content:space-between;align-items:center}
.avail-label{font-size:12px;color:#8ea29a;text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.avail-amount{font-size:22px;font-weight:900;color:#5bc08a}
.field{margin-bottom:18px}
.field label{display:block;margin-bottom:7px;font-size:13px;font-weight:700;color:#d6ddcf}
.field label .req{color:#e06c6c}
.input,.select,.textarea{width:100%;min-height:44px;border-radius:12px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);color:#f3efe6;padding:10px 13px;font-size:13px;outline:none;font-family:inherit}
.select option{background:#1a2e24;color:#f3efe6}
.textarea{min-height:90px;resize:vertical}
.input.error,.select.error{border-color:rgba(224,108,108,.6)}
.error-msg{color:#e06c6c;font-size:12px;margin-top:5px}
.help{color:#8ea29a;font-size:12px;margin-top:5px}
.method-panel{display:none;margin-top:12px;border-radius:14px;border:1px solid rgba(255,255,255,.07);padding:16px;background:rgba(255,255,255,.02)}
.method-panel.active{display:block}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 22px;border-radius:12px;font-weight:800;font-size:14px;cursor:pointer;border:1px solid transparent;transition:.15s;width:100%}
.btn-gold{background:#d8b56b;color:#182018}.btn-gold:hover{background:#c9a45c}
.btn-outline{background:transparent;color:#e7ddca;border-color:rgba(229,201,138,.34)}
.alert{padding:12px 15px;border-radius:12px;font-size:13px;margin-bottom:18px}
.alert-error{background:rgba(214,92,92,.12);border:1px solid rgba(214,92,92,.24);color:#ffd5d5}
.divider{border:none;border-top:1px solid rgba(255,255,255,.06);margin:20px 0}
</style>
</head>
<body>
<div class="wrap">

  <div class="page-header">
    <div class="page-title">Request Payout</div>
    <div class="page-sub">
      Withdraw from your available balance •
      <a href="balance.php">← Back to Balance</a>
    </div>
  </div>

  <?php if (!empty($errors['submit'])): ?>
    <div class="alert alert-error"><?= bv_sb_e($errors['submit']) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors['csrf'])): ?>
    <div class="alert alert-error"><?= bv_sb_e($errors['csrf']) ?></div>
  <?php endif; ?>

  <?php if ($openPayout): ?>
    <div class="card" style="text-align:center;padding:40px;">
      <div style="font-size:16px;font-weight:700;color:#f3efe6;margin-bottom:8px;">Active payout request</div>
      <div style="color:#8ea29a;font-size:13px;margin-bottom:20px;">
        Payout request #<?= bv_sb_e((string)$openPayout['id']) ?> is currently
        <?= bv_sb_e((string)$openPayout['status']) ?> for
        <?= bv_sb_e(bv_sb_money((float)$openPayout['amount'], (string)$openPayout['currency'])) ?>.
      </div>
      <a href="balance.php" class="btn btn-outline" style="width:auto;display:inline-flex;">View Balance</a>
    </div>
  <?php elseif ($available <= 0): ?>
    <div class="card" style="text-align:center;padding:40px;">
      <div style="font-size:32px;margin-bottom:12px;">💸</div>
      <div style="font-size:16px;font-weight:700;color:#f3efe6;margin-bottom:8px;">No available balance</div>
      <div style="color:#8ea29a;font-size:13px;margin-bottom:20px;">
        Your earnings are still <em>pending</em> clearance.
        Once cleared by admin, you can request a payout.
      </div>
      <a href="balance.php" class="btn btn-outline" style="width:auto;display:inline-flex;">View Balance</a>
    </div>
  <?php else: ?>

  <div class="avail-bar">
    <div>
      <div class="avail-label">Available to Withdraw</div>
      <div style="font-size:12px;color:#8ea29a;margin-top:2px;">Commission already deducted</div>
    </div>
    <div class="avail-amount"><?= bv_sb_e(bv_sb_money($available, $currency)) ?></div>
  </div>

  <div class="card">
    <form method="POST" action="">
      <input type="hidden" name="csrf_token" value="<?= bv_sb_e($csrfToken) ?>">
	  
      <div class="alert" style="background:rgba(216,181,107,.1);border:1px solid rgba(216,181,107,.28);color:#f0d7a3;">
        Once submitted, this amount will be locked until admin processes the payout.
      </div>

      <!-- Amount -->
      <div class="field">
        <label for="amount">Amount <span class="req">*</span></label>
        <input
          class="input<?= !empty($errors['amount']) ? ' error' : '' ?>"
          type="number" id="amount" name="amount"
          min="0.01" max="<?= bv_sb_e((string)$available) ?>"
          step="0.01"
          value="<?= bv_sb_e($old['amount'] ?? '') ?>"
          placeholder="e.g. <?= bv_sb_e(number_format($available, 2)) ?>"
        >
        <div class="help">
          Max: <?= bv_sb_e(bv_sb_money($available, $currency)) ?>
          · Min: <?= bv_sb_e(bv_sb_money((float)bv_seller_balance_get_setting('payout_min_amount','10.00'), $currency)) ?>
        </div>
        <?php if (!empty($errors['amount'])): ?>
          <div class="error-msg"><?= bv_sb_e($errors['amount']) ?></div>
        <?php endif; ?>
      </div>

      <hr class="divider">

      <!-- Payout method -->
      <div class="field">
        <label for="payout_method">Payout Method <span class="req">*</span></label>
        <select
          class="select<?= !empty($errors['payout_method']) ? ' error' : '' ?>"
          id="payout_method" name="payout_method"
          onchange="showMethodPanel(this.value)"
        >
          <option value="">— Select method —</option>
          <option value="bank_transfer" <?= ($old['payoutMethod'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer (International / Local)</option>
          <option value="promptpay"     <?= ($old['payoutMethod'] ?? '') === 'promptpay'     ? 'selected' : '' ?>>Thai PromptPay</option>
          <option value="wise"          <?= ($old['payoutMethod'] ?? '') === 'wise'          ? 'selected' : '' ?>>Wise</option>
          <option value="other"         <?= ($old['payoutMethod'] ?? '') === 'other'         ? 'selected' : '' ?>>Other (specify in note)</option>
        </select>
        <?php if (!empty($errors['payout_method'])): ?>
          <div class="error-msg"><?= bv_sb_e($errors['payout_method']) ?></div>
        <?php endif; ?>
      </div>

      <!-- Bank transfer panel -->
      <div class="method-panel<?= ($old['payoutMethod'] ?? '') === 'bank_transfer' ? ' active' : '' ?>" id="panel-bank_transfer">
        <div class="field">
          <label for="bank_name">Bank Name</label>
          <input class="input<?= !empty($errors['bank_name']) ? ' error' : '' ?>"
            type="text" id="bank_name" name="bank_name"
            value="<?= bv_sb_e($old['bankName'] ?? '') ?>"
            placeholder="e.g. Kasikorn Bank / Bangkok Bank / DBS">
          <?php if (!empty($errors['bank_name'])): ?><div class="error-msg"><?= bv_sb_e($errors['bank_name']) ?></div><?php endif; ?>
        </div>
        <div class="field">
          <label for="bank_account_number">Account Number</label>
          <input class="input<?= !empty($errors['bank_account_number']) ? ' error' : '' ?>"
            type="text" id="bank_account_number" name="bank_account_number"
            value="<?= bv_sb_e($old['bankAcctNum'] ?? '') ?>"
            placeholder="e.g. 123-4-56789-0">
          <?php if (!empty($errors['bank_account_number'])): ?><div class="error-msg"><?= bv_sb_e($errors['bank_account_number']) ?></div><?php endif; ?>
        </div>
        <div class="field" style="margin-bottom:0">
          <label for="bank_account_name">Account Holder Name</label>
          <input class="input<?= !empty($errors['bank_account_name']) ? ' error' : '' ?>"
            type="text" id="bank_account_name" name="bank_account_name"
            value="<?= bv_sb_e($old['bankAcctName'] ?? '') ?>"
            placeholder="Full name as on bank account">
          <?php if (!empty($errors['bank_account_name'])): ?><div class="error-msg"><?= bv_sb_e($errors['bank_account_name']) ?></div><?php endif; ?>
        </div>
      </div>
	  
     <!-- Wise panel -->
      <div class="method-panel<?= ($old['payoutMethod'] ?? '') === 'wise' ? ' active' : '' ?>" id="panel-wise">
        <div class="field" style="margin-bottom:0">
          <label for="wise_email">Wise Email (optional, recommended)</label>
          <input class="input"
            type="email" id="wise_email" name="wise_email"
            value="<?= bv_sb_e($old['wiseEmail'] ?? '') ?>"
            placeholder="you@example.com">
          <div class="help">Provide Wise email or include details in note below.</div>
        </div>
      </div>
	  

      <!-- PromptPay panel -->
      <div class="method-panel<?= ($old['payoutMethod'] ?? '') === 'promptpay' ? ' active' : '' ?>" id="panel-promptpay">
        <div class="field" style="margin-bottom:0">
          <label for="promptpay_number">PromptPay Number (Mobile / National ID)</label>
          <input class="input<?= !empty($errors['promptpay_number']) ? ' error' : '' ?>"
            type="text" id="promptpay_number" name="promptpay_number"
            value="<?= bv_sb_e($old['promptpay'] ?? '') ?>"
            placeholder="e.g. 0812345678">
          <?php if (!empty($errors['promptpay_number'])): ?><div class="error-msg"><?= bv_sb_e($errors['promptpay_number']) ?></div><?php endif; ?>
        </div>
      </div>

      <hr class="divider">

      <!-- Note -->
      <div class="field">
        <label for="seller_note">Note to Admin <span style="color:#6b8070;font-weight:400;">(optional)</span></label>
        <textarea class="textarea" id="seller_note" name="seller_note"
          placeholder="Additional payout instructions, Wise email, LINE ID for confirmation, etc."><?= bv_sb_e($old['sellerNote'] ?? '') ?></textarea>
        <?php if (!empty($errors['seller_note'])): ?>
          <div class="error-msg"><?= bv_sb_e($errors['seller_note']) ?></div>
        <?php endif; ?>		  
      </div>

      <button type="submit" class="btn btn-gold" <?= ($available <= 0 || $openPayout) ? "disabled" : "" ?>>Submit Payout Request</button>
      <div style="margin-top:10px;text-align:center;">
        <a href="balance.php" style="font-size:13px;color:#8ea29a;">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div>

<script>
function showMethodPanel(method) {
  document.querySelectorAll('.method-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('panel-' + method);
  if (panel) panel.classList.add('active');
}
// Init on load
document.addEventListener('DOMContentLoaded', function() {
  const sel = document.getElementById('payout_method');
  if (sel && sel.value) showMethodPanel(sel.value);
});
</script>
</body>
</html>
