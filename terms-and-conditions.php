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

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
            line-height: 1.7;
            color: var(--gray-700);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wrapper {
            width: 100%;
            max-width: 1000px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #1a3a8c 0%, #2563eb 50%, #3b82f6 100%);
            color: white;
            padding: 50px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 250px;
            height: 250px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }

        .header-content {
            position: relative;
            z-index: 1;
        }

        .header-icon {
            font-size: 48px;
            margin-bottom: 16px;
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .header p {
            font-size: 15px;
            opacity: 0.95;
        }

        /* Breadcrumb */
        .breadcrumb {
            background: var(--gray-50);
            padding: 12px 40px;
            font-size: 12px;
            color: var(--gray-500);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .breadcrumb a:hover {
            color: var(--primary-dark);
        }

        /* Content */
        .content {
            padding: 50px 40px;
            max-height: calc(100vh - 400px);
            overflow-y: auto;
            scroll-behavior: smooth;
        }

        .content::-webkit-scrollbar {
            width: 8px;
        }

        .content::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        .content::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 4px;
        }

        .content::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        /* Sections */
        .section {
            margin-bottom: 40px;
        }

        .section-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 50%;
            font-weight: 700;
            font-size: 14px;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .section h2 {
            display: flex;
            align-items: center;
            font-size: 20px;
            color: var(--gray-900);
            margin-bottom: 16px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .section p {
            margin-bottom: 14px;
            color: var(--gray-600);
            text-align: justify;
            line-height: 1.8;
        }

        .section ul, .section ol {
            margin-left: 30px;
            margin-bottom: 16px;
        }

        .section li {
            margin-bottom: 10px;
            color: var(--gray-600);
            line-height: 1.8;
        }

        .section ul li::marker {
            color: var(--primary);
            font-weight: 700;
        }

        .section ol li::marker {
            color: var(--primary);
            font-weight: 700;
        }

        /* Contact Box */
        .contact-box {
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(37, 99, 235, 0.05) 100%);
            border-left: 4px solid var(--primary);
            padding: 24px;
            border-radius: 12px;
            margin-top: 20px;
        }

        .contact-box strong {
            color: var(--gray-900);
            display: block;
            margin-bottom: 8px;
        }

        .contact-box p {
            margin-bottom: 4px;
            font-size: 14px;
        }

        /* Last Updated */
        .last-updated {
            background: var(--gray-100);
            padding: 16px 24px;
            border-radius: 8px;
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 32px;
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            padding: 30px 40px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #1546a0 100%);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--primary);
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: var(--primary-light);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                border-radius: 12px;
            }

            .header {
                padding: 40px 24px;
            }

            .header h1 {
                font-size: 28px;
            }

            .header-icon {
                width: 64px;
                height: 64px;
                font-size: 32px;
            }

            .breadcrumb {
                padding: 10px 24px;
                font-size: 11px;
            }

            .content {
                padding: 30px 24px;
                max-height: calc(100vh - 350px);
            }

            .section h2 {
                font-size: 18px;
            }

            .footer {
                padding: 20px 24px;
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .container {
                border-radius: 8px;
            }

            .header {
                padding: 30px 16px;
            }

            .header h1 {
                font-size: 24px;
            }

            .header p {
                font-size: 14px;
            }

            .content {
                padding: 20px 16px;
            }

            .section {
                margin-bottom: 30px;
            }

            .section h2 {
                font-size: 16px;
            }

            .section p, .section li {
                font-size: 14px;
            }

            .contact-box {
                padding: 16px;
            }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-icon">
                    <i class="fa-solid fa-file-contract"></i>
                </div>
                <h1>Terms and Conditions</h1>
                <p>Bestlink College of the Philippines — Registrar Management System</p>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="javascript:history.back()"><i class="fa-solid fa-arrow-left"></i> Back</a>
            <span>/</span>
            <span>Terms and Conditions</span>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="section">
                <h2>
                    <div class="section-number">1</div>
                    Acceptance of Terms
                </h2>
                <p>By accessing and using the Bestlink College of the Philippines (BCP) Registrar Management System ("System"), you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions. If you do not agree to these terms, please do not use this System.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">2</div>
                    System Purpose
                </h2>
                <p>The Registrar Management System is provided by Bestlink College of the Philippines for the exclusive purpose of managing student records, academic history, health records, RFID access control, and document requests. The System is intended for use by authorized students, staff, and administrators only.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">3</div>
                    User Responsibilities
                </h2>
                <ul>
                    <li>You are responsible for maintaining the confidentiality of your login credentials (username and password)</li>
                    <li>You agree not to share your credentials with any other person</li>
                    <li>You agree to immediately notify the IT department if you suspect unauthorized access to your account</li>
                    <li>You are responsible for all activities that occur under your account</li>
                    <li>You agree to use the System only for authorized educational and administrative purposes</li>
                </ul>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">4</div>
                    Prohibited Activities
                </h2>
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
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">5</div>
                    Privacy and Data Protection
                </h2>
                <p>Your personal information, academic records, and health data are protected under applicable data privacy laws. We are committed to safeguarding your information and maintaining its confidentiality. The System implements industry-standard security measures including encryption, access controls, and audit logging.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">6</div>
                    Intellectual Property
                </h2>
                <p>All content, design, and functionality of the Registrar Management System are the intellectual property of Bestlink College of the Philippines. You may not reproduce, modify, or distribute any part of the System without explicit written permission.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">7</div>
                    Limitation of Liability
                </h2>
                <p>The Registrar Management System is provided "as is" without warranties of any kind. Bestlink College of the Philippines shall not be liable for any indirect, incidental, special, or consequential damages arising from your use of or inability to use the System, including loss of data or business interruption.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">8</div>
                    System Availability
                </h2>
                <p>While we strive to maintain continuous availability of the System, we make no guarantee of uninterrupted service. The System may be temporarily unavailable for maintenance, updates, or due to unforeseen circumstances. We will make reasonable efforts to notify users of scheduled maintenance in advance.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">9</div>
                    Changes to Terms
                </h2>
                <p>Bestlink College of the Philippines reserves the right to modify these Terms and Conditions at any time. Your continued use of the System following notification of changes constitutes your acceptance of the revised terms. We encourage you to review these terms periodically.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">10</div>
                    Termination of Access
                </h2>
                <p>The college reserves the right to suspend or terminate your access to the System at any time, with or without cause, including but not limited to violations of these Terms and Conditions.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">11</div>
                    Governing Law
                </h2>
                <p>These Terms and Conditions shall be governed by and construed in accordance with the laws of the Republic of the Philippines. Any disputes arising from these terms shall be resolved in the appropriate courts of the Philippines.</p>
            </div>

            <div class="section">
                <h2>
                    <div class="section-number">12</div>
                    Contact Information
                </h2>
                <div class="contact-box">
                    <strong><i class="fa-solid fa-envelope" style="color: var(--primary); margin-right: 8px;"></i>Office of the Registrar</strong>
                    <p><strong>Bestlink College of the Philippines</strong></p>
                    <p><i class="fa-solid fa-envelope" style="color: var(--primary); margin-right: 6px;"></i> registrar@bestlink.edu.ph</p>
                    <p style="margin-top: 12px; font-size: 12px;">For questions or concerns regarding these Terms and Conditions or the Registrar Management System, please contact the office above.</p>
                </div>

                <div class="last-updated">
                    <i class="fa-solid fa-clock"></i> Last Updated: September 2026
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <button class="btn btn-secondary" onclick="window.history.back()">
                <i class="fa-solid fa-arrow-left"></i> Go Back
            </button>
            <a href="login.php" class="btn btn-primary">
                <i class="fa-solid fa-arrow-right"></i> Return to Login
            </a>
        </div>
    </div>
</div>

</body>
</html>
