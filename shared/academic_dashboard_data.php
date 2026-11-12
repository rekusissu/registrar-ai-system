<?php
require_once __DIR__.'/registrar_access.php';

/** Read-only academic workspace; all derived figures use this authorized roster. */
function academicDashboardData(array $filters=[]): array {
    $db=Database::getInstance();$scope=registrarStudentScope();
    $trend=$db->fetchAll("SELECT a.school_year,a.semester,COUNT(DISTINCT a.student_id) AS students_received,COUNT(DISTINCT a.id) AS records_received,COUNT(g.id) AS subjects_received,SUM(CASE WHEN g.id IS NOT NULL AND (g.final_rating IS NULL OR g.grade_status='incomplete') THEN 1 ELSE 0 END) AS missing_grades FROM academic_history a JOIN students s ON s.id=a.student_id LEFT JOIN academic_grades g ON g.academic_history_id=a.id WHERE {$scope['sql']} GROUP BY a.school_year,a.semester ORDER BY a.school_year DESC,a.semester DESC LIMIT 8",$scope['params']);
    $periods=$db->fetchAll("SELECT DISTINCT a.school_year,a.semester FROM academic_history a JOIN students s ON s.id=a.student_id WHERE {$scope['sql']} AND a.school_year IS NOT NULL ORDER BY a.school_year DESC,FIELD(LOWER(a.semester),'summer','2nd','1st')",$scope['params']);
    $sy=trim((string)($filters['school_year']??($periods[0]['school_year']??'')));
    $sem=trim((string)($filters['semester']??($periods[0]['semester']??'1st')));
    $students=$db->fetchAll("SELECT s.id,s.student_number,s.first_name,s.middle_name,s.last_name,s.course,s.major,s.year_level,s.section,s.status,s.school_year,s.semester FROM students s WHERE {$scope['sql']} ORDER BY s.last_name,s.first_name,s.id",$scope['params']);
    $terms=$db->fetchAll("SELECT a.id,a.student_id,a.school_year,a.semester,a.accepted_at,
        (SELECT COUNT(*) FROM academic_grades g WHERE g.academic_history_id=a.id) AS subject_count,
        (SELECT COUNT(*) FROM academic_grades g WHERE g.academic_history_id=a.id AND (g.final_rating IS NULL OR g.grade_status='incomplete')) AS missing_grades,
        v.review_status,v.remarks AS review_remarks,v.created_at AS reviewed_at
        FROM academic_history a JOIN students s ON s.id=a.student_id
        LEFT JOIN academic_validations v ON v.id=(SELECT MAX(v2.id) FROM academic_validations v2 WHERE v2.academic_history_id=a.id)
        WHERE {$scope['sql']} AND a.school_year=? AND LOWER(a.semester)=LOWER(?) ORDER BY a.id DESC",array_merge($scope['params'],[$sy,$sem]));
    $byStudent=[];foreach($terms as $term){$sid=(int)$term['student_id'];$byStudent[$sid][]=$term;}
    $programs=[];$years=[];$sections=[];$statuses=[];$stats=['students'=>count($students),'received'=>0,'pending'=>0,'incomplete'=>0,'needs_correction'=>0,'reviewed'=>0,'accepted'=>0];
    foreach($students as &$s){
        $s['id']=(int)$s['id'];$s['year_level']=$s['year_level']===null?null:(int)$s['year_level'];
        $records=$byStudent[$s['id']]??[];$term=$records[0]??null;
        $s['academic_record_id']=$term?(int)$term['id']:null;$s['record_count']=count($records);
        $s['subject_count']=$term?(int)$term['subject_count']:0;$s['missing_grades']=$term?(int)$term['missing_grades']:0;
        $s['review_status']=$term['review_status']??null;$s['review_remarks']=$term['review_remarks']??null;
        $s['record_state']=!$term?'pending':(($s['subject_count']===0||$s['missing_grades']>0||count($records)>1||$s['review_status']==='incomplete')?'incomplete':'received');
        if($term)$stats['received']++;else $stats['pending']++;
        if($s['record_state']==='incomplete')$stats['incomplete']++;
        if($s['review_status']==='needs_correction')$stats['needs_correction']++;
        if($s['review_status']==='reviewed')$stats['reviewed']++;
        if(!empty($term['accepted_at']))$stats['accepted']++;
        $p=trim((string)$s['course'])?:'Program not assigned';$programs[$p]=($programs[$p]??0)+1;
        $y=$s['year_level']?($s['year_level']>=11?'Grade ':'Year ').$s['year_level']:'Level not assigned';$years[$y]=($years[$y]??0)+1;
        $section=trim((string)$s['section'])?:'Section not assigned';$sections[$section]=($sections[$section]??0)+1;
        $status=(string)$s['status'];$statuses[$status]=($statuses[$status]??0)+1;
    }unset($s);
    $activity=$db->fetchAll("SELECT v.created_at,v.review_status,u.full_name AS reviewer,CONCAT_WS(' ',s.first_name,s.last_name) AS student_name,a.school_year,a.semester FROM academic_validations v JOIN academic_history a ON a.id=v.academic_history_id JOIN students s ON s.id=a.student_id LEFT JOIN users u ON u.id=v.reviewer_id WHERE {$scope['sql']} ORDER BY v.id DESC LIMIT 8",$scope['params']);
    return ['updated_at'=>date(DATE_ATOM),'school_year'=>$sy,'semester'=>$sem,'periods'=>$periods,'submission_trend'=>$trend,'stats'=>$stats,'students'=>$students,'programs'=>$programs,'years'=>$years,'sections'=>$sections,'statuses'=>$statuses,'activity'=>$activity,'assigned_programs'=>registrarAssignedPrograms(),'all_programs'=>registrarHasAllPrograms()];
}
