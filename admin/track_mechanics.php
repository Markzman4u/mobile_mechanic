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

// Optional: pre-select a specific mechanic via ?id=
$preSelectId = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch all non-deleted mechanics with their active request's customer location
$mechanics = $pdo->query(
    'SELECT m.id, m.name, m.phone, m.email, m.profile_pic,
            m.current_lat, m.current_lng, m.location_updated_at,
            m.last_login, m.last_logout, m.status,
            r.id            AS request_id,
            r.problem_type,
            r.latitude      AS cust_lat,
            r.longitude     AS cust_lng,
            r.status        AS req_status,
            COALESCE(u.full_name, w.full_name, "Walk-in") AS customer_name
     FROM mechanics m
     LEFT JOIN requests r ON r.mechanic_id = m.id
         AND r.status IN ("assigned","in_progress")
     LEFT JOIN users            u ON r.user_id   = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id  = w.id
     WHERE m.is_deleted = FALSE
     ORDER BY m.status = "busy" DESC, m.name ASC'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.track-grid {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 20px;
    margin-top: 20px;
    align-items: start;
}
@media(max-width:860px) {
    .track-grid { grid-template-columns: 1fr; }
}

/* ── Mechanic list panel ─────────────────────────────────────────────────── */
.mech-list {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    overflow: hidden;
}
.mech-list-head {
    background: #f9f9f9;
    border-bottom: 1px solid #efefef;
    padding: 11px 16px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted, #888);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.poll-badge {
    font-size: .68rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 10px;
    background: #e8f5e9;
    color: #2e7d32;
}
.poll-badge.inactive { background: #f0f0f0; color: #aaa; }

.mech-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #f5f5f5;
    cursor: pointer;
    transition: background .15s;
}
.mech-item:last-child { border-bottom: none; }
.mech-item:hover      { background: #fafafa; }
.mech-item.active     { background: #fff3e8; border-left: 3px solid var(--safety-orange,#ff6600); }
.mech-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #eee;
    flex-shrink: 0;
}
.mech-avatar-ph {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: #f0f0f0;
    border: 2px solid #eee;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; color: #bbb;
    flex-shrink: 0;
}
.mech-info { flex: 1; min-width: 0; }
.mech-info strong { display: block; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mech-info small  { display: block; font-size: .75rem; color: var(--muted,#888); }
.loc-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}
.loc-dot.green  { background: #4caf50; }
.loc-dot.orange { background: #ff9800; }
.loc-dot.grey   { background: #ccc; }

/* ── Map panel ───────────────────────────────────────────────────────────── */
.map-panel {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    overflow: hidden;
}
.map-panel-head {
    background: #f9f9f9;
    border-bottom: 1px solid #efefef;
    padding: 11px 16px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted, #888);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
#map {
    width: 100%;
    height: 420px;
    background: #eee;
}
.map-legend {
    padding: 10px 16px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    border-top: 1px solid #f0f0f0;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .8rem;
    color: #555;
}
.legend-dot {
    width: 12px; height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.25);
    flex-shrink: 0;
}

/* ── Detail strip ────────────────────────────────────────────────────────── */
.mech-detail-strip {
    padding: 14px 16px;
    border-top: 1px solid #f0f0f0;
    display: none;
    gap: 24px;
    flex-wrap: wrap;
}
.mech-detail-strip.visible { display: flex; }
.detail-item { font-size: .83rem; }
.detail-item span {
    display: block;
    color: var(--muted,#888);
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 2px;
}

/* ── Map toolbar ─────────────────────────────────────────────────────────── */
.map-toolbar {
    padding: 10px 16px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    border-top: 1px solid #f0f0f0;
}
.map-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 20px;
    font-size: .78rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    background: #f0f0f0; color: #444; border-color: #ddd;
    text-decoration: none;
    transition: background .15s;
}
.map-btn:hover       { background: #e4e4e4; }
.map-btn.btn-orange  { background: #fff3e8; color: var(--safety-orange,#ff6600); border-color: #ffd0a8; }
.map-btn.btn-orange:hover { background: #ffe0c2; }
.map-btn.btn-blue    { background: #e8f0ff; color: #3a5bbd; border-color: #b8ccff; }
.map-btn.btn-blue:hover { background: #d0e0ff; }

.distance-pill {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 6px;
    background: #1a1a1a; color: #fff;
    padding: 6px 14px; border-radius: 20px;
    font-size: .78rem; font-weight: 700;
}
.distance-pill.hidden { display: none; }

/* ── Last updated label ──────────────────────────────────────────────────── */
.updated-label {
    font-size: .7rem;
    color: var(--muted,#aaa);
    font-weight: 400;
}
</style>

<main>
<div class="card">

    <a href="<?php echo getBasePath(); ?>admin/manage_mechanics.php"
       style="display:inline-flex;align-items:center;gap:6px;font-size:.85rem;
              color:var(--muted,#888);text-decoration:none;margin-bottom:6px;">
        ← Back to Manage Mechanics
    </a>
    <h2 style="margin:0 0 4px 0;">Track Mechanics</h2>
    <p class="muted" style="margin:0;">Live mechanic positions update every 15 seconds.</p>

    <div class="track-grid">

        <!-- ── Mechanic list ──────────────────────────────────────────────── -->
        <div class="mech-list">
            <div class="mech-list-head">
                <span>Mechanics (<?php echo count($mechanics); ?>)</span>
                <span class="poll-badge" id="pollBadge">● Live</span>
            </div>
            <?php if (empty($mechanics)): ?>
                <p class="muted" style="padding:20px;text-align:center;">No mechanics found.</p>
            <?php else: ?>
                <?php foreach ($mechanics as $i => $m): ?>
                <?php
                    $hasLoc   = !empty($m['current_lat']) && !empty($m['current_lng']);
                    $hasCust  = !empty($m['cust_lat'])    && !empty($m['cust_lng']);
                    $locAge   = $hasLoc ? (time() - strtotime($m['location_updated_at'])) : null;
                    $locFresh = $locAge !== null && $locAge < 300;
                    $dotClass = !$hasLoc ? 'grey' : ($locFresh ? 'green' : 'orange');

                    // Pre-select mechanic if ?id= matches, otherwise first in list
                    $isActive = $preSelectId
                        ? ((int)$m['id'] === $preSelectId)
                        : ($i === 0);
                ?>
                <div class="mech-item <?php echo $isActive ? 'active' : ''; ?>"
                     id="mechItem_<?php echo (int)$m['id']; ?>"
                     onclick="selectMechanic(this, <?php echo (int)$m['id']; ?>)"
                     data-id="<?php echo (int)$m['id']; ?>"
                     data-name="<?php echo e($m['name']); ?>"
                     data-phone="<?php echo e($m['phone'] ?: '—'); ?>"
                     data-status="<?php echo e($m['status']); ?>"
                     data-problem="<?php echo e($m['problem_type'] ?? '—'); ?>"
                     data-customer="<?php echo e($m['customer_name'] ?? '—'); ?>"
                     data-login="<?php echo $m['last_login']  ? date('M d, Y g:i A', strtotime($m['last_login']))  : '—'; ?>"
                     data-logout="<?php echo $m['last_logout'] ? date('M d, Y g:i A', strtotime($m['last_logout'])) : '—'; ?>"
                     data-mech-lat="<?php echo $hasLoc ? (float)$m['current_lat'] : ''; ?>"
                     data-mech-lng="<?php echo $hasLoc ? (float)$m['current_lng'] : ''; ?>"
                     data-cust-lat="<?php echo $hasCust ? (float)$m['cust_lat'] : ''; ?>"
                     data-cust-lng="<?php echo $hasCust ? (float)$m['cust_lng'] : ''; ?>"
                     data-request-id="<?php echo (int)($m['request_id'] ?? 0); ?>">

                    <?php if (!empty($m['profile_pic'])): ?>
                        <img src="<?php echo getBasePath() . e($m['profile_pic']); ?>"
                             class="mech-avatar" alt="">
                    <?php else: ?>
                        <div class="mech-avatar-ph">👤</div>
                    <?php endif; ?>

                    <div class="mech-info">
                        <strong><?php echo e($m['name']); ?></strong>
                        <small><?php echo $m['status'] === 'busy'
                            ? '🔧 ' . e($m['problem_type'] ?? 'On a job')
                            : '✅ Available'; ?></small>
                        <small><?php echo $m['last_login']
                            ? 'Login: ' . date('g:i A', strtotime($m['last_login']))
                            : 'Never logged in'; ?></small>
                    </div>

                    <span class="loc-dot <?php echo $dotClass; ?>"
                          id="locDot_<?php echo (int)$m['id']; ?>"
                          title="<?php echo !$hasLoc ? 'No location data'
                              : ($locFresh ? 'Location fresh' : 'Location stale (>5 min)'); ?>">
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── Map panel ──────────────────────────────────────────────────── -->
        <div class="map-panel">
            <div class="map-panel-head">
                <span id="mapHeading">Select a mechanic to view map</span>
                <span class="updated-label" id="lastUpdatedLabel"></span>
            </div>

            <div id="map"></div>

            <!-- Toolbar -->
            <div class="map-toolbar" id="mapToolbar" style="display:none;">
                <button class="map-btn btn-blue"  onclick="focusMechanic()">🔧 Go to Mechanic</button>
                <button class="map-btn btn-orange" onclick="focusCustomer()"
                        id="btnFocusCustomer" style="display:none;">📍 Go to Customer</button>
                <a href="#" class="map-btn" id="btnGmaps" target="_blank">🗺 Google Maps</a>
                <span class="distance-pill hidden" id="distPill">
                    📏 <span id="distLabel"></span>
                </span>
            </div>

            <!-- Detail strip -->
            <div class="mech-detail-strip" id="detailStrip">
                <div class="detail-item"><span>Status</span><strong id="dStatus">—</strong></div>
                <div class="detail-item"><span>Job</span><strong id="dJob">—</strong></div>
                <div class="detail-item"><span>Customer</span><strong id="dCustomer">—</strong></div>
                <div class="detail-item"><span>Last Login</span><strong id="dLogin">—</strong></div>
                <div class="detail-item"><span>Last Logout</span><strong id="dLogout">—</strong></div>
            </div>

            <!-- Legend -->
            <div class="map-legend">
                <div class="legend-item">
                    <span class="legend-dot" style="background:#3a5bbd;"></span> Mechanic
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#ff6600;"></span> Customer
                </div>
            </div>
        </div>

    </div>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {

    var POLL_URL    = '<?php echo getBasePath(); ?>mechanic/get_locations.php';
    var POLL_MS     = 15000; // 15 seconds
    var preSelectId = <?php echo $preSelectId ?: 0; ?>;

    /* ── Map init ────────────────────────────────────────────────────────── */
    var map = L.map('map').setView([9.5600, 44.0650], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);

    var mechMarker   = null;
    var custMarker   = null;
    var connectLine  = null;
    var selectedId   = null;

    /* ── Icons ───────────────────────────────────────────────────────────── */
    function makeIcon(color) {
        return L.divIcon({
            className: '',
            html: '<div style="background:' + color + ';width:16px;height:16px;border-radius:50%;'
                + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>',
            iconSize: [16, 16], iconAnchor: [8, 8], popupAnchor: [0, -12]
        });
    }

    /* ── Haversine ───────────────────────────────────────────────────────── */
    function haversine(lat1, lng1, lat2, lng2) {
        var R = 6371, dL = (lat2-lat1)*Math.PI/180, dN = (lng2-lng1)*Math.PI/180;
        var a = Math.sin(dL/2)*Math.sin(dL/2)
              + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)
              * Math.sin(dN/2)*Math.sin(dN/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }
    function formatDist(km) {
        return km < 1 ? (km*1000).toFixed(0)+' m away' : km.toFixed(1)+' km away';
    }

    /* ── Focus helpers ───────────────────────────────────────────────────── */
    window.focusMechanic = function () {
        if (mechMarker) { map.setView(mechMarker.getLatLng(), 16); mechMarker.openPopup(); }
    };
    window.focusCustomer = function () {
        if (custMarker) { map.setView(custMarker.getLatLng(), 16); custMarker.openPopup(); }
    };

    /* ── Render map for a mechanic ───────────────────────────────────────── */
    function renderMap(mechLat, mechLng, custLat, custLng, name, customer) {
        var hasMech = mechLat !== '' && mechLat !== null && !isNaN(mechLat);
        var hasCust = custLat !== '' && custLat !== null && !isNaN(custLat);

        // Clear old layers
        if (mechMarker)  { map.removeLayer(mechMarker);  mechMarker  = null; }
        if (custMarker)  { map.removeLayer(custMarker);  custMarker  = null; }
        if (connectLine) { map.removeLayer(connectLine); connectLine = null; }

        if (hasMech) {
            mechMarker = L.marker([mechLat, mechLng], { icon: makeIcon('#3a5bbd') })
                .addTo(map)
                .bindPopup('<strong>🔧 ' + name + '</strong><br>Mechanic');
        }

        var btnCust = document.getElementById('btnFocusCustomer');
        var pill    = document.getElementById('distPill');

        if (hasCust) {
            custMarker = L.marker([custLat, custLng], { icon: makeIcon('#ff6600') })
                .addTo(map)
                .bindPopup('<strong>📍 ' + customer + '</strong><br>Customer');
            btnCust.style.display = 'inline-flex';

            if (hasMech) {
                connectLine = L.polyline(
                    [[mechLat, mechLng], [custLat, custLng]],
                    { color: '#ff6600', weight: 2.5, dashArray: '6 5', opacity: .75 }
                ).addTo(map);

                var km = haversine(mechLat, mechLng, custLat, custLng);
                pill.classList.remove('hidden');
                document.getElementById('distLabel').textContent = formatDist(km);

                map.fitBounds(L.latLngBounds([mechLat, mechLng], [custLat, custLng]), { padding: [50, 50] });
            } else {
                pill.classList.add('hidden');
                map.setView([custLat, custLng], 15);
            }
        } else {
            btnCust.style.display = 'none';
            pill.classList.add('hidden');
            if (hasMech) map.setView([mechLat, mechLng], 15);
        }

        if (hasMech) {
            document.getElementById('btnGmaps').href =
                'https://www.google.com/maps?q=' + mechLat + ',' + mechLng;
        }
    }

    /* ── Select mechanic from list ───────────────────────────────────────── */
    window.selectMechanic = function (el, id) {
        document.querySelectorAll('.mech-item').forEach(function (i) { i.classList.remove('active'); });
        el.classList.add('active');
        selectedId = id;

        var d = el.dataset;
        var mechLat = d.mechLat !== '' ? parseFloat(d.mechLat) : null;
        var mechLng = d.mechLng !== '' ? parseFloat(d.mechLng) : null;
        var custLat = d.custLat !== '' ? parseFloat(d.custLat) : null;
        var custLng = d.custLng !== '' ? parseFloat(d.custLng) : null;

        document.getElementById('mapHeading').textContent = '🔧 ' + d.name;
        document.getElementById('mapToolbar').style.display = 'flex';

        var strip = document.getElementById('detailStrip');
        strip.classList.add('visible');
        document.getElementById('dStatus').textContent   = d.status.charAt(0).toUpperCase() + d.status.slice(1);
        document.getElementById('dJob').textContent      = d.problem;
        document.getElementById('dCustomer').textContent = d.customer;
        document.getElementById('dLogin').textContent    = d.login;
        document.getElementById('dLogout').textContent   = d.logout;

        renderMap(mechLat, mechLng, custLat, custLng, d.name, d.customer);
    };

    /* ── Background polling ──────────────────────────────────────────────── */
    function pollLocations() {
        fetch(POLL_URL)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Update each mechanic's list item data attributes + location dot
                data.forEach(function (m) {
                    var el  = document.getElementById('mechItem_' + m.id);
                    if (!el) return;

                    // Update data attributes
                    el.dataset.mechLat = m.current_lat  || '';
                    el.dataset.mechLng = m.current_lng  || '';
                    el.dataset.status  = m.status;

                    // Update location dot color
                    var dot = document.getElementById('locDot_' + m.id);
                    if (dot) {
                        var fresh = m.loc_age !== null && m.loc_age < 300;
                        dot.className = 'loc-dot ' + (m.current_lat === null ? 'grey' : (fresh ? 'green' : 'orange'));
                        dot.title = m.current_lat === null ? 'No location data'
                            : (fresh ? 'Location fresh' : 'Location stale (>5 min)');
                    }

                    // If this is the currently selected mechanic, refresh the map
                    if (m.id === selectedId && m.current_lat) {
                        var custLat = el.dataset.custLat !== '' ? parseFloat(el.dataset.custLat) : null;
                        var custLng = el.dataset.custLng !== '' ? parseFloat(el.dataset.custLng) : null;
                        renderMap(
                            parseFloat(m.current_lat), parseFloat(m.current_lng),
                            custLat, custLng,
                            el.dataset.name, el.dataset.customer
                        );
                    }
                });

                // Update "last refreshed" label
                var now = new Date();
                document.getElementById('lastUpdatedLabel').textContent =
                    'Updated ' + now.toLocaleTimeString();
            })
            .catch(function () {
                document.getElementById('pollBadge').textContent   = '● Offline';
                document.getElementById('pollBadge').className     = 'poll-badge inactive';
            });
    }

    /* ── Auto-select on load ─────────────────────────────────────────────── */
    var autoEl = preSelectId
        ? document.getElementById('mechItem_' + preSelectId)
        : document.querySelector('.mech-item');

    if (autoEl) selectMechanic(autoEl, parseInt(autoEl.dataset.id));

    /* ── Start polling ───────────────────────────────────────────────────── */
    setInterval(pollLocations, POLL_MS);

})();
</script>