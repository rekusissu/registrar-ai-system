<?php
// Shared row-by-row classification used by preview and the final database write.
require_once __DIR__ . '/functions.php';
function rfidBatchInput(array $input): array {
    $uids=$input['uids']??null;
    if(!is_array($uids)||!array_is_list($uids)||count($uids)<1||count($uids)>1000)throw new InvalidArgumentException('Enter between 1 and 1,000 card UIDs.');
    foreach($uids as &$uid){if(!is_string($uid)||strlen($uid)>256)throw new InvalidArgumentException('Each UID must be text, up to 256 characters.');$uid=trim($uid);}
    unset($uid);
    if(!array_filter($uids,fn($uid)=>$uid!==''))throw new InvalidArgumentException('Enter at least one card UID.');
    return $uids;
}
function rfidBatchPreview(Database $db,array $uids): array {
    $valid=array_values(array_unique(array_filter($uids,fn($uid)=>preg_match('/^\d{10}$/D',$uid))));$existing=[];
    if($valid){$marks=implode(',',array_fill(0,count($valid),'?'));foreach($db->fetchAll("SELECT card_uid,status FROM rfid_cards WHERE card_uid IN ($marks)",$valid) as $card)$existing[$card['card_uid']]=$card['status'];}
    $entries=[];$seen=[];$ready=[];$already=[];$dupes=[];$invalid=[];
    foreach($uids as $index=>$uid){if($uid==='')continue;$row=['index'=>$index,'uid'=>$uid,'status'=>'ready'];
        if(!preg_match('/^\d{10}$/D',$uid)){$row['status']='invalid';$invalid[]=$uid;}
        elseif(array_key_exists($uid,$seen)){$row['status']='duplicate';$row['duplicate_of']=$seen[$uid];$dupes[]=$uid;}
        else{$seen[$uid]=$index;if(isset($existing[$uid])){$row['status']='existing';$row['inventory_status']=$existing[$uid];$already[]=['uid'=>$uid,'status'=>$existing[$uid]];}else{$ready[]=$uid;}}
        $entries[]=$row;
    }
    return ['summary'=>['total_input'=>count($entries),'new'=>count($ready),'already_registered'=>count($already),'duplicates_in_list'=>count($dupes),'format_errors'=>count($invalid)],'entries'=>$entries,'new_uids'=>$ready,'already_registered'=>$already,'dupes_in_list'=>$dupes,'format_errors'=>$invalid];
}
function rfidBatchRegister(Database $db,array $uids,string $notes): array {
    $pdo=$db->getConnection();$pdo->beginTransaction();
    try{
        $preview=rfidBatchPreview($db,$uids);$registered=[];$raced=[];
        foreach($preview['new_uids'] as $uid){
            try{$db->insert('rfid_cards',['card_uid'=>$uid,'card_type'=>'rfid','status'=>'available','student_id'=>null,'notes'=>$notes?:null,'registered_at'=>date('Y-m-d H:i:s')]);$registered[]=$uid;}
            catch(PDOException $e){if($e->getCode()!=='23000'||!$db->fetchOne('SELECT id FROM rfid_cards WHERE card_uid=? FOR UPDATE',[$uid]))throw $e;$raced[]=$uid;}
        }
        foreach($preview['entries'] as &$entry){if($entry['status']==='ready')$entry['status']=in_array($entry['uid'],$registered,true)?'registered':'existing';}unset($entry);
        if($registered)logActivity($_SESSION['user_id'],'rfid_bulk_register','Registered '.count($registered).' unassigned cards','rfid_cards',null,null,['uids'=>$registered]);
        $pdo->commit();
        return ['registered'=>count($registered),'registered_uids'=>$registered,'skipped'=>array_merge(array_column($preview['already_registered'],'uid'),$raced),'errors'=>$preview['format_errors'],'dupes_in_list'=>$preview['dupes_in_list'],'entries'=>$preview['entries'],'total_input'=>$preview['summary']['total_input'],'skipped_count'=>$preview['summary']['total_input']-count($registered)];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
