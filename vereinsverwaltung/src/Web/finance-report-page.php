<?php declare(strict_types=1);
if($view!=='finance'||$tab!=='reports'||!$canFinance)return;
$financeReportPageState=\Kulturhus\Verein\Web\FinanceReportState::read(new \Kulturhus\Verein\FinanceReports($db,(string)$_SESSION['actor']),$_GET,substr(\Kulturhus\Verein\Finance::today(),0,4));
if(!$financeReportPageState['available']&&http_response_code()<400)http_response_code($financeReportPageState['http_status']);
