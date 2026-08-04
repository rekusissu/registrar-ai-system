<?php
// ============================================================
//  SHARED/DOCUMENT_READER.PHP
//  Extract raw text from uploaded PDF / Word (.docx) / plain-text
//  files so the AI client can parse student details from them.
//
//  Requires (verified in this environment):
//    - PDF:  pdftotext (poppler) on PATH — CLI exec
//    - DOCX: unzip CLI + SimpleXML (word/document.xml)
//    - TXT:  plain file read
// ============================================================

require_once __DIR__ . '/config.php';

if (defined('DOCUMENT_READER_LOADED')) {
    return;
}
define('DOCUMENT_READER_LOADED', true);

/**
 * Extract plain text from an uploaded file. Returns the extracted text
 * or '' on failure. Throws RuntimeException with a friendly message when
 * the file type is unsupported.
 *
 * @param string $tmpPath  Uploaded file temp path
 * @param string $origName Original uploaded filename (for extension)
 * @return string
 */
function extractDocumentText(string $tmpPath, string $origName): string {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf':
            return extractPdfText($tmpPath);
        case 'docx':
            return extractDocxText($tmpPath);
        case 'txt':
        case 'text':
            $data = file_get_contents($tmpPath);
            return $data === false ? '' : $data;
        default:
            throw new RuntimeException('Unsupported file type: .' . $ext . ' (supported: pdf, docx, txt)');
    }
}

/**
 * Resolve the absolute path to a helper CLI tool. Apache's PHP doesn't have
 * the Git tooling on its PATH, so prefer the known Git install paths and fall
 * back to the bare command (for CLI contexts where it's on PATH).
 */
function findToolPath(string $tool): string {
    static $cache = [];
    if (isset($cache[$tool])) {
        return $cache[$tool];
    }
    $candidates = [];
    if ($tool === 'pdftotext') {
        $candidates = [
            'C:/Program Files/Git/mingw64/bin/pdftotext.exe',
            'C:/Program Files (x86)/Git/mingw64/bin/pdftotext.exe',
            'C:/Program Files/poppler/bin/pdftotext.exe',
            '/mingw64/bin/pdftotext',
        ];
    } elseif ($tool === 'unzip') {
        $candidates = [
            'C:/Program Files/Git/usr/bin/unzip.exe',
            'C:/Program Files (x86)/Git/usr/bin/unzip.exe',
            '/usr/bin/unzip',
        ];
    }
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $cache[$tool] = $c;
            return $c;
        }
    }
    // Fall back to bare command (relies on PATH being present).
    $cache[$tool] = $tool;
    return $tool;
}

/**
 * Extract text from a PDF using pdftotext (poppler).
 */
function extractPdfText(string $tmpPath): string {
    $escaped = escapeshellarg($tmpPath);
    $cmd = escapeshellarg(findToolPath('pdftotext')) . ' -layout ' . $escaped . ' -';
    $output = shell_exec($cmd . ' 2>&1');
    if ($output === null || $output === false) {
        return '';
    }
    return trim($output);
}

/**
 * Extract text from a .docx file: unzip word/document.xml, then strip
 * XML tags (paragraphs become newlines).
 */
function extractDocxText(string $tmpPath): string {
    $tmpDir = sys_get_temp_dir() . '/ai_docx_' . getmypid() . '_' . bin2hex(random_bytes(4));
    @mkdir($tmpDir, 0777, true);

    try {
        $escaped = escapeshellarg($tmpPath);
        // Success with -q produces no output; only a non-empty output (or
        // empty string when the shell returned it) signals a problem here.
        // shell_exec returns NULL if no output at all — that's success.
        $unzip = escapeshellarg(findToolPath('unzip'));
        $out = shell_exec($unzip . ' -o -q ' . $escaped . ' -d ' . escapeshellarg($tmpDir) . ' 2>&1');
        if ($out !== null && $out !== '' && strpos($out, 'error') !== false) {
            return '';
        }

        $xmlPath = $tmpDir . '/word/document.xml';
        // realpath() normalizes the 8.3 short path that sys_get_temp_dir()
        // can return on Windows (REKUSI~1 vs rekusissu).
        $real = realpath($xmlPath);
        if ($real === false || !file_exists($real)) {
            return '';
        }

        $xml = simplexml_load_file($real);
        if ($xml === false) {
            return '';
        }

        // Register the wordml namespace and pull text from every <w:t>.
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $texts = $xml->xpath('//w:t');
        if ($texts === false) {
            return '';
        }

        $parts = [];
        foreach ($texts as $t) {
            $parts[] = (string) $t;
        }

        return trim(implode(' ', $parts));
    } finally {
        // Best-effort cleanup of the temp extraction dir.
        if (is_dir($tmpDir)) {
            @exec('rm -rf ' . escapeshellarg($tmpDir));
        }
    }
}

/**
 * Store an uploaded doc for later processing and return its saved path.
 * Used when the caller needs the file past the current request (rare).
 */
function storeAiDocument(string $tmpPath, string $origName): string {
    $dir = __DIR__ . '/../uploads/ai_docs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $name = $safe . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($tmpPath, $dest)) {
        // Fall back to copy if move fails (e.g. not an upload).
        if (!copy($tmpPath, $dest)) {
            return '';
        }
    }
    return $dest;
}
