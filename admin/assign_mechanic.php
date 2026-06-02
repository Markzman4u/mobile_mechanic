<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main>
    <div class="card">
        <h2>Assign Mechanic</h2>
        <p class="muted">Select an available mechanic and assign to the request.</p>
        
        <form method="post" style="background:#f9f9f9;padding:16px;border-radius:8px;">
            <label>Request ID <input type="text" name="request_id" placeholder="Enter request ID"></label>
            <label>Select Mechanic
                <select name="mechanic_id">
                    <option value="">-- Choose Mechanic --</option>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">Assign Mechanic</button>
            <a href="<?php echo getBasePath(); ?>admin/pending_requests.php" class="btn" style="margin-left:8px;">Cancel</a>
        </form>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php';
