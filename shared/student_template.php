<?php
// ============================================================
//  SHARED/STUDENT_TEMPLATE.PHP
//  Build a fillable Word (.docx) enrolment form that students
//  can fill out and the registrar uploads directly into the
//  AI Document Reader.
//
//  Uses PharData to build the OOXML package (no external tools).
//  The template uses form text fields (FFData) so students can
//  tab between fields in Word, plus static labels for each field.
// ============================================================

require_once __DIR__ . '/config.php';

if (defined('STUDENT_TEMPLATE_LOADED')) {
    return;
}
define('STUDENT_TEMPLATE_LOADED', true);

/**
 * All fields used by the add-student form, grouped into sections.
 * The 'key' values match what the AI extractor returns.
 */
function studentTemplateSections(): array {
    return [
        'Personal Information' => [
            ['key' => 'first_name',   'label' => 'First Name'],
            ['key' => 'middle_name',  'label' => 'Middle Name'],
            ['key' => 'last_name',    'label' => 'Last Name'],
            ['key' => 'gender',       'label' => 'Gender (Male/Female)'],
            ['key' => 'civil_status', 'label' => 'Civil Status'],
            ['key' => 'birth_date',   'label' => 'Birth Date (MM/DD/YYYY)'],
            ['key' => 'place_of_birth','label' => 'Place of Birth'],
            ['key' => 'nationality',  'label' => 'Nationality'],
            ['key' => 'religion',     'label' => 'Religion'],
            ['key' => 'email',        'label' => 'Email'],
            ['key' => 'contact_number','label' => 'Contact Number (09xx)'],
            ['key' => 'address',      'label' => 'Address'],
        ],
        'Enrollment Details' => [
            ['key' => 'student_number', 'label' => 'Student ID (leave blank if new)'],
            ['key' => 'course',       'label' => 'Course'],
            ['key' => 'major',        'label' => 'Major (if any)'],
            ['key' => 'year_level',   'label' => 'Year Level (1-4)'],
            ['key' => 'school_year',  'label' => 'School Year (e.g. 2026-2027)'],
            ['key' => 'semester',     'label' => 'Semester (1st/2nd/summer)'],
            ['key' => 'status',       'label' => 'Status'],
        ],
        'Guardian / Parent' => [
            ['key' => 'guardian_name', 'label' => 'Guardian Full Name'],
            ['key' => 'guardian_relationship', 'label' => 'Relationship'],
            ['key' => 'guardian_contact', 'label' => 'Guardian Contact'],
            ['key' => 'guardian_email', 'label' => 'Guardian Email'],
        ],
    ];
}

/**
 * Build the .docx file bytes for the fillable enrolment form.
 * Returns the raw file content, or throws on failure.
 */
function buildStudentTemplateDocx(): string {
    $sections = studentTemplateSections();

    $body = '<w:body>';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Student Enrolment Information Form</w:t></w:r></w:p>';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Subtitle"/></w:pPr><w:r><w:t>Please fill out all fields. The registrar will upload this file to auto-fill your record.</w:t></w:r></w:p>';
    $body .= '<w:p/>';

    foreach ($sections as $sectionTitle => $fields) {
        $body .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . htmlspecialchars($sectionTitle, ENT_XML1) . '</w:t></w:r></w:p>';
        foreach ($fields as $f) {
            $label = htmlspecialchars($f['label'], ENT_XML1);
            $ffName = htmlspecialchars($f['key'], ENT_XML1);
            $body .= '<w:p><w:r><w:t>' . $label . ':  </w:t></w:r>'
                  // A rich text control (not a plain box) so students can type freely.
                  . '<w:r><w:fldChar w:fldCharType="begin"/><w:instrText xml:space="preserve"> FORMTEXT </w:instrText><w:fldChar w:fldCharType="separate"/><w:t/><w:fldChar w:fldCharType="end"/></w:r></w:p>';
        }
        $body .= '<w:p/>';
    }

    $body .= '<w:p><w:pPr><w:pStyle w:val="Subtitle"/></w:pPr><w:r><w:t>Instructions: Save and email this form, or print and fill it out. The registrar can then upload the file into the system.</w:t></w:r></w:p>';
    $body .= '</w:body>';

    // Minimal OOXML package: [Content_Types].xml, _rels/.rels, word/document.xml, word/styles.xml
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:rPr><w:i/><w:sz w:val="22"/><w:color w:val="808080"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="22"/></w:rPr></w:style>'
        . '</w:styles>';

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . $body
        . '</w:document>';

    // Assemble the zip via PharData.
    $tmpZip = tempnam(sys_get_temp_dir(), 'tmp') . '.zip';
    $tmpDir = sys_get_temp_dir() . '/tmplbuild_' . getmypid();
    @mkdir($tmpDir . '/_rels', 0777, true);
    @mkdir($tmpDir . '/word', 0777, true);
    file_put_contents($tmpDir . '/[Content_Types].xml', $contentTypes);
    file_put_contents($tmpDir . '/_rels/.rels', $rels);
    file_put_contents($tmpDir . '/word/document.xml', $document);
    file_put_contents($tmpDir . '/word/styles.xml', $styles);

    try {
        $zip = new PharData($tmpZip);
        $zip->buildFromDirectory($tmpDir);
        $docxBytes = file_get_contents($tmpZip);
        if ($docxBytes === false) {
            throw new RuntimeException('Failed to read generated docx.');
        }
    } finally {
        @unlink($tmpZip);
        @exec('rm -rf ' . escapeshellarg($tmpDir));
    }

    return $docxBytes;
}
