<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo     = getPDO();
$user_id = $_SESSION['user_id'];

// ── Check for existing active request ────────────────────────────────────
$activeStmt = $pdo->prepare(
    "SELECT id, status FROM requests
     WHERE user_id = ? AND status IN ('pending','assigned','in_progress')
     LIMIT 1"
);
$activeStmt->execute([$user_id]);
$activeRequest = $activeStmt->fetch();
$hasActiveRequest = (bool)$activeRequest;

// ── Check cancel block ────────────────────────────────────────────────────
$uStmt = $pdo->prepare(
    'SELECT cancel_count_today, cancel_date, cancel_blocked_until
     FROM users WHERE id = ?'
);
$uStmt->execute([$user_id]);
$userRow = $uStmt->fetch();

$isNewDay       = !$userRow['cancel_date'] || $userRow['cancel_date'] !== date('Y-m-d');
$cancelCount    = $isNewDay ? 0 : (int)$userRow['cancel_count_today'];
$blockedUntil   = $isNewDay ? null : $userRow['cancel_blocked_until'];
$isBlocked      = $blockedUntil && strtotime($blockedUntil) > time();
$blockedUntilTs = $isBlocked ? strtotime($blockedUntil) : 0;

// Current block duration (for display on the block screen)
$currentBlockMins = $isBlocked ? ($cancelCount - 1) * 5 : 0;

$error = '';

$vehicleMakes = [
    'Toyota','Honda','Nissan','Hyundai','Ford','Mitsubishi',
    'Suzuki','Kia','BMW','Mercedes-Benz','Mazda','Subaru',
    'Volkswagen','Chevrolet','Isuzu','Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hard block — active request
    if ($hasActiveRequest) {
        $error = 'You already have an active service request. Please wait for it to be completed before submitting a new one.';
    // Hard block — cancel cooldown
    } elseif ($isBlocked) {
        $error = 'You are temporarily blocked from submitting new requests due to multiple cancellations today. Please wait until the cooldown expires.';
    } else {
        $problem_type       = trim($_POST['problem_type']       ?? '');
        $description        = trim($_POST['description']        ?? '');
        $vehicle_make       = trim($_POST['vehicle_make']       ?? '');
        $vehicle_make_other = trim($_POST['vehicle_make_other'] ?? '');
        $vehicle_model      = trim($_POST['vehicle_model']      ?? '');
        $vehicle_year       = trim($_POST['vehicle_year']       ?? '');
        $latitude           = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
        $longitude          = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

        $resolved_make = ($vehicle_make === 'Other' && $vehicle_make_other !== '')
                         ? $vehicle_make_other
                         : ($vehicle_make !== '' ? $vehicle_make : null);

        $vehicle_year_val = ($vehicle_year !== '' && ctype_digit($vehicle_year)
                             && (int)$vehicle_year >= 1900 && (int)$vehicle_year <= (int)date('Y') + 1)
                            ? (int)$vehicle_year : null;

        $imagePath = null;
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            if ($file['size'] > 5 * 1024 * 1024) {
                $error = 'File too large (max 5 MB).';
            } else {
                $finfo   = finfo_open(FILEINFO_MIME_TYPE);
                $mime    = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
                if (!isset($allowed[$mime])) {
                    $error = 'Invalid image type. Use JPG, PNG or GIF.';
                } else {
                    $ext       = $allowed[$mime];
                    $newName   = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $targetDir = __DIR__ . '/../uploads/service_photos/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                    $target = $targetDir . $newName;
                    if (!move_uploaded_file($file['tmp_name'], $target)) {
                        $error = 'Failed to save uploaded file.';
                    } else {
                        $imagePath = 'uploads/service_photos/' . $newName;
                    }
                }
            }
        }

        if ($error === '') {
            $stmt = $pdo->prepare(
                'INSERT INTO requests
                   (user_id, vehicle_make, vehicle_make_other, vehicle_model, vehicle_year,
                    problem_type, description, image, latitude, longitude, status, created_at)
                 VALUES
                   (:user_id, :vehicle_make, :vehicle_make_other, :vehicle_model, :vehicle_year,
                    :problem_type, :description, :image, :latitude, :longitude, :status, NOW())'
            );
            $stmt->execute([
                ':user_id'            => $user_id,
                ':vehicle_make'       => $resolved_make,
                ':vehicle_make_other' => ($vehicle_make === 'Other' ? $vehicle_make_other : null),
                ':vehicle_model'      => $vehicle_model !== '' ? $vehicle_model : null,
                ':vehicle_year'       => $vehicle_year_val,
                ':problem_type'       => $problem_type,
                ':description'        => $description,
                ':image'              => $imagePath,
                ':latitude'           => $latitude,
                ':longitude'          => $longitude,
                ':status'             => 'pending',
            ]);
            $requestId = $pdo->lastInsertId();
            header('Location: ' . getBasePath() . 'customer/track_service.php?id=' . $requestId);
            exit;
        }
    }
}

