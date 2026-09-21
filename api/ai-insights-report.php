<?php
// ============================================================
//  API/AI-INSIGHTS-REPORT.PHP
//  AI-powered registrar insights report using OpenAI
//  Generates comprehensive analysis of registrar data
// ============================================================

require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/csrf_guard.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../shared/config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/ai_client.php';

header('Content-Type: application/json');

$db = Database::getInstance();

// Get filter parameters
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$filters = $input['filters'] ?? [];
$month  = isset($filters['month']) ? (int)$filters['month'] : date('n');
$year   = isset($filters['year']) ? (int)$filters['year'] : date('Y');

// Validate month and year
if ($month < 1 || $month > 12) $month = date('n');
if ($year < 2000 || $year > 2100) $year = date('Y');

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));

// ============================================================
// DATA COLLECTION
// ============================================================

// --- Student Statistics ---
$totalStudents = $db->fetchColumn("SELECT COUNT(*) FROM students");
$activeStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'active'");
$enrolledStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'enrolled'");
$alumniStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'alumni'");
$graduatedStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'graduated'");
$droppedStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'dropped'");
$transferredStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'transferred'");
$probationStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'probation'");
$atRiskStudents = $db->fetchColumn("SELECT COUNT(*) FROM students WHERE status = 'at-risk'");

// --- RFID Cards ---
$totalCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards");
$activeCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'active'");
$expiredCards = $db->fetchColumn("SELECT COUNT(*) FROM rfid_cards WHERE status = 'expired'");
$issuedThisMonth = $db->fetchColumn(
    "SELECT COUNT(*) FROM rfid_cards WHERE issued_at >= ? AND issued_at <= ?",
    [$startDate, $endDate]
);

// --- Document Transactions ---
$totalDocuments = $db->fetchColumn("SELECT COUNT(*) FROM document_requests");
$pendingDocuments = $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'");
$completedDocuments = $db->fetchColumn("SELECT COUNT(*) FROM document_requests WHERE status IN ('completed','released')");

