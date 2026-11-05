<?php declare(strict_types=1);
try{echo \Kulturhus\Native\Api\TokensView::render($db,(string)($_SESSION['auth_token']??''),(string)$_SESSION['csrf'],$_GET);}
catch(\Throwable $failure){
 http_response_code($failure instanceof \Kulturhus\Native\Api\Problem?$failure->status:($failure instanceof \DomainException?403:503));
 echo \Kulturhus\Native\Api\TokensView::unavailable($failure instanceof \Kulturhus\Native\Api\Problem?$failure->getMessage():'API-Zugänge sind derzeit nicht verfügbar.',$_GET,$managerLanguageMessages??[]);
}
