<?php declare(strict_types=1);
// Plain HTML fallback keeps the entire original form open when scripts are unavailable.
if($view==='help')return;
$inlineHelpQuery=$_GET;$inlineHelpStatus=http_response_code();
try{$_GET=['view'=>'help','help_topic'=>$taskHelpEntry['help_topic']??'access'];ob_start();require __DIR__.'/tasks.php';$inlineHelpBody=ob_get_clean();}
finally{$_GET=$inlineHelpQuery;http_response_code($inlineHelpStatus);}
?>
<noscript><link rel="stylesheet" href="manager-help-no-script.css"><details class="manager-help-no-script" data-nojs-help><summary><?=e($managerText('Hilfe'))?></summary><div class="manager-help-no-script-body"><?=$inlineHelpBody?></div></details></noscript>