// Documents by type
$docsByType = $db->fetchAll("
    SELECT 
        CASE 
            WHEN document_type = 'form137' THEN 'Form 137'
            WHEN document_type = 'good_moral' THEN 'Good Moral'
            WHEN document_type = 'transcript' THEN 'Transcript'
            WHEN document_type = 'certificate' THEN 'Certificate'
            WHEN document_type = 'clearance' THEN 'Clearance'
            ELSE document_type
        END as doc_type,
        COUNT(*) as count
    FROM document_requests 
    GROUP BY document_type
    ORDER BY count DESC
");

// --- Queue Statistics ---
$queueTotal = $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets");
$todayDate = date('Y-m-d');
$queueToday = $db->fetchColumn("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ?", [$todayDate]);
$queueServedThisMonth = $db->fetchColumn(
    "SELECT COUNT(*) FROM queue_tickets WHERE status = 'completed' AND served_at >= ? AND served_at <= ?",
    [$startDate, $endDate]
);

// --- Student Status Distribution ---
$statusDistribution = [
    'Active' => $activeStudents,
    'Enrolled' => $enrolledStudents,
    'Alumni' => $alumniStudents,
    'Graduate' => $graduatedStudents,
    'Dropped' => $droppedStudents,
    'Transferred' => $transferredStudents,
];
if ($probationStudents > 0) $statusDistribution['Probation'] = $probationStudents;
if ($atRiskStudents > 0) $statusDistribution['At-Risk'] = $atRiskStudents;

// --- Student Program Distribution ---
$programs = $db->fetchAll("
    SELECT course, COUNT(*) as count 
    FROM students 
    WHERE course IS NOT NULL AND course != ''
    GROUP BY course 
    ORDER BY count DESC
    LIMIT 10
");

// --- Document Transaction Overview ---
$docOverview = [];
foreach ($docsByType as $doc) {
    $docOverview[$doc['doc_type']] = (int)$doc['count'];
}
$specificDocs = ['Form 137', 'Form 138', 'Good Moral', 'Withdrawal Form', 'COR', 'COG', 'TOR', 'Diploma'];
foreach ($specificDocs as $docName) {
    if (!isset($docOverview[$docName])) $docOverview[$docName] = 0;
}
arsort($docOverview);

// --- RFID Overview ---
$rfidByMonth = $db->fetchAll("
    SELECT DATE_FORMAT(issued_at, '%Y-%m') as month, COUNT(*) as count
    FROM rfid_cards WHERE issued_at IS NOT NULL
    GROUP BY DATE_FORMAT(issued_at, '%Y-%m')
    ORDER BY month DESC LIMIT 12
");

// ============================================================
// BUILD USER PROMPT FOR AI
// ============================================================

$userPrompt = "Reporting Period: {$startDate} to {$endDate}\n\n";
$userPrompt .= "STUDENT STATISTICS:\n";
$userPrompt .= "- Total Students: {$totalStudents}\n";
$userPrompt .= "- Active: {$activeStudents}\n";
$userPrompt .= "- Enrolled: {$enrolledStudents}\n";
$userPrompt .= "- Alumni: {$alumniStudents}\n";
$userPrompt .= "- Graduated: {$graduatedStudents}\n";
$userPrompt .= "- Dropped: {$droppedStudents}\n";
$userPrompt .= "- Transferred: {$transferredStudents}\n";
if ($probationStudents > 0) $userPrompt .= "- Probation: {$probationStudents}\n";
if ($atRiskStudents > 0) $userPrompt .= "- At-Risk: {$atRiskStudents}\n\n";

$userPrompt .= "RFID CARDS:\n";
$userPrompt .= "- Total Cards Issued: {$totalCards}\n";
$userPrompt .= "- Active Cards: {$activeCards}\n";
$userPrompt .= "- Expired Cards: {$expiredCards}\n";
$userPrompt .= "- Cards Issued This Month: {$issuedThisMonth}\n\n";

$userPrompt .= "DOCUMENT TRANSACTIONS:\n";
$userPrompt .= "- Total Transactions: {$totalDocuments}\n";
$userPrompt .= "- Pending: {$pendingDocuments}\n";
$userPrompt .= "- Completed/Released: {$completedDocuments}\n";
$userPrompt .= "- By Document Type:\n";
foreach ($docOverview as $docType => $count) {
    $userPrompt .= "  * {$docType}: {$count}\n";
}
$userPrompt .= "\n";

$userPrompt .= "QUEUE STATISTICS:\n";
$userPrompt .= "- Total Queue Records: {$queueTotal}\n";
$userPrompt .= "- Queue Today ({$todayDate}): {$queueToday}\n";
$userPrompt .= "- Served This Month: {$queueServedThisMonth}\n\n";

$userPrompt .= "STUDENT STATUS DISTRIBUTION:\n";
foreach ($statusDistribution as $status => $count) {
    $userPrompt .= "- {$status}: {$count}\n";
}
$userPrompt .= "\n";

$userPrompt .= "TOP STUDENT PROGRAMS:\n";
foreach ($programs as $prog) {
    $userPrompt .= "- {$prog['course']}: {$prog['count']} students\n";
}

$userPrompt .= "\n";

$userPrompt .= "RFID ACTIVITY (last 12 months):\n";
foreach ($rfidByMonth as $rfid) {
    $userPrompt .= "- {$rfid['month']}: {$rfid['count']} cards issued\n";
}

$systemPrompt = "You are an expert registrar's office analyst. Write a concise, factual AI report from the registrar data provided.

Your response MUST be plain Markdown with EXACTLY these five numbered headings, in this order:

## 1. Executive Summary
## 2. Key Metrics
## 3. Trends
## 4. Observations
## 5. Recommendations

Section content rules:
- Executive Summary: 3-4 sentences on overall registrar health (enrollment, documents, RFID, queue).
- Key Metrics: 4-6 bullet points of the most important numbers with context, e.g. \"- 520 total students, 342 active (65.8%)\".
- Trends: 3-5 bullet points describing patterns visible in the data.
- Observations: 2-4 bullet points on notable strengths, risks, or bottlenecks. Flag any unusually high or low numbers with figures from the data.
- Recommendations: 3-4 bullet points of concrete, prioritized actions for the registrar office.

RULES:
- Use ONLY the data provided above. Never invent numbers.
- Keep every bullet to one sentence; do not write paragraphs between headings.
- Do not write anything before the first heading or after the last bullet.
- Do not repeat these instructions; output the report only.
- Professional administrative tone, under 500 words total.";

// Generate AI report
$report = aiGenerate($systemPrompt, $userPrompt, [
    'max_tokens' => 2000,
    'temperature' => 0.3,
]);

if ($report === '') {
    $report = "## 1. Executive Summary\n\n";
    $report .= "This period covers {$startDate} to {$endDate}. The registrar system manages {$totalStudents} students with {$activeStudents} active, {$totalDocuments} document transactions ({$pendingDocuments} pending), and {$totalCards} RFID cards ({$expiredCards} expired).\n\n";

    $report .= "## 2. Key Metrics\n";
    $studentRate = ($totalStudents > 0) ? round(($activeStudents / $totalStudents) * 100, 1) : 0;
    $docRate     = ($totalDocuments > 0) ? round(($completedDocuments / $totalDocuments) * 100, 1) : 0;
    $cardRate    = ($totalCards > 0) ? round(($activeCards / $totalCards) * 100, 1) : 0;
    $report .= "- {$totalStudents} total students, {$activeStudents} active ({$studentRate}%)\n";
    $report .= "- {$totalDocuments} document transactions, {$pendingDocuments} pending ({$docRate}% completed)\n";
    $report .= "- {$totalCards} RFID cards, {$activeCards} active ({$cardRate}% active)\n";
    $report .= "- {$queueToday} queue tickets today, {$queueTotal} all-time\n\n";

    $report .= "## 3. Trends\n";
    $report .= "- Enrollment distribution shows {$activeStudents} active and {$enrolledStudents} enrolled students engaged.\n";
    $report .= "- Document volume of {$totalDocuments} transactions with {$pendingDocuments} pending requests.\n";
    if ($expiredCards > 0) {
        $report .= "- {$expiredCards} RFID cards expired, reducing active card coverage.\n";
    }
    $report .= "- Queue activity recorded {$queueToday} tickets today.\n\n";

    $report .= "## 4. Observations\n";
    if ($pendingDocuments > $totalDocuments * 0.3) {
        $report .= "- Pending documents exceed 30% of total transactions; document turnaround may need attention.\n";
    } else {
        $report .= "- Document backlog is within normal operating range.\n";
    }
    $report .= "- RFID coverage is {$cardRate}% active across {$totalCards} total cards.\n";
    $report .= "- Student status and program data are available in the dashboard charts.\n\n";

    $report .= "## 5. Recommendations\n";
    $report .= "- Review pending document requests and prioritize overdue items.\n";
    if ($expiredCards > 0) {
        $report .= "- Schedule renewal for {$expiredCards} expired RFID cards.\n";
    }
    $report .= "- Monitor queue load and staff the registrar counter during peak periods.\n";
    $report .= "- Keep student status records current for accurate analytics.\n";
}

echo json_encode([
    'success' => true,
    'data' => [
        'report' => $report,
        'generated_at' => date('Y-m-d H:i:s'),
        'period' => [
            'month' => $month,
            'year' => $year,
            'label' => date('F Y', mktime(0, 0, 0, $month, 1, $year)),
        ],
    ],
]);