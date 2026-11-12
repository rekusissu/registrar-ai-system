<?php
// Printed-card corrections are separate from the student identity and RFID validity.
require_once __DIR__ . '/../shared/security_headers.php';
require_once __DIR__ . '/../shared/session_config.php';
require_once __DIR__ . '/../shared/database.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/csrf_guard.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, private');
function idDetailsFail(int $status, string $message): void { http_response_code($status); echo json_encode(['success'=>false,'message'=>$message]); exit; }
if (!isLoggedIn()) idDetailsFail(401, 'Please sign in.');
if (!in_array(getCurrentUserRole(), ['super_admin','registrar_admin','staff'], true)) idDetailsFail(403, 'Only registrar staff may edit ID details.');
$method=$_SERVER['REQUEST_METHOD'];
if (!in_array($method,['GET','PUT','DELETE'],true)) { header('Allow: GET, PUT, DELETE'); idDetailsFail(405,'Method not allowed.'); }
requireCsrf();
$db=Database::getInstance();$pdo=$db->getConnection();$cardId=(int)($_GET['card_id']??0);
if ($cardId<1) idDetailsFail(422,'Select a card first.');
try {
    if ($method!=='GET') $pdo->beginTransaction();
    $card=$db->fetchOne("SELECT r.id,r.student_id,r.issued_date,r.expiry_date,s.student_number,CONCAT(s.first_name,' ',s.last_name) AS name,s.course,s.address FROM rfid_cards r JOIN students s ON s.id=r.student_id WHERE r.id=?".($method==='GET'?'':' FOR UPDATE'),[$cardId]);
    if (!$card) { if($pdo->inTransaction())$pdo->rollBack(); idDetailsFail(404,'Assigned student card not found.'); }
    $saved=$db->fetchOne('SELECT * FROM id_card_details WHERE rfid_card_id=?',[$cardId]);
    // An old assignment must never supply printed details for a new student.
    if ($saved && (int)$saved['student_id']!==(int)$card['student_id']) $saved=null;
    $version=$saved?(int)$saved['version']:0;
    $sid=$db->fetchOne("SELECT issue_date,expiry_date FROM student_ids WHERE rfid_card_id=? AND student_id=? AND id_type='school_id' ORDER BY id DESC LIMIT 1",[$cardId,$card['student_id']]);
    $contact=$db->fetchOne('SELECT full_name,relationship,contact_number FROM emergency_contacts WHERE student_id=? ORDER BY is_primary DESC,id ASC LIMIT 1',[$card['student_id']]);
    if(!$contact)$contact=$db->fetchOne('SELECT full_name,relationship,contact_number FROM guardians WHERE student_id=? AND is_primary=1 ORDER BY id ASC LIMIT 1',[$card['student_id']]);
    $defaults=['name'=>(string)$card['name'],'program'=>(string)($card['course']??''),'address'=>(string)($card['address']??''),'emergency_name'=>(string)($contact['full_name']??''),'emergency_relationship'=>(string)($contact['relationship']??''),'emergency_phone'=>(string)($contact['contact_number']??''),'issued_date'=>(string)($sid['issue_date']??$card['issued_date']??''),'expiry_date'=>(string)($sid['expiry_date']??$card['expiry_date']??'')];
    $effective=$saved?array_merge($defaults,json_decode($saved['details_json'],true)?:[]):$defaults;
    if($method!=='GET') {
        $input=json_decode(file_get_contents('php://input'),true);
        if(!is_array($input)){ $pdo->rollBack(); idDetailsFail(422,'Invalid request.'); }
        if((int)($input['student_id']??0)!==(int)$card['student_id']){ $pdo->rollBack(); idDetailsFail(409,'This card was reassigned. Close and reopen the preview.'); }
        if(!isset($input['version']) || !is_int($input['version']) || $input['version']!==$version){$pdo->rollBack();idDetailsFail(409,'ID details changed elsewhere. Close and reopen the preview before editing.');}
        $reason=trim((string)($input['reason']??''));
        if($reason==='' || mb_strlen($reason)>255){$pdo->rollBack();idDetailsFail(422,'Enter a reason (up to 255 characters).');}
        $before=$saved?json_decode($saved['details_json'],true):null;
        if($method==='DELETE'){
            $db->query('DELETE FROM id_card_details WHERE rfid_card_id=?',[$cardId]);
            $effective=$defaults;$version=0;$saved=null;
        }else{
            $details=$input['details']??null;
            if(!is_array($details)){ $pdo->rollBack();idDetailsFail(422,'Card details are required.'); }
            $limits=['name'=>100,'program'=>120,'address'=>240,'emergency_name'=>100,'emergency_relationship'=>50,'emergency_phone'=>25,'issued_date'=>10,'expiry_date'=>10];$clean=[];
            foreach($limits as $key=>$max){
                if(!array_key_exists($key,$details)||!is_string($details[$key])){$pdo->rollBack();idDetailsFail(422,'Complete all ID detail fields.');}
                $value=trim($details[$key]);
                if(mb_strlen($value)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)){$pdo->rollBack();idDetailsFail(422,'Invalid or oversized '.str_replace('_',' ',$key).'.');}
                $clean[$key]=$value;
            }
            if($clean['name']===''){$pdo->rollBack();idDetailsFail(422,'Printed name is required.');}
            foreach(['issued_date','expiry_date'] as $key){$date=$clean[$key];$parsed=$date!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;if($date!==''&&(!$parsed||$parsed->format('Y-m-d')!==$date)){$pdo->rollBack();idDetailsFail(422,'Enter a valid printed date.');}}
            if($clean['issued_date']!==''&&$clean['expiry_date']!==''&&$clean['expiry_date']<$clean['issued_date']){$pdo->rollBack();idDetailsFail(422,'Printed expiry must be on or after the issue date.');}
            if($clean['emergency_phone']!==''&&!preg_match('/^\+?[0-9 ()-]{7,25}$/',$clean['emergency_phone'])){$pdo->rollBack();idDetailsFail(422,'Enter a valid emergency phone number.');}
            if(($clean['emergency_phone']!==''||$clean['emergency_relationship']!=='')&&$clean['emergency_name']===''){$pdo->rollBack();idDetailsFail(422,'Enter the emergency contact name.');}
            $version++;
            $db->query('INSERT INTO id_card_details (rfid_card_id,student_id,details_json,reason,version,updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE student_id=VALUES(student_id),details_json=VALUES(details_json),reason=VALUES(reason),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP',[$cardId,$card['student_id'],json_encode($clean,JSON_UNESCAPED_UNICODE),$reason,$version,$_SESSION['user_id']]);
            $saved=true;$effective=$clean;
        }
        logActivity($_SESSION['user_id'],$method==='DELETE'?'id_card_details_reset':'id_card_details_update',$reason,'rfid_cards',$cardId,$before,$method==='DELETE'?null:$effective);
        $pdo->commit();
    }
    echo json_encode(['success'=>true,'data'=>['card_id'=>$cardId,'student_id'=>(int)$card['student_id'],'student_number'=>$card['student_number'],'version'=>$version,'customized'=>(bool)$saved,'defaults'=>$defaults,'details'=>$effective]],JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[id-card-details] '.$e->getMessage());idDetailsFail(500,'Unable to save ID details. Please try again.');}
