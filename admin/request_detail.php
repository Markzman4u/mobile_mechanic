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
        $currStmt = $pdo->prepare('SELECT mechanic_id FROM requests WHERE id = :id');
        $currStmt->execute([':id' => $id]);
        $currReq   = $currStmt->fetch();
        $oldMechanicId = $currReq['mechanic_id'] ?? null;

        $stmt = $pdo->prepare('UPDATE requests SET mechanic_id = :m, status = "assigned" WHERE id = :id');
        $stmt->execute([':m' => $mechanic_id, ':id' => $id]);

        if ($oldMechanicId && $oldMechanicId !== $mechanic_id) {
            $checkStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM requests
                 WHERE mechanic_id = :id AND status IN ("assigned","in_progress") AND id != :req_id'
            );
            $checkStmt->execute([':id' => $oldMechanicId, ':req_id' => $id]);
            if (!$checkStmt->fetchColumn()) {
                $pdo->prepare('UPDATE mechanics SET status = "available" WHERE id = :id')
                    ->execute([':id' => $oldMechanicId]);
            }
        }

        $pdo->prepare('UPDATE mechanics SET status = "busy" WHERE id = :id')
            ->execute([':id' => $mechanic_id]);

        $message = 'Mechanic assigned successfully.';
    }
}

// Fetch full request
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
     LEFT JOIN users            u ON r.user_id     = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id   = w.id
     LEFT JOIN mechanics        m ON r.mechanic_id = m.id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$req = $stmt->fetch();

if (!$req) {
    header('Location: ' . getBasePath() . 'admin/pending_requests.php');
    exit;
}

$custName    = $req['user_name']     ?: ($req['walkin_name']  ?: '—');
$custPhone   = $req['user_phone']    ?: ($req['walkin_phone'] ?: '—');
$custEmail   = $req['user_email']    ?: ($req['walkin_email'] ?: '—');
$custAddress = $req['walkin_address'] ?? '';
$custType    = $req['user_name']     ? 'Online Customer' : ($req['walkin_name'] ? 'Walk-in' : '—');
$imgSrc      = !empty($req['image']) ? getBasePath() . $req['image'] : '';
$hasCoords   = !empty($req['latitude']) && !empty($req['longitude']);

// Resolve vehicle display
$vehicleMake  = $req['vehicle_make']  ?? '';
$vehicleModel = $req['vehicle_model'] ?? '';
$vehicleYear  = $req['vehicle_year']  ?? '';
$vehicleParts = array_filter([$vehicleMake, $vehicleModel, $vehicleYear ? (string)$vehicleYear : '']);
$vehicleLabel = !empty($vehicleParts) ? implode(' · ', $vehicleParts) : '—';

$mechanics = $pdo->query(
    'SELECT id, name FROM mechanics WHERE status = "available" ORDER BY name ASC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Page layout ─────────────────────────────────────────────────────────── */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}
.detail-grid .full-width { grid-column: 1 / -1; }

/* ── Section card ────────────────────────────────────────────────────────── */
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
.section-card-body { padding: 14px 16px; }

/* ── Detail rows ─────────────────────────────────────────────────────────── */
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

/* ── Vehicle badge ───────────────────────────────────────────────────────── */
.vehicle-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f5f5f5;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: .85rem;
    color: #333;
    font-weight: 500;
}