// Status label helper for the block screen
function activeStatusLabel(string $status): string {
    return match($status) {
        'pending'     => 'Pending — waiting for a mechanic to be assigned',
        'assigned'    => 'Assigned — a mechanic has been assigned',
        'in_progress' => 'In Progress — mechanic is currently working on it',
        default       => ucfirst($status),
    };
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<style>
#mapWrap { display:none; margin-bottom:20px; }

#map {
    width:100%; height:300px;
    border-radius:10px;
    border:2px solid #ddd;
    box-shadow:0 2px 10px rgba(0,0,0,.12);
}

#layerToggle {
    display:flex; margin-bottom:8px;
    background:#eee; border-radius:8px; padding:3px;
    width:fit-content; gap:0;
}
#layerToggle button {
    border:none; background:transparent;
    padding:6px 16px; border-radius:6px;
    cursor:pointer; font-size:.82rem; font-weight:600;
    color:#555; transition:all .2s;
}
#layerToggle button.active {
    background:var(--safety-orange,#ff6600);
    color:#fff; box-shadow:0 1px 4px rgba(0,0,0,.2);
}

#locationBanner {
    display:flex; align-items:center; gap:10px;
    padding:12px 16px; border-radius:8px; margin-bottom:20px;
    background:#e8f4fd; border:1px solid #90caf9; color:#1565c0;
    font-size:.9rem; transition:background .3s,border-color .3s,color .3s;
}
#coordsDisplay { font-size:.78rem; color:var(--muted,#888); margin-top:5px; }

#makeOtherWrap { display:none; margin-top:8px; }

/* ── Block screen ───────────────────────────────────────────────────────── */
.block-screen {
    text-align: center;
    padding: 48px 24px;
}
.block-screen-icon {
    font-size: 4rem;
    display: block;
    margin-bottom: 16px;
}
.block-screen h3 {
    font-size: 1.3rem;
    margin: 0 0 10px;
    color: #e65100;
}
.block-screen p {
    color: #666;
    font-size: .92rem;
    margin: 0 0 24px;
    line-height: 1.6;
}
.block-screen-timer {
    display: inline-block;
    font-size: 2.4rem;
    font-weight: 700;
    color: #e65100;
    background: #fff3e0;
    border: 2px solid #ffcc80;
    border-radius: 12px;
    padding: 12px 32px;
    margin-bottom: 28px;
    letter-spacing: .06em;
}
.block-screen-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}

