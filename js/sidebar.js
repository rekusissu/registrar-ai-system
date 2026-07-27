// ============================================================
//  SIDEBAR.JS
//  Handles sidebar toggle, collapse, and mobile overlay
// ============================================================

(function() {
    'use strict';

    // ── DOM Elements ──
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarCollapse = document.getElementById('sidebarCollapse');

    // ── Toggle Sidebar (Mobile) ──
    function toggleSidebar() {
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('show');
        }
    }

    // ── Close Sidebar ──
    function closeSidebar() {
        if (sidebar) {
            sidebar.classList.remove('open');
        }
        if (sidebarOverlay) {
            sidebarOverlay.classList.remove('show');
        }
    }

    // ── Toggle Collapse (Desktop) ──
    function toggleCollapse() {
        if (!sidebar) return;
        sidebar.classList.toggle('collapsed');
        
        // Update CSS variables for main content
        const isCollapsed = sidebar.classList.contains('collapsed');
        const root = document.documentElement;
        if (isCollapsed) {
            root.style.setProperty('--sidebar-width', '72px');
        } else {
            root.style.setProperty('--sidebar-width', '260px');
        }
        
        // Store preference
        try {
            localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
        } catch (e) {
            // ignore
        }
    }

    // ── Restore Collapse State ──
    function restoreCollapseState() {
        if (!sidebar) return;
        try {
            const collapsed = localStorage.getItem('sidebarCollapsed');
            if (collapsed === '1') {
                sidebar.classList.add('collapsed');
                document.documentElement.style.setProperty('--sidebar-width', '72px');
            }
        } catch (e) {
            // ignore
        }
    }

    // ── Event Listeners ──

    // Sidebar Toggle Button (Mobile)
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Sidebar Overlay (close on click)
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    // Collapse Button (Desktop)
    if (sidebarCollapse) {
        sidebarCollapse.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleCollapse();
        });
    }

    // Close sidebar on resize to desktop
    let lastWidth = window.innerWidth;
    window.addEventListener('resize', function() {
        const currentWidth = window.innerWidth;
        if (currentWidth > 768 && lastWidth <= 768) {
            closeSidebar();
        }
        lastWidth = currentWidth;
    });

    // ── Keyboard shortcut: Ctrl+B to toggle sidebar ──
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
            e.preventDefault();
            if (window.innerWidth <= 768) {
                toggleSidebar();
            } else {
                toggleCollapse();
            }
        }
    });

    // ── Initialize ──
    restoreCollapseState();

})();
