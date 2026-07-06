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
$message = '';
$error   = '';
$editing = null;

// Edit mode
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $stmt = $pdo->prepare(
        'SELECT id, name, phone, email, address, profile_pic, status, is_disabled
         FROM mechanics WHERE id = :id AND is_deleted = FALSE'
    );
    $stmt->execute([':id' => (int) $_GET['edit']]);
    $editing = $stmt->fetch();
    if (!$editing) {
        header('Location: ' . getBasePath() . 'admin/manage_mechanics.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mechanic_id = isset($_POST['mechanic_id']) ? (int) $_POST['mechanic_id'] : null;
    $name        = trim($_POST['name']    ?? '');
    $phone       = trim($_POST['phone']   ?? '');
    $email       = trim($_POST['email']   ?? '');
    $address     = trim($_POST['address'] ?? '');
    $password    = $_POST['password']     ?? '';

    if ($name === '') {
        $error = 'Mechanic name is required.';
    } elseif (!$mechanic_id && $email === '') {
        $error = 'Email is required for new mechanics.';
    } elseif (!$mechanic_id && $password === '') {
        $error = 'Password is required for new mechanics.';
    } else {
        // Handle profile pic upload
        $profile_pic = $editing['profile_pic'] ?? null;
        if (!empty($_FILES['profile_pic']['name'])) {
            // __DIR__ = .../admin/ — one level up reaches the project root
            $uploadDir = __DIR__ . '/../uploads/profile_pics/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext     = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed)) {
                $error = 'Profile picture must be JPG, PNG, or WEBP.';
            } elseif ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
                $error = 'Profile picture must be under 2MB.';
            } else {
                $filename    = 'mech_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $profile_pic = 'uploads/profile_pics/' . $filename;
                move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $filename);
            }
        }

        if (!$error) {
            try {
                if ($mechanic_id) {
                    // Update
                    $fields = 'name=:name, phone=:phone, email=:email, address=:address';
                    $params = [':name'=>$name,':phone'=>$phone,':email'=>$email,':address'=>$address,':id'=>$mechanic_id];

                    if ($password !== '') {
                        $fields .= ', password=:password';
                        $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    if ($profile_pic !== ($editing['profile_pic'] ?? null)) {
                        $fields .= ', profile_pic=:profile_pic';
                        $params[':profile_pic'] = $profile_pic;
                    }

                    $pdo->prepare("UPDATE mechanics SET $fields WHERE id = :id")
                        ->execute($params);

                    header('Location: ' . getBasePath() . 'admin/manage_mechanics.php?msg=updated');
                    exit;
                } else {
                    // Insert
                    $stmt = $pdo->prepare(
                        'INSERT INTO mechanics (name, phone, email, password, address, profile_pic, status)
                         VALUES (:name, :phone, :email, :password, :address, :profile_pic, "available")'
                    );
                    $stmt->execute([
                        ':name'        => $name,
                        ':phone'       => $phone,
                        ':email'       => $email,
                        ':password'    => password_hash($password, PASSWORD_DEFAULT),
                        ':address'     => $address,
                        ':profile_pic' => $profile_pic,
                    ]);

                    header('Location: ' . getBasePath() . 'admin/manage_mechanics.php?msg=created');
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Error saving mechanic: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 20px;
}
.form-grid .full { grid-column: 1 / -1; }
.avatar-preview {
    width: 80px; height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #eee;
    display: block;
    margin-bottom: 8px;
}
.avatar-placeholder {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: #f0f0f0;
    border: 3px dashed #ddd;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; color: #ccc;
    margin-bottom: 8px;
}
@media(max-width:600px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-grid .full { grid-column: 1; }
}
</style>

<main>
<div class="card">

    <a href="<?php echo getBasePath(); ?>admin/manage_mechanics.php"
       style="display:inline-flex;align-items:center;gap:6px;font-size:.85rem;
              color:var(--muted,#888);text-decoration:none;margin-bottom:12px;">
        ← Back to Manage Mechanics
    </a>

    <h2 style="margin:0 0 4px 0;"><?php echo $editing ? 'Edit Mechanic' : 'Register New Mechanic'; ?></h2>
    <p class="muted" style="margin:0 0 20px 0;">
        <?php echo $editing ? 'Update mechanic information.' : 'Create a new mechanic account. They can log in via the Mechanic Portal.'; ?>
    </p>

    <?php if ($message): ?>
    <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;
                padding:12px;border-radius:6px;margin-bottom:16px;">
        ✅ <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;
                padding:12px;border-radius:6px;margin-bottom:16px;">
        ⚠️ <?php echo e($error); ?>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data"
          style="background:#f9f9f9;padding:20px;border-radius:10px;">

        <?php if ($editing): ?>
            <input type="hidden" name="mechanic_id" value="<?php echo $editing['id']; ?>">
        <?php endif; ?>

        <!-- Profile picture -->
        <div style="margin-bottom:20px;">
            <label style="display:block;font-weight:600;margin-bottom:8px;">Profile Picture</label>
            <?php if (!empty($editing['profile_pic'])): ?>
                <img src="<?php echo getBasePath() . e($editing['profile_pic']); ?>"
                     alt="" class="avatar-preview" id="avatarPreview">
            <?php else: ?>
                <div class="avatar-placeholder" id="avatarPlaceholder">👤</div>
                <img src="" alt="" class="avatar-preview" id="avatarPreview"
                     style="display:none;">
            <?php endif; ?>
            <input type="file" name="profile_pic" accept="image/jpeg,image/png,image/webp"
                   onchange="previewAvatar(this)"
                   style="font-size:.85rem;margin-top:4px;">
            <p class="muted" style="font-size:.78rem;margin:4px 0 0;">JPG, PNG or WEBP · max 2MB</p>
        </div>

        <div class="form-grid">

            <label>Full Name *
                <input type="text" name="name" required
                       placeholder="e.g. Ahmed Hassan"
                       value="<?php echo e($editing['name'] ?? ($_POST['name'] ?? '')); ?>">
            </label>

            <label>Phone
                <input type="text" name="phone"
                       placeholder="e.g. +252 61 234 5678"
                       value="<?php echo e($editing['phone'] ?? ($_POST['phone'] ?? '')); ?>">
            </label>

            <label>Email <?php echo !$editing ? '*' : ''; ?>
                <input type="email" name="email"
                       placeholder="mechanic@example.com"
                       value="<?php echo e($editing['email'] ?? ($_POST['email'] ?? '')); ?>"
                       <?php echo !$editing ? 'required' : ''; ?>>
            </label>

            <label>
                <?php echo $editing ? 'New Password <span class="muted" style="font-weight:400;font-size:.8rem;">(leave blank to keep current)</span>' : 'Password *'; ?>
                <input type="password" name="password"
                       placeholder="<?php echo $editing ? 'Leave blank to keep current' : 'Set a strong password'; ?>"
                       <?php echo !$editing ? 'required' : ''; ?>>
            </label>

            <label class="full">Address
                <textarea name="address" rows="2"
                          placeholder="e.g. Hargeisa, Woqooyi Galbeed"><?php echo e($editing['address'] ?? ($_POST['address'] ?? '')); ?></textarea>
            </label>

        </div>

        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">
                <?php echo $editing ? 'Save Changes' : 'Register Mechanic'; ?>
            </button>
            <a href="<?php echo getBasePath(); ?>admin/manage_mechanics.php" class="btn">Cancel</a>
        </div>

    </form>
</div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var preview     = document.getElementById('avatarPreview');
        var placeholder = document.getElementById('avatarPlaceholder');
        preview.src     = e.target.result;
        preview.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
}
</script>