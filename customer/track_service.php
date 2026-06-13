<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];

$requests = [];
if ($user_id) {
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        'SELECT r.*, m.name AS mechanic_name
         FROM requests r
         LEFT JOIN mechanics m ON r.mechanic_id = m.id
         WHERE r.user_id = ?
           AND r.status NOT IN ("completed", "rejected")
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

<main>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
        <h2 style="margin:0;">My Service Requests</h2>
        <a href="<?php echo getBasePath(); ?>customer/request_service.php"
           class="btn btn-primary" style="padding:8px 16px;font-size:.85rem;">
            + New Request
        </a>
    </div>
    <p class="muted" style="margin-top:4px;">Active requests are tracked here. View completed ones in <a href="<?php echo getBasePath(); ?>customer/customer_history.php">History</a>.</p>

    <?php if (empty($requests)): ?>
        <!-- ── No active requests ──────────────────────────────────────── -->
        <div class="empty-state">
            <span class="empty-icon">✅</span>
            <strong>No active requests</strong><br>
            <span style="font-size:.9rem;">
                You have no pending or in-progress requests right now.
            </span><br><br>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <a href="<?php echo getBasePath(); ?>customer/request_service.php"
                   class="btn btn-primary">Submit New Request</a>
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
                    <div style="text-align:right;font-size:.82rem;color:var(--muted,#888);">
                        Submitted<br>
                        <strong style="color:#333;"><?php echo e(date('M j, Y  H:i', strtotime($selected['created_at']))); ?></strong>
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
                <div class="card" style="margin-bottom:16px;">
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

            </div>
            <?php endif; ?>

        </div><!-- /.track-wrap -->
    <?php endif; ?>

</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
function openLightbox(src) {
    const overlay = document.getElementById('lightbox');
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
</script>