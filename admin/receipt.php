<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin() && !isSuperAdmin()) {
    header('Location: ' . getBasePath() . 'auth/login.php');
    exit;
}

$pdo     = getPDO();
$baseUrl = getBasePath();

// ── Validate ID ──────────────────────────────────────────────────────────────
$historyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($historyId <= 0) {
    header('Location: ' . $baseUrl . 'admin/history.php');
    exit;
}

// ── Fetch history record (paid only) ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT hr.*,
           p.amount_paid,
           p.payment_method    AS pay_method,
           p.payment_reference AS pay_reference,
           p.paid_at,
           p.notes             AS pay_notes,
           u.full_name         AS recorded_by_name
    FROM   history_records hr
    LEFT JOIN payments p ON p.history_id = hr.id
    LEFT JOIN users    u ON u.id = p.recorded_by
    WHERE  hr.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $historyId]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rec) {
    header('Location: ' . $baseUrl . 'admin/history.php?msg=not_found');
    exit;
}

if ($rec['payment_status'] !== 'paid') {
    header('Location: ' . $baseUrl . 'admin/history.php?msg=not_paid');
    exit;
}

// ── Fetch service items ───────────────────────────────────────────────────────
$itemStmt = $pdo->prepare("
    SELECT * FROM history_service_items
    WHERE  history_id = :id
    ORDER BY type, id
");
$itemStmt->execute([':id' => $historyId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Compute totals ────────────────────────────────────────────────────────────
$subtotalLabor = 0;
$subtotalParts = 0;
$subtotalFees  = 0;
$subtotalOther = 0;

foreach ($items as $item) {
    $lineTotal = $item['price'] * $item['quantity'];
    switch ($item['type']) {
        case 'labor': $subtotalLabor += $lineTotal; break;
        case 'part':  $subtotalParts += $lineTotal; break;
        case 'fee':   $subtotalFees  += $lineTotal; break;
        default:      $subtotalOther += $lineTotal; break;
    }
}
$grandTotal = $subtotalLabor + $subtotalParts + $subtotalFees + $subtotalOther;

// ── Helpers ───────────────────────────────────────────────────────────────────
$payMethodLabels = [
    'cash'          => 'Cash',
    'mobile_money'  => 'Mobile Money',
    'bank_transfer' => 'Bank Transfer',
    'card'          => 'Card',
    'other'         => 'Other',
];

function fmt($amount) {
    return '$' . number_format((float)$amount, 2);
}

$receiptNo = 'RCP-' . str_pad($historyId, 6, '0', STR_PAD_LEFT);
$paidAt    = $rec['paid_at'] ? date('d M Y, g:i A', strtotime($rec['paid_at'])) : '—';
$jobDate   = $rec['request_created_at'] ? date('d M Y', strtotime($rec['request_created_at'])) : '—';

$vehicleStr = trim(implode(' ', array_filter([
    $rec['vehicle_year'] ?? '',
    $rec['vehicle_make'] ?? '',
    $rec['vehicle_model'] ?? '',
])));

$payMethod = $payMethodLabels[$rec['pay_method'] ?? ''] ?? ucfirst($rec['pay_method'] ?? '—');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt #<?php echo $receiptNo; ?> – Mobile Mechanic</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>assets/css/style.css">
    <style>
        /* ── Layout ─────────────────────────────────────────────── */
        .receipt-wrapper {
            max-width: 780px;
            margin: 32px auto;
            padding: 0 16px 48px;
        }

        /* ── Action bar (screen only) ───────────────────────────── */
        .receipt-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            align-items: center;
        }
        .receipt-actions .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #3a3a3a;
            color: #fff;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
        }
        .receipt-actions .btn-back:hover { background: #555; }

        /* ── Receipt card ───────────────────────────────────────── */
        .receipt-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.10);
            overflow: hidden;
        }

        /* ── Header band ────────────────────────────────────────── */
        .receipt-header {
            background: #2c2c2c;
            color: #fff;
            padding: 28px 32px 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        .receipt-header .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .receipt-header .brand-icon {
            width: 48px;
            height: 48px;
            background: #ff6600;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .receipt-header .brand-name {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: .3px;
        }
        .receipt-header .brand-tagline {
            font-size: 12px;
            color: #aaa;
            margin-top: 2px;
        }
        .receipt-header .receipt-meta {
            text-align: right;
        }
        .receipt-header .receipt-no {
            font-size: 22px;
            font-weight: 700;
            color: #ff6600;
            letter-spacing: .5px;
        }
        .receipt-header .receipt-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #999;
            margin-bottom: 4px;
        }
        .receipt-header .paid-stamp {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 12px;
            border: 2px solid #4caf50;
            border-radius: 4px;
            color: #4caf50;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        /* ── Section body ───────────────────────────────────────── */
        .receipt-body {
            padding: 28px 32px;
        }

        /* ── Info grid ──────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        @media (max-width: 560px) {
            .info-grid { grid-template-columns: 1fr; }
            .receipt-header { flex-direction: column; }
            .receipt-header .receipt-meta { text-align: left; }
        }
        .info-block h4 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            color: #888;
            margin: 0 0 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e8e8e8;
        }
        .info-block p {
            margin: 3px 0;
            font-size: 14px;
            color: #333;
            line-height: 1.5;
        }
        .info-block .label {
            color: #888;
            font-size: 12px;
        }

        /* ── Divider ────────────────────────────────────────────── */
        .receipt-divider {
            border: none;
            border-top: 1px dashed #ddd;
            margin: 0 0 24px;
        }

        /* ── Items table ────────────────────────────────────────── */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .items-table thead tr {
            background: #f4f4f4;
        }
        .items-table thead th {
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: #666;
            border-bottom: 2px solid #e0e0e0;
        }
        .items-table thead th.num { text-align: right; }
        .items-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            color: #333;
            vertical-align: top;
        }
        .items-table tbody td.num { text-align: right; }
        .items-table tbody tr:last-child td { border-bottom: none; }
        .items-table tbody tr:hover { background: #fafafa; }

        .type-badge {
            display: inline-block;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .type-labor { background: #e3f2fd; color: #1565c0; }
        .type-part  { background: #fff3e0; color: #e65100; }
        .type-fee   { background: #f3e5f5; color: #6a1b9a; }
        .type-other { background: #f1f1f1; color: #555; }

        /* ── Totals block ───────────────────────────────────────── */
        .totals-block {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 28px;
        }
        .totals-inner {
            width: 280px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 14px;
            color: #555;
            border-bottom: 1px solid #f0f0f0;
        }
        .totals-row:last-child { border-bottom: none; }
        .totals-row.grand {
            font-size: 17px;
            font-weight: 700;
            color: #222;
            padding-top: 10px;
            margin-top: 4px;
            border-top: 2px solid #2c2c2c;
            border-bottom: none;
        }
        .totals-row.amount-paid {
            font-size: 15px;
            font-weight: 600;
            color: #4caf50;
        }

        /* ── Payment band ───────────────────────────────────────── */
        .payment-band {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 28px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .payment-band .pitem h5 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            margin: 0 0 4px;
        }
        .payment-band .pitem p {
            font-size: 14px;
            color: #333;
            margin: 0;
            font-weight: 600;
        }

        /* ── Footer note ────────────────────────────────────────── */
        .receipt-footer-note {
            text-align: center;
            font-size: 13px;
            color: #aaa;
            padding-top: 16px;
            border-top: 1px solid #f0f0f0;
        }
        .receipt-footer-note strong { color: #ff6600; }

        /* ── Print styles ───────────────────────────────────────── */
        @media print {
            body { background: #fff !important; }
            .receipt-actions, header, nav, aside, footer { display: none !important; }
            .receipt-wrapper { max-width: 100%; margin: 0; padding: 0; }
            .receipt-card { box-shadow: none; border: none; }
            .receipt-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .type-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div style="display:flex;">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main style="flex:1; min-width:0; padding: 24px 20px;">
        <div class="receipt-wrapper">

            <!-- Action bar -->
            <div class="receipt-actions">
                <a href="<?php echo $baseUrl; ?>admin/history.php" class="btn-back">
                    &#8592; Back to History
                </a>
                <button class="btn btn-primary" onclick="window.print()">
                    &#128438; Print Receipt
                </button>
            </div>

            <!-- Receipt card -->
            <div class="receipt-card">

                <!-- Header band -->
                <div class="receipt-header">
                    <div class="brand">
                        <div class="brand-icon">&#128295;</div>
                        <div>
                            <div class="brand-name">Mobile Mechanic</div>
                            <div class="brand-tagline">Professional Vehicle Services</div>
                        </div>
                    </div>
                    <div class="receipt-meta">
                        <div class="receipt-label">Receipt</div>
                        <div class="receipt-no"><?php echo e($receiptNo); ?></div>
                        <div style="font-size:13px; color:#bbb; margin-top:4px;"><?php echo $paidAt; ?></div>
                        <div class="paid-stamp">✓ Paid</div>
                    </div>
                </div>

                <!-- Body -->
                <div class="receipt-body">

                    <!-- Info grid -->
                    <div class="info-grid">

                        <!-- Customer -->
                        <div class="info-block">
                            <h4>Billed To</h4>
                            <p><strong><?php echo e($rec['customer_name'] ?? '—'); ?></strong></p>
                            <?php if (!empty($rec['customer_phone'])): ?>
                                <p><span class="label">Phone:</span> <?php echo e($rec['customer_phone']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($rec['customer_email'])): ?>
                                <p><span class="label">Email:</span> <?php echo e($rec['customer_email']); ?></p>
                            <?php endif; ?>
                            <?php if ($rec['is_walkin'] && !empty($rec['customer_address'])): ?>
                                <p><span class="label">Address:</span> <?php echo e($rec['customer_address']); ?></p>
                            <?php endif; ?>
                            <?php if ($rec['is_walkin']): ?>
                                <p style="margin-top:6px;"><span class="type-badge type-fee">Walk-in</span></p>
                            <?php endif; ?>
                        </div>

                        <!-- Job info -->
                        <div class="info-block">
                            <h4>Job Details</h4>
                            <p><span class="label">Job Date:</span> <?php echo $jobDate; ?></p>
                            <?php if (!empty($vehicleStr)): ?>
                                <p><span class="label">Vehicle:</span> <?php echo e($vehicleStr); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($rec['problem_type'])): ?>
                                <p><span class="label">Service Type:</span> <?php echo e($rec['problem_type']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($rec['mechanic_name'])): ?>
                                <p><span class="label">Mechanic:</span> <?php echo e($rec['mechanic_name']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($rec['recorded_by_name'])): ?>
                                <p><span class="label">Processed By:</span> <?php echo e($rec['recorded_by_name']); ?></p>
                            <?php endif; ?>
                        </div>

                    </div><!-- /info-grid -->

                    <?php if (!empty($rec['diagnosis'])): ?>
                        <div class="info-block" style="margin-bottom:24px;">
                            <h4>Diagnosis / Notes</h4>
                            <p><?php echo nl2br(e($rec['diagnosis'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <hr class="receipt-divider">

                    <!-- Service items -->
                    <?php if (!empty($items)): ?>
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th style="width:40%">Description</th>
                                    <th>Type</th>
                                    <th class="num">Unit Price</th>
                                    <th class="num">Qty</th>
                                    <th class="num">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item):
                                    $lineTotal  = $item['price'] * $item['quantity'];
                                    $typeClass  = 'type-' . $item['type'];
                                    $typeLabel  = ucfirst($item['type']);
                                ?>
                                <tr>
                                    <td><?php echo e($item['item_name']); ?></td>
                                    <td><span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                                    <td class="num"><?php echo fmt($item['price']); ?></td>
                                    <td class="num"><?php echo (int)$item['quantity']; ?></td>
                                    <td class="num"><strong><?php echo fmt($lineTotal); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color:#999; font-style:italic; margin-bottom:24px;">No service line items recorded.</p>
                    <?php endif; ?>

                    <!-- Totals -->
                    <div class="totals-block">
                        <div class="totals-inner">
                            <?php if ($subtotalLabor > 0): ?>
                                <div class="totals-row">
                                    <span>Labor</span>
                                    <span><?php echo fmt($subtotalLabor); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($subtotalParts > 0): ?>
                                <div class="totals-row">
                                    <span>Parts</span>
                                    <span><?php echo fmt($subtotalParts); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($subtotalFees > 0): ?>
                                <div class="totals-row">
                                    <span>Fees</span>
                                    <span><?php echo fmt($subtotalFees); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($subtotalOther > 0): ?>
                                <div class="totals-row">
                                    <span>Other</span>
                                    <span><?php echo fmt($subtotalOther); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="totals-row grand">
                                <span>Total</span>
                                <span><?php echo fmt($grandTotal); ?></span>
                            </div>
                            <?php if (!empty($rec['amount_paid'])): ?>
                                <div class="totals-row amount-paid">
                                    <span>Amount Paid</span>
                                    <span><?php echo fmt($rec['amount_paid']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Payment info band -->
                    <div class="payment-band">
                        <div class="pitem">
                            <h5>Payment Method</h5>
                            <p><?php echo e($payMethod); ?></p>
                        </div>
                        <?php if (!empty($rec['pay_reference'])): ?>
                            <div class="pitem">
                                <h5>Reference / Transaction #</h5>
                                <p><?php echo e($rec['pay_reference']); ?></p>
                            </div>
                        <?php endif; ?>
                        <div class="pitem">
                            <h5>Payment Date</h5>
                            <p><?php echo $paidAt; ?></p>
                        </div>
                        <?php if (!empty($rec['pay_notes'])): ?>
                            <div class="pitem">
                                <h5>Notes</h5>
                                <p><?php echo e($rec['pay_notes']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Footer note -->
                    <div class="receipt-footer-note">
                        Thank you for choosing <strong>Mobile Mechanic</strong>.
                        This receipt is your proof of payment. Please keep it for your records.
                    </div>

                </div><!-- /receipt-body -->
            </div><!-- /receipt-card -->

        </div><!-- /receipt-wrapper -->
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>