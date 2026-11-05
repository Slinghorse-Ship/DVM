<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once __DIR__.'/InvoiceSupplierContacts.php';
require_once __DIR__.'/InvoicePositionReview.php';
require_once __DIR__.'/InvoiceInventoryCreate.php';
require_once __DIR__.'/InvoiceAcquisitionLinks.php';
use PDO;use DomainException;
final class InvoiceInbox
{
 public function __construct(private PDO $db,private string $actor){}
 /** A purpose organises the invoice; it never determines a tax sphere or VAT key. */
 public static function purposeOptions():array
 {
  return [
   'general'=>['label'=>'Keine besondere Vorgangsverknüpfung','category'=>'general','hint'=>'Nur für Veranstaltung, Künstler oder GEMA einen Vorgang auswählen. Die steuerliche Zuordnung steht oben.'],
   'membership'=>['label'=>'Mitgliedschaft / Vereinswerbung','category'=>'membership','hint'=>'Zum Beispiel Flyer oder Werbemittel für neue Mitglieder. Die Ausgabe gehört in der Regel zum ideellen Bereich.'],
   'new_item'=>['label'=>'Inventar / Anschaffung','category'=>'inventory','hint'=>'Zum Beispiel Geräte, Möbel oder Zubehör. Der Gesamtbetrag bleibt am Originalbeleg nachvollziehbar.'],
   'insurance'=>['label'=>'Versicherung','category'=>'insurance','hint'=>'Welche Tätigkeit ist versichert? Eine Vereinsversicherung und eine Veranstaltung können unterschiedlich zuzuordnen sein.'],
   'artist'=>['label'=>'Künstler oder Band','category'=>'artist','hint'=>'Wählen Sie die zugehörige Veranstaltung. Die Art der Veranstaltung ist für die Sphäre entscheidend.'],
   'gema'=>['label'=>'GEMA / Musikrechte','category'=>'gema','hint'=>'Wählen Sie die zugehörige Veranstaltung. Die Rechnung wird auch dort bei GEMA verknüpft.'],
   'appointment'=>['label'=>'Veranstaltung / Werbemittel','category'=>'culture','hint'=>'Wählen Sie die zugehörige Veranstaltung, zum Beispiel für Plakate, Raummiete oder Veranstaltungstechnik.'],
   'mixed'=>['label'=>'Mehrere Zwecke / gemischte Rechnung','category'=>'general','hint'=>'Zum Beispiel Büromaterial und Waren für den Verkauf auf einer Rechnung. Die genaue Zuordnung kann intern später ergänzt werden.'],
  ];
 }
 /** Turn the few plain-language answers into an auditable first suggestion. Empty accounts stay open deliberately. */
 private static function classification(array $data,string $date):array
 {
  if(!array_key_exists('classification_area',$data))return [];
  $area=(string)($data['classification_area']??'');$event=(string)($data['classification_event_kind']??'');$asset=(string)($data['classification_asset_kind']??'');$special=(string)($data['classification_special']??'none');$inputTax=(string)($data['classification_input_tax']??'unknown');$rate=(string)($data['classification_vat_rate']??'');
  if(!in_array($area,['membership','event','club','asset','rent','sales','uncertain'],true))throw new DomainException('Bitte den Verwendungszweck auswählen.');
  if($area==='event'&&!in_array($event,['ideell','purpose','business'],true))throw new DomainException('Bitte die Art der Veranstaltung auswählen.');
  if($area==='asset'&&!in_array($asset,['consumable','equipment'],true))throw new DomainException('Bitte die Art des Gegenstands auswählen.');
  if(!in_array($special,['none','mixed','foreign','credit','longterm','unclear'],true))throw new DomainException('Sonderfall prüfen.');
  if(!isset(InputTaxTreatment::OPTIONS[$inputTax]))throw new DomainException('Vorsteuerabzug prüfen.');
  if(!in_array($rate,['0','7','19'],true))throw new DomainException('Steuersatz laut Originalrechnung auswählen.');
  $paymentSource=(string)($data['payment_source']??'none');$invoiceRecipient=(string)($data['invoice_recipient']??'club');
  if($paymentSource==='private_advance'&&$invoiceRecipient!=='club')$inputTax='none';
  $sphere='';$account='';$label='';
  if($special==='none'&&$area!=='uncertain'){
   if($area==='membership')[$sphere,$account,$label]=['1','66310','Ideeller Bereich · Öffentlichkeitsarbeit / Mitgliedschaft'];
   elseif($area==='club')[$sphere,$account,$label]=['1','68150','Ideeller Bereich · Vereinsarbeit'];
   elseif($area==='sales')[$sphere,$account,$label]=['4','66310','Wirtschaftlicher Geschäftsbetrieb · Öffentlichkeitsarbeit'];
   elseif($area==='rent')[$sphere,$account,$label]=['2','','Vermögensverwaltung'];
   elseif($area==='event')[$sphere,$account,$label]=match($event){'purpose'=>['3','66310','Zweckbetrieb · Veranstaltung / Werbung'],'business'=>['4','66310','Wirtschaftlicher Geschäftsbetrieb · Veranstaltung / Werbung'],default=>['1','66310','Ideeller Bereich · Veranstaltung / Werbung']};
   elseif($area==='asset')[$sphere,$account,$label]=$asset==='equipment'?['1','06300','Ideeller Bereich · Ausstattung']:['1','68150','Ideeller Bereich · Verbrauchsmaterial'];
  }
  if($account!==''){$year=TaxCatalog::documentYear($date);$catalog=TaxCatalog::accounts(TaxCatalog::assignmentYear($year));if(!isset($catalog[$account])||empty($catalog[$account]['active']))throw new DomainException('Vorgeschlagenes Steuerkonto ist im SKR42-Katalog nicht aktiv. Bitte vor dem Speichern prüfen.');}
  $booking=$inputTax==='deductible'&&$account!==''?match($rate){'7'=>'8','19'=>'9',default=>''}: '';
  if($inputTax!=='deductible')$booking='';
  return ['area'=>$area,'event'=>$event,'asset'=>$asset,'special'=>$special,'input_tax'=>$inputTax,'tax_rate'=>$rate,'sphere'=>$sphere,'account'=>$account,'booking_key'=>$booking,'label'=>$label];
 }
 /** The reporting category is a background label, derived from the plain-language flow. */
 private static function category(array $classification,string $purpose,string $fallback):string
 {
  $area=(string)($classification['area']??'');
  // An empty classification means the user has not gone through the optional
  // plain-language wizard yet. Keep the explicit category in that case; do
  // not silently replace it with a broader background label.
  if($area==='')return $fallback;
  if(($classification['special']??'none')!=='none'||$area==='uncertain')return 'general';
  return match($area){
   'membership'=>'membership','event'=>'culture','asset'=>'inventory','rent'=>'rooms',
   'club'=>'general','sales'=>'culture',
   default=>match($purpose){'membership'=>'membership','new_item'=>'inventory','insurance'=>'insurance','artist','gema','appointment'=>'culture',default=>$fallback}
  };
 }
 /** Validate the complete reference before using it to narrow accounting history. */
 public static function purposeData(string $purpose):array
 {
  $parts=explode('|',$purpose);$type=$parts[0];
  if(!isset(self::purposeOptions()[$type])&&$type!=='item')throw new DomainException('Rechnungszweck prüfen.');
  $specific=in_array($type,['item','appointment','artist','gema'],true);$count=$type==='gema'?3:($specific?2:1);
  if(count($parts)!==$count)throw new DomainException('Rechnungszweck und zugehörigen Vorgang prüfen.');
  $reference=$parts[1]??'';
  if($specific&&($reference===''||strlen($reference)>150||preg_match('/[\x00-\x1f\x7f]/',$reference)))throw new DomainException('Bitte den zugehörigen Vorgang auswählen.');
  if($type==='gema'&&(!ctype_digit($parts[2])||strlen($parts[2])>10))throw new DomainException('GEMA-Stand prüfen.');
  return ['type'=>$type,'reference'=>$reference,'version'=>$type==='gema'?(int)$parts[2]:null];
 }
 private function incoming(array $doc):?array
 {
  if($doc['origin']!=='upload'||$doc['kind']!=='receipt'||!in_array('finance',$doc['scopes'],true)||!in_array($doc['state'],['active','archived'],true))return null;
  $entries=array_values(array_filter($doc['links'],fn($l)=>$l['source_type']==='entry'));
  $doc['inbox_status']=$doc['state']==='archived'?'archived':($entries?'assigned':'open');$doc['entry_links']=$entries;
  $q=$this->db->prepare('SELECT sender,subject,received_at,imported_at FROM invoice_mail_imports WHERE club_id=? AND document_id=? ORDER BY imported_at');$q->execute([$doc['club_id'],$doc['id']]);$doc['mail_origins']=$q->fetchAll(PDO::FETCH_ASSOC);
  return $doc;
 }
 public function listing():array
 {
  if(!(new Finance($this->db,$this->actor))->canRead())throw new DomainException('Finanzleserecht erforderlich.');
  $documents=new Documents($this->db,$this->actor);$rows=[];
  foreach($documents->listing(['state'=>'all'])as$doc)if(($incoming=$this->incoming($doc))!==null)$rows[]=$incoming;
  return $rows;
 }
 public function document(string $id):array
 {
  if(!(new Finance($this->db,$this->actor))->canRead())throw new DomainException('Finanzleserecht erforderlich.');
  try{$doc=(new Documents($this->db,$this->actor))->document($id);}catch(DomainException $error){throw new DomainException('Eingangsrechnung nicht verfügbar.',0,$error);}
  return $this->incoming($doc)??throw new DomainException('Eingangsrechnung nicht verfügbar.');
 }
 /** One deliberate save: existing Finance transaction includes original and optional purpose. */
 public static function suggestions(array $fields):array
 {
  $out=[];
  foreach(['Rechnungsnummer'=>'supplier_reference','Rechnungssteller'=>'counterparty'] as $label=>$field){$value=trim((string)($fields[$label]??''));if($value!==''&&!str_contains($value,' / ')&&!preg_match('/[\x00-\x1f]/',$value)&&strlen($value)<=($field==='counterparty'?200:120))$out[$field]=$value;}
  foreach(['Rechnungsdatum'=>'document_date','Fälligkeit'=>'due_date'] as $label=>$field){$value=(string)($fields[$label]??'');if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]))$out[$field]=$value;}
  $amount=trim((string)($fields['Rechnungsbetrag brutto']??''));
  if(($fields['Belegart']??'')==='Rechnung'&&($fields['Währung']??'')==='EUR'&&preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$amount)&&(float)$amount>0)$out['amount']=str_replace('.',',',$amount);
  $rate='';
  foreach(['Umsatzsteuersatz','Steuersatz','Mehrwertsteuersatz','VAT rate'] as $label){$candidate=trim((string)($fields[$label]??''));if(preg_match('/(?:^|\s)(0|7|19)(?:[,.]0+)?\s*%?(?:\s|$)/u',$candidate,$m)){$rate=$m[1];break;}}
  if($rate===''){
   $tax=trim((string)($fields['Umsatzsteuerbetrag']??''));$gross=(float)str_replace(',','.',$amount);$taxValue=(float)str_replace(',','.',$tax);
   if($gross>0&&$taxValue>=0){foreach(['0'=>0.0,'7'=>7/107,'19'=>19/119] as $candidate=>$share)if(abs(($taxValue/$gross)-$share)<0.0125){$rate=$candidate;break;}}
  }
  if($rate!=='')$out['classification_vat_rate']=$rate;
  return $out;
 }
 public function create(string $document,int $version,array $data,string $key,string $purpose,string $reference,?Registry $registry=null):string
 {
  $finance=new Finance($this->db,$this->actor);if(!$finance->canWrite())throw new DomainException('Finanzschreibrecht erforderlich.');
  $data=InvoiceRecipientTax::prepareInvoice($data);
  $dueDateMissing=trim((string)($data['due_date']??''))==='';
  if($dueDateMissing)$data['due_date']=trim((string)($data['document_date']??''));
  if($finance->inputTaxEligibility()==='no'){
   $data['classification_input_tax']='none';
   if(is_array($data['invoice_positions']??null))foreach($data['invoice_positions'] as &$position)if(is_array($position))$position['input_tax']='none';
   unset($position);
  }
  $reference=trim($reference);if(strlen($reference)>120||preg_match('/[\x00-\x1f\x7f]/',$reference))throw new DomainException('Rechnungsnummer des Lieferanten prüfen.');
  $parts=explode('|',$purpose);$type=self::purposeData($purpose)['type'];
  $classification=self::classification($data,(string)($data['document_date']??''));$data['category']=self::category($classification,$type,(string)($data['category']??'general'));$review=InvoicePositionReview::form($data);if($review){$data['positions']=$review['positions'];if($review['tax_rounding'])$data['tax_rounding']=$review['tax_rounding'];}
  $classificationDescription=trim((string)($data['description']??''));if($classificationDescription==='')$classificationDescription='Eingangsrechnung';
  if(!$review&&$classification)$data['positions']=[['description'=>$classificationDescription,'amount'=>(string)($data['amount']??''),'tax_rate'=>$classification['tax_rate'],'sphere'=>$classification['sphere'],'account'=>$classification['account'],'booking_key'=>$classification['booking_key'],'input_tax'=>$classification['input_tax']]];
  $acquisitionLinks=InvoiceAcquisitionLinks::prepare($this->db,$this->actor,$document,$data,$review);
  $newItems=InvoiceInventoryCreate::requests($this->db,$this->actor,$data,$review);
  foreach($newItems as $ordinal=>$request)$review['details'][$ordinal]['new_inventory']=$request;
  $itemRefs=$data['item_refs']??[];if(!is_array($itemRefs)||count($itemRefs)>50)throw new DomainException('Bitte höchstens 50 Gegenstände auswählen.');
  $items=[];foreach($itemRefs as $ref){if(!is_string($ref)||!preg_match('/^([a-f0-9]{32})\|(\d+)$/D',$ref,$m))throw new DomainException('Gegenstandsauswahl prüfen.');if(isset($items[$m[1]]))throw new DomainException('Ein Gegenstand ist in dieser Rechnung mehrfach zugeordnet. Entferne die doppelte oder widersprüchliche Zuordnung ausdrücklich; es wird keine Version automatisch übernommen.',422);$items[$m[1]]=(int)$m[2];}
  $positions=$data['item_positions']??[];if(!is_array($positions))throw new DomainException('Positionsangaben prüfen.');$itemNotes=[];foreach($items as $id=>$v){$position=$positions[$id]??'';if(!is_string($position)||strlen($position)>300||preg_match('/[\x00-\x1f]/',$position))throw new DomainException('Position laut Rechnung bitte als kurzen Text eingeben.');$itemNotes[$id]=trim($position);}
  if($review)foreach($review['links'] as $ordinal=>$linkedItems)foreach($linkedItems as $id=>$itemVersion){if(isset($items[$id])&&$items[$id]!==$itemVersion)throw new DomainException('Gegenstandsversionen passen nicht zusammen.');$items[$id]=$itemVersion;$itemNotes[$id]=trim(($itemNotes[$id]??'').' Position '.$ordinal);}
  $supplier=trim((string)($data['supplier_contact']??''));$supplierVersion=(int)($data['supplier_version']??0);if($supplier!==''){$supplierRow=(new InvoiceSupplierContacts($this->db,$this->actor))->checked($supplier,$supplierVersion);$data['counterparty']=$supplierRow['name'];}
  $data['kind']='expense';$note=trim((string)($data['notes']??''));$purposeNote=match($type){'insurance'=>'Versicherung','new_item'=>'Inventar / Anschaffung','mixed'=>'Gemischte Rechnung',default=>''};$data['notes']=($dueDateMissing?'Zahlbar bis nicht auf Rechnung angegeben.\n':'').($reference!==''?'Lieferanten-Rechnungsnummer: '.$reference."\n":'').($purposeNote!==''?'Zweck: '.$purposeNote."\n":'').$note;
  $paymentSource=(string)($data['payment_source']??'none');$reimbursementContact=(string)($data['reimbursement_contact']??'');$invoiceRecipient=(string)($data['invoice_recipient']??'');
  if(!in_array($paymentSource,['none','club','private_advance'],true))throw new DomainException('Zahlungsweg prüfen.');
  if($paymentSource==='private_advance'&&($reimbursementContact===''||!isset(ExpenseReimbursements::RECIPIENTS[$invoiceRecipient])))throw new DomainException('Für eine private Vorleistung Mitglied und Rechnungsempfänger auswählen.');
  if($items){$inventory=new Inventory($this->db,$this->actor);if(!$inventory->canWrite())throw new DomainException('Inventar-Schreibrecht für die Gegenstandszuordnung erforderlich.');foreach($items as $id=>$itemVersion){$item=$inventory->item($id);$data['notes'].="\nInventar: ".$item['code'].' · '.$item['name'].($itemNotes[$id]!==''?' – Position laut Rechnung: '.$itemNotes[$id]:'');}}
  return $finance->createEntry($data,$key,function($entry)use($document,$version,$parts,$type,$registry,$supplier,$supplierVersion,$items,$itemNotes,$review,$newItems,$acquisitionLinks,$paymentSource,$reimbursementContact,$invoiceRecipient,$key){
   if($paymentSource==='private_advance')(new ExpenseReimbursements($this->db,$this->actor))->createForEntry($entry,$reimbursementContact,$invoiceRecipient,$key);
   InvoiceAcquisitionLinks::assertCurrent($this->db,$this->actor,$document,$acquisitionLinks);
   $documents=new Documents($this->db,$this->actor);$before=$documents->document($document);$alreadyLinked=(bool)array_filter($before['links'],fn($link)=>$link['source_type']==='entry'&&$link['source_id']===$entry);
   if(!$alreadyLinked&&$items){$inventory=new Inventory($this->db,$this->actor);if(!$inventory->canWrite())throw new DomainException('Inventar-Schreibrecht für die Gegenstandszuordnung erforderlich.');foreach($items as $id=>$itemVersion){$item=$inventory->item($id);if(!(int)$item['active']||(int)$item['version']!==$itemVersion)throw new DomainException('Ein ausgewählter Gegenstand wurde geändert oder archiviert. Bitte neu öffnen.');}}
   $extra=$type==='item'?[['type'=>'item','id'=>$parts[1]]]:[];
   if(!$alreadyLinked&&in_array($type,['appointment','artist','gema'],true)){
    $event=($registry??new Registry($this->db,$this->actor))->appointment($parts[1]);
    $club=Permissions::actor($this->db,$this->actor)['club_id'];
    if(($event['club_id']??'')!==$club)throw new DomainException('Termin gehört nicht zu diesem Verein.');
    $this->db->prepare('INSERT INTO finance_event_assignments(club_id,entry_id,event_id,purpose,event_title,starts_at,location,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$club,$entry,$parts[1],$type,$event['title'],$event['starts_at'],$event['location']??'',$this->actor,gmdate('c')]);
    DocumentCatalog::register($this->db,$club,'appointment',$parts[1]);
    $extra[]=['type'=>'appointment','id'=>$parts[1]];
   }
   foreach($items as $id=>$itemVersion)$extra[]=['type'=>'item','id'=>$id,'position'=>$itemNotes[$id]];
   $documents->linkExpense($document,$version,$entry,0,$extra);
   if($alreadyLinked){InvoiceInventoryCreate::assertReplay($this->db,$this->actor,$entry,$newItems,$review);return;}
   if($review)InvoicePositionReview::save($this->db,$this->actor,$entry,$review);
   if($newItems)InvoiceInventoryCreate::createForEntry($this->db,$this->actor,$entry,$newItems);
   if($supplier!=='')(new InvoiceSupplierContacts($this->db,$this->actor))->link($entry,$supplier,$supplierVersion);
   if($type==='gema')(new EventGema($this->db,$this->actor,$registry??new Registry($this->db,$this->actor)))->linkInvoice($parts[1],(int)$parts[2],$entry);
  },function(string$entry)use($document,$newItems,$review,$finance):void{
   $identity=Permissions::actor($this->db,$this->actor);(new Documents($this->db,$this->actor))->document($document,true);
   $q=$this->db->prepare("SELECT document_id FROM document_links WHERE club_id=? AND document_id=? AND source_type='entry' AND source_id=? FOR SHARE");$q->execute([$identity['club_id'],$document,$entry]);
   if(!$q->fetchColumn()||$finance->entry($entry)['kind']!=='expense')throw new DomainException('Diese ursprüngliche Vorgangskennung gehört nicht zu einem Beleg dieser Originalrechnung.',409);
   InvoiceInventoryCreate::assertReplay($this->db,$this->actor,$entry,$newItems,$review);
  });
 }
 public function eventAssignment(string $entry):?array
 {
  $e=(new Finance($this->db,$this->actor))->entry($entry);
  $q=$this->db->prepare('SELECT * FROM finance_event_assignments WHERE club_id=? AND entry_id=?');$q->execute([$e['club_id'],$entry]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
 }
 public function archive(string $id,int $version,bool $archive,string $reason):void
 {
  if(!(new Finance($this->db,$this->actor))->canWrite())throw new DomainException('Finanzschreibrecht erforderlich.');
  $doc=$this->document($id);if($doc['entry_links'])throw new DomainException('Zugeordnete Originale können im Rechnungseingang nicht ausgeblendet werden.');
  (new Documents($this->db,$this->actor))->update($id,$version,['title'=>$doc['title'],'kind'=>$doc['kind'],'document_date'=>$doc['document_date'],'folder'=>$doc['folder_id'],'notes'=>$doc['notes'],'links'=>array_map(fn($l)=>$l['source_type'].':'.$l['source_id'],$doc['links']),'state'=>$archive?'archived':'active','reason'=>$reason],static function(array $locked){if(array_filter($locked['links'],fn($link)=>$link['source_type']==='entry'))throw new DomainException('Die Datei wurde inzwischen einem Finanzbeleg zugeordnet.');});
 }
}
