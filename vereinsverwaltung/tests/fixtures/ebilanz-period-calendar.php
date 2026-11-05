<?php declare(strict_types=1);
// Reuse the checked calendar/period arithmetic, including original asset proofs.
ob_start();require __DIR__.'/../balance-period-tax-calendar.php';ob_end_clean();
require_once __DIR__.'/../../src/Finance/Balance/BalanceSubsidiaryClubValues.php';
function calendarPeriodFixture(string $variant):array
{
    if(!in_array($variant,['book','tax'],true))throw new InvalidArgumentException('Unknown calendar bridge case');
    if($variant==='tax')return \Kulturhus\Verein\BalanceSubsidiaryClubValues::build($GLOBALS['out']);
    $prior=\Kulturhus\Verein\BalanceClubTaxReconciliation::build($GLOBALS['income'],taxIncomeReview($GLOBALS['income']));
    $review=taxIncomeReview($GLOBALS['next']);$review['period']=$GLOBALS['next']['period'];
    $carry=\Kulturhus\Verein\BalanceClubTaxReconciliation::carryForward($prior,$GLOBALS['next']);
    $review['opening_adjustments']=$carry['entry']?[$carry['entry']]:[];
    return \Kulturhus\Verein\BalanceSubsidiaryClubValues::build(\Kulturhus\Verein\BalanceClubTaxReconciliation::build($GLOBALS['next'],$review,$prior));
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(calendarPeriodFixture($argv[1]??'tax'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
