<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,Inventory,Finance};
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--synthetic'){fwrite(STDERR,"Nur lokal: --synthetic erforderlich.\n");exit(1);}
$dsn=trim((string)getenv('VEREIN_DATABASE_DSN'));$path=$dsn!==''?$dsn:'';if(getenv('VEREIN_MODE')!=='synthetic'||!str_starts_with(strtolower($path),'mysql:'))throw new RuntimeException('Demo zuerst initialisieren.');
$db=Database::connect($path);$inventory=new Inventory($db,'demo-manager');$finance=new Finance($db,'demo-manager');$key=fn()=>bin2hex(random_bytes(16));
$member=$db->query("SELECT contact_id FROM actors WHERE id='demo-member' AND club_id='kulturhus-demo'")->fetchColumn();if(!$member)throw new RuntimeException('Synthetisches Mitgliederprofil fehlt.');
$locations=[];foreach($inventory->locations() as $l)$locations[$l['name']]=$l['id'];
$location=$locations['Bei Mara · Testdaten']??$inventory->saveLocation('',0,['name'=>'Bei Mara · Testdaten','holder_id'=>$member],$key());
$existing=array_column($inventory->items(['status'=>'all']),'id','code');$before=array_column($finance->accounts(),'balance_cents','id');$added=0;
foreach($finance->goods() as $donation)if($donation['description']==='Lesepult · Testdaten'&&!isset($existing['TEST-PULT-01'])){
 $item=$inventory->saveItem('',0,['code'=>'TEST-PULT-01','name'=>'Lesepult · Testdaten','category'=>'Ausstattung','responsible_id'=>$member,'home_location'=>$location,'source_kind'=>'goods','source_id'=>$donation['id'],'unit_value'=>Finance::decimal((int)$donation['value_cents']),'value_note'=>'Vorhandene synthetische Sachspende, ein Lesepult.'],$key());
 $inventory->move($item,0,['kind'=>'receive','quantity'=>'1','location'=>$location,'reason'=>'Synthetischer Sachspendeneingang im Inventar.'],$key());$added++;
}
if(!isset($existing['TEST-MAT-01'])){
 $item=$inventory->saveItem('',0,['code'=>'TEST-MAT-01','name'=>'Kabelbinder · Testdaten','kind'=>'consumable','category'=>'Verbrauchsmaterial','minimum'=>'50','responsible_id'=>$member,'home_location'=>$location],$key());
 $inventory->move($item,0,['kind'=>'receive','quantity'=>'30','location'=>$location,'reason'=>'Synthetischer Materialbestand für Mindestbestandsprüfung.'],$key());$added++;
}
foreach($finance->accounts() as $account)if($account['kind']==='cash'&&!isset($existing['TEST-KASSE-01'])&&!$inventory->linkedItems('cash',$account['id'])){
 $item=$inventory->saveItem('',0,['code'=>'TEST-KASSE-01','name'=>'Handkasse · Testdaten','category'=>'Kassen','responsible_id'=>$member,'home_location'=>$location,'cash_account'=>$account['id']],$key());
 $inventory->move($item,0,['kind'=>'receive','quantity'=>'1','location'=>$location,'counted'=>Finance::decimal((int)$account['balance_cents']),'reason'=>'Synthetische Aufbewahrung zu bestehendem Test-Kassenbuch bestätigt.'],$key());$added++;break;
}
if(array_column($finance->accounts(),'balance_cents','id')!==$before)throw new RuntimeException('Finanzbestand unerwartet verändert.');
echo "$added zusätzliche Inventar-Testgegenstände angelegt; Geldbestände unverändert.\n";
