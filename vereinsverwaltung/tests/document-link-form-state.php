<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';require __DIR__.'/../src/Web/View.php';
use Kulturhus\Verein\{Database,Documents,Registry,DocumentCatalog};
$checks=0;function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function selections(Documents $documents,array $data):array{ob_start();documentAssociations($documents,$data);$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);return [$html,array_map(fn($node)=>$node->getAttribute('value'),iterator_to_array($xpath->query('//input[@name="links[]"][@checked]')))];}
$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B'); INSERT INTO actors(id,club_id,role,is_superuser) VALUES('manager','a','members',1),('other','b','members',1)");
$documents=new Documents($db,'manager');$registry=new Registry($db,'manager');$key=fn()=>bin2hex(random_bytes(16));
$person=$registry->saveContact(null,0,'person','Alex Beispiel','alex@example.invalid','');
$event=$registry->saveAppointment(null,0,['title'=>'Mustertermin','start'=>'2026-09-05T12:00','end'=>'2026-09-05T13:00','location'=>'Testraum','description'=>'','category'=>'verein'],[]);
$id=$documents->upload('Nachweis.txt','Nur synthetische Daten',['title'=>'Nachweis','kind'=>'membership','links'=>['contact:'.$person,'appointment:'.$event]],$key());
$db->prepare('UPDATE contacts SET archived_at=? WHERE id=?')->execute([gmdate('c'),$person]);DocumentCatalog::register($db,'a','contact',$person);$registry->deleteAppointment($event,1);
$old=$documents->document($id);$data=$old+['folder'=>$old['folder_id']];$data['links']=array_map(fn($link)=>$link['source_type'].':'.$link['source_id'],$old['links']);
[$html,$posted]=selections($documents,$data);check(count($posted)===2&&str_contains($html,'Historische Zuordnung'),'saved historical member and event links stay visibly checked');
$documents->update($id,(int)$old['version'],['title'=>'Nachweis umbenannt','kind'=>$old['kind'],'document_date'=>$old['document_date'],'folder'=>$old['folder_id'],'state'=>$old['state'],'notes'=>$old['notes'],'links'=>$posted,'reason'=>'Titel korrigiert']);
check(count($documents->document($id)['links'])===2,'metadata-only form roundtrip preserves historical associations');
[$newHtml,$newPosted]=selections($documents,['links'=>[]]);check(!str_contains($newHtml,'contact:'.$person)&&!str_contains($newHtml,'appointment:'.$event),'historical sources are unavailable for new uploads');
$data['links']=[];[$errorHtml,$errorPosted]=selections($documents,$data);check($errorPosted===[]&&str_contains($errorHtml,'Historische Zuordnung'),'explicitly unchecked historical link remains unchecked on error');
$other=new Documents($db,'other');try{selections($other,$data);throw new RuntimeException('Foreign document rendered');}catch(DomainException){if(ob_get_level())ob_end_clean();check(true,'foreign document cannot expose retained labels');}
$current=$documents->document($id);$documents->update($id,(int)$current['version'],['title'=>$current['title'],'kind'=>$current['kind'],'document_date'=>$current['document_date'],'folder'=>$current['folder_id'],'state'=>$current['state'],'notes'=>$current['notes'],'links'=>[],'reason'=>'Zuordnung bewusst gelöst']);
check($documents->document($id)['links']===[],'explicit removal remains possible');
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'roundtrip preserves database integrity');
echo "$checks document link form state checks passed\n";
