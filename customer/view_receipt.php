<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$baseUrl = getBasePath();
$pdo     = getPDO();
$userId  = $_SESSION['user_id'];

$historyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$historyId) {
    header('Location: ' . $baseUrl . 'customer/customer_history.php');
    exit;
}

// Fetch history record — must belong to this customer (payment_status check done in PHP)
$stmt = $pdo->prepare("
    SELECT hr.*,
           p.id            AS payment_id,
           p.amount_paid,
           p.paid_at,
           p.notes         AS payment_notes
    FROM   history_records hr
    LEFT JOIN payments p ON p.history_id = hr.id
    WHERE  hr.id      = :hid
      AND  hr.user_id = :uid
    LIMIT  1
");
$stmt->execute([':hid' => $historyId, ':uid' => $userId]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rec) {
    header('Location: ' . $baseUrl . 'customer/customer_history.php?msg=not_found');
    exit;
}

// If not yet paid, show a clear message rather than silently redirecting
$isPaid = ($rec['payment_status'] === 'paid');

// Fetch service line-items (show even for unpaid so customer can see invoice)
$itemStmt = $pdo->prepare("
    SELECT item_name, type, quantity, price, cost_price
    FROM   history_service_items
    WHERE  history_id = :hid
    ORDER  BY id
");
$itemStmt->execute([':hid' => $historyId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Helpers
$methodLabel = [
    'cash'          => 'Cash',
    'mobile_money'  => 'Mobile Money',
    'bank_transfer' => 'Bank Transfer',
    'card'          => 'Card',
    'other'         => 'Other',
];
$typeLabel = [
    'labor' => 'Labour',
    'part'  => 'Part',
    'fee'   => 'Fee',
    'other' => 'Other',
];

$receiptNo  = 'RCP-' . str_pad($rec['payment_id'] ?? $historyId, 6, '0', STR_PAD_LEFT);
$paidAt     = !empty($rec['paid_at']) ? date('d M Y, g:i A', strtotime($rec['paid_at'])) : '—';
$method     = $methodLabel[$rec['payment_method'] ?? ''] ?? ucfirst($rec['payment_method'] ?? '');
$reference  = !empty($rec['payment_reference']) ? e($rec['payment_reference']) : '—';
$amountPaid = number_format((float)($rec['amount_paid'] ?? $rec['total_amount']), 2);
$total      = number_format((float)$rec['total_amount'], 2);

// Vehicle string
$vehicleParts = array_filter([$rec['vehicle_make'], $rec['vehicle_model'], $rec['vehicle_year']]);
$vehicle = $vehicleParts ? implode(' ', $vehicleParts) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $isPaid ? 'Receipt ' . $receiptNo : 'Invoice'; ?> — Mobile Mechanic</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>assets/css/style.css">
    <style>
        /* ── Layout ── */
        .receipt-wrapper {
            max-width: 760px;
            margin: 32px auto 48px;
            padding: 0 16px;
        }

        /* ── Top actions (screen only) ── */
        .receipt-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .receipt-actions .btn { min-width: 120px; }

        /* ── Unpaid notice ── */
        .unpaid-notice {
            background: #fff8e1;
            border-left: 4px solid #ff6600;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: .92rem;
            color: #555;
        }
        .unpaid-notice strong { color: #333; }

        /* ── Receipt card ── */
        .receipt-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.10);
            overflow: hidden;
        }

        /* ── Header band ── */
        .receipt-header {
            background: #2b2b2b;
            color: #fff;
            padding: 28px 32px 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .receipt-brand {
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: .5px;
        }
        .receipt-brand span { color: #ff6600; }
        .receipt-brand-sub {
            font-size: .78rem;
            color: #aaa;
            margin-top: 4px;
        }
        .receipt-meta { text-align: right; }
        .receipt-meta .receipt-no {
            font-size: 1.05rem;
            font-weight: 700;
            color: #ff6600;
            letter-spacing: .5px;
        }
        .receipt-meta .receipt-date {
            font-size: .8rem;
            color: #bbb;
            margin-top: 4px;
        }

        /* ── Stamp ── */
        .paid-stamp {
            display: inline-block;
            border: 3px solid #28a745;
            color: #28a745;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: 2px;
            padding: 3px 12px;
            border-radius: 4px;
            text-transform: uppercase;
            margin-top: 8px;
            transform: rotate(-6deg);
        }
        .unpaid-stamp {
            display: inline-block;
            border: 3px solid #ff6600;
            color: #ff6600;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: 2px;
            padding: 3px 12px;
            border-radius: 4px;
            text-transform: uppercase;
            margin-top: 8px;
            transform: rotate(-6deg);
        }

        /* ── Info grid ── */
        .receipt-body { padding: 28px 32px; }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 32px;
            margin-bottom: 28px;
        }
        @media (max-width: 540px) {
            .info-grid { grid-template-columns: 1fr; }
            .receipt-header { flex-direction: column; }
            .receipt-meta { text-align: left; }
        }
        .info-block h4 {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #999;
            margin: 0 0 6px;
        }
        .info-block p {
            margin: 0;
            font-size: .92rem;
            color: #333;
            line-height: 1.55;
        }

        /* ── Divider ── */
        .receipt-divider {
            border: none;
            border-top: 1px dashed #ddd;
            margin: 0 0 24px;
        }

        /* ── Items table ── */
        .items-section h3 {
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #999;
            margin: 0 0 10px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .88rem;
        }
        .items-table thead tr { background: #f5f5f5; }
        .items-table th {
            padding: 8px 10px;
            text-align: left;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #777;
            border-bottom: 2px solid #eee;
        }
        .items-table th.right,
        .items-table td.right { text-align: right; }
        .items-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
            vertical-align: top;
        }
        .items-table tbody tr:last-child td { border-bottom: none; }
        .type-badge {
            display: inline-block;
            font-size: .68rem;
            padding: 2px 7px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .type-labor { background: #e8f4fd; color: #1a7abf; }
        .type-part  { background: #fff3e0; color: #e65c00; }
        .type-fee   { background: #f3e5f5; color: #7b1fa2; }
        .type-other { background: #f5f5f5; color: #555; }

        /* ── Totals ── */
        .totals-block {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
        }
        .totals-table {
            width: 260px;
            font-size: .9rem;
        }
        .totals-table td { padding: 5px 0; }
        .totals-table td:last-child { text-align: right; font-weight: 500; }
        .totals-table .total-row td {
            padding-top: 10px;
            border-top: 2px solid #2b2b2b;
            font-size: 1.05rem;
            font-weight: 700;
            color: #2b2b2b;
        }
        .totals-table .paid-row td { color: #28a745; font-weight: 700; }
        .totals-table .pending-row td { color: #ff6600; font-weight: 700; }

        /* ── Payment summary band ── */
        .payment-band {
            background: #f9f9f9;
            border-top: 1px solid #eee;
            padding: 18px 32px;
            display: flex;
            gap: 32px;
            flex-wrap: wrap;
        }
        .payment-band .pb-item h4 {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #999;
            margin: 0 0 4px;
        }
        .payment-band .pb-item p {
            margin: 0;
            font-size: .92rem;
            color: #333;
            font-weight: 600;
        }

        /* ── Footer note ── */
        .receipt-footer-note {
            text-align: center;
            padding: 16px 32px 22px;
            font-size: .78rem;
            color: #aaa;
        }

        /* ── Print ── */
        @media print {
            body { background: #fff !important; }
            .receipt-actions,
            .unpaid-notice,
            header, nav, .sidebar,
            footer { display: none !important; }
            .receipt-wrapper { margin: 0; padding: 0; max-width: 100%; }
            .receipt-card { box-shadow: none; border: 1px solid #ddd; }
            .receipt-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main>
<div class="receipt-wrapper">

    <!-- Actions (hidden on print) -->
    <div class="receipt-actions">
        <a href="<?php echo $baseUrl; ?>customer/customer_history.php" class="btn">← Back to History</a>
        <?php if ($isPaid): ?>
        <button class="btn btn-primary" onclick="window.print()">🖨 Print Receipt</button>
        <?php endif; ?>
    </div>

    <!-- Unpaid notice -->
    <?php if (!$isPaid): ?>
    <div class="unpaid-notice">
        <strong>Payment Pending</strong> — This job has been completed but payment has not been recorded yet.
        Your receipt will be available here once payment is confirmed by our team.
    </div>
    <?php endif; ?>

    <div class="receipt-card">

        <!-- Header band -->
        <div class="receipt-header">
            <div>
                <div class="receipt-brand">Mobile <span>Mechanic</span></div>
                <div class="receipt-brand-sub">Professional Mobile Auto Repair</div>
                <?php if ($isPaid): ?>
                    <div class="paid-stamp">✓ Paid</div>
                <?php else: ?>
                    <div class="unpaid-stamp">⏳ Unpaid</div>
                <?php endif; ?>
            </div>
            <div class="receipt-meta">
                <div class="receipt-no">
                    <?php echo $isPaid ? $receiptNo : ('JOB-' . str_pad($historyId, 6, '0', STR_PAD_LEFT)); ?>
                </div>
                <div class="receipt-date">
                    <?php if ($isPaid): ?>
                        Paid on: <?php echo $paidAt; ?>
                    <?php else: ?>
                        Completed: <?php echo $rec['completed_at'] ? date('d M Y', strtotime($rec['completed_at'])) : '—'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="receipt-body">

            <!-- Info grid -->
            <div class="info-grid">
                <div class="info-block">
                    <h4>Customer</h4>
                    <p>
                        <?php echo e($rec['customer_name'] ?? '—'); ?><br>
                        <?php if (!empty($rec['customer_phone'])): ?>
                            <?php echo e($rec['customer_phone']); ?><br>
                        <?php endif; ?>
                        <?php if (!empty($rec['customer_email'])): ?>
                            <?php echo e($rec['customer_email']); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="info-block">
                    <h4>Vehicle</h4>
                    <p><?php echo e($vehicle); ?></p>
                </div>

                <div class="info-block">
                    <h4>Service Type</h4>
                    <p><?php echo e($rec['problem_type'] ?? '—'); ?></p>
                </div>

                <div class="info-block">
                    <h4>Mechanic</h4>
                    <p><?php echo e($rec['mechanic_name'] ?? '—'); ?></p>
                </div>

                <?php if (!empty($rec['diagnosis'])): ?>
                <div class="info-block" style="grid-column: 1 / -1;">
                    <h4>Diagnosis / Notes</h4>
                    <p><?php echo nl2br(e($rec['diagnosis'])); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <hr class="receipt-divider">

            <!-- Service items -->
            <div class="items-section">
                <h3>Service Items</h3>
                <?php if ($items): ?>
                <div style="overflow-x:auto;">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>Type</th>
                            <th class="right">Qty</th>
                            <th class="right">Unit Price</th>
                            <th class="right">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $lineTotal = 0;
                    foreach ($items as $i => $item):
                        $qty   = (int)$item['quantity'];
                        $price = (float)$item['price'];
                        $line  = $qty * $price;
                        $lineTotal += $line;
                        $t = $item['type'] ?? 'other';
                    ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo e($item['item_name']); ?></td>
                            <td>
                                <span class="type-badge type-<?php echo e($t); ?>">
                                    <?php echo $typeLabel[$t] ?? ucfirst($t); ?>
                                </span>
                            </td>
                            <td class="right"><?php echo $qty; ?></td>
                            <td class="right">$<?php echo number_format($price, 2); ?></td>
                            <td class="right">$<?php echo number_format($line, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <p class="muted">No line items recorded.</p>
                <?php endif; ?>

                <!-- Totals -->
                <div class="totals-block">
                    <table class="totals-table">
                        <tr>
                            <td>Subtotal</td>
                            <td>$<?php echo number_format($lineTotal, 2); ?></td>
                        </tr>
                        <tr class="total-row">
                            <td>Total</td>
                            <td>$<?php echo $total; ?></td>
                        </tr>
                        <?php if ($isPaid): ?>
                        <tr class="paid-row">
                            <td>Amount Paid</td>
                            <td>$<?php echo $amountPaid; ?></td>
                        </tr>
                        <?php else: ?>
                        <tr class="pending-row">
                            <td>Balance Due</td>
                            <td>$<?php echo $total; ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

        </div><!-- /.receipt-body -->

        <!-- Payment summary band — only shown when paid -->
        <?php if ($isPaid): ?>
        <div class="payment-band">
            <div class="pb-item">
                <h4>Payment Method</h4>
                <p><?php echo e($method ?: '—'); ?></p>
            </div>
            <div class="pb-item">
                <h4>Reference / Transaction ID</h4>
                <p><?php echo $reference; ?></p>
            </div>
            <div class="pb-item">
                <h4>Receipt No.</h4>
                <p><?php echo $receiptNo; ?></p>
            </div>
            <?php if (!empty($rec['payment_notes'])): ?>
            <div class="pb-item">
                <h4>Notes</h4>
                <p><?php echo e($rec['payment_notes']); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Footer note -->
        <div class="receipt-footer-note">
            <?php if ($isPaid): ?>
                Thank you for choosing Mobile Mechanic. Please keep this receipt for your records.
            <?php else: ?>
                If you have questions about your balance, please contact us.
            <?php endif; ?>
        </div>

    </div><!-- /.receipt-card -->
</div><!-- /.receipt-wrapper -->
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>