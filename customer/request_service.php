<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $problem_type = trim($_POST['problem_type'] ?? '');
    $description  = trim($_POST['description']  ?? '');
    $latitude     = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
    $longitude    = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

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
        $pdo     = getPDO();
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt    = $pdo->prepare(
            'INSERT INTO requests
               (user_id, problem_type, description, image, latitude, longitude, status, created_at)
             VALUES
               (:user_id, :problem_type, :description, :image, :latitude, :longitude, :status, NOW())'
        );
        $stmt->execute([
            ':user_id'      => $user_id,
            ':problem_type' => $problem_type,
            ':description'  => $description,
            ':image'        => $imagePath,
            ':latitude'     => $latitude,
            ':longitude'    => $longitude,
            ':status'       => 'pending',
        ]);
        $requestId = $pdo->lastInsertId();
        header('Location: ' . getBasePath() . 'customer/track_service.php?id=' . $requestId);
        exit;
    }
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

@keyframes pulse {
    0%,100% { box-shadow:0 0 0 4px rgba(255,102,0,.35),0 2px 8px rgba(0,0,0,.4); }
    50%     { box-shadow:0 0 0 9px rgba(255,102,0,.08),0 2px 8px rgba(0,0,0,.4); }
}
</style>

<main>
<div class="card">
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

        <!-- ── Location banner ───────────────────────────────────────────── -->
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

        <!-- ── Map ───────────────────────────────────────────────────────── -->
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

        <!-- ── Problem details ───────────────────────────────────────────── -->
        <h3 style="margin-bottom:12px;">Tell Us About the Problem</h3>

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
</div>
</main>

<script>
(function () {
    var map         = null;
    var marker      = null;
    var activeLayer = 'satellite';

    /* ──────────────────────────────────────────────────────────────────────
       KEY FIX: maxNativeZoom tells Leaflet the highest zoom level where
       real tiles exist. Beyond that it upscales existing tiles instead of
       showing "No map data available".
       maxZoom lets the user still zoom in further (upscaled / blurry but
       visible — far better than a blank grey tile).
    ────────────────────────────────────────────────────────────────────── */

    // 1. Esri World Imagery (satellite, global)
    var layerSatellite = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        {
            attribution : 'Tiles &copy; Esri',
            maxNativeZoom: 17,   // ← tiles only go to zoom 17 in many regions
            maxZoom      : 21    // ← allow zoom beyond that (upscales gracefully)
        }
    );

    // 2. Label overlay (roads, place names on top of satellite)
    var layerLabels = L.tileLayer(
        'https://services.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
        { maxNativeZoom: 17, maxZoom: 21, opacity: 1 }
    );

    // 3. OpenStreetMap street view (always has data everywhere)
    var layerStreet = L.tileLayer(
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            attribution : '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxNativeZoom: 19,
            maxZoom      : 21
        }
    );

    /* ── Init map ────────────────────────────────────────────────────────── */
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

    /* ── Layer switcher ──────────────────────────────────────────────────── */
    window.switchLayer = function (type) {
        if (!map) return;

        // reset all buttons
        ['btnSatellite','btnHybrid','btnStreet'].forEach(function (id) {
            document.getElementById(id).classList.remove('active');
        });

        // remove everything first
        [layerSatellite, layerLabels, layerStreet].forEach(function (l) {
            if (map.hasLayer(l)) map.removeLayer(l);
        });

        if (type === 'satellite') {
            document.getElementById('btnSatellite').classList.add('active');
            layerSatellite.addTo(map);          // imagery only, no labels

        } else if (type === 'hybrid') {
            document.getElementById('btnHybrid').classList.add('active');
            layerSatellite.addTo(map);          // imagery
            layerLabels.addTo(map);             // + road/place names

        } else {
            document.getElementById('btnStreet').classList.add('active');
            layerStreet.addTo(map);             // OSM street map
        }

        activeLayer = type;
    };

    /* ── Banner helper ───────────────────────────────────────────────────── */
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

    /* ── Geolocation ─────────────────────────────────────────────────────── */
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

        // Leaflet needs this after the div becomes visible
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
            timeout : 12000,
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>