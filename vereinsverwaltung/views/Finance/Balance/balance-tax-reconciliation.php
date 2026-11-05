<?php declare(strict_types=1);
if((input($_GET,'periods')==='1'&&input($_GET,'period_tax')==='1')||str_starts_with(input($_POST,'action'),'balance-period-tax-')){require __DIR__.'/balance-tax-reconciliation-period.php';return;}
require __DIR__.'/balance-tax-reconciliation-annual.php';
