<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireMechanicLogin();

$pdo        = getPDO();
$mechanicId = getMechanicId();

// Validate ID — mechanic can only view their own jobs
$id = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: ' . getBasePath() . 'mechanic/dashboard.php');
    exit;
}

$message = '';
$error   = '';

// Handle diagnosis save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_diagnosis'])) {
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    if ($diagnosis === '') {
        $error = 'Diagnosis cannot be empty.';
    } elseif (mb_strlen($diagnosis) > 60) {
        $error = 'Diagnosis must be 60 characters or fewer.';
    } else {
        $pdo->prepare(
            'UPDATE requests SET diagnosis = :diagnosis
             WHERE id = :id AND mechanic_id = :mechanic_id
             AND status IN ("assigned","in_progress")'
        )->execute([
            ':diagnosis'   => $diagnosis,
            ':id'          => $id,
            ':mechanic_id' => $mechanicId,
        ]);
        $message = 'Diagnosis saved successfully.';
    }
}

// Fetch job — must belong to this mechanic and be active
$stmt = $pdo->prepare(
    'SELECT r.*,
            u.full_name  AS user_name,   u.phone  AS user_phone,   u.email AS user_email,
            w.full_name  AS walkin_name, w.phone  AS walkin_phone, w.email AS walkin_email,
            w.address    AS walkin_address
     FROM requests r
     LEFT JOIN users            u ON r.user_id   = u.id
     LEFT JOIN walkin_customers w ON r.walkin_id  = w.id
     WHERE r.id = ? AND r.mechanic_id = ?
     AND r.status IN ("assigned","in_progress")'
);
$stmt->execute([$id, $mechanicId]);
$req = $stmt->fetch();

if (!$req) {
    header('Location: ' . getBasePath() . 'mechanic/dashboard.php');
    exit;
}

// Re-fetch diagnosis after save so textarea always shows latest value
if ($message !== '') {
    $fresh = $pdo->prepare('SELECT diagnosis FROM requests WHERE id = ?');
    $fresh->execute([$id]);
    $req['diagnosis'] = $fresh->fetchColumn();
}

