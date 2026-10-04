<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../shared/masterlist_folders.php';

/**
 * The data-safety rules behind the folders.
 *
 * Kept beside MasterlistFoldersTest because they are the same
 * module's rules; a separate file only so neither grows past the
 * point where a reviewer skims it.
 */
final class MasterlistFoldersDataTest extends TestCase
{
    private function student(array $over = []): array
    {
        return array_merge([
            'id' => 1, 'student_number' => '2026-0001',
            'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
            'middle_name' => 'S', 'name_suffix' => '',
            'course' => 'BSIT', 'year_level' => 1, 'section' => '11001',
            'school_year' => '2026-2027', 'semester' => '1st',
            'gender' => 'Male', 'status' => 'enrolled',
            'contact_number' => '09171234567', 'email' => 'juan@example.edu',
        ], $over);
    }

    /**
     * The reason the "Unassigned" folders exist. A student with no
     * section is a real enrolment, and the tree has to show it
     * under an explicit folder rather than lose it — a cohort that
     * quietly vanishes from a folder tree is one that gets handed
     * to a department as if it did not exist.
     */
    public function testUnplacedStudentsStillAppearInAnExplicitFolder(): void
    {
        $path = mlf_path($this->student([
            'course' => '', 'year_level' => null, 'section' => '',
        ]));

        self::assertSame(MLF_NO_PROGRAM . '/' . MLF_NO_YEAR . '/' . MLF_NO_SECTION, $path);

        $sections = mlf_sections_under(
            mlf_build_tree([$this->student(['section' => ''])]),
            ''
        );

        self::assertCount(1, $sections);
        self::assertSame(MLF_NO_SECTION, $sections[0]['section']);
        self::assertSame(1, $sections[0]['count']);
    }

    /** A section saved as a space is as unplaced as one saved as NULL. */
    public function testWhitespaceOnlySectionCountsAsUnassigned(): void
    {
        self::assertSame(MLF_NO_SECTION, mlf_path_segments($this->student(['section' => '   ']))[2]);
    }

    /**
     * The tile for an unassigned folder must be marked, or an empty
     * section and a cohort nobody has placed yet look alike.
     */
    public function testTheUnassignedFolderIsFlaggedOnItsTile(): void
    {
        // Not on the program's tile at the root — the program IS
        // recorded, it is the section that is missing. The flag
        // belongs on the tile the reader actually sees it on.
        $tree = mlf_build_tree([$this->student(['section' => ''])]);

        self::assertFalse(
            mlf_resolve($tree, 'BSIT')['folders'][0]['unassigned'],
            'the program tile must not be flagged when only the section is missing'
        );

        $sections = mlf_resolve($tree, 'BSIT/Year 1')['folders'];
        self::assertTrue($sections[0]['unassigned']);
    }

    /**
     * A program name is free text typed by a clerk, and the
     * forbidden characters are the ones that make an archive
     * un-extractable on the receiving machine.
     */
    public function testIllegalFolderCharactersAreStripped(): void
    {
        $segments = mlf_path_segments($this->student(['course' => 'BSIT: Computer/Engineering?']));

        foreach (['\\', '/', ':', '*', '?', '"', '<', '>', '|'] as $bad) {
            self::assertStringNotContainsString(
                $bad, $segments[0], 'folder name still holds an illegal character'
            );
        }
        self::assertStringContainsString('BSIT', $segments[0]);
    }

    /**
     * Windows strips a trailing dot, which would make "BSIT." and
     * "BSIT" the same folder on disk while they are two rows in the
     * database.
     */
    public function testTrailingDotsAndSpacesAreRemoved(): void
    {
        self::assertSame('BSIT', mlf_safe_segment('BSIT. ', 'fallback'));
    }

    /** A name made only of forbidden characters must not become an empty folder. */
    public function testFullyStrippedNameFallsBack(): void
    {
        self::assertSame(MLF_NO_PROGRAM, mlf_safe_segment('///', MLF_NO_PROGRAM));
    }

    /** Non-Latin program names are kept, not transliterated away. */
    public function testUnicodeProgramNamesSurvive(): void
    {
        self::assertSame('Akasyon', mlf_safe_segment('Akasyon', MLF_NO_PROGRAM));
    }

