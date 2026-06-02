<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Customers only
if (isAdmin()) {
    header('Location: ' . getBasePath() . 'admin/history.php');
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'] ?? null;

$requestId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;

if (!$requestId) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

// ── Load request — enforce ownership so customers can't see each other's invoices ──
$stmt = $pdo->prepare(
    "SELECT r.*,
            u.full_name  AS customer_name,
            u.email      AS customer_email,
            u.phone      AS customer_phone,
            m.name       AS mechanic_name
     FROM requests r
     LEFT JOIN users     u ON r.user_id    = u.id
     LEFT JOIN mechanics m ON r.mechanic_id = m.id
     WHERE r.id = :id
       AND r.user_id = :uid
       AND r.status = 'completed'"
);
$stmt->execute([':id' => $requestId, ':uid' => $userId]);
$request = $stmt->fetch();

if (!$request) {
    // Either not found, not theirs, or not completed yet
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

// ── Load service + items ──────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM services WHERE request_id = :rid LIMIT 1');
$stmt->execute([':rid' => $requestId]);
$service = $stmt->fetch();

$items = [];
$total = 0;
if ($service) {
    $stmt = $pdo->prepare('SELECT * FROM service_items WHERE service_id = :sid ORDER BY id ASC');
    $stmt->execute([':sid' => $service['id']]);
    $items = $stmt->fetchAll();
    $total = array_sum(array_column($items, 'price'));
}

$invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($requestId, 4, '0', STR_PAD_LEFT);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main>
<div class="card">

    <!-- ── Top bar ────────────────────────────────────────────────────── -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <a href="<?php echo getBasePath(); ?>customer/customer_history.php?id=<?php echo $requestId; ?>" class="btn">← Back</a>
        <button onclick="window.print()" class="btn btn-primary">🖨 Print Invoice</button>
    </div>

    <style>
        /* ── Invoice wrapper ──────────────── */
        .invoice-wrap {
            max-width: 680px;
            margin: 0 auto;
            font-size: 0.92rem;
            color: #333;
        }

        /* ── Header ───────────────────────── */
        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 20px;
            border-bottom: 3px solid var(--safety-orange, #ff6600);
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .inv-header .brand       { font-size: 1.5rem; font-weight: 800; color: var(--charcoal, #2d2d2d); }
        .inv-header .brand span  { color: var(--safety-orange, #ff6600); }
        .inv-header .inv-meta    { text-align: right; }
        .inv-header .inv-number  { font-size: 1rem; font-weight: 700; color: var(--charcoal, #2d2d2d); }
        .inv-header .inv-date    { color: #888; font-size: 0.82rem; margin-top: 4px; }

        /* ── Info row ─────────────────────── */
        .inv-info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 24px;
        }
        .inv-info-box {
            background: #f9f9f9;
            border: 1px solid #ebebeb;
            border-radius: 7px;
            padding: 14px 16px;
        }
        .inv-info-box h5 {
            margin: 0 0 8px 0;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #999;
        }
        .inv-info-box p { margin: 4px 0; font-size: 0.88rem; line-height: 1.55; }

        /* ── Thank-you banner ─────────────── */
        .inv-thankyou {
            background: linear-gradient(135deg, #ff6600 0%, #e55200 100%);
            color: #fff;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .inv-thankyou .icon { font-size: 2rem; }
        .inv-thankyou .text-main { font-size: 1rem; font-weight: 700; }
        .inv-thankyou .text-sub  { font-size: 0.83rem; opacity: 0.85; margin-top: 2px; }

        /* ── Items table ──────────────────── */
        .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .inv-table thead tr { background: var(--charcoal, #2d2d2d); color: #fff; }
        .inv-table thead th { padding: 10px 14px; text-align: left; font-size: 0.85rem; font-weight: 600; }
        .inv-table thead th:last-child { text-align: right; }
        .inv-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .inv-table tbody tr:last-child { border-bottom: none; }
        .inv-table tbody td { padding: 10px 14px; font-size: 0.9rem; }
        .inv-table tbody td:last-child { text-align: right; font-weight: 600; }

        /* ── Total ────────────────────────── */
        .inv-total-bar { display: flex; justify-content: flex-end; margin-bottom: 28px; }
        .inv-total-box {
            background: var(--charcoal, #2d2d2d);
            color: #fff;
            border-radius: 7px;
            padding: 14px 24px;
            min-width: 200px;
            text-align: right;
        }
        .inv-total-box .label  { font-size: 0.82rem; color: #aaa; margin-bottom: 4px; }
        .inv-total-box .amount { font-size: 1.5rem; font-weight: 800; color: var(--safety-orange, #ff6600); }

        /* ── Footer note ──────────────────── */
        .inv-footer-note {
            padding-top: 16px;
            border-top: 1px solid #eee;
            text-align: center;
            color: #bbb;
            font-size: 0.8rem;
        }

        /* ── Print ────────────────────────── */
        @media print {
            .no-print { display: none !important; }
            body, main, .card { background: #fff !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
            header, footer, nav, aside { display: none !important; }
        }

        @media (max-width: 560px) {
            .inv-info-row { grid-template-columns: 1fr; }
        }
    </style>

    <div class="invoice-wrap">

        <!-- ── Header ─────────────────────────────────────────────────── -->
        <div class="inv-header">
            <div>
                <div class="brand">Mobile<span>Mechanic</span></div>
                <div style="color:#aaa;font-size:0.82rem;margin-top:3px;">On-demand vehicle repair service</div>
            </div>
            <div class="inv-meta">
                <div class="inv-number"><?php echo $invoiceNumber; ?></div>
                <div class="inv-date">
                    <?php echo date('F d, Y', strtotime($service['created_at'] ?? $request['created_at'])); ?>
                </div>
                <div style="margin-top:6px;">
                    <span class="status status-completed">Completed</span>
                </div>
            </div>
        </div>

        <!-- ── Thank-you banner ───────────────────────────────────────── -->
        <div class="inv-thankyou">
            <div class="icon">✅</div>
            <div>
                <div class="text-main">Your vehicle service is complete!</div>
                <div class="text-sub">Below is your itemized invoice. Thank you for choosing Mobile Mechanic.</div>
            </div>
        </div>

        <!-- ── Info ───────────────────────────────────────────────────── -->
        <div class="inv-info-row">
            <div class="inv-info-box">
                <h5>Billed To</h5>
                <p><?php echo e($request['customer_name'] ?? '—'); ?></p>
                <p><?php echo e($request['customer_phone'] ?? '—'); ?></p>
                <p><?php echo e($request['customer_email'] ?? '—'); ?></p>
            </div>
            <div class="inv-info-box">
                <h5>Service Summary</h5>
                <p><strong>Problem:</strong> <?php echo e($request['problem_type']); ?></p>
                <p><strong>Service:</strong> <?php echo e($service['service_name'] ?? '—'); ?></p>
                <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($request['created_at'])); ?></p>
            </div>
        </div>

        <!-- ── Items ──────────────────────────────────────────────────── -->
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
                        <td style="color:#ccc;width:36px;"><?php echo $i + 1; ?></td>
                        <td><?php echo e($item['item_name']); ?></td>
                        <td>$<?php echo number_format((float)$item['price'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:var(--muted);margin-bottom:16px;">No itemized services recorded.</p>
        <?php endif; ?>

        <!-- ── Total ──────────────────────────────────────────────────── -->
        <div class="inv-total-bar">
            <div class="inv-total-box">
                <div class="label">Total Amount</div>
                <div class="amount">$<?php echo number_format($total, 2); ?></div>
            </div>
        </div>

        <!-- ── Footer ─────────────────────────────────────────────────── -->
        <div class="inv-footer-note">
            Mobile Mechanic &nbsp;·&nbsp; <?php echo date('Y'); ?> &nbsp;·&nbsp; Thank you for your business
        </div>

    </div><!-- /.invoice-wrap -->
</div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>