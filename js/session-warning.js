/**
 * session-warning.js
 * Idle-session warning for the BCP Registrar System.
 *
 * Uses BcpSessionTimeout (seconds, from includes/footer.php) and
 * BcpAppRoot (relative app path). After (timeout - 180s) of no
 * user activity a modal warns the user; "Stay signed in" calls
 * api/keepalive.php which refreshes last_activity server-side.
 * If the user ignores the warning, they are sent to login.
 */
(function () {
    'use strict';

    var TIMEOUT = (typeof window.BcpSessionTimeout === 'number' && window.BcpSessionTimeout > 60)
        ? window.BcpSessionTimeout
        : 1200;
    var APP_ROOT = (typeof window.BcpAppRoot === 'string' && window.BcpAppRoot)
        ? window.BcpAppRoot
        : './';
    var WARN_LEAD = 180; // seconds before hard expiry
    var TICK_MS = 5000;

    var lastActivity = Date.now();
    var warned = false;
    var remaining = WARN_LEAD;
    var ticker = null;
    var countdownTicker = null;
    var modal = null;
    var countdownEl = null;
    var stayBtn = null;

    var warnedOnce = false;

    function buildModal() {
        modal = document.createElement('div');
        modal.id = 'sessionWarningModal';
        modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(15,23,42,0.45);backdrop-filter:blur(3px);z-index:99999;align-items:center;justify-content:center;padding:20px;';
        modal.addEventListener('click', function (e) { if (e.target === modal) hideWarning(); });

        var card = document.createElement('div');
        card.style.cssText = 'background:#fff;border-radius:18px;padding:26px 28px;max-width:420px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,0.2);text-align:center;';

        var icon = document.createElement('div');
        icon.innerHTML = '<i class="fas fa-clock" style="font-size:34px;color:#d97706;"></i>';
        card.appendChild(icon);

        var title = document.createElement('h3');
        title.textContent = 'Your session is about to expire';
        title.style.cssText = 'margin:14px 0 6px;font-size:17px;font-weight:700;color:#0f172a;';
        card.appendChild(title);

        var msg = document.createElement('p');
        msg.textContent = 'You have been idle for a while. For your security, you will be logged out in:';
        msg.style.cssText = 'margin:0 0 12px;font-size:13px;color:#64748b;line-height:1.5;';
        card.appendChild(msg);

        countdownEl = document.createElement('div');
        countdownEl.style.cssText = 'font-size:28px;font-weight:800;color:#dc2626;margin-bottom:18px;';
        card.appendChild(countdownEl);

        stayBtn = document.createElement('button');
        stayBtn.type = 'button';
        stayBtn.className = 'btn btn-primary';
        stayBtn.innerHTML = '<i class="fas fa-user-check"></i> Stay signed in';
        stayBtn.addEventListener('click', staySignedIn);
        card.appendChild(stayBtn);

        var logoutLink = document.createElement('a');
        logoutLink.href = APP_ROOT + 'logout.php';
        logoutLink.textContent = 'Log out now';
        logoutLink.style.cssText = 'display:block;margin-top:12px;font-size:12px;color:#94a3b8;text-decoration:underline;cursor:pointer;';
        card.appendChild(logoutLink);

        modal.appendChild(card);
        document.body.appendChild(modal);
    }

    function showWarning() {
        if (warned) return;
        warned = true;
        warnedOnce = true;
        if (!modal) buildModal();
        modal.style.display = 'flex';
        remaining = WARN_LEAD;
        renderCountdown();
        clearInterval(countdownTicker);
        countdownTicker = setInterval(function () {
            remaining--;
            renderCountdown();
            if (remaining <= 0) {
                clearInterval(countdownTicker);
                window.location.href = APP_ROOT + 'login.php?timeout=1';
            }
        }, 1000);
    }

    function renderCountdown() {
        if (countdownEl) countdownEl.textContent = Math.max(remaining, 0) + ' seconds';
    }

    function hideWarning() {
        warned = false;
        clearInterval(countdownTicker);
        if (modal) modal.style.display = 'none';
    }

    function staySignedIn() {
        stayBtn.disabled = true;
        stayBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Keeping session...';
        fetch(APP_ROOT + 'api/keepalive.php')
            .then(function (res) {
                if (!res.ok) throw new Error('expired');
                return res.json();
            })
            .then(function (data) {
                if (!data.success) throw new Error('expired');
                lastActivity = Date.now();
                hideWarning();
            })
            .catch(function () {
                window.location.href = APP_ROOT + 'login.php?timeout=1';
            });
    }

    function onActivity() {
        lastActivity = Date.now();
        if (warned) hideWarning();
    }

    function startTicker() {
        ticker = setInterval(function () {
            if (!warned && (Date.now() - lastActivity) >= (TIMEOUT - WARN_LEAD) * 1000) {
                showWarning();
            }
        }, TICK_MS);
    }

    // Track real user activity, not just network requests.
    document.addEventListener('mousemove', onActivity, { passive: true });
    document.addEventListener('keydown', onActivity);
    document.addEventListener('click', onActivity);
    document.addEventListener('touchstart', onActivity, { passive: true });
    startTicker();
})();
