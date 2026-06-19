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
        'SELECT r.*,
                m.name               AS mechanic_name,
                m.current_lat        AS mech_lat,
                m.current_lng        AS mech_lng,
                m.location_updated_at AS mech_loc_updated
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
            <?php
                // Prepare map data for JS
                $hasMech    = !empty($selected['mechanic_id']);
                $mechLat    = $hasMech && !empty($selected['mech_lat'])  ? (float)$selected['mech_lat']  : null;
                $mechLng    = $hasMech && !empty($selected['mech_lng'])  ? (float)$selected['mech_lng']  : null;
                $custLat    = !empty($selected['latitude'])  ? (float)$selected['latitude']  : null;
                $custLng    = !empty($selected['longitude']) ? (float)$selected['longitude'] : null;
                $mechName   = e($selected['mechanic_name'] ?? '');
                $requestId  = (int)$selected['id'];
                $mechId     = (int)($selected['mechanic_id'] ?? 0);
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

                    <button class="map-accordion-toggle" id="mapToggle" onclick="toggleMapAccordion()" aria-expanded="false">
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
                            <!-- No mechanic assigned yet -->
                            <div class="map-unassigned">
                                <span class="ua-icon">📍</span>
                                <strong>Mechanic not yet assigned</strong><br>
                                <span style="font-size:.85rem;">
                                    Once a mechanic is assigned to your request, their live location will appear here.
                                </span>
                            </div>

                        <?php else: ?>
                            <!-- Map -->
                            <div id="custMap" class="cust-map"></div>

                            <!-- Toolbar -->
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

                            <!-- Legend -->
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

    // Initialise map on first open (Leaflet needs visible container)
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

    var custMap      = null;
    var mechMarker   = null;
    var custMarker   = null;
    var connectLine  = null;

    /* expose globally so toggle can call invalidateSize */
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

        /* Customer marker (static — their submitted location) */
        if (CUST_LAT !== null) {
            custMarker = L.marker([CUST_LAT, CUST_LNG], { icon: makeIcon('#ff6600') })
                .addTo(custMap)
                .bindPopup('<strong>📍 Your Location</strong>');
        }

        renderMarkers(INIT_MECH_LAT, INIT_MECH_LNG);

        /* Start polling */
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
                var badge = document.getElementById('custLiveBadge');
                for (var i = 0; i < data.length; i++) {
                    if (data[i].id !== MECH_ID) continue;
                    var m = data[i];
                    if (m.current_lat) {
                        renderMarkers(parseFloat(m.current_lat), parseFloat(m.current_lng));
                        var now = new Date();
                        var lbl = document.getElementById('custLastUpdated');
                        if (lbl) lbl.textContent = 'Updated ' + now.toLocaleTimeString();
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
/* No mechanic assigned — no map JS needed */
window.initCustMap = function () {};
<?php endif; ?>
</script>