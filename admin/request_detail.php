<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'customer/dashboard.php');
    exit;
}

$pdo = getPDO();

// Validate ID
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: ' . getBasePath() . 'admin/pending_requests.php');
    exit;
}

// Handle assign form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    $mechanic_id = (int) $_POST['mechanic_id'];
    if ($mechanic_id > 0) {
        // Get current mechanic if any
        $currStmt = $pdo->prepare('SELECT mechanic_id FROM requests WHERE id = :id');
        $currStmt->execute([':id' => $id]);
        $currReq = $currStmt->fetch();
        $oldMechanicId = $currReq['mechanic_id'] ?? null;
        
        $stmt = $pdo->prepare('UPDATE requests SET mechanic_id = :m, status = "assigned" WHERE id = :id');
        $stmt->execute([':m' => $mechanic_id, ':id' => $id]);
        
        // If reassigning from a different mechanic, free up the old one
        if ($oldMechanicId && $oldMechanicId !== $mechanic_id) {
            // Check if old mechanic has any other active jobs
            $checkStmt = $pdo->prepare(
                'SELECT COUNT(*) as cnt FROM requests WHERE mechanic_id = :id AND status IN ("assigned", "in_progress") AND id != :req_id'
            );
            $checkStmt->execute([':id' => $oldMechanicId, ':req_id' => $id]);
            $hasOtherJobs = $checkStmt->fetchColumn();
            
            if (!$hasOtherJobs) {
                $pdo->prepare('UPDATE mechanics SET status = "available" WHERE id = :id')
                    ->execute([':id' => $oldMechanicId]);
            }
        }
        
        // Update new mechanic status to busy
        $pdo->prepare('UPDATE mechanics SET status = "busy" WHERE id = :id')
            ->execute([':id' => $mechanic_id]);
        
        $message = 'Mechanic assigned successfully.';
    }
}

