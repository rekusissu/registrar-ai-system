<?php
require_once __DIR__.'/../shared/shs_catalog.php';
$checks=0;
foreach(shsPrograms() as $course=>$offerings) {
    foreach([11,12] as $year) foreach($offerings as $major) {
        validateStudentAcademicPlacement(['course'=>$course,'major'=>$major,'year_level'=>$year]);$checks++;
    }
}
$campuses=array_keys(shsPrograms());
$invalid=[
    ['course'=>$campuses[0],'major'=>'Fake strand','year_level'=>11],
    ['course'=>$campuses[0],'major'=>'General Academic Strand (GAS)','year_level'=>1],
    ['course'=>$campuses[1],'major'=>'General Academic Strand (GAS)','year_level'=>11],
    ['course'=>'BS Information Technology','year_level'=>11],
    ['course'=>'BS Information Technology','year_level'=>'1.5'],
];
foreach($invalid as $input) {
    try {validateStudentAcademicPlacement($input);throw new RuntimeException('Invalid placement accepted: '.json_encode($input));}
    catch(InvalidArgumentException $expected) {$checks++;}
}
validateStudentAcademicPlacement(['course'=>'BS Information Technology','year_level'=>1]);$checks++;
echo "PASS $checks campus/strand/grade placement cases\n";
