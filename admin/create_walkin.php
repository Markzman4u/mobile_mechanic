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
$error = '';

$vehicleMakes = [
    'Toyota','Honda','Nissan','Hyundai','Ford','Mitsubishi',
    'Suzuki','Kia','BMW','Mercedes-Benz','Mazda','Subaru',
    'Volkswagen','Chevrolet','Isuzu','Other'
];

// Exclude disabled and soft-deleted mechanics
$mechanics = $pdo->query(
    'SELECT id, name FROM mechanics
     WHERE status = "available"
     AND is_disabled = FALSE
     AND is_deleted  = FALSE
     ORDER BY name ASC'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name         = trim($_POST['full_name']         ?? '');
    $phone             = trim($_POST['phone']             ?? '');
    $email             = trim($_POST['email']             ?? '');
    $address           = trim($_POST['address']           ?? '');
    $date_of_birth     = trim($_POST['date_of_birth']     ?? '');
    $gender            = $_POST['gender']                 ?? '';
    $vehicle_make      = trim($_POST['vehicle_make']      ?? '');
    $vehicle_make_other= trim($_POST['vehicle_make_other']?? '');
    $vehicle_model     = trim($_POST['vehicle_model']     ?? '');
    $vehicle_year      = trim($_POST['vehicle_year']      ?? '');
    $problem_type      = trim($_POST['problem_type']      ?? '');
    $description       = trim($_POST['description']       ?? '');
    $mechanic_id       = (int) ($_POST['mechanic_id']     ?? 0);

    // Gender restricted to DB enum
    if (!in_array($gender, ['male', 'female'])) {
        $gender = '';
    }

    // Resolve vehicle make
    $resolved_make = ($vehicle_make === 'Other' && $vehicle_make_other !== '')
                     ? $vehicle_make_other
                     : ($vehicle_make !== '' ? $vehicle_make : null);

    $vehicle_year_val = ($vehicle_year !== '' && ctype_digit($vehicle_year)
                         && (int)$vehicle_year >= 1900 && (int)$vehicle_year <= (int)date('Y') + 1)
                        ? (int)$vehicle_year : null;

    if ($full_name === '' || $problem_type === '') {
        $error = 'Full name and problem type are required.';
    } elseif ($date_of_birth === '') {
        $error = 'Date of birth is required.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $error = 'Please enter a valid date of birth.';
    } elseif ((new DateTime($date_of_birth)) > new DateTime()) {
        $error = 'Date of birth cannot be in the future.';
    } elseif ($gender === '') {
        $error = 'Gender is required.';
    } elseif ($vehicle_make === 'Other' && $vehicle_make_other === '') {
        $error = 'Please enter the vehicle make name.';
    } elseif ($mechanic_id <= 0) {
        $error = 'Please assign an available mechanic.';
    } else {
        try {
            // Create walk-in customer record (with vehicle info)
            $stmt = $pdo->prepare(
                'INSERT INTO walkin_customers
                   (full_name, phone, email, address, date_of_birth, gender,
                    vehicle_make, vehicle_make_other, vehicle_model, vehicle_year)
                 VALUES
                   (:full_name, :phone, :email, :address, :date_of_birth, :gender,
                    :vehicle_make, :vehicle_make_other, :vehicle_model, :vehicle_year)'
            );
            $stmt->execute([
                ':full_name'          => $full_name,
                ':phone'              => $phone,
                ':email'              => $email,
                ':address'            => $address,
                ':date_of_birth'      => $date_of_birth,
                ':gender'             => $gender,
                ':vehicle_make'       => $resolved_make,
                ':vehicle_make_other' => ($vehicle_make === 'Other' ? $vehicle_make_other : null),
                ':vehicle_model'      => $vehicle_model !== '' ? $vehicle_model : null,
                ':vehicle_year'       => $vehicle_year_val,
            ]);
            $walkinId = $pdo->lastInsertId();

            // Create service request — directly assigned with mechanic
            $stmt = $pdo->prepare(
                'INSERT INTO requests
                   (walkin_id, mechanic_id,
                    vehicle_make, vehicle_make_other, vehicle_model, vehicle_year,
                    problem_type, description, status, created_at)
                 VALUES
                   (:walkin_id, :mechanic_id,
                    :vehicle_make, :vehicle_make_other, :vehicle_model, :vehicle_year,
                    :problem_type, :description, :status, NOW())'
            );
            $stmt->execute([
                ':walkin_id'          => $walkinId,
                ':mechanic_id'        => $mechanic_id,
                ':vehicle_make'       => $resolved_make,
                ':vehicle_make_other' => ($vehicle_make === 'Other' ? $vehicle_make_other : null),
                ':vehicle_model'      => $vehicle_model !== '' ? $vehicle_model : null,
                ':vehicle_year'       => $vehicle_year_val,
                ':problem_type'       => $problem_type,
                ':description'        => $description,
                ':status'             => 'assigned',
            ]);

            // Mark mechanic as busy
            $pdo->prepare('UPDATE mechanics SET status = "busy" WHERE id = :id')
                ->execute([':id' => $mechanic_id]);

            header('Location: ' . getBasePath() . 'admin/active_jobs.php');
            exit;

        } catch (Exception $e) {
            $error = 'Error creating walk-in request: ' . $e->getMessage();
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
.form-grid label {
    display: flex;
    flex-direction: column;
}
.form-grid label input,
.form-grid label select,
.form-grid label textarea {
    margin-top: 4px;
}
#makeOtherWrap { display:none; margin-top:8px; }
@media(max-width:600px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-grid .full { grid-column: 1; }
}
</style>
<main>
    <div class="card">
        <h2>Register Walk-in Customer</h2>
        <p class="muted">Creates a service request and immediately assigns a mechanic — no pending step needed.</p>

        <?php if ($error): ?>
            <div style="background:#fff4f4;border:1px solid #f5c6cb;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" style="background:#f9f9f9;padding:16px;border-radius:8px;">

            <!-- ── Customer Information ───────────────────────────────────── -->
            <h3>Customer Information</h3>
            <div class="form-grid">
                <label class="full">Full Name <input type="text" name="full_name" placeholder="Enter customer name" value="<?php echo e($full_name ?? ''); ?>" required></label>
                <label class="full">Phone <input type="text" name="phone" placeholder="Enter phone number" value="<?php echo e($phone ?? ''); ?>" pattern="[0-9+\-\s()]+" title="Enter a valid phone number"></label>
                <label class="full">Email <input type="email" name="email" placeholder="Enter email (optional)" value="<?php echo e($email ?? ''); ?>"></label>
                <label class="full">Address <textarea name="address" placeholder="Enter address" rows="3"><?php echo e($address ?? ''); ?></textarea></label>

                <label>Date of Birth
                    <input type="date" name="date_of_birth"
                           max="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo e($date_of_birth ?? ''); ?>" required>
                </label>
                <label>Gender
                    <select name="gender" required>
                        <option value="" disabled <?php echo empty($gender) ? 'selected' : ''; ?>>-- Select Gender --</option>
                        <option value="male"   <?php echo (isset($gender) && $gender === 'male')   ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo (isset($gender) && $gender === 'female') ? 'selected' : ''; ?>>Female</option>
                    </select>
                </label>
            </div>

            <!-- ── Vehicle Information ────────────────────────────────────── -->
            <h3 style="margin-top:20px;">Vehicle Information</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 120px;gap:12px;align-items:start;">

                <label style="margin:0;">Make
                    <select name="vehicle_make" id="vehicleMakeSelect" onchange="toggleOtherMake(this)">
                        <option value="">-- Select make --</option>
                        <?php
                        $selMake = $vehicle_make ?? '';
                        foreach ($vehicleMakes as $m) {
                            $selected = ($selMake === $m) ? ' selected' : '';
                            echo '<option value="' . e($m) . '"' . $selected . '>' . e($m) . '</option>';
                        }
                        ?>
                    </select>
                    <div id="makeOtherWrap">
                        <input type="text" name="vehicle_make_other" id="vehicleMakeOther"
                               placeholder="Enter make name"
                               value="<?php echo e($vehicle_make_other ?? ''); ?>">
                    </div>
                </label>

                <label style="margin:0;">Model
                    <input type="text" name="vehicle_model" placeholder="e.g. Camry, Civic, F-150"
                           value="<?php echo e($vehicle_model ?? ''); ?>">
                </label>

                <label style="margin:0;">Year
                    <input type="number" name="vehicle_year" placeholder="e.g. 2019"
                           min="1900" max="<?php echo (int)date('Y') + 1; ?>"
                           value="<?php echo e($vehicle_year ?? ''); ?>">
                </label>

            </div>

            <!-- ── Service Request ────────────────────────────────────────── -->
            <h3 style="margin-top:20px;">Service Request</h3>
            <label>Problem Type
                <select name="problem_type" required>
                    <option value="">-- Select Problem --</option>
                    <?php
                    $problems = ['Flat Tire', 'Dead Battery', 'Brake Problem', 'Oil Leak', 'Fuel Delivery', 'Engine Overheating', 'Other'];
                    foreach ($problems as $p):
                    ?>
                        <option value="<?php echo e($p); ?>" <?php echo (isset($problem_type) && $problem_type === $p) ? 'selected' : ''; ?>>
                            <?php echo e($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Description <textarea name="description" placeholder="Describe the problem" rows="3"><?php echo e($description ?? ''); ?></textarea></label>

            <!-- ── Assign Mechanic ────────────────────────────────────────── -->
            <h3 style="margin-top:20px;">Assign Mechanic</h3>
            <?php if (empty($mechanics)): ?>
                <div style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:10px;border-radius:6px;margin-bottom:12px;">
                    ⚠️ No available mechanics right now. Please free up a mechanic before registering a walk-in.
                </div>
            <?php endif; ?>
            <label>Available Mechanic
                <select name="mechanic_id" required <?php echo empty($mechanics) ? 'disabled' : ''; ?>>
                    <option value="">-- Select Mechanic --</option>
                    <?php foreach ($mechanics as $mech): ?>
                        <option value="<?php echo $mech['id']; ?>" <?php echo (isset($mechanic_id) && $mechanic_id == $mech['id']) ? 'selected' : ''; ?>>
                            <?php echo e($mech['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="margin-top:16px;">
                <button class="btn btn-primary" type="submit" <?php echo empty($mechanics) ? 'disabled' : ''; ?>>
                    Create &amp; Assign
                </button>
                <a href="<?php echo getBasePath(); ?>admin/dashboard.php" class="btn" style="margin-left:8px;">Cancel</a>
            </div>
        </form>
    </div>
</main>

<script>
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
// Restore on page reload after validation error
(function () {
    var sel = document.getElementById('vehicleMakeSelect');
    if (sel && sel.value === 'Other') toggleOtherMake(sel);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>