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
$errors  = [];
$success = '';

// Fetch admin
$stmt = $pdo->prepare('SELECT full_name, email, phone, profile_pic, password FROM users WHERE id = ? AND role = "admin"');
$stmt->execute([$userId]);
$admin = $stmt->fetch();

if (!$admin) {
    header('Location: ' . getBasePath() . 'admin/dashboard.php');
    exit;
}

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    // ── Profile pic upload ─────────────────────────────────────────────────
    if ($section === 'avatar') {
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['profile_pic'];
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxBytes = 2 * 1024 * 1024;

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowed)) {
                $errors[] = 'Only JPG, PNG, GIF, and WebP images are allowed.';
            } elseif ($file['size'] > $maxBytes) {
                $errors[] = 'Image must be smaller than 2 MB.';
            } else {
                $uploadDir = __DIR__ . '/../uploads/profile_pics/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'admin_' . $userId . '_' . time() . '.' . strtolower($ext);
                $destPath = $uploadDir . $filename;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    if (!empty($admin['profile_pic'])) {
                        $oldPath = __DIR__ . '/../' . ltrim($admin['profile_pic'], '/');
                        if (file_exists($oldPath)) unlink($oldPath);
                    }
                    $dbPath = 'uploads/profile_pics/' . $filename;
                    $pdo->prepare('UPDATE users SET profile_pic = ? WHERE id = ? AND role = "admin"')
                        ->execute([$dbPath, $userId]);
                    $success = 'Profile photo updated successfully.';
                    $stmt->execute([$userId]);
                    $admin = $stmt->fetch();
                } else {
                    $errors[] = 'Failed to upload image. Please try again.';
                }
            }
        } else {
            $errors[] = 'Please select an image to upload.';
        }
    }

    // ── Remove profile pic ─────────────────────────────────────────────────
    if ($section === 'remove_avatar') {
        if (!empty($admin['profile_pic'])) {
            $oldPath = __DIR__ . '/../' . ltrim($admin['profile_pic'], '/');
            if (file_exists($oldPath)) unlink($oldPath);
        }
        $pdo->prepare('UPDATE users SET profile_pic = NULL WHERE id = ? AND role = "admin"')
            ->execute([$userId]);
        $success = 'Profile photo removed.';
        $stmt->execute([$userId]);
        $admin = $stmt->fetch();
    }

    // ── Profile (name, email, phone) ───────────────────────────────────────
    if ($section === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $phone    = trim($_POST['phone']     ?? '');

        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($email    === '') $errors[] = 'Email is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

        if (empty($errors) && $email !== $admin['email']) {
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $chk->execute([$email, $userId]);
            if ($chk->fetch()) $errors[] = 'That email is already in use by another account.';
        }

        if (empty($errors)) {
            try {
                $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ? AND role = "admin"')
                    ->execute([$fullName, $email, $phone, $userId]);
                $success = 'Profile updated successfully.';
                $stmt->execute([$userId]);
                $admin = $stmt->fetch();
            } catch (Exception $e) {
                $errors[] = 'An error occurred while updating profile.';
                error_log($e->getMessage());
            }
        }
    }

    // ── Password ───────────────────────────────────────────────────────────
    if ($section === 'password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password']     ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if ($currentPw === '')      $errors[] = 'Current password is required.';
        if ($newPw === '')          $errors[] = 'New password is required.';
        elseif (strlen($newPw) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($newPw !== $confirmPw)  $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            if (!password_verify($currentPw, $admin['password'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                try {
                    $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND role = "admin"')
                        ->execute([password_hash($newPw, PASSWORD_DEFAULT), $userId]);
                    $success = 'Password changed successfully.';
                    $stmt->execute([$userId]);
                    $admin = $stmt->fetch();
                } catch (Exception $e) {
                    $errors[] = 'An error occurred while changing password.';
                    error_log($e->getMessage());
                }
            }
        }
    }
}

$avatarSrc = !empty($admin['profile_pic']) ? getBasePath() . $admin['profile_pic'] : '';
$hasPhoto  = !empty($admin['profile_pic']);

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

