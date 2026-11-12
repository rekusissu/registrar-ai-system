<?php
// Run: C:/xampp/php/php.exe tests/ProgramDataScopeTest.php. All fixtures roll back.
require_once __DIR__.'/../shared/registrar_access.php';
$db=Database::getInstance();$pdo=$db->getConnection();$saved=$_SESSION;
$tag='QA-GATE-'.bin2hex(random_bytes(6));$checks=0;
$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=function($run)use($check){try{$run();}catch(ProgramScopeDenied $e){$check(true,'');return;}$check(false,'Expected program scope denial');};
$pdo->beginTransaction();
try {
    $_SESSION=[];
    $allowed=(int)$db->insert('students',['student_number'=>$tag.'-IT','first_name'=>'QA','last_name'=>'Allowed','course'=>'BS Information Technology','year_level'=>1,'status'=>'active']);
    $outside=(int)$db->insert('students',['student_number'=>$tag.'-CS','first_name'=>'QA','last_name'=>'Outside','course'=>'BS Computer Science','year_level'=>1,'status'=>'active']);
    $uid=(int)$db->insert('users',['username'=>$tag,'email'=>$tag.'@example.invalid','full_name'=>'QA Gate','role'=>'staff','password_hash'=>password_hash(random_bytes(24),PASSWORD_DEFAULT),'is_active'=>1]);
    $db->insert('user_program_assignments',['user_id'=>$uid,'program_code'=>'BSIT','assigned_by'=>$uid,'assigned_at'=>date('Y-m-d H:i:s')]);
    $_SESSION=['user_id'=>$uid,'role'=>'staff'];
    $tables=['students','enrollments','academic_history','communication_log','contact_change_requests','contact_recipients','discipline_records','document_ai_audit','document_requests','documents','emergency_contacts','enrollment_history','finance','guardian_contact_attempts','guardians','id_card_details','mock_payment_transactions','queue_tickets','rfid_cards','rfid_scan_logs','status_tracker','student_ids','student_notifications','academic_grades','academic_validations','document_request_events','document_request_attachments','mock_lalamove_orders','audit_logs'];
    $installed=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $skipped=[];foreach($tables as $table){if(!in_array($table,$installed,true)){$skipped[]=$table;continue;}$check($db->fetchAll('SELECT * FROM `'.$table.'` WHERE 1=0')===[],"Scoped query failed for $table");}
    $check((int)$db->fetchColumn('SELECT COUNT(*) FROM students WHERE id IN (?,?)',[$allowed,$outside])===1,'Aggregate leaked outside student');
    $check(!$db->fetchOne('SELECT s.* FROM students AS s WHERE s.id=?',[$outside]),'Aliased read leaked');
    $check((int)$db->fetchColumn('SELECT id FROM students WHERE id=?',[$allowed])===$allowed,'Allowed read failed');
    $check($db->update('students',['last_name'=>'Hidden edit'],'id=?',[$outside])===0,'Outside update succeeded');
    $check($db->delete('students','id=?',[$outside])===0,'Outside delete succeeded');
    $denied(fn()=>$db->update('students',['course'=>'BS Computer Science'],'id=?',[$allowed]));
    $denied(fn()=>$db->update('students',['COURSE'=>'BS Computer Science'],'id=?',[$allowed]));
    $denied(fn()=>$db->fetchAll('SELECT * FROM registrar_ai.students'));
    $denied(fn()=>$db->fetchAll('SELECT s.id FROM users u, students s'));
    $denied(fn()=>$db->fetchAll('SELECT * FROM (students)'));
    $denied(fn()=>$db->fetchAll('WITH roster AS (SELECT * FROM students) SELECT * FROM roster'));
    $denied(fn()=>$db->query('UPDATE students s SET s.course=? WHERE s.id=?',['BS Computer Science',$allowed]));
    $check($db->fetchColumn("SELECT 'FROM students JOIN students' FROM students WHERE id=?",[$allowed])==='FROM students JOIN students','Quoted SQL text changed');
    $check(count($db->fetchAll('SELECT id FROM students WHERE id=? UNION SELECT id FROM students WHERE id=?',[$allowed,$outside]))===1,'UNION leaked outside records');
    $locked=ProgramDataScope::rewrite($pdo,'SELECT id FROM students WHERE id=? FOR UPDATE',[$allowed]);
    $check(substr_count($locked,'FOR UPDATE')===2,'Scoped derived table lost its row lock');
    $check((int)$db->fetchColumn('SELECT id FROM students WHERE id=? FOR UPDATE',[$allowed])===$allowed,'Scoped locking query failed');
    $committed=(int)$pdo->query("SELECT MIN(id) FROM students WHERE course='BS Information Technology' AND id NOT IN ($allowed,$outside)")->fetchColumn();
    if($committed){
        $db->fetchColumn('SELECT id FROM students WHERE id=? FOR UPDATE',[$committed]);
        $other=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET SESSION innodb_lock_wait_timeout=1');$other->beginTransaction();$blocked=false;
        try{$other->query('SELECT id FROM students WHERE id='.$committed.' FOR UPDATE');}catch(PDOException $e){if(($e->errorInfo[1]??null)!==1205)throw $e;$blocked=true;}finally{$other->rollBack();}
        $check($blocked,'Scoped SELECT failed to lock its underlying student row');
    }
    $denied(fn()=>$db->insert('students',['student_number'=>$tag.'-BAD','first_name'=>'QA','last_name'=>'Bad','course'=>'BS Computer Science']));
    $denied(fn()=>$db->query('INSERT INTO students (student_number,first_name,last_name,course) VALUES (?,?,?,?),(?,?,?,?)',[$tag.'-A','QA','A','BS Information Technology',$tag.'-B','QA','B','BS Computer Science']));
    $denied(fn()=>$db->query('INSERT INTO students (student_number,first_name,last_name,course) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE course=?',[$tag.'-IT','QA','A','BS Information Technology','BS Computer Science']));
    $_SESSION['role']='department_head';
    $denied(fn()=>$db->update('students',['last_name'=>'Forbidden'],'id=?',[$allowed]));
    $check((int)$db->fetchColumn('SELECT id FROM students WHERE id=?',[$allowed])===$allowed,'Read-only allowed read failed');
    $_SESSION['role']='staff';
    $pdo->prepare('DELETE FROM user_program_assignments WHERE user_id=?')->execute([$uid]);
    $check((int)$db->fetchColumn('SELECT COUNT(*) FROM students')===0,'Unassigned staff read leaked');
    foreach($skipped as $table)echo "SKIP optional table $table (not installed)\n";
    echo "PASS $checks query-gateway scope checks\n";
} finally {$pdo->rollBack();$_SESSION=$saved;}
