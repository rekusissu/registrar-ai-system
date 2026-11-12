<?php
// Preserve old bookmarks, opening the contact workspace in its roster context.
require_once __DIR__.'/../shared/registrar_access.php';
requireLogin();requireRole('staff');
$sid=(int)($_GET['student_id']??0);
registrarRequireStudent($sid);
header('Location: guardians.php?contact_student='.$sid);
exit;
