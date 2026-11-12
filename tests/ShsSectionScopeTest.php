<?php
require_once __DIR__.'/../shared/registrar_access.php';
require_once __DIR__.'/../shared/shs_catalog.php';
require_once __DIR__.'/../shared/section_code.php';
$db=Database::getInstance();$pdo=$db->getConnection();$saved=$_SESSION;$_SESSION=[];$tag='QA-SECTION-'.bin2hex(random_bytes(5));$checks=0;$programs=shsPrograms();$campuses=array_keys($programs);$course=$campuses[0];$major=$programs[$course][0];
$expectInvalid=function($run)use(&$checks){try{$run();}catch(InvalidArgumentException $e){$checks++;return;}throw new RuntimeException('Invalid SHS section placement accepted');};
$pdo->beginTransaction();
try {
    $base=['first_name'=>'QA','last_name'=>'Section','course'=>$course,'major'=>$major,'year_level'=>11,'semester'=>'1st','school_year'=>'2026-2027','status'=>'active'];
    $one=(int)$db->insert('students',array_merge($base,['student_number'=>$tag.'-1']));
    $two=(int)$db->insert('students',array_merge($base,['student_number'=>$tag.'-2','major'=>$programs[$course][1]]));
    $college=(int)$db->insert('students',array_merge($base,['student_number'=>$tag.'-C','course'=>'BS Information Technology','major'=>'','year_level'=>1]));
    $fields=['section'=>'111999'];
    validateShsSectionAssignment($db,[$one],$fields);$checks++;
    $expectInvalid(fn()=>validateShsSectionAssignment($db,[$one,$college],$fields));
    $expectInvalid(fn()=>validateShsSectionAssignment($db,[$one,$two],$fields));
    $expectInvalid(fn()=>validateShsSectionAssignment($db,[$one],['section'=>'111999','year_level'=>1]));
    $expectInvalid(fn()=>validateShsSectionAssignment($db,[$one],['section'=>'121999']));
    $db->update('students',['major'=>$major,'school_year'=>'2025-2026'],'id=?',[$two]);
    $expectInvalid(fn()=>validateShsSectionAssignment($db,[$one,$two],$fields));
    $db->update('students',['course'=>$campuses[1],'major'=>$programs[$campuses[1]][0],'semester'=>'T1','school_year'=>'2026-2027'],'id=?',[$two]);
    $expectInvalid(fn()=>validateShsSectionAssignment($db,[$one,$two],$fields));
    echo "PASS $checks SHS section boundary checks\n";
}finally{$pdo->rollBack();$_SESSION=$saved;}
