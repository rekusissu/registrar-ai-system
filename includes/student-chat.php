<?php
// ============================================================
//  INCLUDES/STUDENT-CHAT.PHP
//  Floating AI chat widget for the Student Portal.
//
//  Included from includes/footer.php ONLY when the session role
//  is 'student' so it appears on every student portal page
//  (dashboard, queue, documents, academic, health, ...).
//
//  Posts to ../api/student-ai-chat.php. All CSS/JS inline so the
//  widget is self-contained and CSP-script-safe.
// ============================================================

// Guard: only render for student-role sessions.
$chatRole = $_SESSION['role'] ?? '';
if ($chatRole !== 'student') {
    return;
}

// Resolve API endpoint relative to the page's APP_ROOT.
$chatAppRoot = $APP_ROOT ?? './';
if (substr($chatAppRoot, -1) !== '/') {
    $chatAppRoot .= '/';
}
$chatEndpoint = htmlspecialchars($chatAppRoot . 'api/student-ai-chat.php', ENT_QUOTES, 'UTF-8');
?>
<!-- ══════════ STUDENT AI CHAT WIDGET ══════════ -->
<div class="chat-widget" id="chatWidget"></div>

<style>
    /* ── Floating chat widget ──────────────────────────── */
    .chat-fab {
        position: fixed;
        right: 22px;
        bottom: 22px;
        width: 62px;
        height: 62px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1a3a8c 0%, #2563eb 100%);
        color: #fff;
        border: none;
        box-shadow: 0 12px 32px rgba(26, 58, 140, .36);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        cursor: pointer;
        z-index: 9998;
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1), background 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .chat-fab:hover { transform: translateY(-4px) scale(1.08); box-shadow: 0 16px 40px rgba(26, 58, 140, .45); }
    .chat-fab.opened { background: #dc2626; transform: rotate(135deg) scale(0.95); box-shadow: 0 12px 32px rgba(220, 38, 38, .35); }

    .chat-panel {
        position: fixed;
        right: 22px;
        bottom: 100px;
        width: 380px;
        max-width: calc(100vw - 44px);
        height: 520px;
        max-height: calc(100vh - 160px);
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, .24), 0 0 1px rgba(15, 23, 42, .08);
        display: none;
        flex-direction: column;
        overflow: hidden;
        z-index: 9999;
        animation: chatPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        border: 1px solid rgba(226, 232, 240, .8);
    }
    .chat-panel.open { display: flex; }
    @keyframes chatPop {
        from { opacity: 0; transform: translateY(16px) scale(0.95); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .chat-head {
        background: linear-gradient(135deg, #1a3a8c 0%, #2563eb 100%);
        color: #fff;
        padding: 18px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .chat-head .chat-head-avatar {
        width: 42px; height: 42px;
        border-radius: 50%;
        background: rgba(255,255,255,.18);
        border: 2px solid rgba(255,255,255,.38);
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        font-size: 20px;
    }
    .chat-head .chat-head-title { font-size: 15px; font-weight: 800; letter-spacing: -0.2px; }
    .chat-head .chat-head-sub { font-size: 12px; color: rgba(255,255,255,.72); margin-top: 3px; font-weight: 500; }
    .chat-head .chat-head-close {
        margin-left: auto;
        background: transparent;
        border: none;
        color: rgba(255,255,255,.8);
        font-size: 16px;
        cursor: pointer;
        padding: 6px;
        border-radius: 50%;
        transition: background .2s;
    }
    .chat-head .chat-head-close:hover { background: rgba(255,255,255,.14); color: #fff; }

    .chat-body {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        background: linear-gradient(180deg, #fafbfc 0%, #f5f7fa 100%);
        display: flex;
        flex-direction: column;
        gap: 12px;
        scroll-behavior: smooth;
    }
    .chat-msg {
        max-width: 84%;
        padding: 11px 15px;
        border-radius: 16px;
        font-size: 14px;
        line-height: 1.6;
        word-wrap: break-word;
        white-space: pre-wrap;
        font-weight: 500;
    }
    .chat-msg.user {
        align-self: flex-end;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        border-bottom-right-radius: 4px;
        box-shadow: 0 4px 14px rgba(37, 99, 235, .28);
    }
    .chat-msg.bot {
        align-self: flex-start;
        background: #fff;
        color: #0f172a;
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, .08);
    }
    .chat-msg.bot.fallback { background: #fef3c7; border-color: #fde68a; color: #78350f; box-shadow: 0 4px 12px rgba(251, 191, 36, .12); }

    .chat-typing {
        align-self: flex-start;
        display: inline-flex;
        gap: 5px;
        padding: 13px 16px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        border-bottom-left-radius: 4px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, .08);
    }
    .chat-typing i {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #cbd5e1;
        animation: chatBlink 1.2s infinite ease-in-out;
    }
    .chat-typing i:nth-child(2) { animation-delay: .2s; }
    .chat-typing i:nth-child(3) { animation-delay: .4s; }
    @keyframes chatBlink {
        0%, 80%, 100% { opacity: .3; transform: translateY(0); }
        40% { opacity: 1; transform: translateY(-4px); }
    }

    .chat-disclaimer {
        padding: 12px 16px;
        font-size: 12px;
        color: #64748b;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        text-align: center;
        line-height: 1.5;
        font-weight: 500;
    }

    .chat-foot {
        display: flex;
        gap: 10px;
        padding: 14px;
        background: #ffffff;
        border-top: 1px solid #e2e8f0;
    }
    .chat-foot input {
        flex: 1;
        border: 1.5px solid #e2e8f0;
        border-radius: 22px;
        padding: 11px 16px;
        font-size: 14px;
        font-family: inherit;
        outline: none;
        transition: border-color 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), background 0.15s ease;
        background: #f5f7fa;
        font-weight: 500;
    }
    .chat-foot input:focus { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, .14); background: #fff; }
    .chat-foot input::placeholder { color: #94a3b8; font-weight: 500; }
    .chat-foot button {
        width: 44px; height: 44px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        flex-shrink: 0;
    }
    .chat-foot button:hover { transform: scale(1.08); box-shadow: 0 6px 16px rgba(37, 99, 235, .36); }
    .chat-foot button:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

    @media (max-width: 480px) {
        .chat-panel { right: 10px; left: 10px; width: auto; }
        .chat-fab { right: 14px; bottom: 14px; }
    }
</style>

<script>
(function () {
    'use strict';

    var endpoint = <?= json_encode($chatEndpoint) ?>;
    var opened = false;

    // ── Build DOM ──────────────────────────────────────────
    var host = document.getElementById('chatWidget');
    if (!host) return;

    var fab = document.createElement('button');
    fab.className = 'chat-fab';
    fab.id = 'chatFab';
    fab.setAttribute('aria-label', 'Open AI assistant');
    fab.innerHTML = '<i class="fa-solid fa-star"></i>';

    var panel = document.createElement('div');
    panel.className = 'chat-panel';
    panel.id = 'chatPanel';
    panel.innerHTML =
        '<div class="chat-head">' +
            '<div class="chat-head-avatar"><i class="fa-solid fa-star"></i></div>' +
            '<div>' +
                '<div class="chat-head-title">Registrar AI Assistant</div>' +
                '<div class="chat-head-sub">Powered by Gemini 3.7 Flash</div>' +
            '</div>' +
            '<button type="button" class="chat-head-close" id="chatClose" aria-label="Close chat">' +
                '<i class="fa-solid fa-xmark"></i>' +
            '</button>' +
        '</div>' +
        '<div class="chat-body" id="chatBody">' +
            '<div class="chat-msg bot">Hi! I can help with registrar questions — documents (good moral, TOR, Form 137), queue tickets, grades, and your student status.</div>' +
        '</div>' +
        '<div class="chat-disclaimer">This assistant provides general guidance only. Official matters are handled by the Registrar\'s Office.</div>' +
        '<div class="chat-foot">' +
            '<input type="text" id="chatInput" placeholder="Type your question…" autocomplete="off" maxlength="500">' +
            '<button type="button" id="chatSend" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>' +
        '</div>';

    host.appendChild(fab);
    host.appendChild(panel);

    var chatBody = document.getElementById('chatBody');
    var chatInput = document.getElementById('chatInput');
    var chatSend = document.getElementById('chatSend');
    var chatClose = document.getElementById('chatClose');

    function scrollBottom() {
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function toggle(open) {
        opened = (typeof open === 'boolean') ? open : !opened;
        panel.classList.toggle('open', opened);
        fab.classList.toggle('opened', opened);
        fab.innerHTML = opened ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-star"></i>';
        if (opened) { chatInput.focus(); scrollBottom(); }
    }
    window.toggleStudentChat = function() { toggle(true); };

    function addMsg(text, who, fallback) {
        var m = document.createElement('div');
        m.className = 'chat-msg ' + who + (fallback ? ' fallback' : '');
        m.textContent = text;
        chatBody.appendChild(m);
        scrollBottom();
        return m;
    }

    function addTyping() {
        var t = document.createElement('div');
        t.className = 'chat-typing';
        t.innerHTML = '<i></i><i></i><i></i>';
        chatBody.appendChild(t);
        scrollBottom();
        return t;
    }

    function setBusy(busy) {
        chatSend.disabled = busy;
        chatInput.disabled = busy;
    }

    function send() {
        var text = (chatInput.value || '').trim();
        if (!text || chatSend.disabled) return;

        chatInput.value = '';
        addMsg(text, 'user', false);
        var typing = addTyping();
        setBusy(true);

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (data) {
            typing.remove();
            var answer = (data && data.success && data.data && data.data.answer)
                ? String(data.data.answer)
                : ((data && data.message) ? String(data.message) : '');
            if (!answer) {
                answer = 'This requires registrar assistance. Please visit the registrar\'s office or contact staff directly.';
            }
            addMsg(answer, 'bot', /requires registrar assistance/i.test(answer));
        })
        .catch(function () {
            typing.remove();
            addMsg('This requires registrar assistance. Please visit the registrar\'s office or contact staff directly.', 'bot', true);
        })
        .finally(function () { setBusy(false); chatInput.focus(); });
    }

    fab.addEventListener('click', function () { toggle(); });
    if (chatClose) chatClose.addEventListener('click', function () { toggle(false); });
    if (chatSend) chatSend.addEventListener('click', send);
    if (chatInput) {
        chatInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); send(); }
        });
    }
})();
</script>