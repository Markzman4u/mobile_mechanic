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

const LABEL_MAX_LENGTH = 100;

// ----------------------
// ADD NEW TAG
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tag'])) {
    $label      = trim((string) ($_POST['label'] ?? ''));
    $category   = $_POST['category'] ?? '';
    $isPositive = isset($_POST['is_positive']) ? 1 : 0;
    $sortOrder  = ctype_digit((string) ($_POST['sort_order'] ?? '')) ? (int) $_POST['sort_order'] : 0;

    if ($label === '') {
        $error = 'Tag label is required.';
    } elseif (mb_strlen($label) > LABEL_MAX_LENGTH) {
        $error = 'Tag label cannot exceed ' . LABEL_MAX_LENGTH . ' characters.';
    } elseif (!in_array($category, ['mechanic', 'service'], true)) {
        $error = 'Please select a valid category.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO feedback_tags (label, category, is_positive, sort_order) '
            . 'VALUES (:label, :category, :is_positive, :sort_order)'
        );
        $stmt->execute([
            ':label'       => $label,
            ':category'    => $category,
            ':is_positive' => $isPositive,
            ':sort_order'  => $sortOrder,
        ]);
        header('Location: ' . getBasePath() . 'admin/manage_tags.php?added=1');
        exit;
    }
}

// ----------------------
// UPDATE EXISTING TAG
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tag'])) {
    $tagId      = ctype_digit((string) ($_POST['tag_id'] ?? '')) ? (int) $_POST['tag_id'] : 0;
    $label      = trim((string) ($_POST['label'] ?? ''));
    $category   = $_POST['category'] ?? '';
    $isPositive = isset($_POST['is_positive']) ? 1 : 0;
    $isActive   = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder  = ctype_digit((string) ($_POST['sort_order'] ?? '')) ? (int) $_POST['sort_order'] : 0;

    if ($tagId <= 0) {
        $error = 'Invalid tag.';
    } elseif ($label === '') {
        $error = 'Tag label is required.';
    } elseif (mb_strlen($label) > LABEL_MAX_LENGTH) {
        $error = 'Tag label cannot exceed ' . LABEL_MAX_LENGTH . ' characters.';
    } elseif (!in_array($category, ['mechanic', 'service'], true)) {
        $error = 'Please select a valid category.';
    } else {
        $stmt = $pdo->prepare(
            'UPDATE feedback_tags '
            . 'SET label = :label, category = :category, is_positive = :is_positive, '
            . 'is_active = :is_active, sort_order = :sort_order '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            ':label'       => $label,
            ':category'    => $category,
            ':is_positive' => $isPositive,
            ':is_active'   => $isActive,
            ':sort_order'  => $sortOrder,
            ':id'          => $tagId,
        ]);
        header('Location: ' . getBasePath() . 'admin/manage_tags.php?updated=1');
        exit;
    }
}

if (isset($_GET['added'])) {
    $message = 'Tag added successfully.';
} elseif (isset($_GET['updated'])) {
    $message = 'Tag updated successfully.';
}

// Query all tags, grouped for display
$tags = $pdo->query(
    'SELECT id, label, category, is_positive, is_active, sort_order '
    . 'FROM feedback_tags '
    . 'ORDER BY category ASC, sort_order ASC, id ASC'
)->fetchAll();

$mechanicTags = array_filter($tags, static fn($t) => $t['category'] === 'mechanic');
$serviceTags  = array_filter($tags, static fn($t) => $t['category'] === 'service');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';

/**
 * Renders one tag table for a given category.
 *
 * @param array  $tagList
 * @param string $title
 */
function renderTagTable(array $tagList, string $title): void
{
    ?>
    <h3><?php echo e($title); ?></h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Label</th>
                <th>Type</th>
                <th>Sort Order</th>
                <th>Active</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tagList)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">No tags found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($tagList as $tag): ?>
                    <tr>
                        <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                            <td><?php echo e($tag['id']); ?></td>
                            <input type="hidden" name="tag_id" value="<?php echo e($tag['id']); ?>">
                            <input type="hidden" name="category" value="<?php echo e($tag['category']); ?>">
                            <td>
                                <input type="text" name="label" value="<?php echo e($tag['label']); ?>"
                                       maxlength="100" required style="width:160px;">
                            </td>
                            <td>
                                <label style="font-weight:normal;">
                                    <input type="checkbox" name="is_positive" value="1" <?php echo $tag['is_positive'] ? 'checked' : ''; ?>>
                                    Positive
                                </label>
                            </td>
                            <td>
                                <input type="number" name="sort_order" value="<?php echo e($tag['sort_order']); ?>"
                                       min="0" style="width:70px;">
                            </td>
                            <td>
                                <label style="font-weight:normal;">
                                    <input type="checkbox" name="is_active" value="1" <?php echo $tag['is_active'] ? 'checked' : ''; ?>>
                                </label>
                            </td>
                            <td>
                                <button type="submit" name="update_tag" value="1" class="btn btn-primary" style="padding:4px 10px;">Save</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}
?>
<main>
    <div class="card">
        <h2>Manage Feedback Tags</h2>
        <p class="muted">Add, edit, or disable the reason tags customers can select when leaving feedback.</p>

        <?php if ($message): ?>
            <div style="background:#e8f7e9;border:1px solid #8bc34a;color:#2f6627;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background:#fdecea;border:1px solid #f5c2c0;color:#a94442;padding:12px;border-radius:6px;margin-bottom:16px;">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <h3>Add New Tag</h3>
        <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="margin-bottom:24px;">
            <label>Label
                <input type="text" name="label" maxlength="100" required placeholder="e.g. Arrived late">
            </label>
            <label>Category
                <select name="category" required>
                    <option value="">-- Select --</option>
                    <option value="mechanic">Mechanic</option>
                    <option value="service">Service</option>
                </select>
            </label>
            <label style="font-weight:normal;">
                <input type="checkbox" name="is_positive" value="1" checked>
                Positive tag
            </label>
            <label>Sort Order
                <input type="number" name="sort_order" value="0" min="0" style="width:80px;">
            </label>
            <button type="submit" name="add_tag" value="1" class="btn btn-primary">Add Tag</button>
        </form>

        <?php renderTagTable($mechanicTags, 'Mechanic Tags'); ?>

        <div style="margin-top:24px;"></div>

        <?php renderTagTable($serviceTags, 'Service Tags'); ?>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';