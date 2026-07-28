<?php
// ============================================================
//  SHARED/CONFIG.LOCAL.PHP (template)
//  Copy to config.local.php on each scanning station
//  Set SCANNER_READER_ID to match the reader's ID in card_readers table
//
//  Example:
//    PC at Main Gate   → define('SCANNER_READER_ID', 1);
//    PC at Library      → define('SCANNER_READER_ID', 2);
//    PC at Lab          → define('SCANNER_READER_ID', 3);
//    PC at Dormitory    → define('SCANNER_READER_ID', 4);
// ============================================================

define('SCANNER_READER_ID', 0);
?>