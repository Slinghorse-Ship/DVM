<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/Finance/Tax/TaxReviewQueue.php';
use Kulturhus\Verein\{Database,Documents,Finance,InvoiceInbox,InvoiceRecognition,Settings,TaxReviewQueue};

$checks=0;
function check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);++$checks;echo "PASS $message\n";}
function denied(callable $action,string $message):void {try{$action();}catch(DomainException){check(true,$message);return;}throw new RuntimeException($message);}
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {throw new ErrorException($message,0,$severity,$file,$line);});
putenv('VEREIN_OCR_RUNTIME=/no-ocr-runtime-in-this-read-only-test');
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetischer Verein'),('b','Anderer Verein');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('reader','a','member',0),('member','a','member',0),('foreign','b','member',1)");
$settings=new Settings($db,'admin');$role=$settings->saveRole('',0,['name'=>'Nur Finanzlesen','finance_access'=>'read','members_access'=>'none','settings_access'=>false]);$settings->assignRole('reader',$role,0);
$docs=new Documents($db,'admin');$finance=new Finance($db,'admin');$queue=new TaxReviewQueue($db,'reader');$key=static fn()=>bin2hex(random_bytes(16));
$upload=static function(string $title,string $date='2026-09-08',string $scope='finance',string $kind='receipt')use($docs,$key):string {
 return $docs->upload('synthetic.xml','<Invoice xmlns="urn:synthetic:test"/>',['title'=>$title,'kind'=>$kind,'document_date'=>$date,'scope'=>$scope,'links'=>[]],$key());
};
$fields=['Rechnungsdatum'=>'2025-03-04','Rechnungsnummer'=>'TEST-2025-1','Rechnungssteller'=>'Fiktiver Anbieter','Rechnungsbetrag brutto'=>'123.45','Belegart'=>'Rechnung','Währung'=>'EUR'];
$cache=static function(string $id,array $values=[],array $override=[])use($db,$docs,$fields):void {
 $file=$docs->file($id);$data=array_replace(['revision'=>$file['revision'],'sha256'=>$file['sha256'],'parser_version'=>InvoiceRecognition::VERSION,'state'=>'ready','result_json'=>json_encode(['state'=>'ready','fields'=>array_replace($fields,$values),'text'=>str_repeat('Synthetic PDF text ',1000)],JSON_THROW_ON_ERROR)],$override);
 $q=$db->prepare('INSERT INTO invoice_recognition(club_id,document_id,revision,sha256,parser_version,state,result_json,updated_at,actor_id) VALUES(?,?,?,?,?,?,?,?,?)');
 $q->execute(['a',$id,$data['revision'],$data['sha256'],$data['parser_version'],$data['state'],$data['result_json'],'2026-09-08T12:00:00Z','admin']);
};
$matched=$upload('Fiktives Roll-up');$cache($matched);
$current=$upload('Fiktive Rechnung 2026');$cache($current,['Rechnungsdatum'=>'2026-02-03']);
$old=$upload('Fiktive Rechnung 2022');$cache($old,['Rechnungsdatum'=>'2022-04-04']);
$undated=$upload('Noch nicht ausgelesen');
$oldDocument=$upload('Dokumentdatum ist kein Rechnungsnachweis','2025-06-01');
$invalidDate=$upload('Ungültiges Rechnungsdatum');$cache($invalidDate,['Rechnungsdatum'=>'2025-02-30']);
$outsideDate=$upload('Jahr außerhalb des unterstützten Zeitraums');$cache($outsideDate,['Rechnungsdatum'=>'1999-04-04']);
$stale=[];
foreach(['parser_version'=>'previous','revision'=>2,'sha256'=>str_repeat('0',64),'state'=>'failed'] as $column=>$value){$id=$upload('Veralteter Cache '.$column);$cache($id,[],[$column=>$value]);$stale[]=$id;}
$running=$upload('Erkennung läuft');$cache($running,[],['state'=>'running']);$stale[]=$running;
$broken=$upload('Beschädigter Cache');$cache($broken,[],['result_json'=>'{']);$stale[]=$broken;
$badFields=$upload('Ungültige Feldtypen');$cache($badFields,[],['result_json'=>'{"fields":{"Rechnungsdatum":[],"Rechnungssteller":{},"Rechnungsbetrag brutto":true}}']);$stale[]=$badFields;
$noAmount=$upload('Bruttobetrag ungeklärt');$cache($noAmount,['Rechnungsbetrag brutto'=>'123.45 / 234.56']);
$notEuro=$upload('Andere Währung');$cache($notEuro,['Währung'=>'USD']);
$archived=$upload('Archivierte Rechnung');$cache($archived);(new InvoiceInbox($db,'admin'))->archive($archived,1,true,'Synthetische Archivierung');
$otherKind=$upload('Sonstiges Dokument','2025-01-01','finance','other');
$private=$upload('Vertraulicher Nachweis','2025-01-01','settings');$cache($private);
$db->prepare('UPDATE documents SET scopes=? WHERE club_id=? AND id=?')->execute(['["settings"]','a',$private]);
$mixedScope=$upload('Zusätzlich geschützter Nachweis');$cache($mixedScope);
$db->prepare('UPDATE documents SET scopes=? WHERE club_id=? AND id=?')->execute(['["finance","settings"]','a',$mixedScope]);
$foreign=(new Documents($db,'foreign'))->upload('foreign.xml','<Invoice xmlns="urn:synthetic:foreign"/>',['title'=>'Fremder geheimer Beleg','kind'=>'receipt','document_date'=>'2025-01-01','scope'=>'finance','links'=>[]],$key());
$ubl='<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"><cbc:ID>SYNTHETIC-UBL</cbc:ID><cbc:IssueDate>2025-06-07</cbc:IssueDate><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode><cac:LegalMonetaryTotal><cbc:TaxInclusiveAmount>119.00</cbc:TaxInclusiveAmount><cbc:PayableAmount>119.00</cbc:PayableAmount></cac:LegalMonetaryTotal></Invoice>';
$xmlUncached=$docs->upload('synthetic-ubl.xml',$ubl,['title'=>'Strukturierte XML nur angesehen','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-08','links'=>[]],$key());
$inspection=\Kulturhus\Verein\EInvoiceInspection::document($docs,$xmlUncached);
check($inspection['fields']['Rechnungsdatum']==='2025-06-07'&&$inspection['source']==='xml','real XML inspection extracts date without a persisted recognition cache');
$xmlCached=$docs->upload('synthetic-ubl-cached.xml',$ubl,['title'=>'Strukturierte XML ausgelesen','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-08','links'=>[]],$key());
$xmlResult=(new InvoiceRecognition($db,'admin'))->run($xmlCached);
check($xmlResult['state']==='ready'&&$xmlResult['source']==='xml','real XML recognition can persist its structured result without raster OCR');
// PDF container bytes are irrelevant to this metadata-only test: the unit fixture
// binds a stored embedded-XML result to an immutable PDF revision, without extraction.
$embedded=$docs->upload('synthetic-container.pdf',"%PDF-1.4\nSynthetic metadata-only fixture\n%%EOF",['title'=>'Gespeicherte ZUGFeRD-Felder','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-08','links'=>[]],$key());
$cache($embedded,[],['result_json'=>json_encode(['state'=>'ready','method'=>'xml','source'=>'embedded_xml','fields'=>$inspection['fields']],JSON_THROW_ON_ERROR)]);
$pdfUncached=$docs->upload('synthetic-container-unread.pdf',"%PDF-1.4\nSynthetic unread fixture\n%%EOF",['title'=>'PDF ohne Feldcache','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-08','links'=>[]],$key());
$snapshot=static function()use($db):array{$rows=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$rows[$table]=\Kulturhus\Verein\Tests\MySqlFixture::rows($db,$table);return $rows;};
$before=$snapshot();$db->exec('SET SESSION TRANSACTION READ ONLY');
$result=$queue->build(2025);$rows=array_column($result['rows'],null,'id');$unknown=array_column($result['undated_rows'],null,'id');
check(isset($rows['inbox:'.$matched])&&$rows['inbox:'.$matched]['date']==='2025-03-04','current recognized invoice year wins over mail intake year');
check($rows['inbox:'.$matched]['cents']===12345&&$rows['inbox:'.$matched]['date_basis']==='recognition','recognized gross cents and their provisional basis are explicit');
check(str_contains($rows['inbox:'.$matched]['url'],'tab=inbox')&&str_contains($rows['inbox:'.$matched]['url'],'inbox_doc='.$matched),'unrecorded invoice links directly to its actual review page');
check(array_column($rows['inbox:'.$matched]['reasons'],'code')===['unrecorded_invoice'],'unrecorded invoice is not falsely reported as an accounting position');
check(!isset($rows['inbox:'.$current])&&!isset($unknown['inbox:'.$current]),'known other invoice year is excluded without becoming unknown');
check(isset(array_column($queue->build(2022)['rows'],null,'id')['inbox:'.$old]),'historical invoice appears in its original recognized year');
check(isset($unknown['inbox:'.$undated])&&!isset($rows['inbox:'.$undated]),'unrecognized incoming file is separate from selected year');
check(isset($unknown['inbox:'.$oldDocument])&&!isset($rows['inbox:'.$oldDocument]),'document date alone never claims an invoice belongs to 2025');
check($unknown['inbox:'.$undated]['cents']===null&&$unknown['inbox:'.$undated]['date_basis']==='document_fallback','unknown amount remains unknown with explicit document-date fallback');
check(isset($unknown['inbox:'.$xmlUncached])&&!isset($rows['inbox:'.$xmlUncached]),'viewing structured XML alone does not fabricate a persistent invoice year');
check(isset($rows['inbox:'.$xmlCached])&&$rows['inbox:'.$xmlCached]['date']==='2025-06-07'&&$rows['inbox:'.$xmlCached]['cents']===11900,'bound XML recognition fields establish year and gross amount');
check(isset($rows['inbox:'.$embedded])&&$rows['inbox:'.$embedded]['date']==='2025-06-07','bound embedded-XML cache uses the same invoice-year policy');
check(isset($unknown['inbox:'.$pdfUncached]),'uncached PDF stays unknown without invoking embedded extraction or OCR');
check(isset($unknown['inbox:'.$invalidDate])&&isset($unknown['inbox:'.$outsideDate]),'invalid and unsupported dates cannot assign an invoice year');
foreach($stale as $id)check(isset($unknown['inbox:'.$id])&&!isset($rows['inbox:'.$id]),'stale failed running or malformed cache cannot establish a year');
check($rows['inbox:'.$noAmount]['cents']===null&&$rows['inbox:'.$notEuro]['cents']===null,'ambiguous or foreign-currency values are not displayed as EUR');
foreach([$archived,$otherKind,$private,$mixedScope,$foreign] as $id)check(!isset($rows['inbox:'.$id])&&!isset($unknown['inbox:'.$id]),'archive kind scope and tenant restrictions remain effective');
check(!$result['can_write']&&$snapshot()===$before,'finance reader uses native read-only transactions with no recognition or financial writes');
check((int)$db->query('SELECT COUNT(*) FROM finance_entries')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn()===0,'reviewing input never creates a financial entry or payment');
denied(fn()=>(new TaxReviewQueue($db,'member'))->build(2025),'ordinary member cannot inspect invoice queue');
$db->exec('SET SESSION TRANSACTION READ WRITE');
// Actual association removes the inbox task; the financial task is not duplicated.
$entry=$finance->createEntry(['kind'=>'expense','amount'=>'123.45','description'=>'Fiktives Roll-up','category'=>'general','document_date'=>'2025-03-04','due_date'=>'2025-03-18'],$key(),fn($id)=>$docs->linkExpense($matched,1,$id));
$linked=array_column($queue->build(2025)['rows'],null,'id');
check(!isset($linked['inbox:'.$matched])&&isset($linked['entry:'.$entry]),'actual entry association replaces inbox task with the financial follow-up');
$bank=$finance->createAccount('Synthetisches Konto','offline','0','2025-01-01');$finance->recordPayment($entry,1,$bank,'2025-03-05');
$paid=array_column($queue->build(2025)['rows'],null,'id');
check(!isset($paid['inbox:'.$matched])&&count(array_filter($paid,fn($row)=>$row['id']==='entry:'.$entry))===1,'paid invoice still produces one financial follow-up and no inbox duplicate');
check(!isset(array_column($queue->build(2025)['undated_rows'],null,'id')['inbox:'.$matched]),'linked invoice cannot reappear through fallback section');

