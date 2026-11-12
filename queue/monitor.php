<?php
// ============================================================
//  QUEUE/MONITOR.PHP
//  Public on-site queue monitor (TV display, NO login required).
//  Shows now-serving + the full waiting line, refreshes every 3 s.
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
// For app_url() only. Deliberately NOT config.php: this display must render
// even when the database is unreachable, and config.php opens a mysqli
// connection at include time.
require_once __DIR__ . '/../shared/app_path.php';

$APP_ROOT = '../';
$page_title = 'Queue Monitor';
// Where the API actually is, told to the client by the server that knows.
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
    <link rel="stylesheet" href="<?= $APP_ROOT ?>css/queue-monitor-tech.css?v=<?= filemtime(__DIR__ . '/../css/queue-monitor-tech.css') ?>" />
</head>
<body class="monitor-body" data-page="monitor" data-api-base="<?= htmlspecialchars($api_base, ENT_QUOTES) ?>">
<div class="monitor-screen">

    <header class="mt-header"><div class="mt-brand"><img src="../assets/images/BCP_LOGO.png" alt="Bestlink College crest"><div><p>Bestlink College of the Philippines</p><h1>Registrar Queue</h1><small>OFFICE OF THE REGISTRAR</small></div></div><div class="mt-live" id="mtLive">Connecting…</div><div class="mt-clock"><time id="mtTime"></time><span id="mtDate"></span></div></header>
    <section class="mt-windows" id="windowStrip" aria-label="Service windows"><div class="mt-loading">Connecting to the registrar queue…</div></section>
    <div class="mt-announcement" role="status"><i class="fas fa-volume-high" aria-hidden="true"></i><strong id="mtCall">Please watch for your number</strong><span id="mtCallMessage">Approach your window only when called.</span></div>
    <div class="mt-columns"><section class="mt-panel"><header><h2><i class="fas fa-list-ol" aria-hidden="true"></i> Waiting line</h2><span id="mtWaitingCount">— waiting</span></header><div id="waitList" class="mt-table-body"></div><div id="mtMore" class="mt-more"></div></section><section class="mt-panel"><header><h2><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Recently served</h2></header><div id="recentList" class="mt-table-body"></div></section></div>
    <footer class="mt-footer"><span></span>Watch for your number. Approach the assigned window only when called.</footer>

</div>

<script src="<?= $APP_ROOT ?>js/queue-monitor-tech.js?v=<?= filemtime(__DIR__ . '/../js/queue-monitor-tech.js') ?>"></script>
<script src="<?= $APP_ROOT ?>js/queue.js?v=<?= filemtime(__DIR__ . '/../js/queue.js') ?>"></script>
</body>
</html>