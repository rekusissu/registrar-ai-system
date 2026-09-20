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

$systemPrompt = "You are an expert registrar's office analyst. Write a comprehensive but concise AI analysis report based on the registrar data provided. 

Your response MUST follow this exact structure:

## AI REGISTRAR SUMMARY
[Write a 3-4 sentence executive summary of the registrar's current state based on the data. Highlight key metrics and the overall health of student enrollment, document processing, and RFID management.]

## DETECTED TRENDS
- [Identify 3-5 specific trends based on the data. Use bullet points. Examples:
  - Enrollment patterns (active vs enrolled ratios, graduation rates)
  - Document processing efficiency (pending vs completed ratios)
  - RFID card utilization (active vs expired, issuance trends)
  - Queue management trends
  - Program popularity distribution]
- Each bullet should be a complete thought with specific numbers from the data.

## PATTERNS OBSERVED
[Write 2-3 paragraphs of AI-generated observations analyzing:
1. What the data suggests about student retention and engagement
2. Document request patterns and potential bottlenecks
3. RFID card management effectiveness
4. Any correlations between different data points
5. Recommendations for registrar office efficiency]

IMPORTANT RULES:
- Use ONLY the data provided above. Do not invent or assume additional data.
- Be specific with numbers and percentages when possible.
- Write in professional, administrative language suitable for registrar staff.
- If a metric seems unusually high or low, note it as an observation (not a judgment).
- Keep the report focused and actionable.
- Maximum 500 words for the entire report.

At the end, add:
Generated by: Registrar Information System
AI-generated information is provided for administrative reference.";

// Generate AI report
$report = aiGenerate($systemPrompt, $userPrompt, [
    'max_tokens' => 2000,
    'temperature' => 0.3,
]);

if ($report === '') {
    // Fallback if AI fails
    $report = "## AI REGISTRAR SUMMARY\n\n";
    $report .= "This period covers {$startDate} to {$endDate}. The registrar system currently manages {$totalStudents} students with {$activeStudents} actively enrolled. ";
    $report .= "Document processing has handled {$totalDocuments} transactions with {$pendingDocuments} still pending.\n\n";
    
    $report .= "## DETECTED TRENDS\n\n";
    $report .= "- Student population of {$totalStudents} with {$activeStudents} active (" . round(($activeStudents/$totalStudents)*100, 1) . "% active rate)\n";
    $report .= "- {$totalDocuments} document transactions processed\n";
    $report .= "- {$totalCards} RFID cards in system with {$expiredCards} expired\n";
    $report .= "- Queue system shows {$queueToday} students today\n\n";
    
    $report .= "## PATTERNS OBSERVED\n\n";
    $report .= "The data indicates a functioning registrar system with standard operations. ";
    if ($pendingDocuments > $totalDocuments * 0.3) {
        $report .= "Document processing shows elevated pending requests that may require attention. ";
    }
    $report .= "RFID card management shows " . $activeCards . " active cards out of " . $totalCards . " total. ";
    $report .= "Student enrollment patterns suggest " . ($activeStudents + $enrolledStudents) . " currently engaged students.\n\n";
    
    $report .= "Generated by: Registrar Information System\n";
    $report .= "AI-generated information is provided for administrative reference.";
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