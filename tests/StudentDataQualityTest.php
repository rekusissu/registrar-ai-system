<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/normalize.php';
require_once __DIR__ . '/../shared/student_quality.php';

final class StudentDataQualityTest extends TestCase
{
    public function testBuildsFieldSpecificIssuesAndSafeRepairs(): void
    {
        $student = [
            'id' => 42,
            'student_number' => '2026-01482',
            'first_name' => 'maria',
            'middle_name' => '',
            'last_name' => 'santos',
            'gender' => '',
            'birth_date' => '2090-01-01',
            'address' => 'Quezon City',
            'contact_number' => '9171234567',
            'email' => 'Maria.Santos@Example.COM',
            'course' => '',
            'year_level' => 2,
            'section' => '',
            'school_year' => '',
            'semester' => '',
            'status' => 'active',
        ];

        $report = buildStudentQualityReport(
            $student,
            fn(string $course): string => $course === 'BS Information Technology'
                ? 'Bachelor of Science in Information Technology (BSIT)'
                : $course
        );

        self::assertSame(42, $report['student_id']);
        self::assertLessThan(100, $report['score']);
        self::assertNotEmpty($report['issues']);
        self::assertContains('identity', array_column($report['issues'], 'category'));

        $repairFields = array_column($report['safe_repairs'], 'field');
        self::assertContains('contact_number', $repairFields);
        self::assertContains('email', $repairFields);
        self::assertNotContains('birth_date', $repairFields);
        self::assertNotContains('first_name', $repairFields);

        $phone = $report['safe_repairs'][array_search('contact_number', $repairFields, true)];
        self::assertSame('0917-123-4567', $phone['suggested_value']);
    }

    public function testUnknownCourseIsReviewOnly(): void
    {
        $student = [
            'id' => 44, 'student_number' => '2026-01484', 'first_name' => 'Leo',
            'last_name' => 'Cruz', 'gender' => 'Male', 'birth_date' => '2004-01-01',
            'nationality' => 'Filipino', 'address' => 'Quezon City', 'contact_number' => '09171234567',
            'email' => 'leo@example.com', 'course' => 'Unlisted Course', 'year_level' => 2,
            'section' => '21001', 'school_year' => '2026-2027', 'semester' => '2nd',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertNotContains('course', array_column($report['safe_repairs'], 'field'));
    }

    public function testMissingAcademicTermReducesScore(): void
    {
        $student = [
            'id' => 46, 'student_number' => '2026-01486', 'first_name' => 'Mia',
            'last_name' => 'Reyes', 'gender' => 'Female', 'birth_date' => '2005-01-01',
            'nationality' => 'Filipino', 'address' => 'Manila', 'contact_number' => '09171234567',
            'email' => 'mia@example.com', 'course' => 'BSIT', 'year_level' => 1,
            'section' => '11001', 'school_year' => '2026-2027', 'semester' => '',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertLessThan(100, $report['score']);
    }

    public function testDuplicateDetectionUsesLoadedStudentsAndFindsSameNumber(): void
    {
        $student = [
            'id' => 44, 'student_number' => '2026-01484', 'first_name' => 'Leo',
            'last_name' => 'Cruz', 'birth_date' => '2004-01-01',
        ];
        $duplicates = studentQualityDuplicateCandidates($student, [
            $student,
            ['id' => 45, 'student_number' => '2026-01484', 'first_name' => 'Other', 'last_name' => 'Name', 'birth_date' => '2006-01-01'],
        ]);

        self::assertCount(1, $duplicates);
        self::assertSame('Same student number', $duplicates[0]['reason']);
    }

    public function testCompleteCanonicalRecordScoresOneHundred(): void
    {
        $student = [
            'id' => 47, 'student_number' => '2026-01487', 'first_name' => 'Ana',
            'last_name' => 'Reyes', 'gender' => 'Female', 'birth_date' => '2005-01-01',
            'nationality' => 'Filipino', 'address' => 'Manila', 'contact_number' => '09171234567',
            'email' => 'ana@example.com', 'course' => 'BSIT', 'year_level' => 1,
            'section' => '11001', 'school_year' => '2026-2027', 'semester' => '1st',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertSame(100, $report['score']);
        self::assertCount(1, $report['issues']);
        self::assertSame('contact_number', $report['issues'][0]['field']);
        self::assertSame('low', $report['issues'][0]['severity']);
    }

    public function testInvalidEmailIsReviewOnly(): void
    {
        $student = [
            'id' => 43, 'student_number' => '2026-01483', 'first_name' => 'Ana',
            'last_name' => 'Reyes', 'gender' => 'Female', 'birth_date' => '2005-01-01',
            'nationality' => 'Filipino', 'address' => 'Manila', 'contact_number' => '09171234567',
            'email' => 'invalid-address', 'course' => 'BSIT', 'year_level' => 1,
            'section' => '11001', 'school_year' => '2026-2027', 'semester' => '1st',
        ];

        $report = buildStudentQualityReport($student, fn(string $course): string => $course);

        self::assertNotEmpty(array_filter($report['issues'], static fn(array $issue): bool => $issue['field'] === 'email'));
        self::assertNotContains('email', array_column($report['safe_repairs'], 'field'));
    }
}
