<?php
// ============================================================
//  TERMS AND CONDITIONS PAGE
//  Public page accessible to all users
// ============================================================

require_once __DIR__ . '/shared/security_headers.php';
require_once __DIR__ . '/shared/session_config.php';

$page_title = 'Terms and Conditions';
$APP_ROOT = './';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($page_title) ?> – BCP Registrar System</title>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #334155;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1a3a8c 0%, #2563eb 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        .content {
            padding: 40px 30px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .content h2 {
            font-size: 18px;
            color: #0f172a;
            margin-top: 24px;
            margin-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }
        .content h2:first-child {
            margin-top: 0;
        }
        .content p {
            margin-bottom: 12px;
            text-align: justify;
        }
        .content ul, .content ol {
            margin-left: 20px;
            margin-bottom: 12px;
        }
        .content li {
            margin-bottom: 8px;
        }
        .footer {
            background: #f8fafc;
            padding: 20px 30px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.3);
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #1e293b;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .last-updated {
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
            margin-top: 16px;
        }
        @media (max-width: 640px) {
            .container {
                border-radius: 0;
            }
            .header {
                padding: 30px 20px;
            }
            .header h1 {
                font-size: 24px;
            }
            .content {
                padding: 20px;
            }
            .footer {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fa-solid fa-file-contract"></i> Terms and Conditions</h1>
        <p>Bestlink College of the Philippines — Registrar Management System</p>
    </div>

    <div class="content">
        <h2>1. Acceptance of Terms</h2>
        <p>By accessing and using the Bestlink College of the Philippines (BCP) Registrar Management System ("System"), you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions. If you do not agree to these terms, please do not use this System.</p>

        <h2>2. System Purpose</h2>
        <p>The Registrar Management System is provided by Bestlink College of the Philippines for the exclusive purpose of managing student records, academic history, health records, RFID access control, and document requests. The System is intended for use by authorized students, staff, and administrators only.</p>

        <h2>3. User Responsibilities</h2>
        <ul>
            <li>You are responsible for maintaining the confidentiality of your login credentials (username and password)</li>
            <li>You agree not to share your credentials with any other person</li>
            <li>You agree to immediately notify the IT department if you suspect unauthorized access to your account</li>
            <li>You are responsible for all activities that occur under your account</li>
            <li>You agree to use the System only for authorized educational and administrative purposes</li>
        </ul>

        <h2>4. Prohibited Activities</h2>
        <p>You agree not to:</p>
        <ul>
            <li>Attempt to gain unauthorized access to the System or its data</li>
            <li>Modify, copy, or distribute System content without authorization</li>
            <li>Use the System for any illegal, harmful, or harassing purpose</li>
            <li>Attempt to reverse-engineer, decompile, or discover the source code</li>
            <li>Interfere with or disrupt the normal operation of the System</li>
            <li>Attempt to bypass security measures or access controls</li>
            <li>Use automated tools, scripts, or bots to access the System without authorization</li>
        </ul>

        <h2>5. Privacy and Data Protection</h2>
        <p>Your personal information, academic records, and health data are protected under applicable data privacy laws. We are committed to safeguarding your information and maintaining its confidentiality. The System implements industry-standard security measures including encryption, access controls, and audit logging.</p>

        <h2>6. Intellectual Property</h2>
        <p>All content, design, and functionality of the Registrar Management System are the intellectual property of Bestlink College of the Philippines. You may not reproduce, modify, or distribute any part of the System without explicit written permission.</p>

        <h2>7. Limitation of Liability</h2>
        <p>The Registrar Management System is provided "as is" without warranties of any kind. Bestlink College of the Philippines shall not be liable for any indirect, incidental, special, or consequential damages arising from your use of or inability to use the System, including loss of data or business interruption.</p>

        <h2>8. System Availability</h2>
        <p>While we strive to maintain continuous availability of the System, we make no guarantee of uninterrupted service. The System may be temporarily unavailable for maintenance, updates, or due to unforeseen circumstances. We will make reasonable efforts to notify users of scheduled maintenance in advance.</p>

        <h2>9. Changes to Terms</h2>
        <p>Bestlink College of the Philippines reserves the right to modify these Terms and Conditions at any time. Your continued use of the System following notification of changes constitutes your acceptance of the revised terms. We encourage you to review these terms periodically.</p>

        <h2>10. Termination of Access</h2>
        <p>The college reserves the right to suspend or terminate your access to the System at any time, with or without cause, including but not limited to violations of these Terms and Conditions.</p>

        <h2>11. Governing Law</h2>
        <p>These Terms and Conditions shall be governed by and construed in accordance with the laws of the Republic of the Philippines. Any disputes arising from these terms shall be resolved in the appropriate courts of the Philippines.</p>

        <h2>12. Contact Information</h2>
        <p>For questions or concerns regarding these Terms and Conditions or the Registrar Management System, please contact:<br>
        <strong>Office of the Registrar</strong><br>
        Bestlink College of the Philippines<br>
        Email: registrar@bestlink.edu.ph</p>

        <div class="last-updated">Last Updated: September 2026</div>
    </div>

    <div class="footer">
        <button class="btn btn-secondary" onclick="window.history.back()">
            <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <a href="login.php" class="btn btn-primary">
            <i class="fa-solid fa-arrow-right"></i> Return to Login
        </a>
    </div>
</div>

</body>
</html>
