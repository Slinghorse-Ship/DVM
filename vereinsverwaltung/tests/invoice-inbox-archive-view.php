<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';require dirname(__DIR__).'/src/Web/View.php';
$finance=new class{public function canWrite():bool{return true;}};
$documents=new class{public function canEdit(string $id):bool{return true;}};
$inboxId='synthetic';$inboxSelected=['id'=>$inboxId,'entry_links'=>[],'state'=>'active','version'=>2];$_GET=['inbox_doc'=>$inboxId];$_SESSION=['csrf'=>'own-archive-view'];
$render=static function()use(&$finance,&$documents,&$inboxId,&$inboxSelected):string{ob_start();require __DIR__.'/../views/Finance/Invoices/invoice-inbox-archive.php';return ob_get_clean();};
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$_POST=[];$html=$render();check(str_contains($html,'<details')&&!preg_match('/<details[^>]*\sopen[\s>]/',$html),'ordinary opening keeps archive secondary');
$_POST=['action'=>'invoice-inbox-archive','doc'=>$inboxId,'reason'=>'0','document_version'=>'1','archive'=>'1'];$html=$render();check((bool)preg_match('/<details[^>]*\sopen[\s>]/',$html),'failed archive POST leaves explanation open');check(!str_contains($html,'<form')&&str_contains($html,'data-invoice-archive-raw="document_version"><pre>1</pre>')&&str_contains($html,'data-invoice-archive-raw="reason"><pre>0</pre>'),'stale error preserves original reason and version without another confirmation');
$inboxSelected['state']='archived';$html=$render();check(!str_contains($html,'<form')&&str_contains($html,'data-invoice-archive-raw="archive"><pre>1</pre>')&&!str_contains($html,'<summary>Rechnung wiederherstellen</summary>'),'stale archive cannot become an opposite restore confirmation');
$_POST=[];check(str_contains($render(),'<summary>Rechnung wiederherstellen</summary>'),'fresh explicit archived original read offers restore');
$inboxSelected['entry_links']=[['source_id'=>'synthetic-entry']];check($render()==='','assigned original has no archive form');
echo "$n archive view checks passed; no database or HTTP write\n";