// Fetch full request — online user OR walk-in
$stmt = $pdo->prepare(
    'SELECT r.*,
            u.full_name  AS user_name,
            u.phone      AS user_phone,
            u.email      AS user_email,
            w.full_name  AS walkin_name,
            w.phone      AS walkin_phone,
            w.email      AS walkin_email,
            w.address    AS walkin_address,
            m.name       AS mechanic_name
     FROM requests r
     LEFT JOIN users            u ON r.user_id    = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id  = w.id
     LEFT JOIN mechanics        m ON r.mechanic_id = m.id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$req = $stmt->fetch();

if (!$req) {
    header('Location: ' . getBasePath() . 'admin/pending_requests.php');
    exit;
}

// Resolve display values
$custName    = $req['user_name']    ?: ($req['walkin_name']  ?: '—');
$custPhone   = $req['user_phone']   ?: ($req['walkin_phone'] ?: '—');
$custEmail   = $req['user_email']   ?: ($req['walkin_email'] ?: '—');
$custAddress = $req['walkin_address'] ?? '';
$custType    = $req['user_name']    ? 'Online Customer' : ($req['walkin_name'] ? 'Walk-in' : '—');
$imgSrc      = !empty($req['image']) ? getBasePath() . $req['image'] : '';
$hasCoords   = !empty($req['latitude']) && !empty($req['longitude']);

$mechanics = $pdo->query('SELECT id, name FROM mechanics WHERE status = "available" ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Page layout ────────────────────────────────────────────────────────── */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}
.detail-grid .full-width {
    grid-column: 1 / -1;
}

/* ── Section card ───────────────────────────────────────────────────────── */
.section-card {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    overflow: hidden;
}
.section-card-head {
    background: #f9f9f9;
    border-bottom: 1px solid #efefef;
    padding: 11px 16px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted, #888);
}
.section-card-body {
    padding: 14px 16px;
}

/* ── Detail rows ────────────────────────────────────────────────────────── */
.d-row {
    display: flex;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f5f5f5;
    font-size: .9rem;
}
.d-row:last-child { border-bottom: none; }
.d-lbl { font-weight: 600; min-width: 120px; color: #666; flex-shrink: 0; }
.d-val { color: #222; word-break: break-word; flex: 1; }

/* ── Status badge ───────────────────────────────────────────────────────── */
.type-badge {
    font-size: .72rem; font-weight: 700;
    padding: 2px 10px; border-radius: 10px; display: inline-block;
}
.badge-online  { background: #fff3e8; color: var(--safety-orange, #ff6600); }
.badge-walkin  { background: #e8f0ff; color: #3a5bbd; }

/* ── Photo thumbnail / lightbox ─────────────────────────────────────────── */
.photo-thumb-wrap {
    display: inline-block;
    position: relative;
    cursor: zoom-in;
}
.photo-thumb {
    width: 110px;
    height: 110px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #eee;
    display: block;
    transition: border-color .2s, transform .2s;
}
.photo-thumb:hover {
    border-color: var(--safety-orange, #ff6600);
    transform: scale(1.04);
}
.thumb-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(0,0,0,.55); color: #fff;
    font-size: .6rem; font-weight: 700;
    padding: 2px 6px; border-radius: 4px;
    pointer-events: none;
}
.no-photo {
    width: 110px; height: 110px;
    border-radius: 8px;
    background: #f0f0f0;
    border: 2px dashed #ddd;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: #ccc;
}

/* ── Lightbox ────────────────────────────────────────────────────────────── */
.lb-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.82); z-index: 9999;
    align-items: center; justify-content: center;
}
.lb-overlay.open { display: flex; animation: uiFade .18s ease; }
@keyframes uiFade  { from{opacity:0} to{opacity:1} }
@keyframes uiZoom  { from{transform:scale(.93);opacity:0} to{transform:scale(1);opacity:1} }
.lb-inner { position: relative; max-width: 92vw; max-height: 88vh; }
.lb-inner img {
    max-width: 92vw; max-height: 88vh;
    border-radius: 10px;
    box-shadow: 0 8px 48px rgba(0,0,0,.6);
    animation: uiZoom .2s ease;
}
.lb-close {
    position: absolute; top: -14px; right: -14px;
    width: 34px; height: 34px; border-radius: 50%;
    background: #fff; color: #333; border: none;
    font-size: 1.1rem; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.3);
}
.lb-close:hover { background: var(--safety-orange, #ff6600); color: #fff; }

/* ── Map ─────────────────────────────────────────────────────────────────── */
#map {
    width: 100%; height: 320px;
    border-radius: 8px;
    background: #eee;
}
.map-pill {
    display: inline-flex; align-items: center; gap: 5px;
    background: #fff3e8; color: var(--safety-orange, #ff6600);
    border: 1px solid #ffd0a8; border-radius: 20px;
    padding: 5px 14px; font-size: .82rem; font-weight: 600;
    text-decoration: none; margin-top: 10px;
}
.map-pill:hover { background: #ffe0c2; }

/* ── Assign form ─────────────────────────────────────────────────────────── */
.assign-form {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    margin-top: 14px; padding-top: 14px;
    border-top: 1px solid #f0f0f0;
}
.assign-form select {
    flex: 1; min-width: 180px;
    padding: 8px 12px;
    border-radius: 6px; border: 1px solid #ddd;
    font-size: .9rem;
}

/* ── Back link ───────────────────────────────────────────────────────────── */
.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .85rem; color: var(--muted, #888);
    text-decoration: none; margin-bottom: 4px;
}
.back-link:hover { color: var(--safety-orange, #ff6600); }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media(max-width: 720px) {
    .detail-grid { grid-template-columns: 1fr; }
    .detail-grid .full-width { grid-column: 1; }
}
</style>

<!-- Lightbox -->
<div class="lb-overlay" id="lightbox">
    <div class="lb-inner">
        <button class="lb-close" id="lbClose">&times;</button>
        <img src="" id="lbImg" alt="Photo">
    </div>
</div>

<main>
<div class="card">

    <!-- Back + page title -->
    <a href="<?php echo getBasePath(); ?>admin/pending_requests.php" class="back-link">
        ← Back to Pending Requests
    </a>
    <div style="display:flex; justify-content:space-between; align-items:center;
                flex-wrap:wrap; gap:10px; margin-top:6px;">
        <div>
            <h2 style="margin:0;">
                Request #<?php echo (int)$req['id']; ?>
                — <?php echo e($req['problem_type'] ?: 'General Service'); ?>
            </h2>
            <p class="muted" style="margin:4px 0 0;">
                Submitted <?php echo date('M d, Y  g:i A', strtotime($req['created_at'])); ?>
            </p>
        </div>
        <span class="status status-<?php echo e($req['status']); ?>">
            <?php echo e(statusLabel($req['status'])); ?>
        </span>
    </div>

    <?php if ($message): ?>
    <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;
                padding:12px 14px;border-radius:7px;margin-top:16px;">
        ✅ <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <div class="detail-grid">

        <!-- ── Customer info ─────────────────────────────────────────────── -->
        <div class="section-card">
            <div class="section-card-head">Customer</div>
            <div class="section-card-body">
                <div class="d-row">
                    <span class="d-lbl">Type</span>
                    <span class="d-val">
                        <span class="type-badge <?php echo $req['user_name'] ? 'badge-online' : 'badge-walkin'; ?>">
                            <?php echo $custType; ?>
                        </span>
                    </span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Name</span>
                    <span class="d-val"><strong><?php echo e($custName); ?></strong></span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Phone</span>
                    <span class="d-val">
                        <?php if ($custPhone !== '—'): ?>
                            <a href="tel:<?php echo e($custPhone); ?>" style="color:inherit;"><?php echo e($custPhone); ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Email</span>
                    <span class="d-val">
                        <?php if ($custEmail !== '—'): ?>
                            <a href="mailto:<?php echo e($custEmail); ?>" style="color:inherit;"><?php echo e($custEmail); ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </div>
                <?php if ($custAddress): ?>
                <div class="d-row">
                    <span class="d-lbl">Address</span>
                    <span class="d-val"><?php echo e($custAddress); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Request info ──────────────────────────────────────────────── -->
        <div class="section-card">
            <div class="section-card-head">Request Info</div>
            <div class="section-card-body">
                <div class="d-row">
                    <span class="d-lbl">Problem</span>
                    <span class="d-val"><strong><?php echo e($req['problem_type'] ?: '—'); ?></strong></span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Description</span>
                    <span class="d-val" style="white-space:pre-wrap;"><?php echo e($req['description'] ?: '—'); ?></span>
                </div>
                <?php if (!empty($req['diagnosis'])): ?>
                <div class="d-row">
                    <span class="d-lbl">Diagnosis</span>
                    <span class="d-val" style="white-space:pre-wrap;"><?php echo e($req['diagnosis']); ?></span>
                </div>
                <?php endif; ?>
                <div class="d-row">
                    <span class="d-lbl">Mechanic</span>
                    <span class="d-val">
                        <?php echo !empty($req['mechanic_name']) ? e($req['mechanic_name']) : '<span class="muted">Not assigned yet</span>'; ?>
                    </span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Photo</span>
                    <span class="d-val">
                        <?php if ($imgSrc): ?>
                        <div class="photo-thumb-wrap"
                             onclick="openLightbox('<?php echo e($imgSrc); ?>')"
                             title="Tap to enlarge">
                            <img src="<?php echo e($imgSrc); ?>" alt="Request photo" class="photo-thumb">
                            <span class="thumb-badge">🔍 Tap</span>
                        </div>
                        <?php else: ?>
                        <div class="no-photo">📷</div>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Map (full width) ──────────────────────────────────────────── -->
        <?php if ($hasCoords): ?>
        <div class="section-card full-width">
            <div class="section-card-head">📍 Customer Location</div>
            <div class="section-card-body">
                <div id="map"></div>
                <a href="https://www.google.com/maps?q=<?php echo e($req['latitude']); ?>,<?php echo e($req['longitude']); ?>"
                   target="_blank" class="map-pill">
                    📍 Open in Google Maps
                </a>
                <span style="font-size:.8rem;color:var(--muted,#888);margin-left:12px;">
                    <?php echo number_format((float)$req['latitude'], 6); ?>,
                    <?php echo number_format((float)$req['longitude'], 6); ?>
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Assign mechanic (full width, only if still pending) ─────── -->
        <?php if ($req['status'] === 'pending'): ?>
        <div class="section-card full-width">
            <div class="section-card-head">Assign Mechanic</div>
            <div class="section-card-body">
                <?php if (empty($mechanics)): ?>
                    <p class="muted">No available mechanics right now.</p>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                        <div class="assign-form">
                            <select name="mechanic_id" required>
                                <option value="">— Select a mechanic —</option>
                                <?php foreach ($mechanics as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>"><?php echo e($m['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="assign" value="1"
                                    class="btn btn-primary"
                                    style="padding:9px 22px; font-size:.9rem;">
                                Assign &amp; Confirm
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.detail-grid -->

</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- Lightbox JS -->
<script>
function openLightbox(src) {
    document.getElementById('lbImg').src = src;
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.getElementById('lbImg').src = '';
    document.body.style.overflow = '';
}
document.getElementById('lbClose').addEventListener('click', closeLightbox);
document.getElementById('lightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>

<?php if ($hasCoords): ?>
<!-- Leaflet map (no API key needed) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function() {
    var lat = <?php echo (float)$req['latitude']; ?>;
    var lng = <?php echo (float)$req['longitude']; ?>;
    var map = L.map('map').setView([lat, lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    L.marker([lat, lng])
        .addTo(map)
        .bindPopup('<strong>Customer Location</strong><br><?php echo e($custName); ?>')
        .openPopup();
})();
</script>
<?php endif; ?>