<?php declare(strict_types=1);
require __DIR__.'/../src/Web/View.php';
$checks=0;
function revisionViewCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function revisionView(string $status,array $revisions):array{
    $item=['id'=>55,'status'=>$status,'version'=>'current-token'];$native=['revisions'=>$revisions];$calls=[];
    $simple=static function($operation,$label,$fields,$version)use(&$calls):void{$calls[]=[$operation,$fields,$version];echo '<button>'.e($label).'</button>';};
    ob_start();require __DIR__.'/../views/Website/website-newsletter-revisions.php';return [ob_get_clean(),$calls];
}
$record=['id'=>7,'campaign_id'=>55,'kind'=>'saved','saved_at'=>'2026-10-01 10:00:00','actor_name'=>'Test <b>Editor</b>','source_reference'=>'Test <img src=x onerror=alert(1)>','subject'=>'Saved <subject>','body'=>'<p>Saved text</p><script>alert(1)</script><p>&lt;img src=x onerror=alert(1)&gt;</p>'];
[$html,$calls]=revisionView('draft',[$record]);
revisionViewCheck(str_contains($html,'id="newsletter-revisions"')&&str_contains($html,'Versionsverlauf'),'directly linked history');
revisionViewCheck(str_contains($html,'2026-10-01 10:00:00')&&str_contains($html,'Test &lt;b&gt;Editor&lt;/b&gt;'),'time and escaped actor shown');
revisionViewCheck(str_contains($html,'Gespeichert')&&str_contains($html,'Saved &lt;subject&gt;'),'revision kind and subject shown');
revisionViewCheck(!str_contains($html,'<script')&&!str_contains($html,'<img'),'historical markup never active');
revisionViewCheck($calls===[['newsletter/restore',['id'=>55,'revision'=>7],'current-token']],'restore targets exact campaign revision and current token');
[$deletedHtml,$deletedCalls]=revisionView('deleted',[$record]);revisionViewCheck(count($deletedCalls)===1,'recoverable deleted draft');
foreach(['queued','sending','sent','sent_with_errors'] as $status){[$frozen,$actions]=revisionView($status,[$record]);revisionViewCheck($actions===[]&&str_contains($frozen,'Saved text'),'no restore during or after transport: '.$status);}
[$empty,$actions]=revisionView('draft',[]);revisionViewCheck($actions===[]&&str_contains($empty,'Mit dem nächsten Speichern'),'no invented history');
echo "$checks newsletter revision UI checks passed; no database or transport\n";
