<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pdo     = getPDO();
$baseUrl = getBasePath();
$selfUrl = $baseUrl . 'super_admin/payroll.php';
$adminId = $_SESSION['user_id'];

/* ══════════════════════════════════════════════════════════════
   AJAX: CALCULATE  (returns JSON, exits early)
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'calculate') {
    header('Content-Type: application/json');

    $target_type  = $_POST['target_type']  ?? '';
    $employee_id  = (int)($_POST['employee_id']  ?? 0);
    $period_start = $_POST['period_start'] ?? '';
    $period_end   = $_POST['period_end']   ?? '';

    $result = [
        'employee_name'       => '',
        'base_salary'         => 0,
        'commission_rate'     => 0,
        'commission_earnings' => 0,
        'gross_job_revenue'   => 0,
        'total_jobs'          => 0,
    ];

    if ($target_type === 'mechanic' && $employee_id) {
        $stmt = $pdo->prepare(
            "SELECT name, base_salary, commission_rate
               FROM mechanics
              WHERE id = :id AND is_deleted = FALSE"
        );
        $stmt->execute([':id' => $employee_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            $result['employee_name']   = $emp['name'];
            $result['base_salary']     = (float)$emp['base_salary'];
            $result['commission_rate'] = (float)$emp['commission_rate'];

            if ($period_start && $period_end) {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) AS total_jobs,
                            COALESCE(SUM(total_amount), 0) AS gross
                       FROM history_records
                      WHERE mechanic_id    = :mid
                        AND status        = 'completed'
                        AND payment_status = 'paid'
                        AND completed_at  BETWEEN :ps AND :pe"
                );
                $stmt->execute([
                    ':mid' => $employee_id,
                    ':ps'  => $period_start . ' 00:00:00',
                    ':pe'  => $period_end   . ' 23:59:59',
                ]);
                $jobs = $stmt->fetch(PDO::FETCH_ASSOC);
                $result['total_jobs']          = (int)$jobs['total_jobs'];
                $result['gross_job_revenue']   = (float)$jobs['gross'];
                $result['commission_earnings'] = round(
                    $result['gross_job_revenue'] * $result['commission_rate'] / 100, 2
                );
            }
        }

    } elseif ($target_type === 'staff_admin' && $employee_id) {
        $stmt = $pdo->prepare(
            "SELECT full_name, base_salary
               FROM users
              WHERE id = :id AND role = 'admin'
                AND is_superadmin = FALSE AND is_disabled = FALSE"
        );
        $stmt->execute([':id' => $employee_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            $result['employee_name'] = $emp['full_name'];
            $result['base_salary']   = (float)$emp['base_salary'];
        }
    }

    echo json_encode($result);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   POST ACTIONS (PRG)
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── CREATE (save as draft) ───────────────────────────── */
    if ($action === 'create') {
        $target_type         = $_POST['target_type'] ?? '';
        $employee_id         = (int)($_POST['employee_id'] ?? 0);
        $employee_name       = trim($_POST['employee_name'] ?? '');
        $period_start        = $_POST['period_start'] ?? '';
        $period_end          = $_POST['period_end']   ?? '';
        $base_salary         = (float)($_POST['base_salary']         ?? 0);
        $commission_rate     = (float)($_POST['commission_rate']     ?? 0);
        $commission_earnings = (float)($_POST['commission_earnings'] ?? 0);
        $gross_job_revenue   = (float)($_POST['gross_job_revenue']   ?? 0);
        $total_jobs          = (int)($_POST['total_jobs']            ?? 0);
        $deductions          = (float)($_POST['deductions']          ?? 0);
        $deduction_notes     = trim($_POST['deduction_notes']        ?? '');
        $notes               = trim($_POST['notes']                  ?? '');
        $net_pay             = $base_salary + $commission_earnings - $deductions;

        if (!in_array($target_type, ['mechanic', 'staff_admin'])
            || !$employee_id || !$employee_name
            || !$period_start || !$period_end) {
            header('Location: ' . $selfUrl . '?msg=error_fields');
            exit;
        }

        $mechanic_id    = $target_type === 'mechanic'    ? $employee_id : null;
        $staff_admin_id = $target_type === 'staff_admin' ? $employee_id : null;

        $stmt = $pdo->prepare(
            "INSERT INTO payroll_records
                 (target_type, mechanic_id, staff_admin_id, employee_name,
                  period_start, period_end, total_jobs, gross_job_revenue,
                  base_salary, commission_rate, commission_earnings,
                  deductions, net_pay, deduction_notes, notes, created_by, status)
             VALUES
                 (:target_type, :mechanic_id, :staff_admin_id, :employee_name,
                  :period_start, :period_end, :total_jobs, :gross_job_revenue,
                  :base_salary, :commission_rate, :commission_earnings,
                  :deductions, :net_pay, :deduction_notes, :notes, :created_by, 'draft')"
        );
        $stmt->execute([
            ':target_type'         => $target_type,
            ':mechanic_id'         => $mechanic_id,
            ':staff_admin_id'      => $staff_admin_id,
            ':employee_name'       => $employee_name,
            ':period_start'        => $period_start,
            ':period_end'          => $period_end,
            ':total_jobs'          => $total_jobs,
            ':gross_job_revenue'   => $gross_job_revenue,
            ':base_salary'         => $base_salary,
            ':commission_rate'     => $commission_rate,
            ':commission_earnings' => $commission_earnings,
            ':deductions'          => $deductions,
            ':net_pay'             => $net_pay,
            ':deduction_notes'     => $deduction_notes ?: null,
            ':notes'               => $notes           ?: null,
            ':created_by'          => $adminId,
        ]);
        header('Location: ' . $selfUrl . '?msg=created');
        exit;
    }

    /* ── APPROVE ──────────────────────────────────────────── */
    if ($action === 'approve') {
        $id = (int)($_POST['payroll_id'] ?? 0);
        $stmt = $pdo->prepare(
            "UPDATE payroll_records SET status = 'approved'
              WHERE id = :id AND status = 'draft'"
        );
        $stmt->execute([':id' => $id]);
        header('Location: ' . $selfUrl . '?msg=approved');
        exit;
    }

    /* ── MARK PAID (+ balance_ledger) ─────────────────────── */
    if ($action === 'mark_paid') {
        $id                = (int)($_POST['payroll_id']        ?? 0);
        $payment_method    = $_POST['payment_method']          ?? 'cash';
        $payment_reference = trim($_POST['payment_reference']  ?? '');

        $stmt = $pdo->prepare(
            "SELECT * FROM payroll_records WHERE id = :id AND status = 'approved'"
        );
        $stmt->execute([':id' => $id]);
        $pr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pr) {
            header('Location: ' . $selfUrl . '?msg=error_notfound');
            exit;
        }

        $pdo->beginTransaction();
        try {
            /* 1. Flip payroll to paid */
            $stmt = $pdo->prepare(
                "UPDATE payroll_records
                    SET status            = 'paid',
                        payment_method    = :pm,
                        payment_reference = :ref,
                        paid_at           = NOW()
                  WHERE id = :id"
            );
            $stmt->execute([
                ':pm'  => $payment_method,
                ':ref' => $payment_reference ?: null,
                ':id'  => $id,
            ]);

            /* 2. Write balance_ledger OUT row */
            $stmt = $pdo->prepare(
                "INSERT INTO balance_ledger
                     (type, direction, amount, reference_id, notes, created_by)
                 VALUES
                     ('payroll', 'out', :amount, :ref_id, :notes, :created_by)"
            );
            $stmt->execute([
                ':amount'     => $pr['net_pay'],
                ':ref_id'     => $id,
                ':notes'      => 'Payroll paid to ' . $pr['employee_name']
                                 . ' (' . $pr['period_start'] . ' – ' . $pr['period_end'] . ')',
                ':created_by' => $adminId,
            ]);

            $pdo->commit();
            header('Location: ' . $selfUrl . '?msg=paid');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: ' . $selfUrl . '?msg=error_pay');
            exit;
        }
    }

    /* ── DELETE DRAFT ─────────────────────────────────────── */
    if ($action === 'delete') {
        $id = (int)($_POST['payroll_id'] ?? 0);
        $stmt = $pdo->prepare(
            "DELETE FROM payroll_records WHERE id = :id AND status = 'draft'"
        );
        $stmt->execute([':id' => $id]);
        header('Location: ' . $selfUrl . '?msg=deleted');
        exit;
    }
}

