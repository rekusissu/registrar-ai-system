// ============================================================
//  JS/LOGOUT.JS
//  Logout confirmation modal wiring.
//  Reads the logout URL from a data attribute on #logoutBtn
//  (set server-side so APP_ROOT is respected), then opens the
//  modal, handles cancel/confirm/backdrop/Escape, and navigates
//  to logout.php on confirm.
// ============================================================

(function () {
    'use strict';

    function init() {
        var logoutBtn = document.getElementById('logoutBtn');
        var logoutModal = document.getElementById('logoutModal');
        var logoutCancel = document.getElementById('logoutCancel');
        var logoutConfirm = document.getElementById('logoutConfirm');

        if (!logoutBtn || !logoutModal) return;

        // Resolve the logout destination. Prefer the server-rendered
        // data attribute; fall back to a same-directory logout.php.
        var logoutUrl = logoutBtn.getAttribute('data-logout-url') || 'logout.php';

        function openLogoutModal(e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            logoutModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeLogoutModal() {
            logoutModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function performLogout() {
            closeLogoutModal();
            window.location.href = logoutUrl;
        }

        logoutBtn.addEventListener('click', openLogoutModal);

        if (logoutCancel) {
            logoutCancel.addEventListener('click', closeLogoutModal);
        }

        if (logoutConfirm) {
            logoutConfirm.addEventListener('click', performLogout);
        }

        logoutModal.addEventListener('click', function (e) {
            if (e.target === this) closeLogoutModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && logoutModal.classList.contains('active')) {
                closeLogoutModal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();