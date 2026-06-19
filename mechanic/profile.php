<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireMechanicLogin();

$pdo        = getPDO();
$mechanicId = getMechanicId();
$error      = '';
$success    = '';

// Fetch mechanic
$stmt = $pdo->prepare('SELECT id, name, email, phone, address, profile_pic, status FROM mechanics WHERE id = ?');
$stmt->execute([$mechanicId]);
$mechanic = $stmt->fetch();

$uploadDir    = __DIR__ . '/../uploads/profile_pics/';
$uploadDbBase = 'uploads/profile_pics/';

// ── Handle photo upload ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_photo'])) {
    if (!isset($_FILES['profile_pic']) || $_FILES['profile_pic']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a photo to upload.';
    } elseif ($_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. Please try again.';
    } else {
        $file = $_FILES['profile_pic'];

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize      = 2 * 1024 * 1024; // 2 MB

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            $error = 'Only JPG, PNG, GIF, or WebP images are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Image must be smaller than 2 MB.';
        } else {
            $ext         = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = 'mech_' . $mechanicId . '_' . time() . '.' . strtolower($ext);

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
                // Delete old photo
                if (!empty($mechanic['profile_pic'])) {
                    $oldPath = __DIR__ . '/../' . ltrim($mechanic['profile_pic'], '/');
                    if (is_file($oldPath)) @unlink($oldPath);
                }

                $pdo->prepare('UPDATE mechanics SET profile_pic = ? WHERE id = ?')
                    ->execute([$uploadDbBase . $newFilename, $mechanicId]);

                $success = 'Profile photo updated successfully.';
                $stmt->execute([$mechanicId]);
                $mechanic = $stmt->fetch();
            } else {
                $error = 'Could not save the uploaded photo. Please try again.';
            }
        }
    }
}

// ── Handle photo removal ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    if (!empty($mechanic['profile_pic'])) {
        $oldPath = __DIR__ . '/../' . ltrim($mechanic['profile_pic'], '/');
        if (is_file($oldPath)) @unlink($oldPath);

        $pdo->prepare('UPDATE mechanics SET profile_pic = NULL WHERE id = ?')
            ->execute([$mechanicId]);

        $success = 'Profile photo removed.';
        $stmt->execute([$mechanicId]);
        $mechanic = $stmt->fetch();
    }
}

$avatarSrc = !empty($mechanic['profile_pic']) ? getBasePath() . $mechanic['profile_pic'] : '';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.profile-wrap { max-width: 600px; margin: 0 auto; }

.profile-section {
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 24px;
}
.profile-section-head {
    background: #f9f9f9;
    border-bottom: 1px solid #efefef;
    padding: 12px 20px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #888;
}
.profile-section-body { padding: 20px; }

