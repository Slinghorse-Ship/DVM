<?php declare(strict_types=1);
use Kulturhus\Verein\BalanceTaxMapping as M;
$mappingReview='financial-debt-r19';
require __DIR__.'/balance-operating-r15.php';
foreach([2025,2026] as $year){
    $choices=M::chartChoices($year);
    foreach($spec['years'][$year===2025?0:1]['groups'] as $group)foreach($group['sources'] as $row)
        check($choices[$row['code']]['xml_rule']===true,'debt rule has an exact XML implementation');
    check($choices['36300']['xml_rule']&&$choices['36300']['review_required'],'original clearing now has a qualified conditional direction');
    check($choices['31420']['xml_rule']&&!$choices['31420']['review_required'],'later exact statutory-benefit rule is available');
    foreach(['32490','33490','35300','35990','36950'] as $code)
        check($choices[$code]['xml_rule']&&$choices[$code]['review_required'],'later conditional rule retains explicit review: '.$code);
}
echo "$n financial-debt mapping/view checks passed\n";
