<?php
require __DIR__.'/donation-receipts.php';
require __DIR__.'/../src/Web/View.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$_SESSION=['actor'=>'writer','csrf'=>str_repeat('a',64)];$_POST=[];
$_GET=['kind'=>'money','source_id'=>$entry];$canFinance=true;
ob_start();require __DIR__.'/../views/Finance/Donations/donation-forms.php';$html=ob_get_clean();
check(!str_contains($html,'value="donation-issue"')&&str_contains($html,'value="donation-cancel"'),'already certified source offers cancellation instead of duplicate issuance');
check(str_contains($html,'Bereits bescheinigt.')&&str_contains($html,'Zur Unterschrift erstellt'),'existing receipt and unsigned status visible');
$_SESSION['actor']='reader';$finance=new \Kulturhus\Verein\Finance($db,'reader');
ob_start();require __DIR__.'/../views/Finance/Donations/donation-forms.php';$html=ob_get_clean();
check(!str_contains($html,'value="donation-issue"')&&!str_contains($html,'value="donation-cancel"'),'reader sees neither mutation action');
check(str_contains($html,e(url(['view'=>'finance','tab'=>'receipts','entry'=>$entry])))&&str_contains($html,'Zurück zum Beleg'),'linked money form returns to its source receipt');
$_GET=['kind'=>'goods'];
ob_start();require __DIR__.'/../views/Finance/Donations/donation-forms.php';$html=ob_get_clean();
check(str_contains($html,e(url(['view'=>'donations','tab'=>'goods'])))&&str_contains($html,'Für eine nummerierte Bescheinigung'),'unlinked goods form retains donation type and explains issuance entry point');
echo "5 receipt view checks passed\n";
