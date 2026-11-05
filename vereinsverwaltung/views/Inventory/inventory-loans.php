<?php declare(strict_types=1);
use Kulturhus\Verein\Inventory;
if(!$inventory->canReadLoans())throw new DomainException('Keine Berechtigung für Ausleihen.');
if(isset($inventoryLoanActionState)){require __DIR__.'/inventory-loan-action-original.php';if($inventoryLoanActionState['blocked'])return;}
if(isset($inventoryLoanCreateState)){require __DIR__.'/inventory-loan-original.php';if($inventoryLoanCreateState['blocked'])return;}
$loanListRoute=input($_GET,'return_where')==='1'?(['view'=>'inventory','tab'=>'where']+scalarFields($_GET,['q','member','location','state','where_page'])):['view'=>'inventory','tab'=>'loans']+scalarFields($_GET,['q','state','borrower','loan_page']);
if($loanId){require __DIR__.'/inventory-loan-detail.php';return;} ?>
<?php if(isset($inventoryLoanCreateState))require __DIR__.'/inventory-loan-create.php'; ?>
<?php require __DIR__.'/inventory-loan-catalogue.php'; ?>
