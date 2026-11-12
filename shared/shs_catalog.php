<?php
/** Campus is part of the SHS program; major stores the selected strand/cluster. */
function shsPrograms(): array {
    static $programs;
    if ($programs !== null) return $programs;
    $catalog = json_decode(file_get_contents(__DIR__.'/../config/shs-programs.json'), true, 512, JSON_THROW_ON_ERROR);
    $programs = [];
    foreach ($catalog['campuses'] as $key => $campus) {
        $code = $key === 'quezon_city' ? 'SHSQC' : 'SHSBUL';
        $label = 'SENIOR HIGH SCHOOL — '.$campus['label'].' ('.$code.')';
        $programs[$label] = array_map(static fn($o) => $o['label'].' ('.$o['code'].')', $campus['offerings']);
    }
    return $programs;
}

function validateStudentAcademicPlacement(array $record): void {
    $course = trim((string)($record['course'] ?? ''));
    $year = (string)($record['year_level'] ?? '');
    $programs = shsPrograms();
    if (isset($programs[$course])) {
        if (!in_array($year, ['11','12'], true)) throw new InvalidArgumentException('Select Grade 11 or Grade 12 for Senior High School.');
        if (!in_array(trim((string)($record['major'] ?? '')), $programs[$course], true)) {
            throw new InvalidArgumentException('Select an SHS strand or cluster offered at the selected campus.');
        }
        if(isset($record['semester'])&&$record['semester']!==''){
            $allowed=shsFramework($record)==='strengthened'?['T1','T2','T3']:['1st','2nd','summer'];
            if(!in_array($record['semester'],$allowed,true))throw new InvalidArgumentException('Select the academic period for this SHS curriculum: '.implode(', ',$allowed).'.');
        }
    } elseif (!in_array($year, ['1','2','3','4'], true)) {
        throw new InvalidArgumentException('College year level must be 1st, 2nd, 3rd, or 4th Year.');
    }
}

function shsFramework(array $student): ?string {
    $catalog=json_decode(file_get_contents(__DIR__.'/../config/shs-programs.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($catalog['campuses'] as $campus)foreach($campus['offerings'] as $offer)if(($student['major']??'')===$offer['label'].' ('.$offer['code'].')')return $offer['framework'];
    return null;
}

/** A section must not mix SHS grades, campuses, strands or clusters. */
function validateShsSectionAssignment($db,array $ids,array $fields): void {
    if(!$ids)return;
    $rows=$db->fetchAll('SELECT id,course,major,year_level,semester,school_year FROM students WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
    if(count($rows)!==count(array_unique($ids)))throw new InvalidArgumentException('Some selected students are not available in your programs.');
    $cohort=null;$college=false;$shs=false;$programs=shsPrograms();
    foreach($rows as $row){$next=array_merge($row,$fields);if(!isset($programs[$next['course']])&&(int)$row['year_level']<11){$college=true;continue;}$shs=true;validateStudentAcademicPlacement($next);
        $key=$next['course'].'|'.$next['year_level'].'|'.$next['major'].'|'.$next['semester'].'|'.$next['school_year'];if($cohort!==null&&$cohort!==$key)throw new InvalidArgumentException('SHS sections must contain the same campus, grade, strand/cluster, period and academic year.');$cohort=$key;
        $existing=$db->fetchAll("SELECT id,major FROM students WHERE course=? AND year_level=? AND semester=? AND section=?",[$next['course'],$next['year_level'],$next['semester'],$next['section']]);
        foreach($existing as $other)if(!in_array((int)$other['id'],array_map('intval',$ids),true)&&$other['major']!==$next['major'])throw new InvalidArgumentException('That SHS section belongs to another strand or cluster.');
        if(!preg_match('/^(1[12])([123])[0-9]{3}$/',(string)$next['section'],$m)||(int)$m[1]!== (int)$next['year_level']||(int)$m[2]!==semesterDigit($next['semester']))throw new InvalidArgumentException('The SHS section code must match its grade and academic period.');
    }
    if($college&&$shs)throw new InvalidArgumentException('College and SHS students cannot share a section assignment.');
}
