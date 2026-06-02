<?php if (!empty($sidebarOpened)): ?>
</div>
<?php endif; ?>
<footer>
    <p>&copy; <?php echo date('Y'); ?> Mobile Mechanic</p>
</footer>
<?php 
if (!function_exists('getBasePath')) {
    require_once __DIR__ . '/functions.php';
}
?>
<script src="<?php echo getBasePath(); ?>assets/js/script.js"></script>
</body>
</html>
