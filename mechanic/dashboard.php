<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireMechanicLogin();

$pdo        = getPDO();
$mechanicId = getMechanicId();

// Get mechanic info
$mechanicStmt = $pdo->prepare('SELECT name, status FROM mechanics WHERE id = ?');
$mechanicStmt->execute([$mechanicId]);
$mechanic = $mechanicStmt->fetch();

// Get assigned jobs count
$assignedStmt = $pdo->prepare('
    SELECT COUNT(*) as count
    FROM requests
    WHERE mechanic_id = ? AND status IN ("assigned", "in_progress")
');
$assignedStmt->execute([$mechanicId]);
$assignedCount = $assignedStmt->fetch()['count'];

// Get completed jobs count
$completedStmt = $pdo->prepare('
    SELECT COUNT(*) as count
    FROM history_records
    WHERE mechanic_id = ? AND status = "completed"
    AND hidden_by_admin = FALSE
');
$completedStmt->execute([$mechanicId]);
$completedCount = $completedStmt->fetch()['count'];

// Get current job (if any) — includes customer coordinates for map
$currentStmt = $pdo->prepare('
    SELECT r.id, r.problem_type, r.status, r.description, r.diagnosis,
           r.latitude AS cust_lat, r.longitude AS cust_lng,
           u.full_name  AS customer_name, u.phone  AS customer_phone,
           w.full_name  AS walkin_name,   w.phone  AS walkin_phone
    FROM requests r
    LEFT JOIN users            u ON r.user_id   = u.id
    LEFT JOIN walkin_customers w ON r.walkin_id  = w.id
    WHERE r.mechanic_id = ? AND r.status IN ("assigned", "in_progress")
    ORDER BY r.created_at DESC
    LIMIT 1
');
$currentStmt->execute([$mechanicId]);
$currentJob = $currentStmt->fetch();

$hasJobCoords = $currentJob && !empty($currentJob['cust_lat']) && !empty($currentJob['cust_lng']);

// Get recent completed jobs (not hidden by admin)
$recentStmt = $pdo->prepare('
    SELECT id, problem_type, total_amount, completed_at
    FROM history_records
    WHERE mechanic_id = ? AND status = "completed"
    AND hidden_by_admin = FALSE
    ORDER BY completed_at DESC
    LIMIT 5
');
$recentStmt->execute([$mechanicId]);
$recentJobs = $recentStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Location status bar ─────────────────────────────────────────────────── */
.loc-status-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
    background: #f0f0f0;
    color: #555;
    width: fit-content;
    margin-top: 10px;
}
.loc-status-bar .loc-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
    background: #ccc;
}
.loc-status-bar .loc-dot.green  { background: #4caf50; }
.loc-status-bar .loc-dot.orange { background: #ff9800; }
.loc-status-bar .loc-dot.red    { background: #e74c3c; }

/* ── Job map ─────────────────────────────────────────────────────────────── */
#jobMap {
    width: 100%;
    height: 300px;
    border-radius: 8px;
    background: #eee;
    margin-top: 14px;
}
.map-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
}
.map-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 20px;
    font-size: .78rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    text-decoration: none; transition: background .15s;
}
.map-btn-customer {
    background: #fff3e8; color: var(--safety-orange,#ff6600); border-color: #ffd0a8;
}
.map-btn-customer:hover { background: #ffe0c2; }
.map-btn-me {
    background: #e8f0ff; color: #3a5bbd; border-color: #b8ccff;
}
.map-btn-me:hover { background: #d0e0ff; }
.map-btn-gmaps {
    background: #f0f0f0; color: #444; border-color: #ddd;
}
.map-btn-gmaps:hover { background: #e4e4e4; }
.distance-pill {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 6px;
    background: #1a1a1a; color: #fff;
    padding: 6px 14px; border-radius: 20px;
    font-size: .78rem; font-weight: 700;
}
.distance-pill.hidden { display: none; }
.map-legend {
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-top: 8px; font-size: .78rem; color: #555;
}
.legend-dot {
    width: 11px; height: 11px; border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.25);
    display: inline-block; margin-right: 4px;
    vertical-align: middle;
}
</style>

<main>
    <div class="card">

        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
            <div>
                <h2 style="margin:0 0 4px 0;">Welcome, <?php echo e($mechanic['name']); ?></h2>
                <p class="muted" style="margin:0;">
                    Status: <strong style="color:<?php echo $mechanic['status'] === 'available' ? '#2dbd5e' : '#e74c3c'; ?>;">
                        <?php echo ucfirst($mechanic['status']); ?>
                    </strong>
                </p>
            </div>
        </div>

        <!-- Location status indicator -->
        <div class="loc-status-bar" id="locStatusBar">
            <span class="loc-dot" id="locDot"></span>
            <span id="locStatusText">Acquiring your location…</span>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:20px 0;">
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--safety-orange);margin:0;"><?php echo $assignedCount; ?></h3>
                <p class="muted">Active Jobs</p>
            </div>
            <div class="card" style="text-align:center;">
                <h3 style="color:var(--charcoal);margin:0;"><?php echo $completedCount; ?></h3>
                <p class="muted">Completed Jobs</p>
            </div>
        </div>

        <!-- Current Job -->
        <?php if ($currentJob): ?>
            <h3 style="margin-top:24px;">Current Job</h3>
            <div class="card" style="border-left:4px solid var(--safety-orange);">

                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                    <div>
                        <h4 style="margin:0 0 4px 0;color:var(--charcoal);">
                            <?php echo e($currentJob['problem_type']); ?>
                        </h4>
                        <p class="muted" style="margin:0;font-size:13px;">
                            Request #<?php echo $currentJob['id']; ?> •
                            <?php echo ucfirst($currentJob['status']); ?>
                        </p>
                    </div>
                    <span class="status status-<?php echo e($currentJob['status']); ?>">
                        <?php echo ucfirst($currentJob['status']); ?>
                    </span>
                </div>

                <p style="margin:12px 0;color:#555;font-size:14px;">
                    <strong>Customer:</strong>
                    <?php
                    $customer = $currentJob['customer_name'] ?? $currentJob['walkin_name'] ?? 'Unknown';
                    $phone    = $currentJob['customer_phone'] ?? $currentJob['walkin_phone'] ?? 'N/A';
                    echo e($customer) . ' • ' . e($phone);
                    ?>
                </p>

                <p style="margin:0;color:#555;font-size:14px;">
                    <strong>Issue:</strong> <?php echo e(substr($currentJob['description'], 0, 100)); ?>
                    <?php if (strlen($currentJob['description']) > 100): ?>…<?php endif; ?>
                </p>

                <?php if (!empty($currentJob['diagnosis'])): ?>
                <p style="margin:8px 0 0;color:#555;font-size:14px;">
                    <strong>Diagnosis:</strong> <?php echo e(substr($currentJob['diagnosis'], 0, 100)); ?>
                    <?php if (strlen($currentJob['diagnosis']) > 100): ?>…<?php endif; ?>
                </p>
                <?php endif; ?>

                <!-- ── Customer + Mechanic map ───────────────────────────── -->
                <?php if ($hasJobCoords): ?>
                <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f0;">
                    <p style="margin:0 0 6px;font-size:.8rem;font-weight:700;
                               text-transform:uppercase;letter-spacing:.05em;color:var(--muted,#888);">
                        📍 Location
                    </p>
                    <div id="jobMap"></div>
                    <div class="map-toolbar">
                        <button class="map-btn map-btn-customer" onclick="focusCustomer()">
                            📍 Customer
                        </button>
                        <button class="map-btn map-btn-me" id="btnFocusMe"
                                onclick="focusMe()" style="display:none;">
                            🔵 My Location
                        </button>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?php
                            echo e($currentJob['cust_lat']); ?>,<?php
                            echo e($currentJob['cust_lng']); ?>"
                           target="_blank" class="map-btn map-btn-gmaps">
                            🧭 Directions
                        </a>
                        <span class="distance-pill hidden" id="distPill">
                            📏 <span id="distLabel"></span>
                        </span>
                    </div>
                    <div class="map-legend">
                        <span><span class="legend-dot" style="background:#ff6600;"></span>Customer</span>
                        <span><span class="legend-dot" style="background:#3a5bbd;"></span>My Location</span>
                    </div>
                </div>
                <?php endif; ?>

                <p style="margin-top:14px;text-align:right;">
                    <a class="btn btn-primary"
                       href="<?php echo getBasePath(); ?>mechanic/job_detail.php?id=<?php echo $currentJob['id']; ?>">
                        View &amp; Manage Job
                    </a>
                </p>
            </div>
        <?php else: ?>
            <div class="card" style="background:#e8f5e9;border-left:4px solid #4caf50;text-align:center;padding:20px;">
                <p class="muted" style="margin:0;">✓ No active jobs assigned.</p>
            </div>
        <?php endif; ?>

        <!-- Recent Completed Jobs -->
        <?php if (count($recentJobs) > 0): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px;">
                <h3 style="margin:0;">Recent Completed Jobs</h3>
                <a href="<?php echo getBasePath(); ?>mechanic/history.php"
                   style="font-size:.83rem;font-weight:600;color:var(--safety-orange);text-decoration:none;">
                    View all →
                </a>
            </div>
            <div style="overflow-x:auto;margin-top:12px;">
                <table style="width:100%;font-size:14px;">
                    <thead>
                        <tr style="background:#f5f5f5;border-bottom:1px solid #d0d0d0;">
                            <th style="padding:12px;text-align:left;font-weight:600;">Problem Type</th>
                            <th style="padding:12px;text-align:right;font-weight:600;">Amount</th>
                            <th style="padding:12px;text-align:right;font-weight:600;">Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentJobs as $job): ?>
                            <tr style="border-bottom:1px solid #e0e0e0;">
                                <td style="padding:12px;"><?php echo e($job['problem_type']); ?></td>
                                <td style="padding:12px;text-align:right;font-weight:600;">
                                    $<?php echo number_format($job['total_amount'], 2); ?>
                                </td>
                                <td style="padding:12px;text-align:right;color:#666;font-size:13px;">
                                    <?php echo date('M d, Y', strtotime($job['completed_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($hasJobCoords): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<script>
(function () {
    var UPDATE_URL  = '<?php echo getBasePath(); ?>mechanic/update_location.php';
    var dot         = document.getElementById('locDot');
    var statusText  = document.getElementById('locStatusText');
    var lastSentLat = null;
    var lastSentLng = null;

    /* ── Map (only if job has customer coordinates) ──────────────────────── */
    <?php if ($hasJobCoords): ?>
    var CUST_LAT  = <?php echo (float)$currentJob['cust_lat']; ?>;
    var CUST_LNG  = <?php echo (float)$currentJob['cust_lng']; ?>;
    var CUST_NAME = <?php echo json_encode($customer); ?>;

    var map = L.map('jobMap').setView([CUST_LAT, CUST_LNG], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);

    function makeIcon(color) {
        return L.divIcon({
            className: '',
            html: '<div style="background:' + color + ';width:16px;height:16px;border-radius:50%;'
                + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>',
            iconSize: [16,16], iconAnchor: [8,8], popupAnchor: [0,-12]
        });
    }

    // Customer marker — fixed
    var custMarker = L.marker([CUST_LAT, CUST_LNG], { icon: makeIcon('#ff6600') })
        .addTo(map)
        .bindPopup('<strong>📍 Customer</strong><br>' + CUST_NAME)
        .openPopup();

    // Mechanic marker + line — placed/updated when GPS resolves
    var mechMarker  = null;
    var connectLine = null;
    var myLat       = null;
    var myLng       = null;

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

    function placeMyMarker(lat, lng) {
        myLat = lat; myLng = lng;

        if (mechMarker) {
            mechMarker.setLatLng([lat, lng]);
        } else {
            mechMarker = L.marker([lat, lng], { icon: makeIcon('#3a5bbd') })
                .addTo(map)
                .bindPopup('<strong>🔵 My Location</strong>');
            document.getElementById('btnFocusMe').style.display = 'inline-flex';
        }

        if (connectLine) {
            connectLine.setLatLngs([[lat, lng], [CUST_LAT, CUST_LNG]]);
        } else {
            connectLine = L.polyline(
                [[lat, lng], [CUST_LAT, CUST_LNG]],
                { color: '#ff6600', weight: 2.5, dashArray: '6 5', opacity: .75 }
            ).addTo(map);
        }

        map.fitBounds(
            L.latLngBounds([lat, lng], [CUST_LAT, CUST_LNG]),
            { padding: [48, 48] }
        );

        var km   = haversine(lat, lng, CUST_LAT, CUST_LNG);
        var pill = document.getElementById('distPill');
        pill.classList.remove('hidden');
        document.getElementById('distLabel').textContent = formatDist(km);
    }

    window.focusCustomer = function () {
        map.setView([CUST_LAT, CUST_LNG], 16);
        custMarker.openPopup();
    };
    window.focusMe = function () {
        if (myLat !== null) {
            map.setView([myLat, myLng], 16);
            mechMarker.openPopup();
        }
    };
    <?php endif; ?>

    /* ── Status helper ───────────────────────────────────────────────────── */
    function setStatus(color, text) {
        dot.className          = 'loc-dot ' + color;
        statusText.textContent = text;
    }

    /* ── Send location to server ─────────────────────────────────────────── */
    function sendLocation(lat, lng) {
        if (lastSentLat !== null) {
            var dLat = Math.abs(lat - lastSentLat);
            var dLng = Math.abs(lng - lastSentLng);
            if (dLat < 0.0001 && dLng < 0.0001) return;
        }
        lastSentLat = lat;
        lastSentLng = lng;

        var body = new FormData();
        body.append('lat', lat);
        body.append('lng', lng);

        fetch(UPDATE_URL, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    setStatus('green', 'Location shared with admin (' +
                        lat.toFixed(5) + ', ' + lng.toFixed(5) + ')');
                } else {
                    setStatus('orange', 'Location update failed — retrying…');
                }
            })
            .catch(function () {
                setStatus('orange', 'Connection issue — will retry on next position update.');
            });
    }

    /* ── watchPosition — drives both the status bar and the map dot ──────── */
    if (!navigator.geolocation) {
        setStatus('red', 'Geolocation not supported by your browser.');
    } else {
        setStatus('orange', 'Acquiring your location…');

        navigator.geolocation.watchPosition(
            function (pos) {
                var lat = pos.coords.latitude;
                var lng = pos.coords.longitude;
                sendLocation(lat, lng);
                <?php if ($hasJobCoords): ?>
                placeMyMarker(lat, lng);
                <?php endif; ?>
            },
            function (err) {
                var msgs = {
                    1: 'Location permission denied — admin cannot see your position.',
                    2: 'Location unavailable — check your GPS signal.',
                    3: 'Location request timed out — retrying…'
                };
                setStatus('red', msgs[err.code] || 'Location error.');
            },
            { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 }
        );
    }

})();
</script>