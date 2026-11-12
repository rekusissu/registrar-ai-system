<?php
require_once __DIR__.'/registrar_access.php';
/** Source grades remain unchanged. No assumed curriculum or passing threshold. */
function academicRecordDiagnostics(int $id): array {
    $db=Database::getInstance();$record=$db->fetchOne('SELECT a.*,s.course,s.major,s.year_level,s.student_number FROM academic_history a JOIN students s ON s.id=a.student_id WHERE a.id=?',[$id]);
    if(!$record)throw new RuntimeException('Academic record not found.');
    registrarRequireStudent((int)$record['student_id']);
    $grades=$db->fetchAll('SELECT subject,subject_code,final_rating,grade_status,source_system,source_ref,received_at FROM academic_grades WHERE academic_history_id=? ORDER BY id',[$id]);
    $issues=[];$seen=[];$sources=[];$latest=null;$shs=(int)$record['year_level']>=11;
    if(!$grades)$issues[]='No subjects received.';
    foreach($grades as $grade){
        $label=trim((string)($grade['subject_code']?:$grade['subject']));$key=mb_strtolower($label);
        if($label==='')$issues[]='A received subject has no name or code.';
        elseif(isset($seen[$key]))$issues[]='Possible duplicate subject: '.$label;
        $seen[$key]=true;
        if($grade['final_rating']===null||$grade['final_rating']===''||$grade['grade_status']==='incomplete')$issues[]='Missing or incomplete final grade: '.($label?:'unnamed subject');
        elseif(!is_numeric($grade['final_rating'])||($shs?((float)$grade['final_rating']<0||(float)$grade['final_rating']>100):((float)$grade['final_rating']<1||(float)$grade['final_rating']>5)))$issues[]='Grade outside the '.($shs?'0–100 SHS':'1–5 college').' scale: '.$label;
        $source=trim((string)$grade['source_system'])?:'Unspecified source';$sources[$source]=true;
        if($grade['received_at']&&($latest===null||$grade['received_at']>$latest))$latest=$grade['received_at'];
    }
    $duplicates=(int)$db->fetchColumn('SELECT COUNT(*) FROM academic_history WHERE student_id=? AND school_year=? AND LOWER(semester)=LOWER(?)',[$record['student_id'],$record['school_year'],$record['semester']]);
    if($duplicates>1)$issues[]='Multiple academic records exist for this student and period.';
    if(!trim((string)$record['school_year'])||!trim((string)$record['semester']))$issues[]='Academic period information is incomplete.';
    if(!trim((string)$record['course'])||!trim((string)$record['student_number']))$issues[]='Student program or number is missing.';
    $receipts=array_map(static fn($g)=>['subject'=>$g['subject_code']?:$g['subject'],'source'=>$g['source_system'],'source_reference'=>$g['source_ref'],'received_at'=>$g['received_at']],$grades);
    return ['issues'=>array_values(array_unique($issues)),'subjects_received'=>count($grades),'sources'=>array_keys($sources),'source_receipts'=>$receipts,'last_received_at'=>$latest,'grading_scale'=>$shs?'SHS percentage; no college GWA':'College 1–5','limitation'=>'Expected subjects cannot be verified until an approved curriculum checklist is connected.'];
}