$custName    = $req['user_name']      ?: ($req['walkin_name']  ?: '—');
$custPhone   = $req['user_phone']     ?: ($req['walkin_phone'] ?: '—');
$custEmail   = $req['user_email']     ?: ($req['walkin_email'] ?: '—');
$custAddress = $req['walkin_address'] ?? '';
$custType    = $req['user_name']      ? 'Online Customer' : ($req['walkin_name'] ? 'Walk-in' : '—');
$hasCoords   = !empty($req['latitude']) && !empty($req['longitude']);
$imgSrc      = !empty($req['image']) ? getBasePath() . $req['image'] : '';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
/* ── Grid ────────────────────────────────────────────────────────────────── */
.job-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}
.job-grid .full { grid-column: 1 / -1; }
@media(max-width:700px) {
    .job-grid { grid-template-columns: 1fr; }
    .job-grid .full { grid-column: 1; }
}

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
    color: var(--muted,#888);
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
.d-lbl { font-weight: 600; min-width: 110px; color: #666; flex-shrink: 0; }
.d-val { color: #222; word-break: break-word; flex: 1; }

/* ── Type badge ──────────────────────────────────────────────────────────── */
.type-badge {
    font-size: .72rem; font-weight: 700;
    padding: 2px 10px; border-radius: 10px; display: inline-block;
}
.badge-online { background: #fff3e8; color: var(--safety-orange,#ff6600); }
.badge-walkin { background: #e8f0ff; color: #3a5bbd; }

/* ── Photo thumbnail ─────────────────────────────────────────────────────── */
.photo-thumb-wrap {
    display: inline-block;
    position: relative;
    cursor: zoom-in;
}
.photo-thumb {
    width: 100px; height: 100px;
    object-fit: cover; border-radius: 8px;
    border: 2px solid #eee; display: block;
    transition: border-color .2s, transform .2s;
}
.photo-thumb:hover { border-color: var(--safety-orange,#ff6600); transform: scale(1.04); }
.thumb-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(0,0,0,.55); color: #fff;
    font-size: .6rem; font-weight: 700;
    padding: 2px 6px; border-radius: 4px;
    pointer-events: none;
}
.no-photo {
    width: 100px; height: 100px; border-radius: 8px;
    background: #f0f0f0; border: 2px dashed #ddd;
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
.lb-close:hover { background: var(--safety-orange,#ff6600); color: #fff; }

/* ── Diagnosis textarea ──────────────────────────────────────────────────── */
.diagnosis-area {
    width: 100%;
    min-height: 80px;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: .9rem;
    font-family: inherit;
    resize: vertical;
    box-sizing: border-box;
    transition: border-color .2s;
}
.diagnosis-area:focus { outline: none; border-color: var(--safety-orange,#ff6600); }
.diagnosis-area.over-limit { border-color: #d9534f; }

.char-counter {
    font-size: .78rem;
    color: var(--muted,#888);
    margin-top: 5px;
}
.char-counter.over { color: #d9534f; font-weight: 700; }

.diagnosis-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
}

/* ── Back link ───────────────────────────────────────────────────────────── */
.back-link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .85rem; color: var(--muted,#888);
    text-decoration: none; margin-bottom: 4px;
}
.back-link:hover { color: var(--safety-orange,#ff6600); }
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

    <a href="<?php echo getBasePath(); ?>mechanic/dashboard.php" class="back-link">
        ← Back to Dashboard
    </a>

    <div style="display:flex;justify-content:space-between;align-items:center;
                flex-wrap:wrap;gap:10px;margin-top:6px;">
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

    <?php if ($error): ?>
    <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;
                padding:12px 14px;border-radius:7px;margin-top:16px;">
        ⚠️ <?php echo e($error); ?>
    </div>
    <?php endif; ?>

    <div class="job-grid">

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
                <div class="d-row">
                    <span class="d-lbl">Problem</span>
                    <span class="d-val"><strong><?php echo e($req['problem_type'] ?: '—'); ?></strong></span>
                </div>
                <div class="d-row">
                    <span class="d-lbl">Description</span>
                    <span class="d-val" style="white-space:pre-wrap;"><?php echo e($req['description'] ?: '—'); ?></span>
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

        <!-- ── Diagnosis (full width) ─────────────────────────────────────── -->
        <div class="section-card full">
            <div class="section-card-head">📝 My Diagnosis</div>
            <div class="section-card-body">
                <p class="muted" style="margin:0 0 10px;">
                    Briefly describe what you found. Maximum 60 characters.
                </p>
                <form method="post" action="" onsubmit="return validateDiagnosis()">
                    <textarea
                        id="diagnosis-input"
                        name="diagnosis"
                        class="diagnosis-area"
                        maxlength="60"
                        placeholder="e.g. Front brake pads worn, replaced and fluid topped up."
                        oninput="updateCounter()"
                        required
                    ><?php echo e($req['diagnosis'] ?? ''); ?></textarea>
                    <div class="diagnosis-footer">
                        <span id="char-counter" class="char-counter">
                            <?php $cur = mb_strlen($req['diagnosis'] ?? ''); echo $cur; ?> / 60
                        </span>
                        <button type="submit" name="save_diagnosis" class="btn btn-primary">
                            💾 Save Diagnosis
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div><!-- /.job-grid -->

</div><!-- /.card -->
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

// ── Diagnosis character counter ───────────────────────────────────────────
const DIAG_MAX = 60;

function updateCounter() {
    const textarea = document.getElementById('diagnosis-input');
    const counter  = document.getElementById('char-counter');
    const len      = textarea.value.length;
    counter.textContent = len + ' / ' + DIAG_MAX;
    const over = len > DIAG_MAX;
    counter.classList.toggle('over', over);
    textarea.classList.toggle('over-limit', over);
}

function validateDiagnosis() {
    const textarea = document.getElementById('diagnosis-input');
    if (textarea.value.length > DIAG_MAX) {
        textarea.focus();
        return false;
    }
    return true;
}

// Run on load to set counter for pre-filled value
updateCounter();
</script>