/* ── Escalation table on block screen ──────────────────────────────────── */
.escalation-table {
    display: inline-flex;
    flex-direction: column;
    gap: 6px;
    margin: 0 auto 24px;
    text-align: left;
}
.escalation-row {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #fafafa;
    border: 1px solid #f0f0f0;
    border-radius: 8px;
    padding: 7px 14px;
    font-size: .84rem;
}
.escalation-row.current {
    background: #fff3e0;
    border-color: #ffcc80;
    font-weight: 700;
    color: #e65100;
}
.escalation-row .esc-cancel {
    min-width: 80px;
    color: #888;
    font-size: .78rem;
}
.escalation-row.current .esc-cancel { color: #e65100; }
.escalation-row .esc-block {
    font-weight: 600;
}

/* ── Active request badge ───────────────────────────────────────────────── */
.active-request-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #fff3e0;
    border: 1px solid #ffcc80;
    border-radius: 20px;
    padding: 6px 16px;
    font-size: .85rem;
    font-weight: 600;
    color: #e65100;
    margin-bottom: 20px;
}
.active-request-badge .dot {
    width: 9px; height: 9px;
    background: #ff6600;
    border-radius: 50%;
    animation: pulseDot 1.4s infinite;
}
@keyframes pulseDot {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.4; transform:scale(1.4); }
}

@keyframes pulse {
    0%,100% { box-shadow:0 0 0 4px rgba(255,102,0,.35),0 2px 8px rgba(0,0,0,.4); }
    50%     { box-shadow:0 0 0 9px rgba(255,102,0,.08),0 2px 8px rgba(0,0,0,.4); }
}
</style>

<main>
<div class="card">

<?php if ($hasActiveRequest): ?>

    <!-- ── ACTIVE REQUEST BLOCK ──────────────────────────────────────────── -->
    <div class="block-screen">
        <span class="block-screen-icon">🔧</span>
        <h3>You Already Have an Active Request</h3>

        <div style="margin-bottom:20px;">
            <span class="active-request-badge">
                <span class="dot"></span>
                <?php echo activeStatusLabel($activeRequest['status']); ?>
            </span>
        </div>

        <p>
            Only one service request can be active at a time.<br>
            You can track or cancel your current request, then submit a new one once it's resolved.
        </p>

        <div class="block-screen-actions">
            <a href="<?php echo getBasePath(); ?>customer/track_service.php?id=<?php echo (int)$activeRequest['id']; ?>"
               class="btn btn-primary">
                Track Current Request
            </a>
            <a href="<?php echo getBasePath(); ?>customer/dashboard.php" class="btn">
                Go to Dashboard
            </a>
        </div>
    </div>

<?php elseif ($isBlocked): ?>

    <!-- ── CANCEL COOLDOWN BLOCK ─────────────────────────────────────────── -->
    <div class="block-screen">
        <span class="block-screen-icon">🚫</span>
        <h3>New Requests Blocked for <?php echo $currentBlockMins; ?> Minutes</h3>
        <p>
            You've cancelled <strong><?php echo $cancelCount; ?></strong> request<?php echo $cancelCount !== 1 ? 's' : ''; ?> today.
            Each cancellation from the 2nd onward adds 5 more minutes to the cooldown.
        </p>

        <!-- Escalation breakdown -->
        <div class="escalation-table">
            <?php
            // Show rows for cancels 2 through max(cancelCount + 1, 5) so the user
            // can see where they are and what's coming.
            $showUpTo = max($cancelCount + 1, 5);
            for ($n = 2; $n <= $showUpTo; $n++):
                $mins = ($n - 1) * 5;
                $isCurrent = ($n === $cancelCount);
            ?>
            <div class="escalation-row <?php echo $isCurrent ? 'current' : ''; ?>">
                <span class="esc-cancel">
                    <?php echo $isCurrent ? '👉 ' : ''; ?>Cancel #<?php echo $n; ?>
                </span>
                <span class="esc-block">
                    <?php echo $mins; ?> min block
                    <?php echo $isCurrent ? '← you are here' : ''; ?>
                </span>
            </div>
            <?php endfor; ?>
        </div>

        <div class="block-screen-timer" id="blockScreenTimer">
            <?php
            $remaining = $blockedUntilTs - time();
            $mins = floor($remaining / 60);
            $secs = $remaining % 60;
            echo str_pad($mins, 2, '0', STR_PAD_LEFT) . ':' . str_pad($secs, 2, '0', STR_PAD_LEFT);
            ?>
        </div>

        <div class="block-screen-actions">
            <a href="<?php echo getBasePath(); ?>customer/track_service.php" class="btn btn-primary">
                View My Requests
            </a>
            <a href="<?php echo getBasePath(); ?>customer/dashboard.php" class="btn">
                Go to Dashboard
            </a>
        </div>
    </div>

