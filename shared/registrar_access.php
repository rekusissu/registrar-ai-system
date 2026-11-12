<?php
require_once __DIR__.'/database.php';
require_once __DIR__.'/session_config.php';
require_once __DIR__.'/functions.php';
function registrarProgramCode(string $value): string {
    if(preg_match('/\(([A-Za-z0-9]+)\)/',$value,$m))return strtoupper($m[1]);
    $aliases=['BS Information Technology'=>'BSIT','BS Computer Science'=>'BSCS','BS Hospitality Management'=>'BSHM'];
    foreach($aliases as $name=>$code)if(strcasecmp(trim($value),$name)===0)return $code;
    return strtoupper(trim($value));
}
function registrarProgramCatalog(): array {
    $out=[];foreach(array_keys(getOfferedCourses()) as $label)$out[registrarProgramCode($label)]=$label;
    foreach(Database::getInstance()->fetchAll("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND TRIM(course)<>''") as $row){$code=registrarProgramCode($row['course']);if(!isset($out[$code]))$out[$code]=$row['course'];}
    ksort($out);return $out;
}
function registrarAssignedPrograms(?int $userId=null): array {
    return array_column(Database::getInstance()->fetchAll('SELECT program_code FROM user_program_assignments WHERE user_id=?',[$userId??getCurrentUserId()]),'program_code');
}
/** Existing staff without assignments are preserved until the user provides a mapping. */
function registrarHasAllPrograms(): bool {
    $role=getCurrentUserRole();
    if(in_array($role,['super_admin','registrar_admin'],true))return true;
    if($role==='staff'&&!registrarAssignedPrograms()){
        $username=Database::getInstance()->fetchColumn('SELECT username FROM users WHERE id=?',[getCurrentUserId()]);
        return in_array($username,['RGS-001','demo.staff'],true);
    }
    return false;
}
function registrarStudentScope(string $alias='s'): array {
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/',$alias))throw new InvalidArgumentException('Invalid table alias');
    if(registrarHasAllPrograms())return ['sql'=>'1=1','params'=>[]];
    $codes=registrarAssignedPrograms();if(!$codes)return ['sql'=>'1=0','params'=>[]];
    $labels=[];foreach(registrarProgramCatalog() as $code=>$label)if(in_array($code,$codes,true)){$labels[]=$label;$labels[]=$code;}
    foreach(Database::getInstance()->fetchAll('SELECT DISTINCT course FROM students') as $row)if(in_array(registrarProgramCode((string)$row['course']),$codes,true))$labels[]=$row['course'];
    $labels=array_values(array_unique($labels));if(!$labels)return ['sql'=>'1=0','params'=>[]];
    return ['sql'=>"TRIM($alias.course) IN (".implode(',',array_fill(0,count($labels),'?')).')','params'=>$labels];
}
function registrarCanReadStudent(int $id): bool {
    $scope=registrarStudentScope();return (bool)Database::getInstance()->fetchColumn('SELECT s.id FROM students s WHERE s.id=? AND '.$scope['sql'],array_merge([$id],$scope['params']));
}
function registrarRequireStudent(int $id): void {
    if(!registrarCanReadStudent($id)){http_response_code(403);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'This student is outside your assigned programs.']);exit;}
}
function registrarIsAcademicReader(): bool {return in_array(getCurrentUserRole(),['acad_officer','department_head'],true);}
function registrarRequireProgram(string $course): void {
    if(!registrarHasAllPrograms() && !in_array(registrarProgramCode($course),registrarAssignedPrograms(),true)) {
        http_response_code(403);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'This program is outside your assigned access.']);exit;
    }
}
