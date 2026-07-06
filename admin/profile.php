<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . getBasePath() . 'customer/profile.php');
    exit;
}

$pdo    = getPDO();
$userId = $_SESSION['user_id'];
$error   = '';
$success = '';

// Fetch admin
$stmt = $pdo->prepare('SELECT full_name, email, phone, profile_pic FROM users WHERE id = ? AND role = "admin"');
$stmt->execute([$userId]);
$admin = $stmt->fetch();

if (!$admin) {
    header('Location: ' . getBasePath() . 'admin/dashboard.php');
    exit;
}

// ── Handle photo upload ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_photo'])) {
    if (!isset($_FILES['profile_pic']) || $_FILES['profile_pic']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a photo to upload.';
    } elseif ($_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. Please try again.';
    } else {
        $file = $_FILES['profile_pic'];

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize      = 2 * 1024 * 1024;

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            $error = 'Only JPG, PNG, GIF, or WebP images are allowed.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Image must be smaller than 2 MB.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/profile_pics/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $ext         = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = 'admin_' . $userId . '_' . time() . '.' . strtolower($ext);

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
                if (!empty($admin['profile_pic'])) {
                    $oldPath = __DIR__ . '/../' . ltrim($admin['profile_pic'], '/');
                    if (is_file($oldPath)) @unlink($oldPath);
                }

                $pdo->prepare('UPDATE users SET profile_pic = ? WHERE id = ? AND role = "admin"')
                    ->execute(['uploads/profile_pics/' . $newFilename, $userId]);

                $success = 'Profile photo updated successfully.';
                $stmt->execute([$userId]);
                $admin = $stmt->fetch();
            } else {
                $error = 'Could not save the uploaded photo. Please try again.';
            }
        }
    }
}

// ── Handle photo removal ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    if (!empty($admin['profile_pic'])) {
        $oldPath = __DIR__ . '/../' . ltrim($admin['profile_pic'], '/');
        if (is_file($oldPath)) @unlink($oldPath);

        $pdo->prepare('UPDATE users SET profile_pic = NULL WHERE id = ? AND role = "admin"')
            ->execute([$userId]);

        $success = 'Profile photo removed.';
        $stmt->execute([$userId]);
        $admin = $stmt->fetch();
    }
}

$avatarSrc = !empty($admin['profile_pic']) ? getBasePath() . $admin['profile_pic'] : '';

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

/* ── Read-only info fields ───────────────────────────────────────────────── */
.info-field { margin-bottom: 14px; }
.info-field:last-child { margin-bottom: 0; }
.info-field label {
    display: block; margin-bottom: 4px;
    font-weight: 600; font-size: .85rem; color: #444;
}
.info-field input {
    width: 100%; padding: 10px 12px;
    border: 1px solid #d0d0d0; border-radius: 6px;
    font-size: .9rem; box-sizing: border-box;
    background: #f5f5f5; color: #999;
    cursor: not-allowed;
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
    <p class="muted">You can update your profile photo. All other details are managed by the super admin.</p>

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
                          style="<?php echo $avatarSrc ? 'display:none;' : ''; ?>">👤</span>
                    <div class="avatar-overlay">📷</div>
                </div>

                <p class="avatar-upload-hint">Click photo to change · JPG, PNG, GIF or WebP · Max 2 MB</p>

                <button type="submit" class="btn btn-primary" id="uploadBtn" style="display:none;">
                    Upload Photo
                </button>
            </form>

            <?php if (!empty($admin['profile_pic'])): ?>
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
                Contact the super admin to update any of these details.
            </p>

            <div class="info-field">
                <label>Full Name</label>
                <input type="text" value="<?php echo e($admin['full_name']); ?>" disabled>
            </div>
            <div class="info-field">
                <label>Email Address</label>
                <input type="email" value="<?php echo e($admin['email']); ?>" disabled>
            </div>
            <div class="info-field">
                <label>Phone Number</label>
                <input type="tel" value="<?php echo e($admin['phone'] ?: '—'); ?>" disabled>
            </div>
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