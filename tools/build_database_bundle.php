<?php
/** CLI-only: consolidate the current schema in a disposable database, never mutate the source. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../shared/config.php';
$email=$argv[1]??'';if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Usage: php tools/build_database_bundle.php owner@example.com');
$root=dirname(__DIR__);$source=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$owner=$source->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(?)');$owner->execute([$email]);$owner=$owner->fetch(PDO::FETCH_ASSOC);if(!$owner)throw new RuntimeException('The requested owner account was not found.');
$tmp='registrar_bundle_'.bin2hex(random_bytes(6));$admin=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,'',(int)DB_PORT);$admin->set_charset('utf8mb4');$admin->query("CREATE DATABASE `$tmp` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$test=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,$tmp,(int)DB_PORT);$test->set_charset('utf8mb4');
function bundleRun(mysqli $db,string $sql):void{if(!$db->multi_query($sql))throw new RuntimeException($db->error);do{if($r=$db->store_result())$r->free();if(!$db->more_results())break;if(!$db->next_result())throw new RuntimeException($db->error);}while(true);}
function bundleInsert(PDO $db,string $table,array $row,string $prefix='INSERT INTO'):string{$names=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($row)));$values=implode(',',array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row)));return "$prefix `$table` ($names) VALUES\n($values);\n";}
try{
 $tables=$source->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);$base="SET FOREIGN_KEY_CHECKS=0;\n";
 foreach($tables as [$t,$kind]){if($kind!=='BASE TABLE')throw new RuntimeException('Review non-table schema object: '.$t);$ddl=$source->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];$base.=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl).";\n";}
 bundleRun($test,$base.'SET FOREIGN_KEY_CHECKS=1;');
 // Reference/business configuration only; no students, transactions or operational histories.
 $configSeed='';foreach(['departments','document_catalog'] as $t)foreach($source->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row)$configSeed.=bundleInsert($source,$t,$row);
 bundleRun($test,$configSeed);
 $migrations=['document_online_lifecycle.sql','document_receipt_upload.sql','document_request_wizard.sql','document_requirements_review.sql','grade_acceptance.sql','grades_faculty_source.sql','add_previous_school_fields.sql','restore_student_section.sql','add_queue_daily_tap_cap.sql','add_staff_notification_reads.sql','id_card_details.sql','guardian_contact_attempts.sql','report_export_security.sql','registrar_revision_roles.sql','status_loa_window.sql'];
 foreach($migrations as $file){bundleRun($test,file_get_contents($root.'/migrations/'.$file));echo 'Consolidated '.$file.PHP_EOL;}
 $schema=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$tmp.';charset=utf8mb4',DB_USER,DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $names=$schema->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($names);
 $owner['role']='super_admin';$owner['is_active']=1;foreach(['student_id','department_id','rfid_uid','locked_until','email_bounced_at','email_bounce_reason'] as $c)if(array_key_exists($c,$owner))$owner[$c]=null;if(isset($owner['login_attempts']))$owner['login_attempts']=0;
 $ownerSeed=bundleInsert($source,'users',$owner);
 $head="-- BCP Registrar: consolidated deployment database, 2026-11-13.\n-- FRESH INSTALL / REPLACE ONLY: drops and recreates the listed application tables.\n-- Existing hosted records: use migrations/deployment_upgrade_20261113.sql instead.\n-- Keeps only the requested owner account as super_admin; its existing password hash is preserved.\n-- Includes document catalog, departments and requirement definitions. No demo users/students,\n-- grades, requests, files, queue activity, login tokens, caches or operational audit history.\n-- Uploads are stored separately on disk. No database name/CREATE DATABASE required by hosting.\nSET NAMES utf8mb4;\nSET @bundle_old_fk=@@FOREIGN_KEY_CHECKS;\nSET FOREIGN_KEY_CHECKS=0;\n";
 $fresh=$head;$createAll='';$adds='';$ddlByTable=[];
 foreach($names as $t){$ddl=$schema->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);$ddlByTable[$t]=$ddl;$fresh.="\nDROP TABLE IF EXISTS `$t`;\n$ddl;\n";$createAll.=str_replace('CREATE TABLE `','CREATE TABLE IF NOT EXISTS `',$ddl).";\n\n";
  foreach(explode("\n",$ddl) as $line){if(!preg_match('/^\s+`([^`]+)`\s+(.+)$/',$line,$col))continue;$c=$col[1];$def=rtrim(trim($line),',');$statement="ALTER TABLE `$t` ADD COLUMN $def";$adds.="SET @bundle_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$source->quote($t)." AND COLUMN_NAME=".$source->quote($c).")=0,".$source->quote($statement).",'DO 0');\nPREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;\n";}
 }
 $fresh.="\n-- Business/reference configuration\n";
 foreach(['departments','document_catalog','document_type_requirements'] as $t)foreach($schema->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row)$fresh.=bundleInsert($source,$t,$row);
 $fresh.="\n-- Owner account (no plaintext password)\n".$ownerSeed."\nSET FOREIGN_KEY_CHECKS=@bundle_old_fk;\nCOMMIT;\n";
 // Upgrade: create missing tables, add missing columns, retain existing accounts and records.
 $upgrade="-- Existing-database upgrade, 2026-11-13. Take a backup before schema changes.\n-- No DROP TABLE, TRUNCATE, account deletion or password replacement. Re-runnable.\n-- Preserves unknown legacy roles for manual review instead of silently granting privileges.\nSET NAMES utf8mb4;\nSET @bundle_old_fk=@@FOREIGN_KEY_CHECKS; SET FOREIGN_KEY_CHECKS=0;\n\n".$createAll."\n".$adds;
 $upgrade.="\n-- Expand role storage before mapping legacy values; retain unfamiliar values.\nALTER TABLE users MODIFY COLUMN role VARCHAR(40) NOT NULL DEFAULT 'staff';\nUPDATE users SET role=CASE LOWER(TRIM(role)) WHEN 'admin' THEN 'super_admin' WHEN 'registrar' THEN 'staff' WHEN 'teacher' THEN 'acad_officer' ELSE LOWER(TRIM(role)) END;\n";
 $targetRoles="'super_admin','registrar_admin','staff','acad_officer','department_head','student'";
 $upgrade.="SET @bundle_sql=IF((SELECT COUNT(*) FROM users WHERE role NOT IN ($targetRoles))=0,".$source->quote("ALTER TABLE users MODIFY COLUMN role ENUM($targetRoles) NOT NULL DEFAULT 'staff'").",'DO 0');\nPREPARE bundle_stmt FROM @bundle_sql; EXECUTE bundle_stmt; DEALLOCATE PREPARE bundle_stmt;\n";
 // Existing host password wins. Insert the requested account only if absent.

 // Let an existing host allocate its own numeric ID.
 $newOwner=$owner;unset($newOwner['id']);$cols=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($newOwner)));$vals=implode(',',array_map(fn($v)=>$v===null?'NULL':$source->quote((string)$v),array_values($newOwner)));
 $upgrade.="INSERT INTO users ($cols) SELECT $vals WHERE NOT EXISTS(SELECT 1 FROM users WHERE LOWER(email)=LOWER(".$source->quote($email).")) AND NOT EXISTS(SELECT 1 FROM users WHERE username=".$source->quote($owner['username']).");\nUPDATE users SET role='super_admin',is_active=1 WHERE LOWER(email)=LOWER(".$source->quote($email).");\n";
 // Latest document enums/columns/review definitions; no operational records removed.
 foreach(['document_request_wizard.sql','grade_acceptance.sql','grades_faculty_source.sql','document_receipt_upload.sql','add_previous_school_fields.sql','add_queue_daily_tap_cap.sql','add_staff_notification_reads.sql','status_loa_window.sql'] as $file)$upgrade.="\n-- Consolidated: $file\n".file_get_contents($root.'/migrations/'.$file)."\n";
 $upgrade.="\nSET FOREIGN_KEY_CHECKS=@bundle_old_fk;\nSELECT role,COUNT(*) AS accounts FROM users GROUP BY role;\nSELECT id,username,role FROM users WHERE role NOT IN ($targetRoles);\n";
 file_put_contents($root.'/registrar_ai.sql',$fresh);file_put_contents($root.'/migrations/deployment_upgrade_20261113.sql',$upgrade);
 echo count($names).' tables consolidated; one owner account retained. Source database unchanged.'.PHP_EOL;
}finally{$test->close();$admin->query("DROP DATABASE `$tmp`");$admin->close();}
