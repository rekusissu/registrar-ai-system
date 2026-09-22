<?php
// VERIFY-STUDENT.PHP - Public student verification (zero PII)
require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/database.php';
$db = Database::getInstance();
$verified = false; $notFound = false;
$idNumber = trim((string) ($_GET['id_number'] ?? ''));
if ($idNumber !== '') {
    $row = $db->fetchOne("SELECT si.id, si.status, si.expiry_date FROM student_ids si WHERE si.id_number = ? LIMIT 1", [$idNumber]);
    if ($row) {
        $isValid = $row['status'] === 'active' && ($row['expiry_date'] === null || $row['expiry_date'] >= date('Y-m-d'));
        $verified = $isValid;
        if (!$isValid) $notFound = true;
    } else { $notFound = true; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Student Verification - Bestlink College of the Philippines</title>
<link rel="icon" type="image/x-icon" href="assets/images/favicon.ico" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
<style>
:root{--blue:#2563eb;--ink:#0f172a;--mut:#64748b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Inter","Segoe UI",-apple-system,sans-serif;background:linear-gradient(160deg,#f1f5f9,#e2e8f0);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:28px 16px;color:var(--ink)}
.wrap{width:100%;max-width:480px;text-align:center}
.brand{margin-bottom:18px}
.brand img{width:72px;height:72px;margin:0 auto 12px;display:block;border-radius:14px;box-shadow:0 6px 16px rgba(15,23,42,.10)}
.brand h1{font-size:16px;font-weight:800}.brand p{font-size:12px;color:var(--mut);margin-top:2px}
.card{background:#fff;border-radius:18px;box-shadow:0 18px 46px rgba(15,23,42,.12);border:1px solid #e2e8f0;overflow:hidden}
.ch{padding:20px 22px;color:#fff;display:flex;align-items:center;gap:12px;justify-content:center}
.ch.v{background:linear-gradient(135deg,#15803d,#22c55e)}
.ch.nf{background:linear-gradient(135deg,#991b1b,#dc2626)}
.ch.nu{background:linear-gradient(135deg,#1e3a8a,#2563eb)}
.ch .ic{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px}
.ch h2{font-size:16px;font-weight:800}
.cb{padding:28px 22px}
.vm{font-size:15px;font-weight:700;color:#15803d;line-height:1.5;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px}
.nm{font-size:14px;font-weight:600;color:#b91c1c;line-height:1.5;padding:16px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px}
.sf{display:flex;gap:8px;margin-top:16px}
.sf input{flex:1;padding:11px 13px;border:1px solid #cbd5e1;border-radius:10px;font-size:13px;font-family:inherit}
.sf button{border:none;border-radius:10px;padding:0 16px;font-size:13px;font-weight:700;cursor:pointer;background:var(--blue);color:#fff}
.sf button:hover{filter:brightness(.96)}
.ft{text-align:center;margin-top:16px;font-size:11px;color:#94a3b8}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <img src="assets/images/BCP_LOGO.png" alt="BCP Logo" />
        <h1>Bestlink College of the Philippines</h1>
        <p>Student Verification Portal</p>
    </div>
    <div class="card">
        <?php if ($verified): ?>
            <div class="ch v"><div class="ic"><i class="fa-solid fa-circle-check"></i></div><h2>Verified Student</h2></div>
            <div class="cb"><div class="vm"><i class="fa-solid fa-shield-halved" style="margin-right:6px;"></i>This individual is a verified student of Bestlink College of the Philippines.</div></div>
        <?php elseif ($notFound): ?>
            <div class="ch nf"><div class="ic"><i class="fa-solid fa-circle-xmark"></i></div><h2>Not Verified</h2></div>
            <div class="cb">
                <div class="nm"><i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>No verified student record found.</div>
                <form class="sf" method="get" action="verify-student.php"><input type="text" name="id_number" placeholder="ID number (e.g. 2026-0001)" required /><button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Verify</button></form>
            </div>
        <?php else: ?>
            <div class="ch nu"><div class="ic"><i class="fa-solid fa-qrcode"></i></div><h2>Student Verification</h2></div>
            <div class="cb">
                <p style="font-size:13px;color:var(--mut);margin-bottom:16px;">Scan a student ID QR code or enter the ID number manually.</p>
                <form class="sf" method="get" action="verify-student.php"><input type="text" name="id_number" placeholder="ID number (e.g. 2026-0001)" required /><button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Verify</button></form>
            </div>
        <?php endif; ?>
    </div>
    <div class="ft">Bestlink College of the Philippines &mdash; Office of the Registrar &middot; <?= date("Y") ?></div>
</div>
</body>
</html>