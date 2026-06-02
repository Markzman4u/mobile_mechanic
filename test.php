<?php
require_once __DIR__ . '/includes/functions.php';
$baseUrl = getBasePath();
?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Style Verification - Mobile Mechanic</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>assets/css/style.css">
    <style>
        .check { color: green; font-weight: bold; }
        .cross { color: red; font-weight: bold; }
        .test-section { margin: 20px 0; padding: 12px; background: #f9f9f9; border-left: 4px solid #ff6600; }
        code { background: #f0f0f0; padding: 4px 8px; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <h1>Mobile Mechanic - Style Check</h1>
</header>

<main>
    <div class="card">
        <h2>Styling Verification Report</h2>
        
        <div class="test-section">
            <h3>System Info</h3>
            <p><strong>Base URL:</strong> <code><?php echo htmlspecialchars($baseUrl); ?></code></p>
            <p><strong>CSS URL:</strong> <code><?php echo htmlspecialchars($baseUrl . 'assets/css/style.css'); ?></code></p>
        </div>

        <div class="test-section">
            <h3>Visual Tests</h3>
            <p><span class="check">✓</span> Dark charcoal header with white text (header above)</p>
            <p><span class="check">✓</span> Light gray page background</p>
            <p><span class="check">✓</span> White main content card</p>
            <p><span class="check">✓</span> Rounded corners on elements</p>
        </div>

        <div class="test-section">
            <h3>Component Tests</h3>
            <p>
                <button class="btn btn-primary">Primary Button (Orange)</button>
                <button class="btn" style="margin-left:8px;">Secondary Button</button>
            </p>
            <p style="margin-top:12px;">
                <span class="status status-pending">Pending</span>
                <span class="status status-assigned">Assigned</span>
                <span class="status status-in_progress">In Progress</span>
                <span class="status status-completed">Completed</span>
            </p>
        </div>

        <div class="test-section">
            <h3>Page Links (Verify Each)</h3>
            <ul>
                <li><a href="<?php echo $baseUrl; ?>">Home / Index</a></li>
                <li><a href="<?php echo $baseUrl; ?>auth/login.php">Login</a></li>
                <li><a href="<?php echo $baseUrl; ?>auth/register.php">Register</a></li>
                <li><a href="<?php echo $baseUrl; ?>customer/dashboard.php">Customer Dashboard</a></li>
                <li><a href="<?php echo $baseUrl; ?>customer/request_service.php">Request Service</a></li>
                <li><a href="<?php echo $baseUrl; ?>customer/track_service.php">Track Service</a></li>
                <li><a href="<?php echo $baseUrl; ?>admin/dashboard.php">Admin Dashboard</a></li>
                <li><a href="<?php echo $baseUrl; ?>admin/pending_requests.php">Pending Requests</a></li>
                <li><a href="<?php echo $baseUrl; ?>admin/manage_mechanics.php">Manage Mechanics</a></li>
            </ul>
        </div>

        <div class="test-section">
            <h3>Form Elements Test</h3>
            <form style="margin:0;">
                <label>Text Input: <input type="text" placeholder="Test"></label>
                <label>Email: <input type="email" placeholder="test@example.com"></label>
                <label>Select: <select><option>Option 1</option></select></label>
                <label>Textarea: <textarea rows="3" placeholder="Test textarea"></textarea></label>
            </form>
        </div>

        <div class="test-section">
            <h3>Table Test</h3>
            <table>
                <thead><tr><th>ID</th><th>Name</th><th>Status</th></tr></thead>
                <tbody>
                    <tr><td>1</td><td>Request A</td><td><span class="status status-pending">Pending</span></td></tr>
                    <tr><td>2</td><td>Request B</td><td><span class="status status-completed">Completed</span></td></tr>
                </tbody>
            </table>
        </div>

        <div class="test-section">
            <h3>Notes</h3>
            <p class="muted">If all above elements appear styled (colors, rounded corners, shadows, buttons orange), then CSS is loading correctly on all pages.</p>
        </div>
    </div>
</main>

<footer>
    <p>&copy; 2026 Mobile Mechanic</p>
</footer>
</body>
</html>