<?php else: ?>

    <!-- ── NORMAL FORM ───────────────────────────────────────────────────── -->
    <h2>Request Emergency Service</h2>
    <p class="muted">Tell us about your issue and we'll dispatch a mechanic to your location.</p>

    <?php if ($error): ?>
    <div style="background:#fff6eb;border:1px solid #ffb84d;
                border-left:4px solid var(--safety-orange);color:#b35b00;
                padding:12px;border-radius:6px;margin-bottom:16px;">
        <strong>Error:</strong> <?php echo e($error); ?>
    </div>
    <?php endif; ?>

    <form id="serviceForm" method="post" enctype="multipart/form-data"
          style="background:#f9f9f9;padding:20px;border-radius:8px;">

        <input type="hidden" name="latitude"  id="latitude"  value="">
        <input type="hidden" name="longitude" id="longitude" value="">

        <!-- ── Location banner ──────────────────────────────────────────── -->
        <div id="locationBanner">
            <span id="locationIcon" style="font-size:1.3rem;">📡</span>
            <div>
                <strong id="locationTitle">Detecting your location…</strong><br>
                <span id="locationSub" style="font-size:.8rem;opacity:.8;">
                    Please allow location access when prompted.
                </span>
            </div>
            <button type="button" id="retryBtn" onclick="requestLocation()"
                    style="display:none;margin-left:auto;
                           background:var(--safety-orange,#ff6600);color:#fff;
                           border:none;padding:6px 14px;border-radius:5px;
                           cursor:pointer;font-size:.8rem;font-weight:600;">
                Retry
            </button>
        </div>

        <!-- ── Map ──────────────────────────────────────────────────────── -->
        <div id="mapWrap">
            <label style="display:block;margin-bottom:6px;font-weight:600;">Your Location</label>

            <div id="layerToggle">
                <button type="button" id="btnSatellite" class="active"
                        onclick="switchLayer('satellite')">🛰 Satellite</button>
                <button type="button" id="btnHybrid"
                        onclick="switchLayer('hybrid')">🏙 Hybrid</button>
                <button type="button" id="btnStreet"
                        onclick="switchLayer('street')">🗺 Street</button>
            </div>

            <div id="map"></div>
            <p id="coordsDisplay"></p>
        </div>

        <!-- ── Vehicle info ─────────────────────────────────────────────── -->
        <h3 style="margin-bottom:12px;">Vehicle Information</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr 120px;gap:12px;align-items:start;">

            <label style="margin:0;">Make
                <select name="vehicle_make" id="vehicleMakeSelect" onchange="toggleOtherMake(this)">
                    <option value="">-- Select make --</option>
                    <?php
                    $selMake = $_POST['vehicle_make'] ?? '';
                    foreach ($vehicleMakes as $m) {
                        $selected = ($selMake === $m) ? ' selected' : '';
                        echo '<option value="' . e($m) . '"' . $selected . '>' . e($m) . '</option>';
                    }
                    ?>
                </select>
                <div id="makeOtherWrap">
                    <input type="text" name="vehicle_make_other" id="vehicleMakeOther"
                           placeholder="Enter make name"
                           value="<?php echo e($_POST['vehicle_make_other'] ?? ''); ?>">
                </div>
            </label>

            <label style="margin:0;">Model
                <input type="text" name="vehicle_model" placeholder="e.g. Camry, Civic, F-150"
                       value="<?php echo e($_POST['vehicle_model'] ?? ''); ?>">
            </label>

            <label style="margin:0;">Year
                <input type="number" name="vehicle_year" placeholder="e.g. 2019"
                       min="1900" max="<?php echo (int)date('Y') + 1; ?>"
                       value="<?php echo e($_POST['vehicle_year'] ?? ''); ?>">
            </label>

        </div>

        <!-- ── Problem details ──────────────────────────────────────────── -->
        <h3 style="margin-top:20px;margin-bottom:12px;">Problem Details</h3>

        <label>What's the problem?
            <select name="problem_type" required>
                <option value="">-- Select a problem --</option>
                <?php
                $problems = ['Flat Tire','Dead Battery','Brake Problem','Oil Leak','Fuel Delivery','Engine Overheating','Other'];
                $sel = $_POST['problem_type'] ?? '';
                foreach ($problems as $p) {
                    echo '<option' . ($sel === $p ? ' selected' : '') . '>' . e($p) . '</option>';
                }
                ?>
            </select>
        </label>

        <label>Description
            <textarea name="description" rows="4"
                placeholder="Describe what's happening with your vehicle"><?php echo e($_POST['description'] ?? ''); ?></textarea>
        </label>

        <label>Upload Photo
            <span style="font-weight:400;color:var(--muted)">(Optional)</span>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/gif">
            <small style="color:var(--muted);">JPG, PNG or GIF · Max 5 MB</small>
        </label>

        <div style="display:flex;gap:10px;margin-top:20px;">
            <button class="btn btn-primary" type="submit"
                    style="flex:1;padding:13px;font-size:1rem;font-weight:600;">
                Submit Service Request
            </button>
            <a href="<?php echo getBasePath(); ?>customer/dashboard.php"
               class="btn" style="padding:13px 20px;">Cancel</a>
        </div>

        <p id="noLocWarning"
           style="display:none;margin-top:10px;font-size:.82rem;color:#b35b00;text-align:center;">
            ⚠️ Location not detected — request will be submitted without coordinates.
        </p>
    </form>

<?php endif; ?>

</div>
</main>

<?php if ($isBlocked && !$hasActiveRequest): ?>
<script>
/* ── Cancel cooldown countdown ────────────────────────────────────────── */
(function () {
    var endsAt = <?php echo $blockedUntilTs; ?> * 1000;
    var el     = document.getElementById('blockScreenTimer');

    function tick() {
        var diff = Math.max(0, Math.floor((endsAt - Date.now()) / 1000));
        var m = Math.floor(diff / 60), s = diff % 60;
        if (el) el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        if (diff <= 0) location.reload();
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php elseif (!$hasActiveRequest && !$isBlocked): ?>
<script>
/* ── Vehicle make "Other" toggle ──────────────────────────────────────── */
function toggleOtherMake(sel) {
    var wrap  = document.getElementById('makeOtherWrap');
    var input = document.getElementById('vehicleMakeOther');
    if (sel.value === 'Other') {
        wrap.style.display = 'block';
        input.required     = true;
    } else {
        wrap.style.display = 'none';
        input.required     = false;
        input.value        = '';
    }
}
(function () {
    var sel = document.getElementById('vehicleMakeSelect');
    if (sel && sel.value === 'Other') toggleOtherMake(sel);
})();

/* ── Map & location ───────────────────────────────────────────────────── */
(function () {
    var map         = null;
    var marker      = null;
    var activeLayer = 'satellite';

    var layerSatellite = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        { attribution: 'Tiles &copy; Esri', maxNativeZoom: 17, maxZoom: 21 }
    );
    var layerLabels = L.tileLayer(
        'https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
        { maxNativeZoom: 17, maxZoom: 21, opacity: 1 }
    );
    var layerStreet = L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxNativeZoom: 19, maxZoom: 21
        }
    );

    function initMap(lat, lng) {
        if (!map) {
            map = L.map('map', { zoomControl: true }).setView([lat, lng], 17);
            layerSatellite.addTo(map);
            layerLabels.addTo(map);

            var icon = L.divIcon({
                className: '',
                html: '<div style="width:20px;height:20px;'
                    + 'background:var(--safety-orange,#ff6600);'
                    + 'border:3px solid #fff;border-radius:50%;'
                    + 'animation:pulse 1.6s infinite;"></div>',
                iconSize   : [20, 20],
                iconAnchor : [10, 10],
                popupAnchor: [0, -13]
            });

            marker = L.marker([lat, lng], { icon: icon })
                .addTo(map)
                .bindPopup('<strong>📍 You are here</strong><br>'
                    + lat.toFixed(5) + ', ' + lng.toFixed(5))
                .openPopup();

        } else {
            map.setView([lat, lng], 17);
            marker.setLatLng([lat, lng])
                  .setPopupContent('<strong>📍 You are here</strong><br>'
                      + lat.toFixed(5) + ', ' + lng.toFixed(5))
                  .openPopup();
        }
    }

    window.switchLayer = function (type) {
        if (!map) return;
        ['btnSatellite','btnHybrid','btnStreet'].forEach(function (id) {
            document.getElementById(id).classList.remove('active');
        });
        [layerSatellite, layerLabels, layerStreet].forEach(function (l) {
            if (map.hasLayer(l)) map.removeLayer(l);
        });
        if (type === 'satellite') {
            document.getElementById('btnSatellite').classList.add('active');
            layerSatellite.addTo(map);
        } else if (type === 'hybrid') {
            document.getElementById('btnHybrid').classList.add('active');
            layerSatellite.addTo(map);
            layerLabels.addTo(map);
        } else {
            document.getElementById('btnStreet').classList.add('active');
            layerStreet.addTo(map);
        }
        activeLayer = type;
    };

    function setBanner(icon, title, sub, bg, border, color, showRetry) {
        var b = document.getElementById('locationBanner');
        document.getElementById('locationIcon').textContent  = icon;
        document.getElementById('locationTitle').textContent = title;
        document.getElementById('locationSub').textContent   = sub;
        b.style.background  = bg;
        b.style.borderColor = border;
        b.style.color       = color;
        document.getElementById('retryBtn').style.display = showRetry ? 'inline-block' : 'none';
    }

    function onSuccess(pos) {
        var lat = pos.coords.latitude;
        var lng = pos.coords.longitude;
        var acc = Math.round(pos.coords.accuracy);

        document.getElementById('latitude').value  = lat;
        document.getElementById('longitude').value = lng;

        setBanner('✅', 'Location detected',
            'Accuracy: ±' + acc + ' m  ·  ' + lat.toFixed(5) + ', ' + lng.toFixed(5),
            '#e8f5e9', '#a5d6a7', '#1b5e20', false);

        document.getElementById('mapWrap').style.display = 'block';
        initMap(lat, lng);

        document.getElementById('coordsDisplay').textContent =
            'Lat: ' + lat.toFixed(6) + '  ·  Lng: ' + lng.toFixed(6)
            + '  ·  Accuracy: ±' + acc + ' m';

        document.getElementById('noLocWarning').style.display = 'none';
        setTimeout(function () { map.invalidateSize(); }, 120);
    }

    function onError(err) {
        var msgs = {
            1: 'Location access denied. Enable it in your browser settings and retry.',
            2: 'Location unavailable. Check your GPS or network connection.',
            3: 'Location request timed out. Please retry.',
        };
        setBanner('❌', 'Location not detected',
            msgs[err.code] || 'Unknown error.',
            '#fff3e0', '#ffb74d', '#e65100', true);
        document.getElementById('noLocWarning').style.display = 'block';
    }

    window.requestLocation = function () {
        if (!navigator.geolocation) {
            setBanner('🚫', 'Geolocation not supported',
                'Your browser does not support GPS detection.',
                '#fce4ec', '#ef9a9a', '#b71c1c', false);
            document.getElementById('noLocWarning').style.display = 'block';
            return;
        }
        setBanner('📡', 'Detecting your location…',
            'Please allow location access when prompted.',
            '#e8f4fd', '#90caf9', '#1565c0', false);

        navigator.geolocation.getCurrentPosition(onSuccess, onError, {
            enableHighAccuracy: true,
            timeout   : 12000,
            maximumAge: 0
        });
    };

    document.getElementById('serviceForm').addEventListener('submit', function () {
        if (!document.getElementById('latitude').value) {
            document.getElementById('noLocWarning').style.display = 'block';
        }
    });

    requestLocation();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>