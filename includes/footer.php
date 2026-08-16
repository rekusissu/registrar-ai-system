<?php
// ============================================================
//  FOOTER.PHP  (includes/)
//  Reusable page footer with scripts and closing tags.
//  Include this at the bottom of every page.
//
//  Usage:
//    include 'includes/footer.php';
// ============================================================

// Add any page-specific scripts
$page_scripts = $page_scripts ?? [];
?>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Common Scripts -->
    <script src="<?= $APP_ROOT ?>js/sidebar.js"></script>
    <script src="<?= $APP_ROOT ?>js/auth.js"></script>
    <script src="<?= $APP_ROOT ?>js/logout.js"></script>

    <!-- Chart.js (if needed) -->
    <?php if (isset($use_chart) && $use_chart === true): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>

    <!-- Page-specific scripts -->
    <?php foreach ($page_scripts as $script): ?>
        <script src="<?= $APP_ROOT ?>js/<?= $script ?>"></script>
    <?php endforeach; ?>

    <!-- Custom page scripts -->
    <?php if (isset($inline_scripts)): ?>
        <script>
            <?= $inline_scripts ?>
        </script>
    <?php endif; ?>

    <!-- Student AI chat widget (student role only) -->
    <?php if (($_SESSION['role'] ?? '') === 'student'): ?>
        <?php include __DIR__ . '/student-chat.php'; ?>
    <?php endif; ?>

</body>
</html>