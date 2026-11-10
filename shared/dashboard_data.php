<?php
/** Read-only registrar dashboard snapshot. Shared by page load and polling. */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

function registrarDashboardData(): array {
    $db = Database::getInstance();
    $students = $db->fetchAll('SELECT status, COUNT(*) AS count FROM students GROUP BY status');
    $statusCounts = array_fill_keys(studentStatuses(), 0);
    foreach ($students as $row) $statusCounts[(string)$row['status']] = (int)$row['count'];
    $totalStudents = array_sum($statusCounts);
    $statuses = [];
    foreach ($statusCounts as $key => $count) {
        $meta = studentStatusMeta($key);
        $statuses[] = ['key'=>$key, 'label'=>$meta['label'] ?? ucfirst($key), 'count'=>$count];
    }
    $docRows = $db->fetchAll("SELECT document_status, COUNT(*) AS count FROM document_requests WHERE document_status <> 'Draft' GROUP BY document_status");
    $documents = [];
    foreach ($docRows as $row) $documents[(string)$row['document_status']] = (int)$row['count'];
    $pending = 0;
    foreach (['Filed','Pending_Clearance','Awaiting_Payment','Processing'] as $state) $pending += $documents[$state] ?? 0;
    $cards = $db->fetchOne("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'active'),0) AS active FROM rfid_cards");
    $zone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $zone);
    $start = $now->modify('first day of this month')->setTime(0,0)->modify('-5 months');
    $end = $now->modify('first day of next month')->setTime(0,0);
    $monthly = $db->fetchAll("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS count FROM students WHERE created_at >= ? AND created_at < ? GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month", [$start->format('Y-m-d'),$end->format('Y-m-d')]);
    $map = [];
    foreach ($monthly as $row) $map[$row['month']] = (int)$row['count'];
    $trend = [];
    for ($i=0;$i<6;$i++) {
        $month = $start->modify("+$i months");
        $trend[] = ['label'=>$month->format('M'), 'month'=>$month->format('M Y'), 'count'=>$map[$month->format('Y-m')] ?? 0];
    }
    $courses = $db->fetchAll("SELECT COALESCE(NULLIF(TRIM(course),''),'Not assigned') AS label, COUNT(*) AS count FROM students GROUP BY COALESCE(NULLIF(TRIM(course),''),'Not assigned') ORDER BY count DESC, label ASC LIMIT 5");
    foreach ($courses as &$course) $course['count'] = (int)$course['count'];
    unset($course);
    $recent = $db->fetchAll("SELECT dr.id, dr.request_id, dr.document_type, dr.document_status, dr.request_date, CONCAT_WS(' ', s.first_name,s.last_name) AS student_name, s.student_number FROM document_requests dr LEFT JOIN students s ON s.id = dr.student_id WHERE dr.document_status <> 'Draft' ORDER BY dr.request_date DESC,dr.id DESC LIMIT 6");
    return [
        'updated_at'=>$now->format(DateTimeInterface::ATOM),
        'stats'=>['students'=>$totalStudents,'active_students'=>($statusCounts['enrolled']??0)+($statusCounts['active']??0),'pending'=>$pending,'ready'=>$documents['Ready']??0,'active_cards'=>(int)$cards['active'],'total_cards'=>(int)$cards['total'],'documents'=>array_sum($documents),'claimed'=>$documents['Claimed']??0],
        'trend'=>$trend,'courses'=>$courses,'statuses'=>$statuses,'document_states'=>$documents,'recent'=>$recent
    ];
}
