<?php
// ============================================================
//  QUEUE/KIOSK.PHP
//  Public self-service queue kiosk (NO login required).
//  Students tap their RFID card to get a queue number,
//  or use the tabs to see the full line / check a number.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
// For app_url() only. Deliberately NOT config.php: this page is public and
// must render on a wall display even when the database is unreachable, and
// config.php opens a mysqli connection at include time.
require_once __DIR__ . '/../shared/app_path.php';

$APP_ROOT = '../';
$page_title = 'Queue Kiosk';
// Where the API actually is, told to the client by the server that knows.
// See the note in js/queue.js: counting slashes in window.location works on
// one deployment and 404s on another.
$api_base = rtrim(app_url('/api'), '/') . '/';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> — BCP Registrar System</title>
    <link rel="icon" type="image/x-icon" href="<?= $APP_ROOT ?>assets/images/favicon.ico" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/queue.css" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/queue-rfid-art.css?v=<?= filemtime(__DIR__ . '/../css/queue-rfid-art.css') ?>" />
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/queue-kiosk-tech.css?v=<?= filemtime(__DIR__ . '/../css/queue-kiosk-tech.css') ?>" />
</head>
<body class="queue-body" data-page="kiosk" data-api-base="<?= htmlspecialchars($api_base, ENT_QUOTES) ?>">
<div class="queue-screen">

    <div class="queue-brand">
        <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP Logo" />
        <div>
            <h1>Bestlink College of the Philippines</h1>
            <p>Office of the Registrar · Queue kiosk</p>
        </div>
    </div>

    <div class="kt-clock"><span id="ktConnection">Connecting to queue…</span><time id="ktTime"></time></div>
    <div class="q-tabs" hidden aria-hidden="true">
        <button class="q-tab-btn" data-tab="tap"><i class="fas fa-id-card"></i> Tap Card</button>
        <button class="q-tab-btn" data-tab="board"><i class="fas fa-list-ol"></i> Full Queue</button>
        <button class="q-tab-btn" data-tab="standing"><i class="fas fa-magnifying-glass"></i> Check my number</button>
    </div>

    <section id="screen-tap" aria-label="Tap your student ID card">
      <div class="kt-ready kt-frame"><div class="kt-main"><span class="kt-open-badge"><i class="fas fa-circle-check" aria-hidden="true"></i> Queue open</span><div class="kt-eyebrow">SELF-SERVICE QUEUE</div><h2>Your next step starts here.</h2><p>Tap your student ID card on the reader to get a queue number.</p>
      <div class="kt-reader-art" aria-hidden="true"><div class="assign-rfid-art kt-rfid-float" aria-label="Campus RFID card illustration"><div class="assign-art-brand"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div><strong>Bestlink College</strong><small>CAMPUS ACCESS</small></div></div><svg class="assign-coil" viewBox="0 0 120 120" fill="none" aria-hidden="true"><g stroke="#18e7ff" stroke-width="1.6" stroke-linecap="round"><path d="M49 109a50 50 0 1 1 22 0M50 101a42 42 0 1 1 20 0M51 93a34 34 0 1 1 18 0M52 85a26 26 0 1 1 16 0"/></g><g fill="#83f0ff"><circle cx="49" cy="109" r="2.2"/><circle cx="71" cy="109" r="2.2"/><circle cx="50" cy="101" r="2"/><circle cx="70" cy="101" r="2"/><circle cx="51" cy="93" r="1.8"/><circle cx="69" cy="93" r="1.8"/><circle cx="52" cy="85" r="1.7"/><circle cx="68" cy="85" r="1.7"/></g></svg><div class="assign-art-bottom"><div><small>CARD UID</small><strong>— — — — —</strong></div><span>RFID</span></div></div><div class="kt-reader-ring"><span></span><span></span><span></span><i class="fas fa-wifi"></i></div></div>
      <h3>Tap your student ID card</h3><p class="kt-help">Keep your card near the reader until confirmed.</p><div class="kt-ready-state"><span></span>Ready to scan</div></div>
      <aside class="kt-guide"><h2>Before you join</h2><ol><li><b>1</b><span>Tap your card</span></li><li><b>2</b><span>Choose your service</span></li><li><b>3</b><span>Keep your queue number</span></li></ol><p><i class="fas fa-shield-halved" aria-hidden="true"></i> Your queue number will appear on screen.</p></aside></div>
    </section>
    <!-- LANE PICKER — shown after the card is read.
         Two questions, one at a time. The first is WHAT you came for;
         the second is WHO you are. Keeping them on separate screens
         (rather than four buttons, or a dropdown) means the choice is
         always two taps at most and never a misread small label. -->
    <div id="screen-pick" style="display:none;">
        <div class="lane-pick" id="lanePick">
            <div class="lane-head">
                <div class="lane-step" id="laneStep">1</div>
                <div>
                    <h2 id="laneQuestion">What do you need?</h2>
                    <p id="laneSub">Choose one to continue.</p>
                </div>
            </div>
            <div class="lane-choices" id="laneChoices"></div>
            <button type="button" class="lane-back" id="laneBack">
                <i class="fas fa-arrow-left"></i> <span id="laneBackLabel">Back</span>
            </button>
        </div>
    </div>

    <!-- CLOSED — the queue is not taking numbers. Replaces the tap
         prompt entirely so nobody starts a tap that cannot finish.

         The mark is a clock, not a door. Every way this screen appears
         (before open, after close, cut off) is about TIME, and a door
         just says "locked" without saying why. A still door also reads
         as broken equipment to a student who is looking for something
         to tap. -->
    <div id="screen-closed" style="display:none;">
        <div class="closed-sign">
            <div class="closed-mark" aria-hidden="true">
                <svg viewBox="0 0 100 100" class="closed-clock">
                    <circle class="cc-face" cx="50" cy="50" r="43" />
                    <circle class="cc-tick" cx="50" cy="8" r="2.6" />
                    <circle class="cc-tick" cx="92" cy="50" r="2.6" />
                    <circle class="cc-tick" cx="50" cy="92" r="2.6" />
                    <circle class="cc-tick" cx="8" cy="50" r="2.6" />
                    <circle class="cc-ring" cx="50" cy="50" r="37" />
                    <line class="cc-hand cc-hand-h" x1="50" y1="50" x2="50" y2="27" />
                    <line class="cc-hand cc-hand-m" x1="50" y1="50" x2="50" y2="18" />
                    <circle class="cc-pin" cx="50" cy="50" r="3.6" />
                </svg>
            </div>
            <h2 id="closedTitle">The queue is closed</h2>
            <p id="closedMessage">The queue is closed right now.</p>
            <div class="closed-when" id="closedWhen"></div>
        </div>
    </div>

    <!-- RESULT screen -->
    <div id="screen-result" style="display:none;" aria-live="polite">
        <div class="result-card" id="resultCard">
            <div class="result-icon success" id="rIcon" style="display:none;"><i class="fas fa-circle-check"></i></div>
            <div class="ticket-number" id="rNumber" style="display:none;"></div>
            <div class="ticket-name" id="rName" style="display:none;"></div>
            <div class="ticket-sub" id="rSub"></div>
        </div>
    </div>

    <!-- FULL QUEUE board -->
    <div id="screen-board" style="display:none;">
        <div class="q-panel">
            <h3><i class="fas fa-list-ol"></i> Current line</h3>
            <div class="q-grid" id="boardList">
                <div style="color:#64748b;text-align:center;padding:24px;grid-column:1/-1;">Loading…</div>
            </div>
        </div>
    </div>

    <!-- STANDING screen -->
    <div id="screen-standing" style="display:none;">
        <div class="q-panel">
            <h3><i class="fas fa-magnifying-glass"></i> Check my number</h3>
            <div class="standing-input-wrap">
                <input type="text" id="standingNumber" inputmode="numeric" placeholder="Enter number, e.g. 001" />
                <button class="q-tab-btn active" id="standingCheck" style="border-radius:12px;">Check</button>
            </div>
            <div id="standingResult"></div>
        </div>
    </div>


</div>

<!-- Hidden RFID capture input: reader types the UID and presses Enter -->
<input type="text" aria-label="RFID card reader" id="cardInput" class="q-hidden-input" autocomplete="off" />

<script src="<?= $APP_ROOT ?>js/queue-kiosk-tech.js?v=<?= filemtime(__DIR__ . '/../js/queue-kiosk-tech.js') ?>"></script>
<script src="<?= $APP_ROOT ?>js/queue.js?v=<?= filemtime(__DIR__ . '/../js/queue.js') ?>"></script>
</body>
</html>
