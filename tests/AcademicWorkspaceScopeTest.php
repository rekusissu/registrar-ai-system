<?php
// Integration check against the configured local DB. Every fixture rolls back.
require_once __DIR__.'/../shared/academic_dashboard_data.php';
require_once __DIR__.'/../shared/dashboard_data.php';
$db=Database::getInstance();$pdo=$db->getConnection();$savedSession=$_SESSION;$tag='QA-SCOPE-'.bin2hex(random_bytes(6));$checks=0;
$pdo->beginTransaction();
try {
    $allowed=(int)$db->insert('students',['student_number'=>$tag.'-IT','first_name'=>'QA','last_name'=>'Allowed Scope','course'=>'BS Information Technology','year_level'=>1,'status'=>'active']);
    $outside=(int)$db->insert('students',['student_number'=>$tag.'-CS','first_name'=>'QA','last_name'=>'Outside Scope','course'=>'BS Computer Science','year_level'=>1,'status'=>'active']);
    foreach([$allowed,$outside] as $sid)$db->insert('academic_history',['student_id'=>$sid,'school_name'=>'QA only','school_year'=>'2026-2027','semester'=>'1st']);
    foreach(['acad_officer','department_head','staff'] as $role) {
        $uid=(int)$db->insert('users',['username'=>$tag.'-'.$role,'email'=>$tag.'-'.$role.'@example.invalid','full_name'=>'QA Scope','role'=>$role,'password_hash'=>password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'is_active'=>1]);
        $db->insert('user_program_assignments',['user_id'=>$uid,'program_code'=>'BSIT','assigned_by'=>$uid,'assigned_at'=>date('Y-m-d H:i:s')]);
        $_SESSION['user_id']=$uid;$_SESSION['role']=$role;
        $snapshot=academicDashboardData(['school_year'=>'2026-2027','semester'=>'1st']);$ids=array_column($snapshot['students'],'id');
        if(!in_array($allowed,$ids,true)||in_array($outside,$ids,true))throw new RuntimeException("$role roster scope failed");$checks++;
        if(registrarCanReadStudent($outside)||!registrarCanReadStudent($allowed))throw new RuntimeException("$role ownership scope failed");$checks++;
        foreach($snapshot['students'] as $student)if(registrarProgramCode((string)$student['course'])!=='BSIT')throw new RuntimeException("$role program data leaked");$checks++;
        if($role==='staff') {
            $registrar=registrarDashboardData();
            if($registrar['stats']['students']!==count($ids))throw new RuntimeException('Registrar dashboard total is not scoped');$checks++;
            foreach($registrar['courses'] as $course)if(registrarProgramCode($course['label'])!=='BSIT')throw new RuntimeException('Registrar dashboard distribution leaked');$checks++;
        } else {
            $db->query('DELETE FROM user_program_assignments WHERE user_id=?',[$uid]);
            $empty=academicDashboardData();if($empty['students']||$empty['activity']||$empty['periods']||$empty['stats']['students']!==0)throw new RuntimeException('Unassigned academic reader received records');$checks++;
        }
    }
    echo "PASS $checks dashboard, roster, ownership and unassigned-reader scope checks\n";
} finally {
    $pdo->rollBack();$_SESSION=$savedSession;
}