/* ── Avatar ──────────────────────────────────────────────────────────────── */
.avatar-upload-wrap { text-align: center; }
.avatar-circle {
    width: 100px; height: 100px; border-radius: 50%;
    background: #f0f0f0; margin: 0 auto 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 2.6rem; overflow: hidden;
    border: 3px solid #eee;
    position: relative;
    cursor: pointer;
    transition: border-color .2s;
}
.avatar-circle:hover { border-color: var(--safety-orange, #ff6600); }
.avatar-circle img { width: 100%; height: 100%; object-fit: cover; }
.avatar-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.38);
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    opacity: 0;
    transition: opacity .2s;
    color: #fff;
    font-size: 1.4rem;
}
.avatar-circle:hover .avatar-overlay { opacity: 1; }
.avatar-file-input { display: none; }
.avatar-upload-hint { font-size: .75rem; color: #aaa; margin: 0 0 14px; }

/* ── Status badge ────────────────────────────────────────────────────────── */
.status-badge {
    display: inline-block;
    padding: 2px 12px;
    border-radius: 12px;
    font-size: .78rem;
    font-weight: 700;
    margin-bottom: 14px;
}
.status-available { background: #e7f5eb; color: #2dbd5e; }
.status-busy      { background: #fde7e7; color: #e74c3c; }

/* ── Read-only info fields ───────────────────────────────────────────────── */
.info-field { margin-bottom: 14px; }
.info-field:last-child { margin-bottom: 0; }
.info-field label {
    display: block; margin-bottom: 4px;
    font-weight: 600; font-size: .85rem; color: #444;
}
.info-field input,
.info-field textarea {
    width: 100%; padding: 10px 12px;
    border: 1px solid #d0d0d0; border-radius: 6px;
    font-size: .9rem; box-sizing: border-box;
    background: #f5f5f5; color: #999;
    cursor: not-allowed; font-family: inherit;
    resize: none;
}

/* ── Alerts ──────────────────────────────────────────────────────────────── */
.alert-error {
    background: #fde7e7; border: 1px solid #f5a3a3;
    border-left: 4px solid #e74c3c; color: #c0392b;
    padding: 12px 14px; border-radius: 6px; margin-bottom: 20px;
    font-size: .88rem;
}
.alert-success {
    background: #e7f5eb; border: 1px solid #7dd3a3;
    border-left: 4px solid #2dbd5e; color: #1e5e3f;
    padding: 12px 14px; border-radius: 6px; margin-bottom: 20px;
    font-size: .88rem;
}
</style>

<main>
<div class="card profile-wrap">
    <h2>My Profile</h2>
    <p class="muted">You can update your profile photo. All other details are managed by an administrator.</p>

    <?php if ($error): ?>
        <div class="alert-error"><strong>Error:</strong> <?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success">✅ <?php echo e($success); ?></div>
    <?php endif; ?>

    <!-- ── Profile Photo ─────────────────────────────────────────────────── -->
    <div class="profile-section">
        <div class="profile-section-head">Profile Photo</div>
        <div class="profile-section-body">
            <form method="POST" enctype="multipart/form-data" class="avatar-upload-wrap">
                <input type="hidden" name="update_photo" value="1">
                <input type="file" name="profile_pic" id="avatarInput" class="avatar-file-input"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       onchange="previewAvatar(this)">

                <div class="avatar-circle"
                     onclick="document.getElementById('avatarInput').click();"
                     title="Click to change photo">
                    <img id="avatarPreview"
                         src="<?php echo $avatarSrc ? e($avatarSrc) : ''; ?>"
                         alt="Profile photo"
                         style="<?php echo $avatarSrc ? '' : 'display:none;'; ?>">
                    <span id="avatarPlaceholder"
                          style="<?php echo $avatarSrc ? 'display:none;' : ''; ?>">👨‍🔧</span>
                    <div class="avatar-overlay">📷</div>
                </div>

                <span class="status-badge status-<?php echo e($mechanic['status']); ?>">
                    <?php echo ucfirst($mechanic['status']); ?>
                </span>

                <p class="avatar-upload-hint">Click photo to change · JPG, PNG, GIF or WebP · Max 2 MB</p>

                <button type="submit" class="btn btn-primary" id="uploadBtn" style="display:none;">
                    Upload Photo
                </button>
            </form>

            <?php if (!empty($mechanic['profile_pic'])): ?>
                <form method="POST" style="text-align:center;margin-top:10px;"
                      onsubmit="return confirm(<?php echo json_encode('Remove your profile photo?'); ?>);">
                    <button type="submit" name="remove_photo" value="1"
                            class="btn"
                            style="color:#c0392b;border:1px solid #f5a3a3;background:#fff;">
                        Remove Photo
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Account Information (read-only) ──────────────────────────────── -->
    <div class="profile-section">
        <div class="profile-section-head">Account Information</div>
        <div class="profile-section-body">
            <p class="muted" style="font-size:.8rem;margin:0 0 16px;">
                Contact your administrator to update any of these details.
            </p>

            <div class="info-field">
                <label>Full Name</label>
                <input type="text" value="<?php echo e($mechanic['name']); ?>" disabled>
            </div>
            <div class="info-field">
                <label>Email</label>
                <input type="email" value="<?php echo e($mechanic['email'] ?: '—'); ?>" disabled>
            </div>
            <div class="info-field">
                <label>Phone</label>
                <input type="tel" value="<?php echo e($mechanic['phone'] ?: '—'); ?>" disabled>
            </div>
            <div class="info-field">
                <label>Address</label>
                <textarea rows="3" disabled><?php echo e($mechanic['address'] ?: '—'); ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Account actions ───────────────────────────────────────────────── -->
    <div class="profile-section">
        <div class="profile-section-head">Account</div>
        <div class="profile-section-body">
            <a class="btn" href="<?php echo getBasePath(); ?>mechanic/logout.php">Log Out</a>
        </div>
    </div>

</div>
</main>

<script>
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        const preview     = document.getElementById('avatarPreview');
        const placeholder = document.getElementById('avatarPlaceholder');
        const uploadBtn   = document.getElementById('uploadBtn');
        preview.src               = e.target.result;
        preview.style.display     = '';
        placeholder.style.display = 'none';
        uploadBtn.style.display   = 'inline-block';
    };
    reader.readAsDataURL(input.files[0]);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>