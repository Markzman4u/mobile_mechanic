<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$pdo     = getPDO();

// ── Fetch current user cancel state ──────────────────────────────────────
$uStmt = $pdo->prepare(
    'SELECT cancel_count_today, cancel_date, cancel_blocked_until
     FROM users WHERE id = ?'
);
$uStmt->execute([$user_id]);
$userRow = $uStmt->fetch();

// Resolve today's values (auto-reset if a new calendar day has started)
$isNewDay     = !$userRow['cancel_date'] || $userRow['cancel_date'] !== date('Y-m-d');
$cancelCount  = $isNewDay ? 0 : (int)$userRow['cancel_count_today'];
$blockedUntil = $isNewDay ? null : $userRow['cancel_blocked_until'];

// Is the user currently blocked?
$isBlocked        = $blockedUntil && strtotime($blockedUntil) > time();
$blockedUntilTs   = $isBlocked ? strtotime($blockedUntil) : 0;

// ── Handle cancel POST ────────────────────────────────────────────────────
$flash     = null;
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cancel_request_id'])) {
    $cancelId = (int)$_POST['cancel_request_id'];

    // Verify the request belongs to this user and is still pending
    $chk = $pdo->prepare(
        'SELECT r.*, u.full_name, u.phone, u.gender, u.date_of_birth,
                m.name AS mech_name, m.gender AS mech_gender
         FROM requests r
         LEFT JOIN users     u ON r.user_id     = u.id
         LEFT JOIN mechanics m ON r.mechanic_id = m.id
         WHERE r.id = ? AND r.user_id = ? AND r.status = "pending"'
    );
    $chk->execute([$cancelId, $user_id]);
    $reqData = $chk->fetch();

    if (!$reqData) {
        $flash     = 'This request cannot be cancelled — it may have already been assigned or processed.';
        $flashType = 'error';
    } else {
        // ── Calculate new cancel count & escalating block ───────────────
        // If it's a new day, start fresh regardless of stored values
        $newCount = ($isNewDay ? 0 : $cancelCount) + 1;

        // Escalating block: block starts from the 2nd cancellation.
        // Duration = (newCount - 1) * 5 minutes.
        // e.g. 2nd cancel = 5 min, 3rd = 10 min, 4th = 15 min, etc.
        $newBlockedUntil = null;
        $blockMinutes    = 0;
        if ($newCount >= 2) {
            $blockMinutes    = ($newCount - 1) * 5;
            $newBlockedUntil = date('Y-m-d H:i:s', strtotime('+' . $blockMinutes . ' minutes'));
        }

        // ── Archive to history_records ──────────────────────────────────
        $resolvedMake = ($reqData['vehicle_make'] === 'Other' && !empty($reqData['vehicle_make_other']))
            ? $reqData['vehicle_make_other']
            : $reqData['vehicle_make'];

        $histStmt = $pdo->prepare(
            'INSERT INTO history_records
             (request_id, user_id, walkin_id, is_walkin,
              customer_name, customer_phone, customer_gender, customer_dob,
              mechanic_id, mechanic_name, mechanic_gender,
              vehicle_make, vehicle_model, vehicle_year,
              problem_type, description, image,
              latitude, longitude, diagnosis,
              status, rejection_reason, total_amount,
              request_created_at, completed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $histStmt->execute([
            $cancelId,
            $reqData['user_id'],
            $reqData['walkin_id'],
            0, // is_walkin
            $reqData['full_name'],
            $reqData['phone'],
            $reqData['gender'],
            $reqData['date_of_birth'],
            $reqData['mechanic_id'],
            $reqData['mech_name'],
            $reqData['mech_gender'],
            $resolvedMake,
            $reqData['vehicle_model'],
            $reqData['vehicle_year'],
            $reqData['problem_type'],
            $reqData['description'],
            $reqData['image'],
            $reqData['latitude'],
            $reqData['longitude'],
            $reqData['diagnosis'],
            'cancelled',
            null,
            0.00,
            $reqData['created_at'],
        ]);

        // ── Mark request as cancelled ───────────────────────────────────
        $pdo->prepare('UPDATE requests SET status = "cancelled" WHERE id = ?')
            ->execute([$cancelId]);

        // ── Persist cancel tracking to users table ──────────────────────
        $pdo->prepare(
            'UPDATE users
             SET cancel_count_today   = ?,
                 cancel_date          = CURDATE(),
                 cancel_blocked_until = ?
             WHERE id = ?'
        )->execute([$newCount, $newBlockedUntil, $user_id]);

        // Update local state for the current page render
        $cancelCount  = $newCount;
        $blockedUntil = $newBlockedUntil;
        $isBlocked    = $newBlockedUntil && strtotime($newBlockedUntil) > time();
        $blockedUntilTs = $isBlocked ? strtotime($newBlockedUntil) : 0;
        $isNewDay     = false;

        if ($newBlockedUntil) {
            $blockedFmt = date('g:i:s A', strtotime($newBlockedUntil));
            $flash      = 'Request #' . $cancelId . ' cancelled. '
                        . 'You\'ve cancelled ' . $newCount . ' time' . ($newCount !== 1 ? 's' : '') . ' today — '
                        . 'new requests are blocked for <strong>' . $blockMinutes . ' minutes</strong> until '
                        . '<strong>' . $blockedFmt . '</strong>.';
            $flashType  = 'warning';
        } else {
            $flash     = 'Request #' . $cancelId . ' has been cancelled.';
            $flashType = 'success';
        }
    }
}

// ── Load active requests ──────────────────────────────────────────────────
$requests = [];
if ($user_id) {
    $stmt = $pdo->prepare(
        'SELECT r.*,
                m.name                AS mechanic_name,
                m.current_lat         AS mech_lat,
                m.current_lng         AS mech_lng,
                m.location_updated_at AS mech_loc_updated
         FROM requests r
         LEFT JOIN mechanics m ON r.mechanic_id = m.id
         WHERE r.user_id = ?
           AND r.status NOT IN ("completed","rejected","cancelled")
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();
}

$selectedId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : null;

$selected = null;
if ($selectedId) {
    foreach ($requests as $r) {
        if ((int)$r['id'] === $selectedId) { $selected = $r; break; }
    }
}
if (!$selected && !empty($requests)) {
    $selected = $requests[0];
}

// Pre-calculate the block duration that WOULD apply on the next cancel,
// so we can show it in the modal warning.
$nextCancelCount   = $cancelCount + 1;
$nextBlockMinutes  = $nextCancelCount >= 2 ? ($nextCancelCount - 1) * 5 : 0;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Layout ────────────────────────────────────────────────────────────── */
.track-wrap {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}
.track-list {
    width: 280px;
    flex-shrink: 0;
}
.track-detail {
    flex: 1;
    min-width: 0;
}

/* ── Request list card ─────────────────────────────────────────────────── */
.req-item {
    display: block;
    padding: 12px 14px;
    border-radius: 8px;
    border: 2px solid transparent;
    background: #f9f9f9;
    margin-bottom: 8px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    transition: border-color .2s, background .2s;
}
.req-item:hover { background: #fff4ec; border-color: #ffcc99; }
.req-item.active { background: #fff4ec; border-color: var(--safety-orange,#ff6600); }
.req-item .req-meta {
    font-size: .75rem;
    color: var(--muted,#888);
    margin-top: 3px;
}

/* ── Progress stepper ──────────────────────────────────────────────────── */
.stepper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 20px 0 24px;
    position: relative;
}
.stepper::before {
    content: '';
    position: absolute;
    top: 18px;
    left: 18px;
    right: 18px;
    height: 3px;
    background: #e0e0e0;
    z-index: 0;
}
.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    position: relative;
    z-index: 1;
    flex: 1;
}
.step-circle {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: #e0e0e0;
    color: #999;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    font-weight: 700;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #e0e0e0;
    transition: background .3s, box-shadow .3s;
}
.step-label {
    font-size: .72rem;
    color: #999;
    text-align: center;
    font-weight: 600;
    white-space: nowrap;
}
.step.done   .step-circle { background:#4caf50; color:#fff; box-shadow:0 0 0 2px #4caf50; }
.step.done   .step-label  { color:#388e3c; }
.step.active .step-circle { background:var(--safety-orange,#ff6600); color:#fff; box-shadow:0 0 0 2px var(--safety-orange,#ff6600); }
.step.active .step-label  { color:var(--safety-orange,#ff6600); }

/* ── Detail rows ───────────────────────────────────────────────────────── */
.detail-row {
    display:flex; gap:8px;
    padding:10px 0;
    border-bottom:1px solid #f0f0f0;
    font-size:.9rem;
}
.detail-row:last-child { border-bottom:none; }
.detail-label { font-weight:600; min-width:130px; color:#555; }

/* ── Empty state ───────────────────────────────────────────────────────── */
.empty-state {
    text-align:center; padding:40px 20px; color:var(--muted,#888);
}
.empty-state .empty-icon { font-size:3rem; display:block; margin-bottom:12px; }

/* ── Flash messages ────────────────────────────────────────────────────── */
.flash {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: .9rem;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.flash.success { background:#e8f5e9; border-left:4px solid #4caf50; color:#2e7d32; }
.flash.warning { background:#fff8e1; border-left:4px solid #ff9800; color:#7c4a00; }
.flash.error   { background:#fce4ec; border-left:4px solid #e53935; color:#b71c1c; }
.flash.info    { background:#e3f2fd; border-left:4px solid #1e88e5; color:#0d47a1; }
.flash-icon    { font-size:1.1rem; flex-shrink:0; margin-top:1px; }

/* ── Block banner ──────────────────────────────────────────────────────── */
.block-banner {
    background: #fff3e0;
    border: 1.5px solid #ff9800;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.block-banner-icon { font-size: 1.6rem; flex-shrink: 0; }
.block-banner-text { flex: 1; min-width: 0; }
.block-banner-text strong { display: block; font-size: .95rem; color: #e65100; margin-bottom: 3px; }
.block-banner-text p { margin: 0; font-size: .84rem; color: #7c4a00; }
.block-countdown {
    font-size: 1.2rem;
    font-weight: 700;
    color: #e65100;
    background: #ffe0b2;
    padding: 6px 14px;
    border-radius: 8px;
    min-width: 80px;
    text-align: center;
    flex-shrink: 0;
}

/* ── Cancel streak counter ─────────────────────────────────────────────── */
.cancel-tally {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fafafa;
    border: 1px solid #eee;
    border-radius: 20px;
    padding: 4px 12px;
    font-size: .78rem;
    color: #666;
    margin-bottom: 14px;
}
.cancel-tally .tally-dots {
    display: flex; gap: 4px;
}
.cancel-tally .tally-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: #ddd;
}
.cancel-tally .tally-dot.used { background: #ff9800; }
.cancel-tally .tally-dot.warn { background: #e53935; }

/* ── Photo thumbnail ───────────────────────────────────────────────────── */
.photo-thumb-wrap {
    display: inline-block;
    position: relative;
    cursor: zoom-in;
}
.photo-thumb {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #eee;
    display: block;
    transition: border-color .2s, transform .2s;
}
.photo-thumb:hover {
    border-color: var(--safety-orange, #ff6600);
    transform: scale(1.03);
}
.photo-thumb-badge {
    position: absolute;
    bottom: 6px;
    right: 6px;
    background: rgba(0,0,0,.55);
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    pointer-events: none;
    letter-spacing: .04em;
}

/* ── Lightbox ──────────────────────────────────────────────────────────── */
.lightbox-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.82);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    animation: lbFadeIn .18s ease;
}
.lightbox-overlay.open { display: flex; }
@keyframes lbFadeIn { from { opacity:0; } to { opacity:1; } }
.lightbox-inner {
    position: relative;
    max-width: 92vw;
    max-height: 88vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.lightbox-inner img {
    max-width: 92vw;
    max-height: 88vh;
    border-radius: 10px;
    box-shadow: 0 8px 48px rgba(0,0,0,.6);
    animation: lbZoomIn .2s ease;
}
@keyframes lbZoomIn {
    from { transform: scale(.93); opacity:0; }
    to   { transform: scale(1);   opacity:1; }
}
.lightbox-close {
    position: absolute;
    top: -14px; right: -14px;
    width: 34px; height: 34px;
    border-radius: 50%;
    background: #fff;
    color: #333;
    border: none;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
    line-height: 1;
}
.lightbox-close:hover { background: var(--safety-orange,#ff6600); color:#fff; }

/* ── Cancel confirm modal ──────────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 8000;
    align-items: center;
    justify-content: center;
    animation: lbFadeIn .18s ease;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 12px;
    padding: 28px 28px 22px;
    max-width: 420px;
    width: calc(100% - 40px);
    box-shadow: 0 12px 48px rgba(0,0,0,.22);
    animation: lbZoomIn .2s ease;
}
.modal-box h3 { margin: 0 0 10px; font-size: 1.1rem; }
.modal-box p  { margin: 0 0 20px; font-size: .88rem; color: #555; line-height: 1.5; }
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* ── Map accordion ─────────────────────────────────────────────────────── */
.map-accordion {
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 16px;
}
.map-accordion-toggle {
    width: 100%;
    background: #f9f9f9;
    border: none;
    padding: 13px 16px;
    text-align: left;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: .88rem;
    font-weight: 700;
    color: #333;
    transition: background .15s;
    gap: 10px;
}
.map-accordion-toggle:hover { background: #f0f0f0; }
.map-accordion-toggle .toggle-left {
    display: flex;
    align-items: center;
    gap: 8px;
}
.map-accordion-toggle .toggle-arrow {
    font-size: .75rem;
    color: var(--muted,#888);
    transition: transform .25s;
}
.map-accordion-toggle.open .toggle-arrow { transform: rotate(180deg); }

.map-accordion-body {
    display: none;
    border-top: 1px solid #efefef;
}
.map-accordion-body.open { display: block; }

/* Live badge */
.live-badge {
    font-size: .67rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 10px;
    background: #e8f5e9;
    color: #2e7d32;
}
.live-badge.inactive { background: #f0f0f0; color: #aaa; }

/* The map itself */
.cust-map {
    width: 100%;
    height: 320px;
    background: #eee;
}

/* Not-assigned placeholder */
.map-unassigned {
    padding: 32px 20px;
    text-align: center;
    color: var(--muted,#888);
}
.map-unassigned .ua-icon { font-size: 2.4rem; display:block; margin-bottom:10px; }

/* Toolbar inside accordion */
.map-acc-toolbar {
    padding: 9px 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    border-top: 1px solid #f0f0f0;
    background: #fafafa;
}
.map-acc-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 13px; border-radius: 16px;
    font-size: .76rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    background: #f0f0f0; color: #444; border-color: #ddd;
    text-decoration: none;
    transition: background .15s;
}
.map-acc-btn:hover { background: #e4e4e4; }
.map-acc-btn.orange { background:#fff3e8; color:var(--safety-orange,#ff6600); border-color:#ffd0a8; }
.map-acc-btn.orange:hover { background:#ffe0c2; }

.distance-chip {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 5px;
    background: #1a1a1a; color: #fff;
    padding: 5px 13px; border-radius: 16px;
    font-size: .76rem; font-weight: 700;
}
.distance-chip.hidden { display: none; }

/* Legend */
.map-acc-legend {
    padding: 8px 14px;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    border-top: 1px solid #f0f0f0;
}
.legend-item {
    display: flex; align-items: center; gap: 5px;
    font-size: .78rem; color: #555;
}
.legend-dot {
    width: 11px; height: 11px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
    flex-shrink: 0;
}

/* ── Cancel btn ────────────────────────────────────────────────────────── */
.btn-cancel {
    background: #fff0f0;
    color: #c62828;
    border: 1px solid #ffcdd2;
    padding: 7px 16px;
    border-radius: 6px;
    font-size: .85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
}
.btn-cancel:hover { background: #ffebee; }

/* ── Responsive ────────────────────────────────────────────────────────── */
@media(max-width:680px){
    .track-wrap { flex-direction:column; }
    .track-list { width:100%; }
}
</style>

<!-- ── Lightbox overlay ────────────────────────────────────────────────── -->
<div class="lightbox-overlay" id="lightbox" role="dialog" aria-modal="true" aria-label="Photo preview">
    <div class="lightbox-inner">
        <button class="lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>
        <img src="" alt="Request photo" id="lightboxImg">
    </div>
</div>

<!-- ── Cancel confirm modal ───────────────────────────────────────────── -->
<div class="modal-overlay" id="cancelModal" role="dialog" aria-modal="true" aria-label="Cancel request">
    <div class="modal-box">
        <h3>⚠️ Cancel this request?</h3>
        <p id="cancelModalMsg">Are you sure you want to cancel this request? This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn" onclick="closeCancelModal()">Keep Request</button>
            <form method="POST" style="display:inline;" id="cancelForm">
                <input type="hidden" name="cancel_request_id" id="cancelRequestInput" value="">
                <button type="submit" class="btn-cancel">Yes, Cancel It</button>
            </form>
        </div>
    </div>
</div>

<main>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
        <h2 style="margin:0;">My Service Requests</h2>
        <?php if ($isBlocked): ?>
            <button class="btn btn-primary" disabled
                    style="opacity:.5;cursor:not-allowed;padding:8px 16px;font-size:.85rem;"
                    title="You are temporarily blocked from submitting new requests.">
                + New Request
            </button>
        <?php else: ?>
            <a href="<?php echo getBasePath(); ?>customer/request_service.php"
               class="btn btn-primary" style="padding:8px 16px;font-size:.85rem;">
                + New Request
            </a>
        <?php endif; ?>
    </div>
    <p class="muted" style="margin-top:4px;">
        Active requests are tracked here. View completed ones in
        <a href="<?php echo getBasePath(); ?>customer/customer_history.php">History</a>.
    </p>

    <?php if ($flash): ?>
    <div class="flash <?php echo e($flashType); ?>">
        <span class="flash-icon">
            <?php echo $flashType === 'success' ? '✅' : ($flashType === 'warning' ? '⏱️' : '❌'); ?>
        </span>
        <span><?php echo $flash; ?></span>
    </div>
    <?php endif; ?>

    <?php
    // ── Block banner (shown whenever block is active) ─────────────────────
    if ($isBlocked):
        $remaining = $blockedUntilTs - time(); // seconds remaining
        // How many minutes was this block?
        $currentBlockMins = ($cancelCount - 1) * 5;
    ?>
    <div class="block-banner">
        <div class="block-banner-icon">🚫</div>
        <div class="block-banner-text">
            <strong>New requests blocked for <?php echo $currentBlockMins; ?> minutes</strong>
            <p>
                You have cancelled <?php echo $cancelCount; ?> time<?php echo $cancelCount !== 1 ? 's' : ''; ?> today.
                Each cancellation from the 2nd onward adds 5 more minutes to the cooldown
                (2nd = 5 min, 3rd = 10 min, 4th = 15 min, and so on).
                The block lifts automatically — you can still cancel existing pending requests.
            </p>
        </div>
        <div class="block-countdown" id="blockCountdown">
            <?php
            $mins = floor($remaining / 60);
            $secs = $remaining % 60;
            echo str_pad($mins, 2, '0', STR_PAD_LEFT) . ':' . str_pad($secs, 2, '0', STR_PAD_LEFT);
            ?>
        </div>
    </div>
    <?php elseif ($cancelCount > 0 && !$isNewDay): ?>
    <!-- Soft warning: not blocked yet but has cancels today -->
    <div class="cancel-tally">
        <span>Today's cancels:</span>
        <span class="tally-dots">
            <?php for ($d = 0; $d < 4; $d++): ?>
                <span class="tally-dot <?php echo $d < $cancelCount ? 'used' : ''; ?>"></span>
            <?php endfor; ?>
        </span>
        <span style="color:#999;">
            <?php
            if ($cancelCount === 1) {
                echo '⚠️ Next cancel = 5 min block';
            } else {
                $nextBlock = $cancelCount * 5; // next cancel will be ($cancelCount+1 - 1)*5
                echo '⚠️ Next cancel = ' . $nextBlock . ' min block';
            }
            ?>
        </span>
    </div>
    <?php endif; ?>

    <?php if (empty($requests)): ?>
        <!-- ── No active requests ──────────────────────────────────────── -->
        <div class="empty-state">
            <span class="empty-icon">✅</span>
            <strong>No active requests</strong><br>
            <span style="font-size:.9rem;">
                You have no pending or in-progress requests right now.
            </span><br><br>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <?php if ($isBlocked): ?>
                    <button class="btn btn-primary" disabled style="opacity:.5;cursor:not-allowed;">
                        Submit New Request (blocked)
                    </button>
                <?php else: ?>
                    <a href="<?php echo getBasePath(); ?>customer/request_service.php"
                       class="btn btn-primary">Submit New Request</a>
                <?php endif; ?>
                <a href="<?php echo getBasePath(); ?>customer/customer_history.php"
                   class="btn">View History</a>
            </div>
        </div>

    <?php else: ?>
        <div class="track-wrap">

            <!-- ── Left: request list ──────────────────────────────────── -->
            <div class="track-list">
                <p style="font-size:.8rem;font-weight:600;color:var(--muted,#888);
                           margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">
                    <?php echo count($requests); ?> Active Request<?php echo count($requests) !== 1 ? 's' : ''; ?>
                </p>

                <?php foreach ($requests as $r): ?>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>?id=<?php echo (int)$r['id']; ?>"
                   class="req-item <?php echo ($selected && (int)$selected['id'] === (int)$r['id']) ? 'active' : ''; ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <strong style="font-size:.9rem;">
                            #<?php echo (int)$r['id']; ?> — <?php echo e($r['problem_type'] ?: 'Service Request'); ?>
                        </strong>
                        <span class="status status-<?php echo e($r['status']); ?>"
                              style="font-size:.7rem;padding:2px 8px;">
                            <?php echo e(statusLabel($r['status'])); ?>
                        </span>
                    </div>
                    <div class="req-meta">
                        <?php echo e(date('M j, Y  H:i', strtotime($r['created_at']))); ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ── Right: selected request detail ─────────────────────── -->
            <?php if ($selected): ?>
            <?php
                $hasMech   = !empty($selected['mechanic_id']);
                $mechLat   = $hasMech && !empty($selected['mech_lat'])  ? (float)$selected['mech_lat']  : null;
                $mechLng   = $hasMech && !empty($selected['mech_lng'])  ? (float)$selected['mech_lng']  : null;
                $custLat   = !empty($selected['latitude'])  ? (float)$selected['latitude']  : null;
                $custLng   = !empty($selected['longitude']) ? (float)$selected['longitude'] : null;
                $mechName  = e($selected['mechanic_name'] ?? '');
                $requestId = (int)$selected['id'];
                $mechId    = (int)($selected['mechanic_id'] ?? 0);
                $isPending = $selected['status'] === 'pending';
            ?>
            <div class="track-detail">

                <!-- Header -->
                <div style="background:#f9f9f9;padding:14px 16px;border-radius:8px;
                             margin-bottom:16px;display:flex;justify-content:space-between;
                             align-items:center;flex-wrap:wrap;gap:8px;">
                    <div>
                        <span style="font-size:.75rem;color:var(--muted,#888);font-weight:600;
                                     text-transform:uppercase;letter-spacing:.05em;">Request</span>
                        <h3 style="margin:0;">#<?php echo (int)$selected['id']; ?></h3>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <div style="text-align:right;font-size:.82rem;color:var(--muted,#888);">
                            Submitted<br>
                            <strong style="color:#333;"><?php echo e(date('M j, Y  H:i', strtotime($selected['created_at']))); ?></strong>
                        </div>
                        <?php if ($isPending): ?>
                        <button class="btn-cancel"
                                onclick="openCancelModal(<?php echo $requestId; ?>, <?php echo $cancelCount; ?>, <?php echo $nextBlockMinutes; ?>)">
                            Cancel Request
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Progress stepper ────────────────────────────────── -->
                <?php
                $steps   = ['pending','assigned','in_progress','completed'];
                $current = array_search($selected['status'], $steps, true);
                $labels  = ['Pending','Assigned','In Progress','Completed'];
                $icons   = ['🕐','👨‍🔧','🔧','✅'];
                ?>
                <div class="stepper">
                    <?php foreach ($steps as $i => $step): ?>
                    <div class="step <?php
                        if ($i < $current)      echo 'done';
                        elseif ($i === $current) echo 'active';
                    ?>">
                        <div class="step-circle"><?php echo $icons[$i]; ?></div>
                        <div class="step-label"><?php echo $labels[$i]; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- ── Details ─────────────────────────────────────────── -->
                <div class="card" style="margin-bottom:0;">
                    <h3 style="margin-top:0;">Service Details</h3>

                    <div class="detail-row">
                        <span class="detail-label">Problem</span>
                        <span><?php echo e($selected['problem_type'] ?: '—'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Description</span>
                        <span><?php echo e($selected['description'] ?: '—'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status</span>
                        <span>
                            <span class="status status-<?php echo e($selected['status']); ?>">
                                <?php echo e(statusLabel($selected['status'])); ?>
                            </span>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Assigned Mechanic</span>
                        <span>
                            <?php if (!empty($selected['mechanic_name'])): ?>
                                <strong><?php echo e($selected['mechanic_name']); ?></strong>
                            <?php else: ?>
                                <span class="muted">Finding best match…</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if (!empty($selected['diagnosis'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Diagnosis</span>
                        <span><?php echo e($selected['diagnosis']); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($selected['latitude']) && !empty($selected['longitude'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Location</span>
                        <span style="font-size:.82rem;">
                            <?php echo number_format((float)$selected['latitude'],  6); ?>,
                            <?php echo number_format((float)$selected['longitude'], 6); ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($selected['image'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Your Photo</span>
                        <span>
                            <div class="photo-thumb-wrap"
                                 onclick="openLightbox('<?php echo e(getBasePath() . $selected['image']); ?>')"
                                 title="Tap to enlarge">
                                <img src="<?php echo e(getBasePath() . $selected['image']); ?>"
                                     alt="Request photo"
                                     class="photo-thumb">
                                <span class="photo-thumb-badge">🔍 Tap</span>
                            </div>
                        </span>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- ── Map accordion ──────────────────────────────────── -->
                <div class="map-accordion" style="margin-top:16px;">

                    <button class="map-accordion-toggle" id="mapToggle"
                            onclick="toggleMapAccordion()" aria-expanded="false">
                        <span class="toggle-left">
                            🗺️ <span>Mechanic Location</span>
                            <?php if ($hasMech): ?>
                                <span class="live-badge" id="custLiveBadge">● Live</span>
                            <?php else: ?>
                                <span class="live-badge inactive">Not Assigned</span>
                            <?php endif; ?>
                        </span>
                        <span class="toggle-arrow">▼</span>
                    </button>

                    <div class="map-accordion-body" id="mapAccordionBody">

                        <?php if (!$hasMech): ?>
                            <div class="map-unassigned">
                                <span class="ua-icon">📍</span>
                                <strong>Mechanic not yet assigned</strong><br>
                                <span style="font-size:.85rem;">
                                    Once a mechanic is assigned to your request, their live location will appear here.
                                </span>
                            </div>

                        <?php else: ?>
                            <div id="custMap" class="cust-map"></div>

                            <div class="map-acc-toolbar">
                                <button class="map-acc-btn" onclick="custFocusMechanic()">🔧 Go to Mechanic</button>
                                <?php if ($custLat && $custLng): ?>
                                <button class="map-acc-btn orange" onclick="custFocusCustomer()">📍 My Location</button>
                                <?php endif; ?>
                                <a href="<?php echo $mechLat ? 'https://www.google.com/maps?q='.$mechLat.','.$mechLng : '#'; ?>"
                                   class="map-acc-btn" id="custGmapsBtn" target="_blank">🗺 Google Maps</a>
                                <span class="distance-chip hidden" id="custDistChip">
                                    📏 <span id="custDistLabel"></span>
                                </span>
                            </div>

                            <div class="map-acc-legend">
                                <div class="legend-item">
                                    <span class="legend-dot" style="background:#3a5bbd;"></span> Mechanic
                                </div>
                                <?php if ($custLat && $custLng): ?>
                                <div class="legend-item">
                                    <span class="legend-dot" style="background:#ff6600;"></span> My Location
                                </div>
                                <?php endif; ?>
                                <span style="margin-left:auto;font-size:.72rem;color:var(--muted,#aaa);" id="custLastUpdated"></span>
                            </div>

                        <?php endif; ?>

                    </div><!-- /.map-accordion-body -->
                </div><!-- /.map-accordion -->

            </div><!-- /.track-detail -->
            <?php endif; ?>

        </div><!-- /.track-wrap -->
    <?php endif; ?>

</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if (!empty($selected) && !empty($selected['mechanic_id'])): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<script>
/* ── Block countdown timer ────────────────────────────────────────────── */
<?php if ($isBlocked): ?>
(function () {
    var endsAt = <?php echo $blockedUntilTs; ?> * 1000; // ms

    function tick() {
        var diff = Math.max(0, Math.floor((endsAt - Date.now()) / 1000));
        var el = document.getElementById('blockCountdown');
        if (el) {
            var m = Math.floor(diff / 60), s = diff % 60;
            el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }
        if (diff <= 0) {
            // Block expired — reload so the banner disappears and button re-enables
            location.reload();
        }
    }
    tick();
    setInterval(tick, 1000);
})();
<?php endif; ?>

/* ── Cancel modal ─────────────────────────────────────────────────────── */
function openCancelModal(requestId, cancelCount, nextBlockMinutes) {
    var modal = document.getElementById('cancelModal');
    var msg   = document.getElementById('cancelModalMsg');

    document.getElementById('cancelRequestInput').value = requestId;

    // Build a personalised warning based on the block that WILL apply
    var warningNote = '';
    if (nextBlockMinutes === 0) {
        // First cancel — warn that the next one triggers a block
        warningNote = ' <strong>Note:</strong> A second cancellation today will block new submissions for 5 minutes.';
    } else {
        warningNote = ' <strong>Warning:</strong> This cancellation will block new submissions for '
                    + nextBlockMinutes + ' minute' + (nextBlockMinutes !== 1 ? 's' : '') + '.';
    }

    msg.innerHTML = 'Are you sure you want to cancel request #' + requestId
        + '? This action cannot be undone.' + warningNote;

    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('open');
    document.body.style.overflow = '';
}

// Close modal on backdrop click or Escape
document.getElementById('cancelModal').addEventListener('click', function (e) {
    if (e.target === this) closeCancelModal();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCancelModal();
});

/* ── Lightbox ─────────────────────────────────────────────────────────── */
function openLightbox(src) {
    var overlay = document.getElementById('lightbox');
    document.getElementById('lightboxImg').src = src;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.getElementById('lightboxImg').src = '';
    document.body.style.overflow = '';
}
document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
document.getElementById('lightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});

/* ── Map accordion toggle ─────────────────────────────────────────────── */
var mapInitialised = false;

function toggleMapAccordion() {
    var btn  = document.getElementById('mapToggle');
    var body = document.getElementById('mapAccordionBody');
    var open = body.classList.toggle('open');
    btn.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open);

    if (open && !mapInitialised) {
        initCustMap();
        mapInitialised = true;
    }
    if (open && mapInitialised && typeof custMap !== 'undefined') {
        setTimeout(function () { custMap.invalidateSize(); }, 200);
    }
}

<?php if (!empty($selected) && !empty($selected['mechanic_id'])): ?>
/* ── Customer-side map ────────────────────────────────────────────────── */
(function () {

    var POLL_URL  = '<?php echo getBasePath(); ?>mechanic/get_locations.php';
    var POLL_MS   = 15000;
    var MECH_ID   = <?php echo $mechId; ?>;
    var MECH_NAME = <?php echo json_encode($mechName); ?>;

    var INIT_MECH_LAT = <?php echo $mechLat !== null ? $mechLat : 'null'; ?>;
    var INIT_MECH_LNG = <?php echo $mechLng !== null ? $mechLng : 'null'; ?>;
    var CUST_LAT      = <?php echo $custLat !== null ? $custLat : 'null'; ?>;
    var CUST_LNG      = <?php echo $custLng !== null ? $custLng : 'null'; ?>;

    var custMap     = null;
    var mechMarker  = null;
    var custMarker  = null;
    var connectLine = null;

    window.custMap = null;

    function makeIcon(color) {
        return L.divIcon({
            className: '',
            html: '<div style="background:' + color + ';width:16px;height:16px;border-radius:50%;'
                + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>',
            iconSize: [16,16], iconAnchor: [8,8], popupAnchor: [0,-12]
        });
    }

    function haversine(lat1,lng1,lat2,lng2) {
        var R=6371, dL=(lat2-lat1)*Math.PI/180, dN=(lng2-lng1)*Math.PI/180;
        var a=Math.sin(dL/2)*Math.sin(dL/2)
             +Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)
             *Math.sin(dN/2)*Math.sin(dN/2);
        return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
    }
    function fmtDist(km) {
        return km < 1 ? (km*1000).toFixed(0)+' m away' : km.toFixed(1)+' km away';
    }

    function renderMarkers(mLat, mLng) {
        var hasMech = mLat !== null && !isNaN(mLat);
        var hasCust = CUST_LAT !== null;

        if (mechMarker)  { custMap.removeLayer(mechMarker);  mechMarker  = null; }
        if (connectLine) { custMap.removeLayer(connectLine); connectLine = null; }

        var chip  = document.getElementById('custDistChip');
        var gmBtn = document.getElementById('custGmapsBtn');

        if (hasMech) {
            mechMarker = L.marker([mLat, mLng], { icon: makeIcon('#3a5bbd') })
                .addTo(custMap)
                .bindPopup('<strong>🔧 ' + MECH_NAME + '</strong><br>Your Mechanic');

            if (gmBtn) gmBtn.href = 'https://www.google.com/maps?q=' + mLat + ',' + mLng;
        }

        if (hasCust && hasMech) {
            connectLine = L.polyline(
                [[mLat, mLng], [CUST_LAT, CUST_LNG]],
                { color:'#ff6600', weight:2.5, dashArray:'6 5', opacity:.75 }
            ).addTo(custMap);

            var km = haversine(mLat, mLng, CUST_LAT, CUST_LNG);
            if (chip) {
                chip.classList.remove('hidden');
                document.getElementById('custDistLabel').textContent = fmtDist(km);
            }
            custMap.fitBounds(L.latLngBounds([mLat, mLng],[CUST_LAT, CUST_LNG]), { padding:[50,50] });
        } else if (hasMech) {
            if (chip) chip.classList.add('hidden');
            custMap.setView([mLat, mLng], 15);
        } else if (hasCust) {
            if (chip) chip.classList.add('hidden');
            custMap.setView([CUST_LAT, CUST_LNG], 15);
        }
    }

    window.initCustMap = function () {
        var defaultView = (INIT_MECH_LAT !== null)
            ? [INIT_MECH_LAT, INIT_MECH_LNG]
            : (CUST_LAT !== null ? [CUST_LAT, CUST_LNG] : [9.5600, 44.0650]);

        custMap = L.map('custMap').setView(defaultView, 14);
        window.custMap = custMap;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors', maxZoom: 19
        }).addTo(custMap);

        if (CUST_LAT !== null) {
            custMarker = L.marker([CUST_LAT, CUST_LNG], { icon: makeIcon('#ff6600') })
                .addTo(custMap)
                .bindPopup('<strong>📍 Your Location</strong>');
        }

        renderMarkers(INIT_MECH_LAT, INIT_MECH_LNG);
        setInterval(pollMechanic, POLL_MS);
    };

    window.custFocusMechanic = function () {
        if (mechMarker) { custMap.setView(mechMarker.getLatLng(), 16); mechMarker.openPopup(); }
    };
    window.custFocusCustomer = function () {
        if (custMarker) { custMap.setView(custMarker.getLatLng(), 16); custMarker.openPopup(); }
    };

    function pollMechanic() {
        fetch(POLL_URL)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                for (var i = 0; i < data.length; i++) {
                    if (data[i].id !== MECH_ID) continue;
                    var m = data[i];
                    if (m.current_lat) {
                        renderMarkers(parseFloat(m.current_lat), parseFloat(m.current_lng));
                        var lbl = document.getElementById('custLastUpdated');
                        if (lbl) lbl.textContent = 'Updated ' + new Date().toLocaleTimeString();
                    }
                    break;
                }
            })
            .catch(function() {
                var badge = document.getElementById('custLiveBadge');
                if (badge) { badge.textContent = '● Offline'; badge.className = 'live-badge inactive'; }
            });
    }

})();
<?php else: ?>
window.initCustMap = function () {};
<?php endif; ?>
</script>