function input(array $data,string $key,string $default=''):string{return is_string($data[$key]??null)?$data[$key]:$default;}
function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function hidden(string $name,mixed $value):string{return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';}
function url(array $data):string{return '?'.http_build_query($data);}
$render=static function(string $search)use($db):string {$_SESSION=['actor'=>'reader'];$_GET=['year'=>'2025','q'=>$search];ob_start();require __DIR__.'/../views/Finance/Tax/tax-review-queue.php';return ob_get_clean();};
$db->prepare('UPDATE documents SET title=? WHERE id=?')->execute(['<script>synthetic invoice</script>',$undated]);
$html=$render('');
check(str_contains($html,'Rechnungsjahr noch prüfen')&&str_contains($html,'Dokumentdatum 2026-09-08'),'fallback section and date basis are clearly rendered');
check(str_contains($html,'Betrag noch prüfen')&&!str_contains($html,' · 0,00 EUR'),'unknown amounts never render as invented zero');
check(str_contains($html,'&lt;script&gt;synthetic invoice&lt;/script&gt;')&&!str_contains($html,'<script>synthetic invoice'),'incoming document labels are escaped');
check(str_contains($html,'Vorgang ansehen')&&!str_contains($html,'Rechnung prüfen und erfassen'),'finance read-only view does not promise write rights');
$search=$render('synthetic invoice');
check(str_contains($search,'&lt;script&gt;synthetic invoice&lt;/script&gt;')&&!str_contains($search,'Bruttobetrag ungeklärt'),'same search filters dated and unknown-year sections');
check(str_contains($search,'value="2025" selected')&&str_contains($search,'value="synthetic invoice"'),'year and search state persist');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'database integrity preserved');
echo "$checks invoice follow-up checks passed\n";
