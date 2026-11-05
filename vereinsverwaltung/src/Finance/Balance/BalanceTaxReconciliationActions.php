<?php declare(strict_types=1);
// Central authenticated POST/CSRF protection precedes these original-bound controllers.
require_once __DIR__.'/BalanceTaxReconciliations.php';
if(str_starts_with($action,'balance-period-tax-')){require __DIR__.'/BalanceTaxReconciliationPeriodActions.php';return;}
require __DIR__.'/BalanceTaxReconciliationAnnualActions.php';
