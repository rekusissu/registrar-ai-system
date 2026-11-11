<?php
// One transaction for both pool assignment and entering a new UID.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/qr_generator.php';
function assignRfidCredential(Database $db, array $input, bool $fromPool): array {
    $studentId=filter_var($input['student_id']??null,FILTER_VALIDATE_INT);
    if(!$studentId||$studentId<1)throw new InvalidArgumentException('Select a student.');
    $dates=[];
    foreach(['issued_date','expiry_date'] as $key){
        $date=$input[$key]??null;
        if(!is_string($date))throw new InvalidArgumentException('Issue and expiry dates are required.');
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Enter valid issue and expiry dates.');
        $dates[$key]=$date;
    }
    if($dates['expiry_date']<$dates['issued_date'])throw new InvalidArgumentException('Expiry date must be on or after the issue date.');
    if(!is_string($input['notes']??''))throw new InvalidArgumentException('Invalid notes.');
    $notes=trim($input['notes']??'');if(mb_strlen($notes)>1000)throw new InvalidArgumentException('Notes must be 1,000 characters or fewer.');
    $cardId=$fromPool?filter_var($input['card_id']??null,FILTER_VALIDATE_INT):null;
    $uid=$fromPool?'':trim((string)($input['card_uid']??''));
    if($fromPool&&(!$cardId||$cardId<1))throw new InvalidArgumentException('Select a card from the available pool.');
    if(!$fromPool&&!preg_match('/^\d{10}$/',$uid))throw new InvalidArgumentException('Card UID must be exactly 10 digits.');
    $pdo=$db->getConnection();$pdo->beginTransaction();
    try{
        // Lock the student first so two requests cannot issue two active cards.
        $student=$db->fetchOne('SELECT id FROM students WHERE id=? FOR UPDATE',[$studentId]);
        if(!$student)throw new InvalidArgumentException('Student no longer exists. Reopen the assignment form.');
        if($db->fetchOne("SELECT id FROM rfid_cards WHERE student_id=? AND status='active' LIMIT 1",[$studentId]))throw new RuntimeException('Student already has an active RFID card.',409);
        $card=$fromPool?$db->fetchOne('SELECT id,card_uid,status,student_id FROM rfid_cards WHERE id=? FOR UPDATE',[$cardId]):$db->fetchOne('SELECT id,card_uid,status,student_id FROM rfid_cards WHERE card_uid=? FOR UPDATE',[$uid]);
        if($fromPool&&(!$card||$card['status']!=='available'||!empty($card['student_id'])))throw new RuntimeException('This card is no longer available. Choose another card.',409);
        if(!$fromPool&&$card)throw new RuntimeException('This UID is already registered. Select it from the available pool or use a new UID.',409);
        $qrPath=generateStudentQrFile((int)$studentId);
        if(!$qrPath)throw new RuntimeException('Unable to generate the verification QR code. No card was assigned.');
        $values=['student_id'=>$studentId,'status'=>'active','issued_date'=>$dates['issued_date'],'expiry_date'=>$dates['expiry_date'],'assigned_at'=>date('Y-m-d H:i:s'),'notes'=>$notes?:null];
        if($fromPool){$uid=$card['card_uid'];$db->update('rfid_cards',$values,'id=?',[$cardId]);}
        else{$cardId=(int)$db->insert('rfid_cards',array_merge($values,['card_uid'=>$uid,'card_type'=>'rfid']));}
        $existing=$db->fetchOne("SELECT id FROM student_ids WHERE student_id=? AND id_type='school_id' AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$studentId]);
        $idValues=['rfid_card_id'=>$cardId,'issue_date'=>$dates['issued_date'],'expiry_date'=>$dates['expiry_date'],'qr_code_path'=>$qrPath];
        if($existing)$db->update('student_ids',$idValues,'id=?',[$existing['id']]);
        else$db->insert('student_ids',array_merge($idValues,['student_id'=>$studentId,'id_type'=>'school_id','id_number'=>'','status'=>'active']));
        logActivity($_SESSION['user_id'],'rfid_assign','Assigned RFID card and student ID card','rfid_cards',$cardId);
        $pdo->commit();
        return ['id'=>(int)$cardId,'card_uid'=>$uid];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
