<?php
/** Summaries use authorized aggregate counts only; never send student identities. */
function academicAnalytics(array $snapshot): array {
    $students=$snapshot['students'];$total=count($students);$missing=0;$duplicates=0;$subjects=0;
    foreach($students as $student){$missing+=(int)$student['missing_grades'];$subjects+=(int)$student['subject_count'];if($student['record_count']>1)$duplicates++;}
    $stats=$snapshot['stats'];
    $counts=['students'=>$total,'received'=>(int)$stats['received'],'pending'=>(int)$stats['pending'],'incomplete'=>(int)$stats['incomplete'],'needs_correction'=>(int)$stats['needs_correction'],'reviewed'=>(int)$stats['reviewed'],'subjects'=>$subjects,'missing_grades'=>$missing,'duplicate_terms'=>$duplicates];
    $rate=$total?round(100*$counts['received']/$total,1):0;
    $summary="Authorized students: $total. With term records: {$counts['received']} ($rate%). Awaiting records: {$counts['pending']}. Incomplete records: {$counts['incomplete']}. Missing final grades: $missing. Students with multiple records for the selected period: $duplicates. Needs correction: {$counts['needs_correction']}. Reviewed: {$counts['reviewed']}.";
    $periods=$snapshot['submission_trend']??[];
    if($periods){$summary.="\n\nReceived records by period for the currently authorized roster (not historical enrollment totals):";foreach($periods as $period)$summary.="\n{$period['school_year']} {$period['semester']}: students {$period['students_received']}, records {$period['records_received']}, missing grades {$period['missing_grades']}.";}
    $recommendations=[];
    if($counts['pending'])$recommendations[]='Request missing term submissions from the responsible academic office.';
    if($missing)$recommendations[]='Reconcile missing final grades with Curriculum before certification.';
    if($duplicates)$recommendations[]='Review duplicate term records against source references; preserve source history.';
    if($counts['needs_correction'])$recommendations[]='Resolve correction remarks through the local review queue; external forwarding is disabled.';
    if(!$recommendations)$recommendations[]=$total?'Continue periodic completeness checks; received records do not prove curriculum completeness.':'Assign programs or select a period with authorized records.';
    return ['counts'=>$counts,'periods'=>$periods,'received_percent'=>$rate,'summary'=>$summary,'recommendations'=>$recommendations,'limitation'=>'Completeness reflects received rows, not a confirmed curriculum checklist. No grades or student statuses are changed.'];
}