/* ══════════════════════════════════════════════════════════════
   FLASH MESSAGES
══════════════════════════════════════════════════════════════ */
$msgMap = [
    'created'        => ['Payroll record saved as draft.',                       'success'],
    'approved'       => ['Payroll record approved.',                             'success'],
    'paid'           => ['Payroll marked as paid and ledger updated.',           'success'],
    'deleted'        => ['Draft payroll record deleted.',                        'success'],
    'error_fields'   => ['Please fill in all required fields.',                  'error'],
    'error_notfound' => ['Record not found or not in approved state.',           'error'],
    'error_pay'      => ['Payment processing failed. Please try again.',         'error'],
];
$msg     = '';
$msgType = '';
if (isset($_GET['msg'], $msgMap[$_GET['msg']])) {
    [$msg, $msgType] = $msgMap[$_GET['msg']];
}

/* ══════════════════════════════════════════════════════════════
   FILTERS
══════════════════════════════════════════════════════════════ */
$filterStatus = $_GET['status']      ?? '';
$filterType   = $_GET['target_type'] ?? '';
$filterSearch = trim($_GET['q']      ?? '');

$where  = [];
$params = [];

if ($filterStatus !== '') {
    $where[]           = 'pr.status = :status';
    $params[':status'] = $filterStatus;
}
if ($filterType !== '') {
    $where[]          = 'pr.target_type = :ttype';
    $params[':ttype'] = $filterType;
}
if ($filterSearch !== '') {
    $where[]      = 'pr.employee_name LIKE :q';
    $params[':q'] = '%' . $filterSearch . '%';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT pr.*, u.full_name AS created_by_name
       FROM payroll_records pr
       LEFT JOIN users u ON u.id = pr.created_by
     $whereSql
     ORDER BY pr.created_at DESC"
);
$stmt->execute($params);
$payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════════
   EMPLOYEE LISTS (for create form)