    /**
     * "Unassigned" is the work queue, not part of the cohort
     * order — sorted by name it would land between real section
     * codes and read as a section called "Unassigned".
     */
    public function testUnassignedFoldersSortLastAtEveryLevel(): void
    {
        $tree = mlf_build_tree([
            $this->student(['id' => 1, 'course' => 'BSIT', 'year_level' => 2, 'section' => '21001']),
            $this->student(['id' => 2, 'course' => 'BSIT', 'year_level' => 1, 'section' => '']),
            $this->student(['id' => 3, 'course' => 'BSIT', 'year_level' => 1, 'section' => '11002']),
            $this->student(['id' => 4, 'course' => '', 'year_level' => 1, 'section' => '11001']),
        ]);

        self::assertSame(['BSIT', MLF_NO_PROGRAM], array_keys($tree));
        self::assertSame(['Year 1', 'Year 2'], array_keys($tree['BSIT']['years']));

        // Read back through the public API rather than the raw array
        // keys: PHP coerces a numeric key like '11002' into an int,
        // so the tree's internals are ints while mlf_resolve() hands
        // callers the strings they actually use.
        $names = array_column(mlf_resolve($tree, 'BSIT/Year 1')['folders'], 'name');

        self::assertSame(['11002', MLF_NO_SECTION], $names);
    }

    /** Counts are the point of the tile: a registrar reads them to spot a missing cohort. */
    public function testCountsRollUpThroughEveryLevel(): void
    {
        $tree = mlf_build_tree([
            $this->student(['id' => 1, 'section' => '11001']),
            $this->student(['id' => 2, 'section' => '11001']),
            $this->student(['id' => 3, 'section' => '11002']),
        ]);

        self::assertSame(3, $tree['BSIT']['count']);
        self::assertSame(3, $tree['BSIT']['years']['Year 1']['count']);
        self::assertSame(2, $tree['BSIT']['years']['Year 1']['sections']['11001']['count']);
        self::assertSame(1, $tree['BSIT']['years']['Year 1']['sections']['11002']['count']);
    }

    /** One section's students stay together, in the order the Masterlist page prints. */
    public function testStudentsInsideASectionAreSortedByName(): void
    {
        $tree = mlf_build_tree([
            $this->student(['id' => 1, 'last_name' => 'Zulu']),
            $this->student(['id' => 2, 'last_name' => 'Alvarez']),
            $this->student(['id' => 3, 'last_name' => 'Mendoza']),
        ]);

        $names = array_map(
            static fn($s) => $s['last_name'],
            $tree['BSIT']['years']['Year 1']['sections']['11001']['students']
        );

        self::assertSame(['Alvarez', 'Mendoza', 'Zulu'], $names);
    }

    /**
     * The folder is a projection of the student row, so a changed
     * section MOVES the row on the next build. This is the property
     * that would break if a path were ever stored.
     */
    public function testFolderFollowsTheStudentsCurrentSection(): void
    {
        $student = $this->student(['section' => '11001']);
        self::assertStringEndsWith('/11001', mlf_path($student));

        $student['section'] = '11002';
        self::assertStringEndsWith('/11002', mlf_path($student));
    }

    public function testEmptyInputProducesAnEmptyTree(): void
    {
        self::assertSame([], mlf_build_tree([]));
        self::assertSame([], mlf_sections_under([], ''));
        self::assertSame([], mlf_resolve([], '')['folders']);
    }

    /**
     * THE CATALOGUE IS THE SHAPE; THE ROWS FILL IT IN.
     *
     * A folder that only exists once it holds something is a folder the
     * reader cannot navigate past: "where is BSCpE?" and "does BSCpE
     * have no Year 3?" would print the same empty screen, and only the
     * second question is one a registrar actually asks. Academic
     * History's board is built this way, and a masterlist that cannot
     * show a missing cohort is a masterlist that cannot report one.
     */
    public function testEveryOfferedProgramGetsAFolderEvenWithNoStudents(): void
    {
        $catalogue = [
            'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)',
            'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
        ];

        $node = mlf_resolve(mlf_seed_catalogue([], $catalogue), '');

        self::assertSame(
            ['BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)',
             'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)'],
            array_column($node['folders'], 'name'),
            'a program nobody has enrolled yet still gets a folder'
        );
    }

    /** Four year levels per program, always, and named the way the paths are. */
    public function testEveryProgramHasAllFourYearFolders(): void
    {
        $catalogue = ['BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)'];
        $program   = $catalogue[0];

        $node = mlf_resolve(mlf_seed_catalogue([], $catalogue), $program);

        self::assertSame(
            ['Year 1', 'Year 2', 'Year 3', 'Year 4'],
            array_column($node['folders'], 'name'),
            'an empty program still offers all four year levels'
        );
        foreach ($node['folders'] as $folder) {
            self::assertSame(0, (int) $folder['count'], 'a seeded folder counts zero, never a guess');
        }
    }

