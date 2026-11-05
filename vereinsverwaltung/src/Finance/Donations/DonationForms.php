<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Uses the unchanged official originals. Output remains an unsigned, marked draft. */
final class DonationForms
{
    public function __construct(private PDO $db,private string $actorId){}
    private function actor(bool $write=false):array
    {
        $a=Permissions::actor($this->db,$this->actorId);
        if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');
        return $a;
    }
    /** The invoice recipient is fixed; a shared email or another covered family member is not the payer. */
    public function contributionPayer(string $id):?array
    {
        $a=$this->actor();$entry=(new Finance($this->db,$this->actorId))->entry($id);if($entry['kind']!=='contribution')return null;
        $payer=DocumentCatalog::query($this->db,'SELECT recipient_contact_id FROM contribution_invoices WHERE club_id=? AND entry_id=?',[$a['club_id'],$id])->fetchColumn();
        if(!$payer&&DocumentCatalog::query($this->db,'SELECT 1 FROM member_dues_people WHERE club_id=? AND entry_id=?',[$a['club_id'],$id])->fetchColumn()){
            $details=json_decode($entry['notes'],true);$payer=is_array($details)&&is_string($details['payer']??null)?$details['payer']:null;
        }
        return $payer?(DocumentCatalog::raw($this->db,$a['club_id'],'contact',$payer)?:null):null;
    }
    private function source(string $kind,string $id,string $payment=''):?array
    {
        $this->actor();if(!in_array($kind,['money','goods'],true))throw new DomainException('Geld- oder Sachzuwendung auswählen.');
        if($id==='')return null;$finance=new Finance($this->db,$this->actorId);
        $current=$this->db->inTransaction();$r=$kind==='goods'?($current?$finance->currentPositionState('goods',$id)['source']:$finance->goodsEntry($id)):($current?$finance->currentEntry($id):$finance->entry($id));
        if($kind==='money'&&(!in_array($r['kind'],['donation','contribution'],true)||$r['status']==='cancelled'||!empty($r['reversed_on'])))throw new DomainException('Eine aktive Spende oder einen Beitrag auswählen.');
        if($kind==='money'){
            $allocations=array_values(array_filter($finance->allocations($id,false,$current),fn($x)=>!$x['voided_at']&&!$x['reversal_of']&&!$x['reversed_on']&&($payment===''||$x['id']===$payment)));
            if(PaymentFundingLedger::hasBasis($this->db,$r['club_id'],$id,$current)){
                $ledger=PaymentFundingLedger::entry($this->db,$r['club_id'],$id,(int)$r['amount_cents'],$current);
                foreach($allocations as&$allocation)$allocation['cents']=$ledger['allocations'][$allocation['id']]['remaining_cents']??0;unset($allocation);
                $allocations=array_values(array_filter($allocations,static fn(array$row):bool=>(int)$row['cents']>0));
            }
            if($payment!==''&&!$allocations)throw new DomainException('Zahlungszuordnung nicht mehr verfügbar.');
            $dates=array_unique(array_column($allocations,'booked_on'));$r['payment_amount']=array_sum(array_column($allocations,'cents'));$r['payment_date']=count($dates)===1?reset($dates):'';$r['payment_ids']=array_column($allocations,'id');
        }
        if($kind==='goods'||$r['kind']==='donation'){$person=$finance->donationContact($kind==='goods'?'goods':'entry',$id);if($person){$r['linked_donor']=$person['name']."\n".$person['address'];$r['linked_donor_id']=$person['id'];}}
        elseif($r['kind']==='contribution'&&($person=$this->contributionPayer($id))){$address=VolunteerForm::postalAddress($person['address']);$r['linked_donor']=implode("\n",array_filter([$person['name'],$address['street'],$address['city']],fn($v)=>$v!==''));}
        return $r;
    }
    /** Search existing evidence only. A selection never creates a donation, payment or inventory item. */
    public function sourceChoices(string $kind,string $search=''):array
    {
        $a=$this->actor();if(!in_array($kind,['money','goods'],true))throw new DomainException('Geld- oder Sachzuwendung auswählen.');
        $search=self::text(['search'=>$search],'search',150);$params=[$a['club_id']];
        if($kind==='money'){
            $deductible=((new Settings($this->db,$this->actorId))->section('tax')['data']['membership_deductible']??'')==='yes';
            $payer="EXISTS(SELECT 1 FROM contribution_invoices ci JOIN contacts cp ON cp.club_id=ci.club_id AND cp.id=ci.recipient_contact_id WHERE ci.club_id=e.club_id AND ci.entry_id=e.id) OR EXISTS(SELECT 1 FROM member_dues_people md JOIN contacts cp ON cp.club_id=md.club_id AND cp.id=CASE WHEN json_valid(e.notes) THEN json_extract(e.notes,'$.payer') END WHERE md.club_id=e.club_id AND md.entry_id=e.id AND NOT EXISTS(SELECT 1 FROM contribution_invoices ci WHERE ci.club_id=e.club_id AND ci.entry_id=e.id AND ci.recipient_contact_id IS NOT NULL))";
            $kinds=$deductible?"(e.kind='donation' OR (e.kind='contribution' AND ($payer)))":"e.kind='donation'";
            $sql="SELECT e.id source_id,l.id payment_id,e.display_number number,e.description label,CASE WHEN e.kind='contribution' THEN COALESCE((SELECT cp.name FROM contribution_invoices ci JOIN contacts cp ON cp.club_id=ci.club_id AND cp.id=ci.recipient_contact_id WHERE ci.club_id=e.club_id AND ci.entry_id=e.id),(SELECT cp.name FROM contacts cp WHERE cp.club_id=e.club_id AND cp.id=CASE WHEN json_valid(e.notes) THEN json_extract(e.notes,'$.payer') END),e.counterparty) ELSE COALESCE(c.name,e.counterparty) END donor,".PaymentFunding::remainingSql($this->db,'l')." amount_cents,t.booked_on donated_on,a.name account_name,'' inventory,e.kind,claim.receipt_id FROM finance_allocations l JOIN finance_entries e ON e.club_id=l.club_id AND e.id=l.entry_id JOIN finance_transactions t ON t.club_id=l.club_id AND t.id=l.transaction_id JOIN finance_accounts a ON a.club_id=t.club_id AND a.id=t.account_id LEFT JOIN donation_contacts dc ON dc.club_id=e.club_id AND dc.source_type='entry' AND dc.source_id=e.id LEFT JOIN contacts c ON c.club_id=dc.club_id AND c.id=dc.contact_id LEFT JOIN donation_receipt_claims claim ON claim.club_id=l.club_id AND claim.source_type='payment' AND claim.source_id=l.id WHERE l.club_id=? AND $kinds AND e.status!='cancelled' AND l.voided_at IS NULL AND t.reversal_of IS NULL AND l.cents>0 AND t.cents>0 AND NOT EXISTS(SELECT 1 FROM finance_reversals er WHERE er.club_id=e.club_id AND er.entry_id=e.id) AND NOT EXISTS(SELECT 1 FROM finance_transactions tr WHERE tr.club_id=t.club_id AND tr.reversal_of=t.id)";
        }else{
            $sql="SELECT g.id source_id,'' payment_id,'' number,COALESCE(NULLIF(g.designation,''),g.description) label,COALESCE(c.name,g.donor) donor,g.value_cents amount_cents,g.received_on donated_on,'' account_name,COALESCE((SELECT GROUP_CONCAT(CONCAT(i.code,' ',i.name) ORDER BY i.code SEPARATOR ' · ') FROM inventory_items i WHERE i.club_id=g.club_id AND i.source_goods=g.id AND i.deleted_at IS NULL),'') inventory,'goods' kind,claim.receipt_id FROM goods_donations g LEFT JOIN donation_contacts dc ON dc.club_id=g.club_id AND dc.source_type='goods' AND dc.source_id=g.id LEFT JOIN contacts c ON c.club_id=dc.club_id AND c.id=dc.contact_id LEFT JOIN donation_receipt_claims claim ON claim.club_id=g.club_id AND claim.source_type='goods' AND claim.source_id=g.id WHERE g.club_id=? AND g.value_cents>0";
        }
        $where='amount_cents>0 AND donated_on<=?';$params[]=date('Y-m-d');
        // Explicit German folds keep user-entered search terms consistent with stored labels.
        $haystack="lower(replace(replace(replace(replace(CONCAT(number,' ',label,' ',donor,' ',inventory,' ',account_name,' ',donated_on,' ',substr(donated_on,9,2),'.',substr(donated_on,6,2),'.',substr(donated_on,1,4)),'Ä','ä'),'Ö','ö'),'Ü','ü'),'ẞ','ß'))";
        foreach(preg_split('/\s+/u',$search,-1,PREG_SPLIT_NO_EMPTY)?:[] as $term){$where.=' AND instr('.$haystack.',?)>0';$params[]=strtolower(strtr($term,['Ä'=>'ä','Ö'=>'ö','Ü'=>'ü','ẞ'=>'ß']));}
        $rows=DocumentCatalog::query($this->db,'SELECT * FROM ('.$sql.') AS source_choices WHERE '.$where.' ORDER BY donated_on DESC,source_id,payment_id LIMIT 9',$params)->fetchAll();
        $more=count($rows)>8;$rows=array_slice($rows,0,8);
        return ['rows'=>$rows,'more'=>$more];
    }
    public function sourceDetails(string $kind,string $id,string $payment=''):array
    {
        $a=$this->actor();$r=$this->source($kind,$id,$payment);if(!$r)return [];
        $ids=$kind==='goods'?[$id]:$r['payment_ids'];$claimed=[];
        foreach($ids as $claim){$receipt=DocumentCatalog::query($this->db,'SELECT receipt_id FROM donation_receipt_claims WHERE club_id=? AND source_type=? AND source_id=?',[$a['club_id'],$kind==='goods'?'goods':'payment',$claim])->fetchColumn();if($receipt)$claimed[]=$receipt;}
        return ['source'=>$r,'claimed'=>array_values(array_unique($claimed)),'inventory'=>$kind==='goods'?(new Inventory($this->db,$this->actorId))->linkedItems('goods',$id):[]];
    }
    public function defaults(string $kind,string $sourceId='',string $payment=''):array
    {
        $a=$this->actor();$r=$this->source($kind,$sourceId,$payment);
        $stored=DocumentCatalog::query($this->db,"SELECT payload FROM club_settings WHERE club_id=? AND section='club'",[$a['club_id']])->fetchColumn();
        $club=$stored?json_decode($stored,true,32,JSON_THROW_ON_ERROR):SettingsSchema::defaults('club');
        $tax=(new Settings($this->db,$this->actorId))->section('tax')['data'];
        $issuer=implode("\n",array_filter([$club['name']??'', $club['street']??'', $club['address2']??'',trim(($club['postcode']??'').' '.($club['city']??''))],fn($v)=>$v!==''));
        $values=['payment_id'=>$payment,'kind'=>$kind,'source_id'=>$sourceId,'source_version'=>(string)($r['version']??0),'issuer'=>$issuer,'donor'=>'','amount'=>'','donated_on'=>'','purpose'=>$tax['purpose']??'','tax_office'=>$tax['office']??'','tax_number'=>$tax['number']??'','notice_type'=>$tax['notice_type']??'','notice_date'=>($tax['notice_type']??'')==='assessment'?($tax['assessment_date']??''):($tax['exemption_date']??''),'assessment_period'=>$tax['assessment']??'','waiver'=>'','goods_description'=>'','goods_origin'=>'','goods_evidence'=>'0','signature_place_date'=>trim((string)($club['city']??''))!==''?trim($club['city']).', '.date('d.m.Y'):''];
        if($r){$values['donor']=$r['linked_donor']??($kind==='goods'?$r['donor']:$r['counterparty']);$values['amount']=self::amount((int)($kind==='goods'?$r['value_cents']:$r['payment_amount']));$values['donated_on']=$kind==='goods'?$r['received_on']:$r['payment_date'];
            if($kind==='goods'){$values['goods_description']=trim(($r['designation']??'')."\n".$r['description']);$values['goods_origin']=$r['origin'];$values['goods_evidence']=(string)$r['evidence_present'];}}
        return $values;
    }
    private static function amount(int $cents):string{return intdiv($cents,100).','.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
    private static function text(array $input,string $key,int $limit,bool $required=false):string
    {
        $s=$input[$key]??'';if(!is_string($s))throw new DomainException('Feld '.$key.' prüfen.');$s=trim(str_replace(["\r\n","\r"],"\n",$s));
        if(strlen($s)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$s)||($required&&$s===''))throw new DomainException('Feld '.$key.' ausfüllen bzw. kürzen.');return $s;
    }
    private static function date(string $date):string
    {
        if($date==='')return '';$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new DomainException('Datum prüfen; keine zukünftigen Daten verwenden.');return $d->format('d.m.Y');
    }
    public function build(array $input):array
    {
        $this->actor();$kind=self::text($input,'kind',10,true);$sourceId=self::text($input,'source_id',100);$payment=self::text($input,'payment_id',100);$r=$this->source($kind,$sourceId,$payment);
        if($r&&(string)$r['version']!==self::text($input,'source_version',20,true))throw new DomainException('Der zugrunde liegende Beleg wurde geändert. Formular neu öffnen.');
        if($kind==='money'&&($r['kind']??'')==='contribution'&&((new Settings($this->db,$this->actorId))->section('tax')['data']['membership_deductible']??'')!=='yes')throw new DomainException('Für diesen Mitgliedsbeitrag ist keine steuerliche Zuwendungsbestätigung freigegeben. Rechnung und Zahlungsstatus unter Finanzen öffnen.');
        $data=['kind'=>$kind];foreach(['issuer'=>500,'donor'=>500,'purpose'=>1000,'tax_office'=>120,'tax_number'=>60,'assessment_period'=>80,'goods_description'=>600,'signature_place_date'=>150] as $field=>$limit)$data[$field]=self::text($input,$field,$limit,in_array($field,['issuer','donor'],true));
        $cents=Finance::cents(self::text($input,'amount',20,true));if($cents<=0)throw new DomainException('Positiven Zuwendungsbetrag angeben.');$data['amount']=self::amount($cents);if($r&&$kind==='money'&&($r['payment_amount']<=0||$cents!==$r['payment_amount']))throw new DomainException('Der Betrag muss den ausgewählten, tatsächlich zugeordneten Zahlungen entsprechen.');
        $digits=['null','eins','zwei','drei','vier','fünf','sechs','sieben','acht','neun'];$spell=static fn(string $v)=>implode(' - ',array_map(static fn($d)=>$digits[(int)$d],str_split($v)));
        $data['amount_words']='X '.$spell((string)intdiv($cents,100)).' Euro '.$spell(str_pad((string)($cents%100),2,'0',STR_PAD_LEFT)).' Cent X';
        $date=self::text($input,'donated_on',10,true);$data['donated_on']=self::date($date);if($r&&$kind==='money'&&($r['payment_date']===''||$date!==$r['payment_date']))throw new DomainException('Die Zahlungen liegen an unterschiedlichen Tagen oder das Datum wurde geändert. Bitte eine einzelne Zahlung auswählen.');$data['notice_date']=self::date(self::text($input,'notice_date',10));
        foreach(['notice_type'=>['','exemption','corporate','assessment'],'waiver'=>['','yes','no'],'goods_origin'=>['','private','business','unknown']] as $key=>$choices){$v=self::text($input,$key,20);if(!in_array($v,$choices,true))throw new DomainException('Auswahl '.$key.' prüfen.');$data[$key]=$v;}
        $evidence=self::text($input,'goods_evidence',1);if(!in_array($evidence,['','0','1'],true))throw new DomainException('Unterlagenvermerk prüfen.');$data['goods_evidence']=$evidence==='1';if($r&&$kind==='goods'&&($cents!==(int)$r['value_cents']||$date!==$r['received_on']||$data['goods_origin']!==$r['origin']||$data['goods_evidence']!==(bool)$r['evidence_present']))throw new DomainException('Wert, Datum, Herkunft und Unterlagen müssen der erfassten Sachspende entsprechen.');if($kind==='goods'&&$data['goods_description']==='')throw new DomainException('Sachzuwendung genau beschreiben.');
        if($r&&isset($r['linked_donor'])&&$data['donor']!==$r['linked_donor'])throw new DomainException('Zuwender muss dem zugeordneten Spender entsprechen. Kontaktanschrift zuerst berichtigen.');
        $missing=[];foreach(['purpose'=>'Begünstigter Zweck','tax_office'=>'Finanzamt','tax_number'=>'Steuernummer','notice_type'=>'Bescheidart','notice_date'=>'Bescheiddatum'] as $key=>$label)if($data[$key]==='')$missing[]=$label;
        if($kind==='money'&&$data['waiver']==='')$missing[]='Aufwandsverzicht';if($kind==='goods'&&$data['goods_origin']==='')$missing[]='Herkunft der Sachzuwendung';
        $tax=(new Settings($this->db,$this->actorId))->section('tax')['data'];
        $notMembership=$kind==='money'&&($tax['membership_deductible']??'')==='no'&&$r&&$r['kind']==='donation';
        return ['donor_contact_id'=>$r['linked_donor_id']??null,'non_deductible_memberships'=>$notMembership,'payment_ids'=>$r['payment_ids']??[],'kind'=>'donation_form_draft','donation_form'=>$data,'source_id'=>$sourceId,'source_version'=>(int)($r['version']??0),'document_date'=>$date,'missing'=>$missing,'original_sha256'=>hash_file('sha256',dirname(__DIR__, 2).'/../resources/donation-forms/'.$kind.'-original.pdf')];
    }
    /** Called after an authorized write; never from GET. Generates unsigned drafts only. */
    public function prepareForSource(string $kind,string $sourceId):array
    {
        $actor=$this->actor(true);$finance=new Finance($this->db,$this->actorId);
        $source=$this->source($kind,$sourceId);
        if(!$source)return ['state'=>'blocked','documents'=>[],'reason'=>'Spendenbeleg fehlt.'];
        if($kind==='money'&&$source['kind']!=='donation')return ['state'=>'blocked','documents'=>[],'reason'=>'Nur echte Spenden werden automatisch vorbereitet.'];
        $person=$finance->donationContact($kind==='goods'?'goods':'entry',$sourceId);
        if(!$person)return ['state'=>'blocked','documents'=>[],'reason'=>'Tatsächlichen Spender als Kontakt zuordnen.'];
        $lines=array_values(array_filter(array_map('trim',explode("\n",$person['name']."\n".$person['address']))));
        if(count($lines)<3)return ['state'=>'blocked','documents'=>[],'reason'=>'Vollständige Anschrift des zugeordneten Spenders ergänzen.'];
        $payments=$kind==='money'?($source['payment_ids']??[]):[''];
        if(!$payments)return ['state'=>'blocked','documents'=>[],'reason'=>'Zahlungseingang zuerst zuordnen.'];
        $ids=[];$issued=0;
        foreach($payments as $payment){
            $claim=DocumentCatalog::query($this->db,'SELECT 1 FROM donation_receipt_claims WHERE club_id=? AND source_type=? AND source_id=?',[$actor['club_id'],$kind==='goods'?'goods':'payment',$kind==='goods'?$sourceId:$payment])->fetchColumn();
            if($claim){++$issued;continue;}
            $values=$this->defaults($kind,$sourceId,$payment);if($kind==='money')$values['waiver']='no';
            $snapshot=$this->build($values);$identity=$snapshot;unset($identity['donation_form']['signature_place_date'],$identity['source_version']);
            $key=substr(hash('sha256','automatic-donation-draft-v1|'.$actor['club_id'].'|'.$person['id'].'|'.json_encode($identity,JSON_THROW_ON_ERROR)),0,32);
            $previous=DocumentCatalog::query($this->db,'SELECT document_id FROM document_requests WHERE club_id=? AND request_key=?',[$actor['club_id'],$key])->fetchColumn();
            if($previous){$doc=(new Documents($this->db,$this->actorId))->document($previous);if($doc['state']==='active')$ids[]=$previous;continue;}
            try{$ids[]=$this->save($values,$key);}catch(DomainException $error){
                // A concurrent identical request may have completed while the PDF rendered.
                $previous=DocumentCatalog::query($this->db,'SELECT document_id FROM document_requests WHERE club_id=? AND request_key=?',[$actor['club_id'],$key])->fetchColumn();
                if(!$previous||$this->build($values)!==$snapshot)throw $error;(new Documents($this->db,$this->actorId))->document($previous);$ids[]=$previous;
            }
        }
        return ['state'=>$ids?'ready':($issued?'issued':'archived'),'documents'=>$ids,'issued'=>$issued,'reason'=>$ids?'Entwurf mit zugeordnetem Spender vorbereitet; noch nicht unterschrieben oder ausgestellt.':($issued?'Zuwendung bereits bescheinigt.':'Vorbereiteter Entwurf wurde archiviert.')];
    }
    public function save(array $input,string $key):string
    {
        $this->actor(true);$snapshot=$this->build($input);$bytes=DocumentPreview::pdf($snapshot);if($this->build($input)!==$snapshot)throw new DomainException('Quelldaten während der PDF-Erstellung geändert. Bitte erneut prüfen.');$links=$snapshot['source_id']!==''?[($snapshot['donation_form']['kind']==='goods'?'goods':'entry').':'.$snapshot['source_id']]:[];
        $prefix='Entwurf Zuwendungsbestätigung · ';$label=(string)strtok($snapshot['donation_form']['donor'],"\n");
        if(strlen($prefix.$label)>300){$limit=300-strlen($prefix)-strlen('…');while(strlen($label)>$limit)$label=(string)preg_replace('/.$/us','',$label);$label=rtrim($label).'…';}
        return (new Documents($this->db,$this->actorId))->generated('entwurf-zuwendungsbestaetigung.pdf',$bytes,['title'=>$prefix.$label,'kind'=>'donation','scope'=>'finance','document_date'=>$snapshot['document_date'],'links'=>$links,'notes'=>'Ausgefülltes amtliches Original. Unterschriftsloser Entwurf; nicht zur Vorlage beim Finanzamt.'],$key,$snapshot,function()use($input,$snapshot){if($this->build($input)!==$snapshot)throw new DomainException('Quelldaten während der Entwurfserstellung geändert. Bitte erneut prüfen.');});
    }
}