.field-group { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
.field-group:last-of-type { margin-bottom: 0; }
.field-group label { font-weight: 600; font-size: .85rem; color: #444; }
.field-group input {
    width: 100%; padding: 10px 12px;
    border: 1px solid #d0d0d0; border-radius: 6px;
    font-size: .9rem; box-sizing: border-box;
    transition: border-color .15s;
}
.field-group input:focus { outline: none; border-color: var(--safety-orange, #ff6600); }
.field-group input:disabled { background: #f5f5f5; color: #999; cursor: not-allowed; }
.field-hint { font-size: .75rem; color: #aaa; margin: 2px 0 0; }

.alert-error {
    background: #fde7e7; border: 1px solid #f5a3a3;
    border-left: 4px solid #e74c3c; color: #c0392b;
    padding: 12px 14px; border-radius: 6px; margin-bottom: 20px;
    font-size: .88rem;
}
.alert-error ul { margin: 6px 0 0 16px; padding: 0; }
.alert-success {
    background: #e7f5eb; border: 1px solid #7dd3a3;
    border-left: 4px solid #2dbd5e; color: #1e5e3f;
    padding: 12px 14px; border-radius: 6px; margin-bottom: 20px;
    font-size: .88rem;
}

/* ── Avatar upload ───────────────────────────────────────────────────────── */
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
</style>

<main>
<div class="card profile-wrap">
    <h2>My Profile</h2>
    <p class="muted">Manage your account information and security.</p>

    <?php if (!empty($errors)): ?>
        <div class="alert-error">
            <?php if (count($errors) === 1): ?>
                <?php echo e($errors[0]); ?>
            <?php else: ?>
                <ul><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success">✅ <?php echo e($success); ?></div>
    <?php endif; ?>

    <!-- ── Profile Photo ─────────────────────────────────────────────────── -->
    <div class="profile-section">
        <div class="profile-section-head">Profile Photo</div>
        <div class="profile-section-body">

            <!-- Upload form -->
            <form method="POST" enctype="multipart/form-data" class="avatar-upload-wrap">
                <input type="hidden" name="section" value="avatar">
                <input type="file" name="profile_pic" id="avatarInput" class="avatar-file-input"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       onchange="previewAvatar(this)">

                <div class="avatar-circle" onclick="document.getElementById('avatarInput').click();"
                     title="Click to change photo">
                    <img id="avatarPreview"
                         src="<?php echo $avatarSrc ? e($avatarSrc) : ''; ?>"
                         alt="Profile photo"
                         style="<?php echo $avatarSrc ? '' : 'display:none;'; ?>">
                    <span id="avatarPlaceholder" style="<?php echo $avatarSrc ? 'display:none;' : ''; ?>">👤</span>
                    <div class="avatar-overlay">📷</div>
                </div>

                <p class="avatar-upload-hint">Click photo to change · JPG, PNG, GIF or WebP · Max 2 MB</p>

                <button type="submit" class="btn btn-primary" id="uploadBtn" style="display:none;">
                    Upload Photo
                </button>
            </form>

            <!-- Remove form — only shown when a photo exists -->
            <?php if ($hasPhoto): ?>
            <form method="POST" style="text-align:center;margin-top:10px;"
                  onsubmit="return confirm(<?php echo json_encode('Remove your profile photo?'); ?>);">
                <input type="hidden" name="section" value="remove_avatar">
                <button type="submit" class="btn"
                        style="color:#c0392b;border:1px solid #f5a3a3;background:#fff;">
                    Remove Photo
                </button>
            </form>
            <?php endif; ?>

        </div>
    </div>

    <!-- ── Account Information ───────────────────────────────────────────── -->
    <div class="profile-section">
        <div class="profile-section-head">Account Information</div>
        <div class="profile-section-body">
            <form method="POST">
                <input type="hidden" name="section" value="profile">

                <div class="field-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name"
                           value="<?php echo e($admin['full_name']); ?>" required>
                </div>

                <div class="field-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email"
                           value="<?php echo e($admin['email']); ?>" required>
                </div>

                <div class="field-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?php echo e($admin['phone'] ?? ''); ?>"
                           placeholder="+1234567890">
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:8px;">
                    Save Changes
                </button>
            </form>
        </div>
    </div>

    <!-- ── Change Password ───────────────────────────────────────────────── -->
    <div class="profile-section">
        <div class="profile-section-head">Change Password</div>
        <div class="profile-section-body">
            <form method="POST">
                <input type="hidden" name="section" value="password">

                <div class="field-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password"
                           placeholder="Enter your current password" required>
                </div>

                <div class="field-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password"
                           placeholder="At least 6 characters" required minlength="6">
                    <span class="field-hint">Minimum 6 characters.</span>
                </div>

                <div class="field-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password"
                           placeholder="Repeat new password" required>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:8px;">
                    Change Password
                </button>
            </form>
        </div>
    </div>

    <p style="text-align:center;margin-top:4px;">
        <a href="<?php echo getBasePath(); ?>admin/settings.php"
           style="font-size:.85rem;color:#aaa;text-decoration:underline;">← Back to Settings</a>
    </p>
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
        preview.src              = e.target.result;
        preview.style.display    = '';
        placeholder.style.display = 'none';
        uploadBtn.style.display  = 'inline-block';
    };
    reader.readAsDataURL(input.files[0]);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>