/* ── Badges ──────────────────────────────────────────────────────────────── */
.type-badge {
    font-size: .72rem; font-weight: 700;
    padding: 2px 10px; border-radius: 10px; display: inline-block;
}
.badge-online { background: #fff3e8; color: var(--safety-orange, #ff6600); }
.badge-walkin { background: #e8f0ff; color: #3a5bbd; }

/* ── Mechanic link ───────────────────────────────────────────────────────── */
.mechanic-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #3a5bbd;
    font-weight: 600;
    text-decoration: none;
    font-size: .9rem;
}
.mechanic-link:hover {
    color: var(--safety-orange, #ff6600);
    text-decoration: underline;
}
.mechanic-link .track-icon {
    font-size: .78rem;
    opacity: .75;
}

/* ── Diagnosis box ───────────────────────────────────────────────────────── */
.diagnosis-box {
    background: #f9f6f0;
    border: 1px solid #f0d9b8;
    border-left: 4px solid var(--safety-orange, #ff6600);
    border-radius: 7px;
    padding: 10px 14px;
    font-size: .88rem;
    color: #4a3a28;
    white-space: pre-wrap;
    line-height: 1.55;
    margin-top: 2px;
}
.diagnosis-empty {
    font-size: .85rem;
    color: var(--muted, #aaa);
    font-style: italic;
}

/* ── Photo ───────────────────────────────────────────────────────────────── */
.photo-thumb-wrap {
    display: inline-block;
    position: relative;
    cursor: zoom-in;
}
.photo-thumb {
    width: 110px; height: 110px;
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
@keyframes uiFade { from{opacity:0} to{opacity:1} }
@keyframes uiZoom { from{transform:scale(.93);opacity:0} to{transform:scale(1);opacity:1} }
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
    width: 100%; height: 360px;
    border-radius: 8px;
    background: #eee;
}

/* ── Map toolbar ─────────────────────────────────────────────────────────── */
.map-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
}
.map-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 16px; border-radius: 20px;
    font-size: .82rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    text-decoration: none; transition: background .15s, border-color .15s;
}
.map-btn-customer {
    background: #fff3e8;
    color: var(--safety-orange, #ff6600);
    border-color: #ffd0a8;
}
.map-btn-customer:hover { background: #ffe0c2; }

.map-btn-admin {
    background: #e8f0ff;
    color: #3a5bbd;
    border-color: #b8ccff;
}
.map-btn-admin:hover { background: #d0e0ff; }

.map-btn-gmaps {
    background: #f0f0f0;
    color: #444;
    border-color: #ddd;
}
.map-btn-gmaps:hover { background: #e4e4e4; }

/* ── Distance pill ───────────────────────────────────────────────────────── */
.distance-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #1a1a1a; color: #fff;
    padding: 7px 16px; border-radius: 20px;
    font-size: .82rem; font-weight: 700;
    margin-left: auto;
}
.distance-pill.loading { background: #888; }
.distance-pill.error   { background: #c0392b; }

/* ── Location status bar ─────────────────────────────────────────────────── */
.location-status {
    font-size: .78rem;
    color: var(--muted, #888);
    margin-top: 8px;
    display: flex; align-items: center; gap: 6px;
}
.loc-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    display: inline-block;
    background: #ccc;
    flex-shrink: 0;
}
.loc-dot.green  { background: #4caf50; }
.loc-dot.orange { background: #ff9800; }
.loc-dot.red    { background: #f44336; }

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
    .distance-pill { margin-left: 0; }
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

        <!-- ── Customer info ──────────────────────────────────────────────── -->
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

        <!-- ── Request info ───────────────────────────────────────────────── -->
        <div class="section-card">
            <div class="section-card-head">Request Info</div>
            <div class="section-card-body">

                <!-- ── Vehicle ───────────────────────────────────────────── -->
                <div class="d-row">
                    <span class="d-lbl">Vehicle</span>
                    <span class="d-val">
                        <?php if ($vehicleLabel !== '—'): ?>
                            <span class="vehicle-badge">🚗 <?php echo e($vehicleLabel); ?></span>
                        <?php else: ?>
                            <span class="muted" style="font-style:italic;font-size:.85rem;">Not specified</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="d-row">
                    <span class="d-lbl">Problem</span>
                    <span class="d-val"><strong><?php echo e($req['problem_type'] ?: '—'); ?></strong></span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Description</span>
                    <span class="d-val" style="white-space:pre-wrap;"><?php echo e($req['description'] ?: '—'); ?></span>
                </div>

                <!-- ── Mechanic ──────────────────────────────────────────── -->
                <div class="d-row">
                    <span class="d-lbl">Mechanic</span>
                    <span class="d-val">
                        <?php if (!empty($req['mechanic_name']) && !empty($req['mechanic_id'])): ?>
                            <a href="<?php echo getBasePath(); ?>admin/track_mechanics.php?mechanic_id=<?php echo (int)$req['mechanic_id']; ?>"
                               class="mechanic-link"
                               title="Track <?php echo e($req['mechanic_name']); ?> on map">
                                <?php echo e($req['mechanic_name']); ?>
                                <span class="track-icon">📍 Track</span>
                            </a>
                        <?php else: ?>
                            <span class="muted">Not assigned yet</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- ── Mechanic Diagnosis ────────────────────────────────── -->
                <div class="d-row" style="flex-direction: column; gap: 6px;">
                    <span class="d-lbl">Mechanic Diagnosis</span>
                    <span class="d-val">
                        <?php if (!empty($req['diagnosis'])): ?>
                            <div class="diagnosis-box"><?php echo e($req['diagnosis']); ?></div>
                        <?php else: ?>
                            <span class="diagnosis-empty">No diagnosis submitted yet.</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- ── Photo ─────────────────────────────────────────────── -->
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

        <!-- ── Map (full width) ───────────────────────────────────────────── -->
        <?php if ($hasCoords): ?>
        <div class="section-card full-width">
            <div class="section-card-head">📍 Location Overview</div>
            <div class="section-card-body">

                <div id="map"></div>

                <!-- Toolbar: focus buttons + distance pill -->
                <div class="map-toolbar">
                    <button class="map-btn map-btn-customer" onclick="focusCustomer()">
                        📍 Go to Customer
                    </button>
                    <button class="map-btn map-btn-admin" id="btnFocusAdmin" onclick="focusAdmin()" style="display:none;">
                        🧭 Go to My Location
                    </button>
                    <a href="https://www.google.com/maps?q=<?php echo e($req['latitude']); ?>,<?php echo e($req['longitude']); ?>"
                       target="_blank" class="map-btn map-btn-gmaps">
                        🗺 Open in Google Maps
                    </a>
                    <!-- Distance pill — hidden until admin location is known -->
                    <span class="distance-pill loading" id="distPill" style="display:none;">
                        📏 <span id="distLabel">Calculating…</span>
                    </span>
                </div>

                <!-- Location status -->
                <div class="location-status" id="locStatus">
                    <span class="loc-dot orange" id="locDot"></span>
                    <span id="locText">Requesting your location…</span>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <!-- ── Assign mechanic ────────────────────────────────────────────── -->
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {

    /* ── Customer coordinates (from PHP) ─────────────────────────────────── */
    var CUST_LAT = <?php echo (float)$req['latitude']; ?>;
    var CUST_LNG = <?php echo (float)$req['longitude']; ?>;
    var CUST_NAME = <?php echo json_encode($custName); ?>;

    /* ── Map init (centred on customer) ──────────────────────────────────── */
    var map = L.map('map').setView([CUST_LAT, CUST_LNG], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    /* ── Customer marker (orange) ────────────────────────────────────────── */
    var custIcon = L.divIcon({
        className: '',
        html: '<div style="background:#ff6600;width:16px;height:16px;border-radius:50%;'
            + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
        popupAnchor: [0, -12]
    });
    var custMarker = L.marker([CUST_LAT, CUST_LNG], { icon: custIcon })
        .addTo(map)
        .bindPopup('<strong>📍 Customer</strong><br>' + CUST_NAME);

    /* ── Admin marker + line (added once geolocation resolves) ───────────── */
    var adminMarker = null;
    var connectLine  = null;
    var adminLat     = null;
    var adminLng     = null;

    /* ── Haversine distance (km) ─────────────────────────────────────────── */
    function haversine(lat1, lng1, lat2, lng2) {
        var R  = 6371;
        var dL = (lat2 - lat1) * Math.PI / 180;
        var dN = (lng2 - lng1) * Math.PI / 180;
        var a  = Math.sin(dL/2) * Math.sin(dL/2)
               + Math.cos(lat1 * Math.PI/180) * Math.cos(lat2 * Math.PI/180)
               * Math.sin(dN/2) * Math.sin(dN/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function formatDistance(km) {
        if (km < 1) return (km * 1000).toFixed(0) + ' m away';
        return km.toFixed(1) + ' km away';
    }

    /* ── Place / update admin marker and line ────────────────────────────── */
    function placeAdminLayer(lat, lng) {
        adminLat = lat;
        adminLng = lng;

        /* Marker */
        if (adminMarker) {
            adminMarker.setLatLng([lat, lng]);
        } else {
            var adminIcon = L.divIcon({
                className: '',
                html: '<div style="background:#3a5bbd;width:16px;height:16px;border-radius:50%;'
                    + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>',
                iconSize: [16, 16],
                iconAnchor: [8, 8],
                popupAnchor: [0, -12]
            });
            adminMarker = L.marker([lat, lng], { icon: adminIcon })
                .addTo(map)
                .bindPopup('<strong>🧭 Your Location</strong><br>Admin');
        }

        /* Connecting line */
        if (connectLine) {
            connectLine.setLatLngs([[CUST_LAT, CUST_LNG], [lat, lng]]);
        } else {
            connectLine = L.polyline(
                [[CUST_LAT, CUST_LNG], [lat, lng]],
                { color: '#ff6600', weight: 2.5, dashArray: '6 5', opacity: .75 }
            ).addTo(map);
        }

        /* Fit both markers in view */
        map.fitBounds(
            L.latLngBounds([CUST_LAT, CUST_LNG], [lat, lng]),
            { padding: [48, 48] }
        );

        /* Distance pill */
        var km = haversine(lat, lng, CUST_LAT, CUST_LNG);
        var pill = document.getElementById('distPill');
        pill.style.display = 'inline-flex';
        pill.classList.remove('loading', 'error');
        document.getElementById('distLabel').textContent = formatDistance(km);

        /* Show admin focus button */
        document.getElementById('btnFocusAdmin').style.display = 'inline-flex';

        /* Status */
        setStatus('green', 'Your live location is active.');
    }

    /* ── Status bar helper ───────────────────────────────────────────────── */
    function setStatus(color, text) {
        document.getElementById('locDot').className  = 'loc-dot ' + color;
        document.getElementById('locText').textContent = text;
    }

    /* ── Focus helpers ────────────────────────────────────────────────────── */
    window.focusCustomer = function () {
        map.setView([CUST_LAT, CUST_LNG], 16);
        custMarker.openPopup();
    };
    window.focusAdmin = function () {
        if (adminLat !== null) {
            map.setView([adminLat, adminLng], 16);
            adminMarker.openPopup();
        }
    };

    /* ── Geolocation ─────────────────────────────────────────────────────── */
    if (!navigator.geolocation) {
        setStatus('red', 'Geolocation is not supported by your browser.');
        document.getElementById('distPill').style.display  = 'none';
    } else {
        setStatus('orange', 'Requesting your location…');

        /* Watch so the dot moves if admin moves */
        navigator.geolocation.watchPosition(
            function (pos) {
                placeAdminLayer(pos.coords.latitude, pos.coords.longitude);
            },
            function (err) {
                var msg = {
                    1: 'Location permission denied.',
                    2: 'Location unavailable.',
                    3: 'Location request timed out.'
                }[err.code] || 'Location error.';
                setStatus('red', msg);
                var pill = document.getElementById('distPill');
                pill.style.display = 'inline-flex';
                pill.classList.add('error');
                document.getElementById('distLabel').textContent = 'Location unavailable';
            },
            { enableHighAccuracy: true, maximumAge: 10000, timeout: 15000 }
        );
    }

})();
</script>
<?php endif; ?>