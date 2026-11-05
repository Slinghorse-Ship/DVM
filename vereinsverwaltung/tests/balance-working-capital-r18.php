<?php declare(strict_types=1);
use Kulturhus\Verein\BalanceTaxMapping as M;
$mappingReview='working-capital-r18';
require __DIR__.'/balance-operating-r15.php';
foreach([2025,2026] as $year){
    $choices=M::chartChoices($year);
    foreach($spec['years'][$year===2025?0:1]['groups'] as $group)foreach($group['sources'] as $row)
        check($choices[$row['code']]['xml_rule']===true,'reviewed XML rule distinct from printed group');
    check($choices['72500']['group']!==null&&!$choices['72500']['xml_rule'],'a printed group alone is not XML coverage');
}
echo "$n checks including exact XML coverage distinction passed\n";
