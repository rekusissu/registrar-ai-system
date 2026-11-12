<?php
/** Program boundaries at the application's single PDO query gateway.
 * SELECT table sources become scoped derived tables; predicates are composed
 * before aggregates/joins run. Tokens preserve quoted strings and comments.
 * Mutations are constrained independently, including raw query() writes.
 */
final class ProgramScopeDenied extends RuntimeException {}
final class ProgramDataScope {
    private const DIRECT=['academic_history','communication_log','contact_change_requests','contact_recipients','discipline_records','document_ai_audit','document_requests','documents','emergency_contacts','enrollment_history','finance','guardian_contact_attempts','guardians','id_card_details','mock_payment_transactions','queue_tickets','rfid_cards','rfid_scan_logs','status_tracker','student_ids','student_notifications'];
    public static function programCode(string $value): string {
        if(preg_match('/\(([A-Za-z0-9]+)\)/',$value,$m))return strtoupper($m[1]);
        $aliases=['BS Information Technology'=>'BSIT','BS Computer Science'=>'BSCS','BS Hospitality Management'=>'BSHM'];
        foreach($aliases as $name=>$code)if(strcasecmp(trim($value),$name)===0)return $code;
        return strtoupper(trim($value));
    }
    public static function context(PDO $pdo): ?array {
        $role=$_SESSION['role']??'';$uid=(int)($_SESSION['user_id']??0);
        if(!$uid||!in_array($role,['staff','acad_officer','department_head'],true))return null;
        // Public kiosk/verification routes retain their existing public contract.
        $script=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');
        if(preg_match('~/(api/queue-public\.php|queue/(kiosk|monitor)\.php|verify\.php)$~',$script))return null;
        $q=$pdo->prepare('SELECT program_code FROM user_program_assignments WHERE user_id=?');$q->execute([$uid]);$codes=$q->fetchAll(PDO::FETCH_COLUMN);
        if($role==='staff'&&!$codes){$q=$pdo->prepare('SELECT username FROM users WHERE id=?');$q->execute([$uid]);if(in_array($q->fetchColumn(),['RGS-001','demo.staff'],true))return null;}
        $labels=[];if($codes){foreach($pdo->query("SELECT DISTINCT course FROM students WHERE course IS NOT NULL UNION SELECT DISTINCT course FROM enrollments WHERE course IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $course)if(in_array(self::programCode((string)$course),$codes,true))$labels[]=$course;
            // Codes also work before the first enrollment record is created.
            foreach($codes as $code)$labels[]=$code;
            if(function_exists('getOfferedCourses'))foreach(array_keys(getOfferedCourses()) as $course)if(in_array(self::programCode($course),$codes,true))$labels[]=$course;
        }
        $values=implode(',',array_map([$pdo,'quote'],array_values(array_unique($labels))));
        return ['codes'=>$codes,'course'=>$values!==''?'course IN ('.$values.')':'1=0','values'=>$values];
    }
    private static function predicate(string $table,array $ctx): ?string {
        if(in_array($table,['students','enrollments'],true))return $ctx['course'];
        $ids='SELECT _ps.id FROM students _ps WHERE '.($ctx['values']!==''?'_ps.course IN ('.$ctx['values'].')':'1=0');
        if(in_array($table,self::DIRECT,true)){
            $p='student_id IN ('.$ids.')';
            if($table==='rfid_cards')$p='student_id IS NULL OR '.$p; // Shared unassigned inventory.
            if($table==='queue_tickets')$p='('.$p.') OR (student_id IS NULL AND '.$ctx['course'].')';
            return '('.$p.')';
        }
        if(in_array($table,['academic_grades','academic_validations'],true))return 'academic_history_id IN (SELECT _ph.id FROM academic_history _ph WHERE _ph.student_id IN ('.$ids.'))';
        if(in_array($table,['document_request_events','document_request_attachments','mock_lalamove_orders'],true))return 'request_id IN (SELECT _pr.id FROM document_requests _pr WHERE _pr.student_id IN ('.$ids.'))';
        // Legacy staff bells read the audit feed. Scoped accounts see their own
        // audit events; academic/student activity is available in scoped views.
        if($table==='audit_logs')return 'user_id='.(int)($_SESSION['user_id']??0);
        return null;
    }
    private static function tokens(string $sql): array {
        preg_match_all('~\s+|/\*[\s\S]*?\*/|--[^\r\n]*|\#[^\r\n]*|\'(?:\\\\.|\'\'|[^\'])*\'|"(?:\\\\.|""|[^"])*"|`(?:``|[^`])*`|[A-Za-z_][A-Za-z0-9_]*|[0-9]+|.~',$sql,$m,PREG_OFFSET_CAPTURE);
        return array_values(array_filter($m[0],static fn($t)=>!preg_match('~^(\s|/\*|--|\#)~',$t[0])));
    }
    public static function rewrite(PDO $pdo,string $sql,array $params): string {
        $ctx=self::context($pdo);if($ctx===null)return $sql;$ts=self::tokens($sql);if(!$ts)return $sql;$verb=strtoupper($ts[0][0]);
        // ponytail: application SQL uses unqualified tables and explicit JOINs; reject unsupported scoped grammar.
        if($verb==='WITH')self::deny();
        foreach($ts as $i=>$token){
            if($token[0]==='.'&&isset($ts[$i+1])&&self::predicate(strtolower(trim($ts[$i+1][0],'`')),$ctx)!==null)self::deny();
            if($token[0]===','&&isset($ts[$i+1])&&self::predicate(strtolower(trim($ts[$i+1][0],'`')),$ctx)!==null)self::deny();
            if(in_array(strtoupper($token[0]),['FROM','JOIN'],true)&&($ts[$i+1][0]??'')==='('){$j=$i+1;while(($ts[$j][0]??'')==='(')$j++;if(self::predicate(strtolower(trim($ts[$j][0]??'','`')),$ctx)!==null)self::deny();}
        }
        if(in_array($verb,['SELECT','WITH'],true)){
            $edits=[];$reserved=['WHERE','ON','USING','LEFT','RIGHT','INNER','OUTER','CROSS','JOIN','GROUP','ORDER','HAVING','LIMIT','UNION','FOR','SET','LOCK','OFFSET','WINDOW','PARTITION'];
            foreach($ts as $i=>$token){if(!in_array(strtoupper($token[0]),['FROM','JOIN'],true)||!isset($ts[$i+1]))continue;
                $name=strtolower(trim($ts[$i+1][0],'`'));$predicate=self::predicate($name,$ctx);if($predicate===null)continue;
                $next=$ts[$i+2][0]??'';$explicit=strtoupper($next)==='AS'||(preg_match('/^`?[A-Za-z_][A-Za-z0-9_]*`?$/',$next)&&!in_array(strtoupper($next),$reserved,true));
                $locking=preg_match('/\bFOR\s+UPDATE\b/i',$sql)?' FOR UPDATE':(preg_match('/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',$sql)?' LOCK IN SHARE MODE':'');
                $replacement='(SELECT * FROM `'.$name.'` WHERE '.$predicate.$locking.')'.($explicit?'':' AS `'.$name.'`');$edits[]=[$ts[$i+1][1],strlen($ts[$i+1][0]),$replacement];
            }
            foreach(array_reverse($edits) as [$start,$len,$replacement])$sql=substr_replace($sql,$replacement,$start,$len);return $sql;
        }
        if(in_array($verb,['INSERT','REPLACE'],true)){
            // ponytail: one bound VALUES row only; add per-row validation before supporting bulk/upsert SQL.
            if(preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+`?(\w+)`?\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)\s*;?\s*$/i',$sql,$m)){
                $table=strtolower($m[1]);if(self::predicate($table,$ctx)!==null){self::requireWriteRole($table);$cols=array_map(static fn($s)=>strtolower(trim($s," \t\n\r`")),explode(',',$m[2]));$values=array_map('trim',explode(',',$m[3]));if(count($cols)!==count($values)||array_filter($values,static fn($v)=>$v!=='?'))self::deny();self::validateValues($pdo,$table,array_combine($cols,array_slice($params,0,count($cols))),$ctx);}
            }elseif(preg_match('/\bINTO\s+`?(\w+)/i',$sql,$m)&&self::predicate(strtolower($m[1]),$ctx)!==null)self::deny();
            return $sql;
        }
        if(in_array($verb,['UPDATE','DELETE'],true)){
            if($verb==='DELETE'&&strtoupper($ts[1][0]??'')!=='FROM') {foreach($ts as $t)if(self::predicate(strtolower(trim($t[0],'`')),$ctx)!==null)self::deny();return $sql;}
            $index=$verb==='UPDATE'?1:2;$table=strtolower(trim($ts[$index][0]??'','`'));$predicate=self::predicate($table,$ctx);if($predicate===null){foreach($ts as $token)if(self::predicate(strtolower(trim($token[0],'`')),$ctx)!==null)self::deny();return $sql;}
            if($verb==='UPDATE'&&strtoupper($ts[2][0]??'')!=='SET')self::deny();
            self::requireWriteRole($table);
            if($verb==='DELETE'&&strtoupper($ts[1][0]??'')!=='FROM')self::deny();
            $depth=0;$where=null;$tail=strlen(rtrim($sql," ;\t\r\n"));$set=null;
            foreach($ts as $i=>$token){$word=strtoupper($token[0]);if($word==='(')$depth++;elseif($word===')')$depth--;elseif($depth===0){if($word==='JOIN')self::deny();if($word==='SET')$set=$token[1]+strlen($token[0]);if($word==='WHERE')$where=$token[1];if(in_array($word,['ORDER','LIMIT','RETURNING'],true)){$tail=$token[1];break;}}}
            if($verb==='UPDATE'&&$set!==null){$end=$where??$tail;$setSql=substr($sql,$set,$end-$set);$bind=0;$values=[];foreach(explode(',',$setSql) as $assignment){if(preg_match('/^\s*`?(\w+)`?\s*=\s*(.*?)\s*$/s',$assignment,$m)){$m[1]=strtolower($m[1]);$rhs=$m[2];if($rhs==='?')$values[$m[1]]=$params[$bind]??null;elseif(strcasecmp($rhs,'NULL')===0)$values[$m[1]]=null;elseif(in_array($m[1],['student_id','course','academic_history_id','request_id','rfid_card_id'],true))self::deny();}$bind+=substr_count($assignment,'?');}self::validateValues($pdo,$table,$values,$ctx,false);}
            if($where!==null){$condition=trim(substr($sql,$where+5,$tail-$where-5));return substr($sql,0,$where).'WHERE ('.$condition.') AND ('.$predicate.') '.substr($sql,$tail);}
            return substr($sql,0,$tail).' WHERE '.$predicate.' '.substr($sql,$tail);
        }
        return $sql;
    }
    private static function validateValues(PDO $pdo,string $table,array $values,array $ctx,bool $insert=true): void {
        if(in_array($table,['students','enrollments'],true)&&array_key_exists('course',$values)&&!in_array(self::programCode((string)$values['course']),$ctx['codes'],true))self::deny();
        $parents=['student_id'=>'students','academic_history_id'=>'academic_history','rfid_card_id'=>'rfid_cards'];
        if(!in_array($table,['document_requests','mock_payment_transactions'],true))$parents['request_id']='document_requests';
        foreach($parents as $field=>$parent){if(!isset($values[$field]))continue;$p=self::predicate($parent,$ctx);$q=$pdo->prepare('SELECT id FROM `'.$parent.'` WHERE id=? AND ('.$p.')');$q->execute([(int)$values[$field]]);if(!$q->fetchColumn())self::deny();}
        if($insert&&$table==='students'&&!isset($values['course']))self::deny();
    }
    private static function deny(): void {http_response_code(403);throw new ProgramScopeDenied('This record is outside your assigned programs.');}
    private static function requireWriteRole(string $table): void {if($table==='audit_logs')return;if(($_SESSION['role']??'')==='department_head'||(($_SESSION['role']??'')==='acad_officer'&&$table!=='academic_validations'))self::deny();}
}
