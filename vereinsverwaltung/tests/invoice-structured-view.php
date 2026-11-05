<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect();\Kulturhus\Verein\Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic invoice rendering');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1)");
$_SESSION=['actor'=>'admin'];$_POST=[];
$itemInventory=new \Kulturhus\Verein\Inventory($db,'admin');$finance=new \Kulturhus\Verein\Finance($db,'admin');
function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function url(array $args):string{return '/?'.http_build_query($args);}
function input(array $a,string $key,string $default=''):string{return is_string($a[$key]??null)?$a[$key]:$default;}
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
$render=static function(string $mime,array $inspection)use($db,$itemInventory,$finance):string{$inboxSelected=['mime'=>$mime,'revision'=>2];$inboxId='synthetic';$guidedPost=false;$itemChoices=[];ob_start();require __DIR__.'/../views/Finance/Invoices/invoice-recognition.php';return ob_get_clean();};
$inspection=['state'=>'recognized','source'=>'xml','fields'=>['Rechnungssteller'=>'Synthetic XML','E-Mail Rechnungssteller'=>'xml@example.org'],'items'=>[],'bank_accounts'=>[['iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX','bank_name'=>'Synthetic']]];
foreach(['application/xml'=>'xml','application/pdf'=>'embedded_xml'] as $mime=>$source){$inspection['source']=$source;$html=$render($mime,$inspection);preg_match('/data-seed="([^"]+)"/',$html,$m);$seed=json_decode(htmlspecialchars_decode($m[1],ENT_QUOTES),true,512,JSON_THROW_ON_ERROR);check($seed['method']==='xml'&&$seed['source']===$source,'structured source retained '.$source);check($seed['bank_accounts']===$inspection['bank_accounts'],'all bank candidates retained '.$source);check($seed['fields']['E-Mail Rechnungssteller']==='xml@example.org','supplier contact retained '.$source);check(str_contains($html,'data-recognition-run')===($mime==='application/xml'),'XML can be read explicitly; embedded XML needs no raster OCR button');}
check(str_contains($render('application/pdf',[]),'data-recognition-run'),'ordinary PDF keeps explicit OCR');check(!str_contains($render('application/xml',['state'=>'invalid']),'data-recognition-run'),'invalid XML never sent to OCR');
echo "$n structured invoice view checks passed; in-memory database, no OCR or network\n";
