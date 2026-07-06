<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin() && !isSuperAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$pdo = getPDO();

$historyId = isset($_GET['history_id']) && ctype_digit($_GET['history_id']) ? (int) $_GET['history_id'] : null;

if (!$historyId) {
    $fallback = isSuperAdmin() ? 'super_admin/history.php' : 'admin/history.php';
    header('Location: ' . getBasePath() . $fallback);
    exit;
}

// ── Load history record (permanent source of truth for invoices) ──────────────
$stmt = $pdo->prepare('SELECT * FROM history_records WHERE id = :hid');
$stmt->execute([':hid' => $historyId]);
$request = $stmt->fetch();

if (!$request) {
    $fallback = isSuperAdmin() ? 'super_admin/history.php' : 'admin/history.php';
    header('Location: ' . getBasePath() . $fallback);
    exit;
}

// ── Load service items from history ──────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM history_service_items WHERE history_id = :hid ORDER BY id ASC');
$stmt->execute([':hid' => $historyId]);
$items = $stmt->fetchAll();

$total   = array_sum(array_column($items, 'price'));
$service = ['created_at' => $request['completed_at']];

// Invoice number formatted as INV-YEAR-REQUEST_ID
$invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($request['request_id'], 4, '0', STR_PAD_LEFT);

// ── Resolve vehicle display string ────────────────────────────────────────────
$vehicleParts = array_filter([
    $request['vehicle_make']  ?? null,
    $request['vehicle_model'] ?? null,
    $request['vehicle_year']  ? (string)$request['vehicle_year'] : null,
]);
$vehicleDisplay = !empty($vehicleParts) ? implode(' ', $vehicleParts) : null;

// ── Back link depends on who is viewing ──────────────────────────────────────
$backLink = isSuperAdmin()
    ? getBasePath() . 'super_admin/history.php'
    : getBasePath() . 'admin/history.php';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