══════════════════════════════════════════════════════════════ */
$mechanics = $pdo->query(
    "SELECT id, name, base_salary, commission_rate
       FROM mechanics
      WHERE is_deleted = FALSE AND is_disabled = FALSE
      ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$staffAdmins = $pdo->query(
    "SELECT id, full_name, base_salary
       FROM users
      WHERE role = 'admin' AND is_superadmin = FALSE AND is_disabled = FALSE
      ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════════
   SUMMARY STATS
══════════════════════════════════════════════════════════════ */
$stats = $pdo->query(
    "SELECT
         COUNT(*) AS total,
         SUM(CASE WHEN status = 'draft'    THEN 1    ELSE 0    END) AS drafts,
         SUM(CASE WHEN status = 'approved' THEN 1    ELSE 0    END) AS approved,
         SUM(CASE WHEN status = 'paid'     THEN 1    ELSE 0    END) AS paid_count,
         COALESCE(SUM(CASE WHEN status = 'paid' THEN net_pay ELSE 0 END), 0) AS total_paid_out
       FROM payroll_records"
)->fetch(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function fmtMoney(float $v): string {
    return '$' . number_format($v, 2);
}
function statusBadge(string $s): string {
    $map = [
        'draft'    => 'status-pending',
        'approved' => 'status-assigned',
        'paid'     => 'status-completed',
    ];
    $cls = $map[$s] ?? 'status-pending';
    return '<span class="status ' . $cls . '">' . ucfirst($s) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payroll Management – Mobile Mechanic</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>assets/css/style.css">
    <style>
        /* ── Page layout ───────────────────────────────────── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }
        .page-header h1 { margin: 0; font-size: 1.6rem; color: #222; }

        /* ── Stat cards ────────────────────────────────────── */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
            border-top: 3px solid #ff6600;
        }
        .stat-card .stat-value {
            font-size: 1.7rem;
            font-weight: 700;
            color: #222;
            line-height: 1.1;
        }
        .stat-card .stat-label {
            font-size: .78rem;
            color: #777;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .stat-card.accent .stat-value { color: #ff6600; }

        /* ── Filters ───────────────────────────────────────── */
        .filter-bar {
            background: #fff;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .filter-bar label { font-size: .78rem; color: #555; font-weight: 600; }
        .filter-bar input,
        .filter-bar select {
            padding: 7px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: .88rem;
            min-width: 140px;
            height: 34px;
            box-sizing: border-box;
        }
        /* Buttons row — no label above, so align-self pushes it to bottom */
        .filter-actions {
            display: flex;
            gap: 8px;
            align-self: flex-end;
        }
        .filter-actions .btn {
            height: 34px;
            padding: 0 16px;
            line-height: 34px;
            box-sizing: border-box;
            white-space: nowrap;
        }

        /* ── Table wrapper ─────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        .table-wrap table { min-width: 860px; }

        /* ── Action buttons inside table ───────────────────── */
        .action-btn {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: opacity .15s;
        }
        .action-btn:hover { opacity: .82; }
        .btn-approve  { background: #28a745; color: #fff; }
        .btn-pay      { background: #ff6600; color: #fff; }
        .btn-delete   { background: #dc3545; color: #fff; }
        .btn-view     { background: #6c757d; color: #fff; }

        /* ── Modals ────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 10px;
            width: 100%;
            max-width: 680px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 8px 32px rgba(0,0,0,.22);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #eee;
        }
        .modal-header h2 { margin: 0; font-size: 1.15rem; }
        .modal-close {
            background: none; border: none;
            font-size: 1.4rem; cursor: pointer; color: #888; line-height: 1;
        }
        .modal-close:hover { color: #222; }
        .modal-body { padding: 22px 24px; }
        .modal-footer {
            padding: 14px 24px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* ── Form grid ─────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-size: .82rem; font-weight: 600; color: #444; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 8px 11px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: .9rem;
            width: 100%;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ff6600;
            box-shadow: 0 0 0 2px rgba(255,102,0,.15);
        }
        .form-group .hint { font-size: .73rem; color: #999; margin-top: 2px; }

        /* ── Calc section ──────────────────────────────────── */
        .calc-box {
            background: #f7f7f7;
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 16px;
            margin-top: 4px;
        }
        .calc-box h4 { margin: 0 0 12px; font-size: .88rem; color: #555; text-transform: uppercase; letter-spacing: .04em; }
        .calc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            font-size: .88rem;
            border-bottom: 1px solid #eee;
        }
        .calc-row:last-child { border-bottom: none; font-weight: 700; font-size: .95rem; }
        .calc-row .calc-val { font-weight: 600; color: #222; }
        .calc-row.net .calc-val { color: #ff6600; font-size: 1.05rem; }

        /* ── Detail view ───────────────────────────────────── */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
        }
        .detail-item { padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
        .detail-item .detail-label { font-size: .75rem; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .detail-item .detail-val   { font-size: .93rem; color: #222; margin-top: 2px; font-weight: 500; }

        /* ── Alert / flash ─────────────────────────────────── */
        .alert {
            padding: 12px 16px;
            border-radius: 7px;
            margin-bottom: 18px;
            font-size: .9rem;
            font-weight: 500;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* ── Empty state ───────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #999;
        }
        .empty-state .empty-icon { font-size: 3rem; margin-bottom: 10px; }

        /* ── Period badge ──────────────────────────────────── */
        .period { font-size: .78rem; color: #777; }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/header.php'; ?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div style="display:flex; min-height:calc(100vh - 60px);">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main style="flex:1; padding:28px 24px; background:#f4f4f4; min-width:0;">

        <!-- Page header -->
        <div class="page-header">
            <h1>💰 Payroll Management</h1>
            <button class="btn btn-primary" onclick="openModal('modal-create')">
                + New Payroll Entry
            </button>
        </div>

        <!-- Flash message -->
        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msgType === 'success' ? 'success' : 'error'; ?>">
                <?php echo e($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Stat cards -->
        <div class="stat-row">
            <div class="stat-card">
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
                <div class="stat-label">Total Records</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo (int)$stats['drafts']; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo (int)$stats['approved']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo (int)$stats['paid_count']; ?></div>
                <div class="stat-label">Paid Out</div>
            </div>
            <div class="stat-card accent">
                <div class="stat-value"><?php echo fmtMoney((float)$stats['total_paid_out']); ?></div>
                <div class="stat-label">Total Disbursed</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" action="<?php echo $selfUrl; ?>" class="filter-bar">
            <div class="filter-group">
                <label>Search Employee</label>
                <input type="text" name="q" value="<?php echo e($filterSearch); ?>" placeholder="Employee name…">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="draft"    <?php echo $filterStatus === 'draft'    ? 'selected' : ''; ?>>Draft</option>
                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="paid"     <?php echo $filterStatus === 'paid'     ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Employee Type</label>
                <select name="target_type">
                    <option value="">All Types</option>
                    <option value="mechanic"    <?php echo $filterType === 'mechanic'    ? 'selected' : ''; ?>>Mechanic</option>
                    <option value="staff_admin" <?php echo $filterType === 'staff_admin' ? 'selected' : ''; ?>>Staff Admin</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Filter</button>
                <?php if ($filterStatus || $filterType || $filterSearch): ?>
                    <a href="<?php echo $selfUrl; ?>" class="btn">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Payroll table -->
        <div class="card" style="padding:0; overflow:hidden;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Jobs</th>
                            <th>Base Salary</th>
                            <th>Commission</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payrolls)): ?>
                            <tr>
                                <td colspan="11">
                                    <div class="empty-state">
                                        <div class="empty-icon">📋</div>
                                        <div>No payroll records found.</div>
                                        <div style="margin-top:8px; font-size:.85rem;">
                                            Click <strong>+ New Payroll Entry</strong> to create one.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payrolls as $pr): ?>
                                <tr>
                                    <td><?php echo (int)$pr['id']; ?></td>
                                    <td>
                                        <strong><?php echo e($pr['employee_name']); ?></strong>
                                        <?php if ($pr['created_by_name']): ?>
                                            <br><span class="muted" style="font-size:.75rem;">by <?php echo e($pr['created_by_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $pr['target_type'] === 'mechanic'
                                            ? '<span style="background:#fff3e0;color:#e65c00;padding:2px 8px;border-radius:4px;font-size:.78rem;font-weight:600;">Mechanic</span>'
                                            : '<span style="background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:4px;font-size:.78rem;font-weight:600;">Staff Admin</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <span class="period">
                                            <?php echo e(date('M d', strtotime($pr['period_start']))); ?> –<br>
                                            <?php echo e(date('M d, Y', strtotime($pr['period_end']))); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;"><?php echo (int)$pr['total_jobs']; ?></td>
                                    <td><?php echo fmtMoney((float)$pr['base_salary']); ?></td>
                                    <td><?php echo fmtMoney((float)$pr['commission_earnings']); ?></td>
                                    <td style="color:#dc3545;">
                                        <?php echo $pr['deductions'] > 0 ? '-' . fmtMoney((float)$pr['deductions']) : '—'; ?>
                                    </td>
                                    <td style="font-weight:700; color:#ff6600;">
                                        <?php echo fmtMoney((float)$pr['net_pay']); ?>
                                    </td>
                                    <td><?php echo statusBadge($pr['status']); ?></td>
                                    <td>
                                        <div style="display:flex; gap:4px; flex-wrap:wrap;">
                                            <!-- View detail -->
                                            <button
                                                class="action-btn btn-view"
                                                onclick='openDetail(<?php echo json_encode($pr); ?>)'>
                                                View
                                            </button>

                                            <?php if ($pr['status'] === 'draft'): ?>
                                                <!-- Approve -->
                                                <form method="POST" action="<?php echo $selfUrl; ?>" style="display:inline;"
                                                      onsubmit="return confirm('Approve this payroll record?')">
                                                    <input type="hidden" name="action"     value="approve">
                                                    <input type="hidden" name="payroll_id" value="<?php echo (int)$pr['id']; ?>">
                                                    <button type="submit" class="action-btn btn-approve">Approve</button>
                                                </form>
                                                <!-- Delete draft -->
                                                <form method="POST" action="<?php echo $selfUrl; ?>" style="display:inline;"
                                                      onsubmit="return confirm('Delete this draft? This cannot be undone.')">
                                                    <input type="hidden" name="action"     value="delete">
                                                    <input type="hidden" name="payroll_id" value="<?php echo (int)$pr['id']; ?>">
                                                    <button type="submit" class="action-btn btn-delete">Delete</button>
                                                </form>

                                            <?php elseif ($pr['status'] === 'approved'): ?>
                                                <!-- Mark Paid -->
                                                <button
                                                    class="action-btn btn-pay"
                                                    onclick='openPayModal(<?php echo (int)$pr["id"]; ?>, <?php echo htmlspecialchars(json_encode($pr["employee_name"])); ?>, <?php echo (float)$pr["net_pay"]; ?>)'>
                                                    Mark Paid
                                                </button>

                                            <?php elseif ($pr['status'] === 'paid'): ?>
                                                <span style="font-size:.75rem; color:#28a745; font-weight:600;">
                                                    ✓ Paid <?php echo $pr['paid_at'] ? date('M d, Y', strtotime($pr['paid_at'])) : ''; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


<!-- ══════════════════════════════════════════════════════════
     MODAL: CREATE NEW PAYROLL ENTRY
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-create">
    <div class="modal-box">
        <div class="modal-header">
            <h2>New Payroll Entry</h2>
            <button class="modal-close" onclick="closeModal('modal-create')">✕</button>
        </div>
        <form method="POST" action="<?php echo $selfUrl; ?>" id="form-create">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">

                <!-- Step 1: Employee selection -->
                <div class="form-grid">
                    <div class="form-group">
                        <label>Employee Type <span style="color:#dc3545">*</span></label>
                        <select name="target_type" id="sel-type" onchange="onTypeChange()" required>
                            <option value="">— Select type —</option>
                            <option value="mechanic">Mechanic</option>
                            <option value="staff_admin">Staff Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Employee <span style="color:#dc3545">*</span></label>
                        <select name="employee_id" id="sel-emp" onchange="onEmployeeChange()" required disabled>
                            <option value="">— Select employee —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Period Start <span style="color:#dc3545">*</span></label>
                        <input type="date" name="period_start" id="inp-ps" required>
                    </div>
                    <div class="form-group">
                        <label>Period End <span style="color:#dc3545">*</span></label>
                        <input type="date" name="period_end" id="inp-pe" required>
                    </div>
                    <div class="form-group full" style="align-items:flex-start;">
                        <button type="button" class="btn btn-primary" onclick="calculate()" id="btn-calc" disabled>
                            ⟳ Auto-Calculate from Jobs
                        </button>
                        <span class="hint" style="margin-top:6px;">
                            Pulls completed &amp; paid jobs for the selected mechanic in the chosen period.
                            Staff admins receive base salary only.
                        </span>
                    </div>
                </div>

                <!-- Hidden: employee name passed through -->
                <input type="hidden" name="employee_name" id="inp-ename">

                <!-- Calculation summary -->
                <div class="calc-box" id="calc-box" style="display:none; margin-top:16px;">
                    <h4>Pay Breakdown</h4>
                    <div class="calc-row">
                        <span>Total Jobs Completed</span>
                        <span class="calc-val" id="disp-jobs">0</span>
                    </div>
                    <div class="calc-row">
                        <span>Gross Job Revenue</span>
                        <span class="calc-val" id="disp-gross">$0.00</span>
                    </div>
                    <div class="calc-row">
                        <span>Base Salary</span>
                        <span class="calc-val" id="disp-base">$0.00</span>
                    </div>
                    <div class="calc-row" id="row-comm">
                        <span id="disp-rate-label">Commission (0%)</span>
                        <span class="calc-val" id="disp-comm">$0.00</span>
                    </div>
                    <div class="calc-row">
                        <span style="color:#dc3545;">Deductions</span>
                        <span class="calc-val" id="disp-ded" style="color:#dc3545;">-$0.00</span>
                    </div>
                    <div class="calc-row net">
                        <span>Net Pay</span>
                        <span class="calc-val" id="disp-net">$0.00</span>
                    </div>
                </div>

                <!-- Hidden calculated fields -->
                <input type="hidden" name="total_jobs"          id="h-jobs"   value="0">
                <input type="hidden" name="gross_job_revenue"   id="h-gross"  value="0">
                <input type="hidden" name="base_salary"         id="h-base"   value="0">
                <input type="hidden" name="commission_rate"     id="h-rate"   value="0">
                <input type="hidden" name="commission_earnings" id="h-comm"   value="0">

                <!-- Adjustable fields -->
                <div class="form-grid" id="adj-fields" style="display:none; margin-top:16px;">
                    <div class="form-group">
                        <label>Deductions ($)</label>
                        <input type="number" name="deductions" id="inp-ded" value="0" min="0" step="0.01"
                               oninput="recalcNet()">
                        <span class="hint">Advances, absences, or other deductions</span>
                    </div>
                    <div class="form-group">
                        <label>Deduction Notes</label>
                        <input type="text" name="deduction_notes" id="inp-dednotes" placeholder="e.g. Advance repayment">
                    </div>
                    <div class="form-group full">
                        <label>Additional Notes</label>
                        <textarea name="notes" id="inp-notes" rows="2" placeholder="Optional internal notes…"></textarea>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('modal-create')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btn-save" disabled>Save as Draft</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     MODAL: MARK AS PAID
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-pay">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h2>Mark Payroll as Paid</h2>
            <button class="modal-close" onclick="closeModal('modal-pay')">✕</button>
        </div>
        <form method="POST" action="<?php echo $selfUrl; ?>">
            <input type="hidden" name="action"     value="mark_paid">
            <input type="hidden" name="payroll_id" id="pay-id">
            <div class="modal-body">
                <p style="margin:0 0 16px; font-size:.95rem;">
                    Paying <strong id="pay-name"></strong> — Net Pay:
                    <strong id="pay-amount" style="color:#ff6600;"></strong>
                </p>
                <div class="form-group" style="margin-bottom:14px;">
                    <label>Payment Method <span style="color:#dc3545">*</span></label>
                    <select name="payment_method" required>
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Reference / Transaction ID</label>
                    <input type="text" name="payment_reference" placeholder="e.g. TXN123456 (optional)">
                </div>
                <p class="hint" style="margin-top:12px;">
                    This will mark the record as <strong>paid</strong> and post an <strong>OUT</strong> entry
                    to the balance ledger.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('modal-pay')">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     MODAL: VIEW DETAIL
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-detail">
    <div class="modal-box" style="max-width:600px;">
        <div class="modal-header">
            <h2>Payroll Detail</h2>
            <button class="modal-close" onclick="closeModal('modal-detail')">✕</button>
        </div>
        <div class="modal-body" id="detail-body">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('modal-detail')">Close</button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     EMPLOYEE DATA (for JS)
══════════════════════════════════════════════════════════ -->
<script>
const MECHANICS = <?php echo json_encode($mechanics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const STAFF_ADMINS = <?php echo json_encode($staffAdmins, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

/* ── Modal helpers ──────────────────────────────────────── */
function openModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === el) el.classList.remove('active');
    });
});

/* ── Mark-paid modal ─────────────────────────────────── */
function openPayModal(id, name, net) {
    document.getElementById('pay-id').value           = id;
    document.getElementById('pay-name').textContent   = name;
    document.getElementById('pay-amount').textContent = '$' + parseFloat(net).toFixed(2);
    openModal('modal-pay');
}

/* ── Detail modal ────────────────────────────────────── */
function openDetail(pr) {
    const fmt  = v => '$' + parseFloat(v || 0).toFixed(2);
    const date = s => s ? s.substring(0, 10) : '—';
    const statusColor = { draft: '#f39c12', approved: '#2980b9', paid: '#27ae60' };
    const col = statusColor[pr.status] || '#999';

    const typeLabel = pr.target_type === 'mechanic' ? 'Mechanic' : 'Staff Admin';
    const payMethod = pr.payment_method
        ? pr.payment_method.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
        : '—';

    document.getElementById('detail-body').innerHTML = `
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
            <div>
                <div style="font-size:1.2rem; font-weight:700;">${esc(pr.employee_name)}</div>
                <div style="font-size:.8rem; color:#888; margin-top:3px;">${typeLabel} &bull; Record #${pr.id}</div>
            </div>
            <span style="padding:4px 14px; border-radius:20px; background:${col}20; color:${col}; font-weight:700; font-size:.88rem; border:1px solid ${col}40;">
                ${pr.status.toUpperCase()}
            </span>
        </div>

        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Period</div>
                <div class="detail-val">${date(pr.period_start)} → ${date(pr.period_end)}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Total Jobs</div>
                <div class="detail-val">${pr.total_jobs} jobs</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Gross Job Revenue</div>
                <div class="detail-val">${fmt(pr.gross_job_revenue)}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Base Salary</div>
                <div class="detail-val">${fmt(pr.base_salary)}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Commission Rate</div>
                <div class="detail-val">${parseFloat(pr.commission_rate || 0).toFixed(2)}%</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Commission Earned</div>
                <div class="detail-val">${fmt(pr.commission_earnings)}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Deductions</div>
                <div class="detail-val" style="color:#dc3545;">${pr.deductions > 0 ? '-' + fmt(pr.deductions) : '—'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Net Pay</div>
                <div class="detail-val" style="color:#ff6600; font-size:1.1rem;">${fmt(pr.net_pay)}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Payment Method</div>
                <div class="detail-val">${payMethod}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Payment Reference</div>
                <div class="detail-val">${pr.payment_reference ? esc(pr.payment_reference) : '—'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Paid At</div>
                <div class="detail-val">${pr.paid_at ? pr.paid_at.substring(0, 16).replace('T', ' ') : '—'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Created At</div>
                <div class="detail-val">${pr.created_at ? pr.created_at.substring(0, 16).replace('T', ' ') : '—'}</div>
            </div>
        </div>

        ${pr.deduction_notes ? `<div style="margin-top:14px; background:#fff3cd; border-radius:6px; padding:10px 14px; font-size:.88rem;"><strong>Deduction Notes:</strong> ${esc(pr.deduction_notes)}</div>` : ''}
        ${pr.notes ? `<div style="margin-top:10px; background:#f0f4ff; border-radius:6px; padding:10px 14px; font-size:.88rem;"><strong>Notes:</strong> ${esc(pr.notes)}</div>` : ''}
    `;
    openModal('modal-detail');
}

function esc(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Create form logic ───────────────────────────────── */
function onTypeChange() {
    const type = document.getElementById('sel-type').value;
    const empSel = document.getElementById('sel-emp');
    empSel.innerHTML = '<option value="">— Select employee —</option>';
    empSel.disabled = !type;

    const list = type === 'mechanic' ? MECHANICS : STAFF_ADMINS;
    list.forEach(e => {
        const opt = document.createElement('option');
        opt.value = e.id;
        opt.textContent = type === 'mechanic' ? e.name : e.full_name;
        empSel.appendChild(opt);
    });

    resetCalc();
    updateCalcBtn();
}

function onEmployeeChange() {
    resetCalc();
    updateCalcBtn();
}

function updateCalcBtn() {
    const hasType = !!document.getElementById('sel-type').value;
    const hasEmp  = !!document.getElementById('sel-emp').value;
    document.getElementById('btn-calc').disabled = !(hasType && hasEmp);
}

function resetCalc() {
    document.getElementById('calc-box').style.display   = 'none';
    document.getElementById('adj-fields').style.display = 'none';
    document.getElementById('btn-save').disabled = true;
    setHidden(0, 0, 0, 0, 0, '');
}

function setHidden(jobs, gross, base, rate, comm, ename) {
    document.getElementById('h-jobs').value    = jobs;
    document.getElementById('h-gross').value   = gross;
    document.getElementById('h-base').value    = base;
    document.getElementById('h-rate').value    = rate;
    document.getElementById('h-comm').value    = comm;
    document.getElementById('inp-ename').value = ename;
}

function fmt(v) { return '$' + parseFloat(v || 0).toFixed(2); }

function calculate() {
    const type  = document.getElementById('sel-type').value;
    const empId = document.getElementById('sel-emp').value;
    const ps    = document.getElementById('inp-ps').value;
    const pe    = document.getElementById('inp-pe').value;

    if (!type || !empId) { alert('Please select an employee type and employee.'); return; }
    if (!ps || !pe)      { alert('Please select a date range first.'); return; }
    if (ps > pe)         { alert('Period start cannot be after period end.'); return; }

    const btn = document.getElementById('btn-calc');
    btn.textContent = 'Calculating…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'calculate');
    fd.append('target_type',  type);
    fd.append('employee_id',  empId);
    fd.append('period_start', ps);
    fd.append('period_end',   pe);

    fetch('<?php echo $selfUrl; ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const ded = parseFloat(document.getElementById('inp-ded').value) || 0;
            const net = parseFloat(data.base_salary) + parseFloat(data.commission_earnings) - ded;

            // Update displays
            document.getElementById('disp-jobs').textContent        = data.total_jobs;
            document.getElementById('disp-gross').textContent       = fmt(data.gross_job_revenue);
            document.getElementById('disp-base').textContent        = fmt(data.base_salary);
            document.getElementById('disp-rate-label').textContent  = `Commission (${parseFloat(data.commission_rate).toFixed(2)}%)`;
            document.getElementById('disp-comm').textContent        = fmt(data.commission_earnings);
            document.getElementById('disp-ded').textContent         = '-' + fmt(ded);
            document.getElementById('disp-net').textContent         = fmt(net);

            // Update hidden
            setHidden(
                data.total_jobs, data.gross_job_revenue,
                data.base_salary, data.commission_rate,
                data.commission_earnings, data.employee_name
            );

            // Show/hide commission row (staff admins have 0%)
            document.getElementById('row-comm').style.display =
                parseFloat(data.commission_rate) > 0 ? '' : 'none';

            document.getElementById('calc-box').style.display   = '';
            document.getElementById('adj-fields').style.display = '';
            document.getElementById('btn-save').disabled = false;

            btn.textContent = '⟳ Recalculate';
            btn.disabled = false;
        })
        .catch(() => {
            alert('Calculation failed. Please try again.');
            btn.textContent = '⟳ Auto-Calculate from Jobs';
            btn.disabled = false;
        });
}

function recalcNet() {
    const base = parseFloat(document.getElementById('h-base').value) || 0;
    const comm = parseFloat(document.getElementById('h-comm').value) || 0;
    const ded  = parseFloat(document.getElementById('inp-ded').value) || 0;
    const net  = base + comm - ded;

    document.getElementById('disp-ded').textContent = '-' + fmt(ded);
    document.getElementById('disp-net').textContent = fmt(net < 0 ? 0 : net);
}
</script>

</body>
</html>