    /**
     * The seeded year has to be the SAME path a row-built year uses, or
     * 'BSIT/Year 3' would 404 on the way to an empty cohort.
     */
    public function testASeededYearResolvesUnderThePathARowWouldUse(): void
    {
        $catalogue = ['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)'];

        $seeded = mlf_resolve(mlf_seed_catalogue([], $catalogue), 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)/Year 3');
        $fromRow = mlf_resolve(
            mlf_build_tree([$this->student(['year_level' => 3, 'section' => '31001'])]),
            'BSIT/Year 3'
        );

        self::assertTrue($seeded['exists'], 'a seeded year folder must resolve, not 404');
        self::assertSame('year', $seeded['level'], 'a seeded year reads as a year, not as a program');
        self::assertSame('year', $fromRow['level'], 'the path shape is unchanged by seeding');
        self::assertSame(
            $catalogue[0],
            $seeded['parent'],
            'the seeded year sits directly under the program folder'
        );
        self::assertSame([], $seeded['folders'], 'an empty year offers no sections - that is the whole point');
        self::assertSame(0, $seeded['count'], 'and counts zero rather than guessing');

        // And the path a ROW produces resolves to the same shape as the
        // seeded one, so a bookmark of a real cohort and a click into an
        // empty year are the same kind of address.
        self::assertSame(
            array_keys($seeded),
            array_keys($fromRow),
            'a seeded year folder and a row-built one carry the same keys'
        );
    }

    /**
     * A clerk types "BSIT"; the catalogue says the full degree title. Two
     * BSIT folders, one of them permanently empty, is worse than either -
     * and the students under the acronym must not vanish from the folder
     * the catalogue owns.
     */
    public function testAnAcronymInTheDataFoldsOntoItsCatalogueFolder(): void
    {
        $catalogue = ['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)'];
        $tree = mlf_seed_catalogue(
            mlf_build_tree([
                $this->student(['id' => 1, 'course' => 'BSIT', 'section' => '11001']),
                $this->student(['id' => 2, 'course' => 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)', 'section' => '11002']),
            ]),
            $catalogue
        );

        self::assertSame(
            ['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)'],
            array_keys($tree),
            'one BSIT folder, not a catalogue BSIT beside a data BSIT'
        );
        self::assertSame(2, $tree['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)']['count'],
            'folding must add the rows up, not drop either set');

        $names = array_column(mlf_resolve($tree, 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)/Year 1')['folders'], 'name');
        self::assertSame(['11001', '11002'], $names, 'both sections survive the fold');
    }

    /**
     * Renaming a course does not delete its students, so a program that is
     * NOT in the catalogue keeps its own folder rather than being folded
     * away — and the seeded years still appear inside it.
     */
    public function testAProgramOutsideTheCatalogueKeepsItsOwnFolder(): void
    {
        $tree = mlf_seed_catalogue(
            mlf_build_tree([$this->student(['course' => 'BSED-GRADUATE', 'section' => '11001'])]),
            ['BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY (BSIT)']
        );

        self::assertArrayHasKey('BSED-GRADUATE', $tree);
        self::assertSame(1, $tree['BSED-GRADUATE']['count']);
        self::assertSame(
            ['Year 1', 'Year 2', 'Year 3', 'Year 4'],
            array_keys($tree['BSED-GRADUATE']['years'])
        );
    }

    /** Seeding adds folders, never rows: it cannot invent a student. */
    public function testSeedingNeverInventsAStudent(): void
    {
        $catalogue = ['BACHELOR OF SCIENCE IN COMPUTER ENGINEERING (BSCpE)'];
        $tree = mlf_seed_catalogue([], $catalogue);

        self::assertSame(0, $tree[$catalogue[0]]['count']);
        self::assertSame([], mlf_sections_under($tree, ''), 'an empty catalogue exports nothing');
    }

    /**
     * An empty catalogue adds no PROGRAMS - only the year shape.
     *
     * With no catalogue to seed from, the tree is still what the rows
     * said: no program is invented, and the only thing added is the four
     * year levels every program now carries.
     */
    public function testSeedingWithNoCatalogueInventsNoProgram(): void
    {
        $tree = mlf_build_tree([$this->student(['section' => '11001'])]);
        $seeded = mlf_seed_catalogue($tree, []);

        self::assertSame(array_keys($tree), array_keys($seeded), 'no program is invented');
        self::assertSame(1, $seeded['BSIT']['count'], 'and no student is');
        self::assertSame(
            ['Year 1', 'Year 2', 'Year 3', 'Year 4'],
            array_keys($seeded['BSIT']['years'])
        );
        self::assertSame(
            ['11001'],
            // Through the public resolver, not the raw array: PHP coerces a
            // numeric key like '11001' into an int, and the reader only ever
            // sees the string form the resolver hands back.
            array_column(mlf_resolve($seeded, 'BSIT/Year 1')['folders'], 'name'),
            'the section that existed still exists, untouched'
        );
    }
}
