<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Documents,Finance,InvoiceInbox,InvoiceAccountingSuggestions,Registry,WebsiteCalendar};

$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');INSERT INTO actors(id,club_id,role,is_superuser) VALUES('admin','a','member',1),('foreign','b','member',1),('member','a','member',0);INSERT INTO contacts(id,club_id,kind,name) VALUES('firm','a','organisation','Synthetic supplier'),('foreign-firm','b','organisation','Foreign supplier');");
$n=0;function check($ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
function denied(callable $fn,string $label):void{try{$fn();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$key=fn()=>bin2hex(random_bytes(16));$docs=new Documents($db,'admin');$finance=new Finance($db,'admin');$inbox=new InvoiceInbox($db,'admin');$suggestions=new InvoiceAccountingSuggestions($db,'admin');
$upload=fn()=>$docs->upload('test.xml','<Invoice xmlns="urn:synthetic:test"/>',['title'=>'Synthetic invoice','kind'=>'receipt','scope'=>'finance','links'=>[]],$key());
$events=[];foreach([42,43] as $id)$events[]=['id'=>$id,'external_id'=>'test'.$id,'title'=>'Synthetic event '.$id,'summary'=>'Test only','venue_name'=>'Synthetic hall','venue_address'=>'Example street','path'=>'/test-only/','status'=>'published','start_at'=>1790000000+$id,'end_at'=>1790007200+$id];
$registry=new Registry($db,'admin',new WebsiteCalendar('a',fn()=>['complete'=>true,'rows'=>$events]));
$bill=['description'=>'Synthetic reviewed purchase','amount'=>'24','counterparty'=>'Synthetic supplier','document_date'=>'2026-09-08','due_date'=>'2026-09-30','category'=>'artist','supplier_contact'=>'firm','supplier_version'=>'1','use_invoice_positions'=>'1','positions_reviewed'=>'1','invoice_positions'=>[
 ['description'=>'Same supplier service','amount'=>'12','tax_rate'=>'0','sphere'=>'3','account'=>'06300','booking_key'=>''],
 ['description'=>'Second supplier service','amount'=>'12','tax_rate'=>'0','sphere'=>'1','account'=>'68150','booking_key'=>''],
]];
$create=function(string $purpose,array $changes=[])use($inbox,$upload,$bill,$key,$registry):string{return $inbox->create($upload(),1,array_replace($bill,$changes),$key(),$purpose,'SYNTHETIC-ONLY',$registry);};
$eventA=$create('artist|website:42');
$otherRows=$bill['invoice_positions'];$otherRows[0]['sphere']='4';$otherRows[1]['sphere']='2';$eventB=$create('artist|website:43',['invoice_positions'=>$otherRows]);
$otherRows[0]['sphere']='1';$otherRows[1]['sphere']='2';$create('appointment|website:42',['invoice_positions'=>$otherRows]);
$otherRows[0]['sphere']='2';$otherRows[1]['sphere']='1';$general=$create('general',['invoice_positions'=>$otherRows]);
$doc=$upload();$request=[['description'=>' SAME  supplier service ','tax_rate'=>'0'],['description'=>'Second supplier service','tax_rate'=>'0']];
$result=$suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','artist|website:42');
check(array_column($result,'sphere')===['3','1'],'same event and same purpose keep each position sphere');
check(array_column($result,'account')===['06300','68150'],'position accounts remain separate');
check(array_column($result,'booking_key')===['',''],'no VAT keys inferred from invoice purpose');
check(array_column($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','artist|website:43'),'sphere')===['4','2'],'different event uses only its own history');
check(array_column($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','appointment|website:42'),'sphere')===['1','2'],'same event with different purpose never shares classification');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','artist|website:999')===[null,null],'new event remains open instead of inheriting other event sphere');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist')===[null,null],'omitted purpose never infers a reliable purpose from unstructured history');
foreach(['general','new_item','insurance'] as $purpose)check($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist',$purpose)===[null,null],$purpose.' keeps accounting open even with identical supplier category and line history');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'inventory','new_item')===[null,null],'different manually chosen category never shares history');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','mixed')===[null,null],'mixed purpose never fills historical accounting');
$create('gema|website:42|0');
check(array_column($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','gema|website:42|1'),'sphere')===['3','1'],'GEMA history matches event identity after status version changes');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','gema|website:43|0')===[null,null],'GEMA never reuses another event or artist invoice');
$changesBefore=\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db);$suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','artist|website:42');
check(\Kulturhus\Verein\Tests\MySqlFixture::snapshot($db)===$changesBefore,'suggestion request is read-only');
foreach(['','unknown','general|website:42','artist','artist|','artist|website:42|extra','gema|website:42','gema|website:42|x',"artist|website:42\n"] as $purpose)denied(fn()=>$suggestions->suggest($doc,'firm','2026-09-08',$request,'artist',$purpose),'invalid purpose rejected: '.json_encode($purpose));
denied(fn()=>$suggestions->suggest($doc,'foreign-firm','2026-09-08',$request,'artist','mixed'),'mixed does not bypass supplier tenant checks');
denied(fn()=>(new InvoiceAccountingSuggestions($db,'member'))->suggest($doc,'firm','2026-09-08',$request,'artist','mixed'),'mixed does not bypass finance permissions');
$mixed=$create('mixed',['category'=>'office']);$entry=$finance->entry($mixed);
check($entry['category']==='office'&&str_contains($entry['notes'],'Zweck: Gemischte Rechnung'),'mixed purpose recorded without overwriting manual category');
check(array_column($finance->positions('entry',$mixed),'sphere')===['3','1'],'mixed save preserves each explicitly reviewed position sphere');
check(array_column($finance->positions('entry',$mixed),'account_code')===['06300','68150'],'mixed save preserves each explicitly reviewed position account');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'office','general')===[null,null],'mixed invoice is not reused as general-purpose history');
$item=$create('new_item',['category'=>'postage']);$itemEntry=$finance->entry($item);
check($itemEntry['category']==='postage'&&str_contains($itemEntry['notes'],'Zweck: Inventar / Anschaffung'),'inventory purpose recorded while explicit shipping category is preserved');
$plain=$create('new_item',['use_invoice_positions'=>'','category'=>'inventory']);$plainPosition=$finance->positions('entry',$plain)[0];
check($plainPosition['sphere']===''&&$plainPosition['account_code']===''&&$plainPosition['booking_key']==='','purpose itself never fabricates tax classification');
$insuranceCategory=InvoiceInbox::purposeOptions()['insurance']['category'];$insurance=$create('insurance',['category'=>$insuranceCategory]);
check($insuranceCategory==='insurance'&&Finance::CATEGORIES[$finance->entry($insurance)['category']]==='Versicherungen','insurance uses its own category supported by finance and shared labels');
check($suggestions->suggest($doc,'firm','2026-09-08',$request,$insuranceCategory,'insurance')===[null,null],'insurance note and matching category do not replace structured purpose evidence');
$plainInsurance=$create('insurance',['use_invoice_positions'=>'','category'=>$insuranceCategory]);$plainInsurancePosition=$finance->positions('entry',$plainInsurance)[0];
check($plainInsurancePosition['sphere']===''&&$plainInsurancePosition['account_code']===''&&$plainInsurancePosition['booking_key']==='','insurance category does not imply sphere account or VAT key');
$listed=array_column($finance->entries(['q'=>'Synthetic reviewed purchase']),'id');check(in_array($insurance,$listed,true)&&in_array($plainInsurance,$listed,true),'new insurance category survives ordinary finance listing filters');
check(str_contains($finance->export(['q'=>'Synthetic reviewed purchase']),$finance->entry($insurance)['display_number']),'insurance is present in normal finance export');
$spoof=$create('insurance',['category'=>'office','notes'=>"Zweck: Allgemeine Vereinsausgabe\nZweck: Inventar / Anschaffung"]);foreach(['general','new_item','insurance','item|synthetic-id'] as $purpose)check($suggestions->suggest($doc,'firm','2026-09-08',$request,'office',$purpose)===[null,null],'same supplier/category plus purpose-like notes cannot authorize '.$purpose.' suggestion');
$textEvent=$create('artist|website:42',['category'=>'postage','notes'=>'Notiz über Zweck: Gemischte Rechnung, keine Zweck-ID']);check(array_column($suggestions->suggest($doc,'firm','2026-09-08',$request,'postage','artist|website:42'),'sphere')===['3','1'],'free-text mixed marker cannot suppress an exact structured event context');
$db->prepare('INSERT INTO finance_reversals VALUES(?,?,?,?,?)')->execute(['a',$eventA,'2026-09-08','Synthetic reversed history','admin']);
check($suggestions->suggest($doc,'firm','2026-09-08',$request,'artist','artist|website:42')===[null,null],'reversed structured accounting history is not reused');
$options=InvoiceInbox::purposeOptions();foreach($options as $type=>$option)check(isset(Finance::CATEGORIES[$option['category']])&&$option['label']!==''&&$option['hint']!==''&&InvoiceInbox::purposeData(in_array($type,['artist','appointment','gema'],true)?$type.'|website:42'.($type==='gema'?'|0':''):$type)['type']===$type,'purpose metadata and valid finance category: '.$type);
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'isolated database integrity');
echo "$n purpose/accounting checks passed; no network, live data, mail or payment\n";