<div class="card">

    <!-- ── Top action bar (hidden on print) ───────────────────────────── -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <div>
            <a href="<?php echo $backLink; ?>" class="btn" style="margin-right:8px;">← Back to History</a>
        </div>
        <button onclick="window.print()" class="btn btn-primary">🖨 Print Invoice</button>
    </div>

    <style>
        .invoice-wrap {
            max-width: 760px;
            margin: 0 auto;
            font-size: 0.92rem;
            color: #333;
        }

        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 20px;
            border-bottom: 3px solid var(--safety-orange, #ff6600);
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .inv-header .brand { font-size: 1.6rem; font-weight: 800; color: var(--charcoal, #2d2d2d); }
        .inv-header .brand span { color: var(--safety-orange, #ff6600); }
        .inv-header .inv-meta { text-align: right; }
        .inv-header .inv-meta .inv-number { font-size: 1.1rem; font-weight: 700; color: var(--charcoal, #2d2d2d); }
        .inv-header .inv-meta .inv-date   { color: #777; font-size: 0.85rem; margin-top: 4px; }

        .inv-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        .inv-info-box { background: #f9f9f9; border-radius: 7px; padding: 14px 16px; border: 1px solid #ebebeb; }
        .inv-info-box h5 {
            margin: 0 0 8px 0;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #999;
        }
        .inv-info-box p { margin: 3px 0; font-size: 0.88rem; line-height: 1.5; }
        .inv-info-box p strong { color: #555; }

        .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .inv-table thead tr { background: var(--charcoal, #2d2d2d); color: #fff; }
        .inv-table thead th { padding: 10px 14px; text-align: left; font-size: 0.85rem; font-weight: 600; }
        .inv-table thead th:last-child { text-align: right; }
        .inv-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .inv-table tbody tr:last-child { border-bottom: none; }
        .inv-table tbody td { padding: 10px 14px; font-size: 0.9rem; }
        .inv-table tbody td:last-child { text-align: right; font-weight: 600; color: var(--charcoal, #2d2d2d); }
        .inv-table tfoot tr { background: #f5f5f5; }
        .inv-table tfoot td { padding: 10px 14px; font-size: 0.9rem; }

        .inv-total-bar { display: flex; justify-content: flex-end; }
        .inv-total-box {
            background: var(--charcoal, #2d2d2d);
            color: #fff;
            border-radius: 7px;
            padding: 14px 24px;
            min-width: 220px;
        }
        .inv-total-box .label  { font-size: 0.85rem; color: #aaa; margin-bottom: 4px; }
        .inv-total-box .amount { font-size: 1.6rem; font-weight: 800; color: var(--safety-orange, #ff6600); }

        .inv-internal {
            margin-top: 24px;
            padding: 14px 16px;
            background: #fffbf0;
            border: 1px solid #ffe0a0;
            border-radius: 7px;
        }
        .inv-internal h5 {
            margin: 0 0 8px 0;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #b07800;
        }
        .inv-internal p { margin: 0; font-size: 0.88rem; color: #555; }

        .inv-footer-note {
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid #eee;
            text-align: center;
            color: #aaa;
            font-size: 0.8rem;
        }

        @media print {
            .no-print { display: none !important; }
            body, main, .card { background: #fff !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
            header, footer, nav, aside { display: none !important; }
            .inv-wrap { max-width: 100%; }
        }

        @media (max-width: 640px) {
            .inv-info-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="invoice-wrap">

        <!-- ── Invoice Header ─────────────────────────────────────────── -->
        <div class="inv-header">
            <div>
                <div class="brand">Mobile<span>Mechanic</span></div>
                <div style="color:#777;font-size:0.85rem;margin-top:4px;">On-demand vehicle repair service</div>
            </div>
            <div class="inv-meta">
                <div class="inv-number"><?php echo $invoiceNumber; ?></div>
                <div class="inv-date">Issued: <?php echo date('F d, Y', strtotime($request['completed_at'])); ?></div>
                <div style="margin-top:6px;">
                    <span class="status status-<?php echo e($request['status']); ?>"><?php echo ucfirst(e($request['status'])); ?></span>
                </div>
            </div>
        </div>

        <!-- ── Info Grid ──────────────────────────────────────────────── -->
        <div class="inv-info-grid">

            <div class="inv-info-box">
                <h5>Customer</h5>
                <p><?php echo e($request['customer_name'] ?? '—'); ?></p>
                <p><?php echo e($request['customer_phone'] ?? '—'); ?></p>
                <?php if ($request['walkin_id']): ?>
                    <p><span style="font-size:0.75rem;background:#fff3e0;color:#e65100;border:1px solid #ffcc80;border-radius:3px;padding:1px 6px;">Walk-in</span></p>
                <?php endif; ?>
            </div>

            <div class="inv-info-box">
                <h5>Request Details</h5>
                <p><strong>Request ID:</strong> #<?php echo e($request['request_id']); ?></p>
                <p><strong>Problem:</strong> <?php echo e($request['problem_type']); ?></p>
                <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($request['request_created_at'])); ?></p>
                <?php if ($vehicleDisplay): ?>
                    <p><strong>Vehicle:</strong> <?php echo e($vehicleDisplay); ?></p>
                <?php endif; ?>
            </div>

            <div class="inv-info-box">
                <h5>Assigned Mechanic</h5>
                <p><?php echo e($request['mechanic_name'] ?? '—'); ?></p>
                <?php if ($request['diagnosis']): ?>
                    <p style="margin-top:6px;font-size:0.83rem;color:#666;"><?php echo e($request['diagnosis']); ?></p>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Service Items Table ────────────────────────────────────── -->
        <?php if (!empty($items)): ?>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item / Service</th>
                    <th style="text-align:right;">Price</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td style="color:#aaa;width:36px;"><?php echo $i + 1; ?></td>
                        <td><?php echo e($item['item_name']); ?></td>
                        <td>$<?php echo number_format((float)$item['price'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:var(--muted);font-size:0.9rem;margin-bottom:16px;">No itemized services recorded.</p>
        <?php endif; ?>

        <!-- ── Total ──────────────────────────────────────────────────── -->
        <div class="inv-total-bar">
            <div class="inv-total-box">
                <div class="label">Total Amount</div>
                <div class="amount">$<?php echo number_format($total, 2); ?></div>
            </div>
        </div>

        <!-- ── Internal Notes (Admin only — hidden on print optional) ─── -->
        <?php if ($request['diagnosis'] || $request['description']): ?>
        <div class="inv-internal no-print">
            <h5>⚠ Internal Notes (Admin Only)</h5>
            <?php if ($request['description']): ?>
                <p><strong>Customer Description:</strong> <?php echo e($request['description']); ?></p>
            <?php endif; ?>
            <?php if ($request['diagnosis']): ?>
                <p style="margin-top:6px;"><strong>Mechanic Diagnosis:</strong> <?php echo e($request['diagnosis']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Footer ─────────────────────────────────────────────────── -->
        <div class="inv-footer-note">
            Thank you for choosing Mobile Mechanic. &nbsp;·&nbsp; Generated <?php echo date('M d, Y H:i'); ?>
        </div>

    </div><!-- /.invoice-wrap -->
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>