<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

final class DocumentRecords
{
    public function __construct(private PDO $db,private string $actorId) {}
    private static function money(int $v):string{return number_format($v/100,2,',','.').' EUR';}
    public function build(string $type,string $id):array
    {
        $this->db->exec('BEGIN');try{$result=$this->snapshot($type,$id);$this->db->commit();return $result;}catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function snapshot(string $type,string $id):array
    {
        $store=new Documents($this->db,$this->actorId);$source=$store->source($type,$id);$a=Permissions::actor($this->db,$this->actorId);$r=DocumentCatalog::raw($this->db,$a['club_id'],$type,$id);
        $club=DocumentCatalog::query($this->db,'SELECT name FROM clubs WHERE id=?',[$a['club_id']])->fetchColumn();$sections=[];$warning='Gespeicherter Stand des Vorgangs zum angegebenen Erstellungszeitpunkt.';$title='Interner Vorgangsnachweis';$financial=new Finance($this->db,$this->actorId);
        $table=static fn(string $title,array $columns,array $rows)=>['title'=>$title,'columns'=>$columns,'rows'=>$rows];
        if($type==='entry'||$type==='goods'){
            if($type==='entry'){$r=$financial->entry($id);$title='Interner Belegnachweis';$warning='Interner gespeicherter Vorgangsnachweis. Kein neu ausgestellter Rechnungsbeleg.';$fields=['Nummer'=>$r['display_number']?:sprintf('KH-%05d',$r['number']),'Art'=>Finance::KINDS[$r['kind']],'Bezeichnung'=>$r['description'],'Name'=>$r['counterparty'],'Belegdatum'=>$r['document_date'],'Fälligkeit'=>$r['due_date'],'Zahlungsstatus'=>['open'=>'Offen','partial'=>'Teilbezahlt','paid'=>'Bezahlt','cancelled'=>'Verworfen','reversed'=>'Gegengebucht'][$r['payment_state']],'Gesamtbetrag'=>self::money((int)$r['amount_cents']),'Zugeordnet'=>self::money((int)$r['allocated_cents']),'Noch offen'=>self::money((int)$r['remaining_cents']),'Notiz'=>$r['notes']];}
            else{$r=$financial->goodsEntry($id);$title='Sachspenden-Nachweis';$fields=['Zuwender'=>$r['donor'],'Bezeichnung'=>$r['designation'],'Beschreibung'=>$r['description'],'Spendendatum'=>$r['received_on'],'Dokumentierter Wert'=>self::money((int)$r['value_cents']),'Wertermittlung'=>$r['valuation'],'Ursprung'=>['private'=>'Privatvermögen','business'=>'Betriebsvermögen','unknown'=>'Nicht angegeben'][$r['origin']],'Unterlagenvermerk'=>$r['evidence_present']?'Als vorhanden angegeben':'Nicht bestätigt'];}
            if($type==='goods'||($r['kind']??'')==='donation')$warning='Interner Spendenvorgang. KEINE Zuwendungsbescheinigung.';
            $sections[]=$table('Vorgang',['Angabe','Wert'],array_map(fn($k,$v)=>[$k,(string)$v],array_keys($fields),array_values($fields)));
            $positions=$financial->positions($type,$id);$sections[]=$table('Positionen und Kontierung',['Position','Brutto','Steuer','Sphäre / Konto'],array_map(fn($p)=>[$p['description'],self::money((int)$p['gross_cents']),$p['tax_rate'].' %',($p['account_code']!==''?$p['sphere'].' / '.$p['account_code']:'Noch nicht kontiert')."\n".$p['account_name']],$positions));$r['positions']=$positions;
        }elseif($type==='loan'){
            $r=(new Inventory($this->db,$this->actorId))->loan($id);$title='Ausleihprotokoll';$warning='Dokumentierter Ausleihstand. Eine Unterschrift wurde nicht digital erfasst.';
            $fields=['Ausleihe'=>Inventory::number((int)$r['number']),'Entleiher'=>$r['contact_name'],'Verwendungszweck'=>$r['purpose'],'Status'=>Inventory::STATES[$r['state']],'Beginn'=>$r['starts_on'],'Rückgabefrist'=>$r['due_on'],'Ausgegeben am'=>$r['issued_on']??'','Aufenthaltsort'=>$r['current_place'],'Vereinbarung'=>$r['notes']];
            $sections[]=$table('Ausleihe',['Angabe','Wert'],array_map(fn($k,$v)=>[$k,(string)$v],array_keys($fields),array_values($fields)));
            $sections[]=$table('Gegenstände',['Gegenstand / Ausgangsort','Vereinbart','Zurück','Verlust','Offen'],array_map(fn($p)=>[$p['item_code'].' · '.$p['item_name']."\n".$p['location_name'],$p['quantity'].' '.$p['unit'],(string)$p['returned'],(string)$p['lost'],(string)($r['state']==='cancelled'?0:$p['quantity']-$p['returned']-$p['lost'])],$r['lines']));
            $history=[];foreach($r['history'] as $h){$d=json_decode($h['details'],true);$d=$d[1]??$d;$history[]=[substr($h['happened_at'],0,19).' UTC',Inventory::ACTIONS[$h['action']]??$h['action'],$d['reason']??$d['purpose']??'',$h['actor_id']];}$sections[]=$table('Übergaben und Verlauf',['Zeitpunkt','Vorgang','Vermerk','Bearbeiter'],$history);
        }elseif($type==='contact'){
            $registry=new Registry($this->db,$this->actorId);$r=$registry->contact($id);$title='Mitglieder- und Kontaktstand';$fields=['Name'=>$r['name'],'E-Mail'=>$r['email'],'Anschrift'=>$r['address'],'Telefon'=>$r['phone'],'Geburtstag'=>$r['birthday']??'','Notizen'=>$r['notes']];$sections[]=$table('Kontaktdaten',['Angabe','Wert'],array_map(fn($k,$v)=>[$k,(string)$v],array_keys($fields),array_values($fields)));
            $memberships=$registry->memberships($id);$r['memberships']=$memberships;$sections[]=$table('Mitgliedschaften',['Nummer','Status','Beginn','Ende'],array_map(fn($m)=>[$m['member_number'],$m['status'],$m['starts_on'],$m['ends_on']??''],$memberships));
        }elseif($type==='item'){
            $inv=new Inventory($this->db,$this->actorId);$r=$inv->item($id);$places=$inv->whereabouts(['item'=>$id]);$r['places']=$places;$title='Inventarnachweis';$fields=['Inventarnummer'=>$r['code'],'Gegenstand'=>$r['name'],'Kategorie'=>$r['category'],'Eigentum'=>$r['ownership']==='club'?'Vereinseigentum':'Fremde Leihgabe','Zustand'=>$r['condition_note'],'Beschreibung'=>$r['notes']];$sections[]=$table('Gegenstand',['Angabe','Wert'],array_map(fn($k,$v)=>[$k,(string)$v],array_keys($fields),array_values($fields)));
            $sections[]=$table('Aufbewahrung',['Menge','Ort','Aufbewahrer','Status'],array_map(fn($p)=>[$p['quantity'].' '.$p['unit'],$p['location_name'],$p['holder_name']??'',['available'=>'Verfügbar','reserved'=>'Reserviert','issued'=>'Ausgegeben','blocked'=>'Gesperrt'][$p['state']]],$places));
            // Financial values intentionally belong to their financial source documents.
            unset($r['unit_value_cents'],$r['value_note'],$r['source_entry'],$r['source_goods']);
        }elseif($type==='account'){
            $r=$financial->account($id);$accounts=$financial->accounts();foreach($accounts as $account)if($account['id']===$id)$r=$account;$title='Kontostand-Nachweis';$fields=['Konto / Kasse'=>$r['name'],'Art'=>['cash'=>'Barkasse','offline'=>'Offlinekonto'][$r['kind']],'Beginn'=>$r['opened_on'],'Anfangsbestand'=>self::money((int)$r['opening_cents']),'Aktueller dokumentierter Bestand'=>self::money((int)$r['balance_cents'])];$sections[]=$table('Kontostand',['Angabe','Wert'],array_map(fn($k,$v)=>[$k,(string)$v],array_keys($fields),array_values($fields)));
        }elseif($type==='application'){
            $r=(new MembershipApplications($this->db,$a['club_id'],$this->actorId))->application($id);$title='Mitgliedsantrag';$answers=json_decode($r['answers'],true);$rows=[];foreach($answers as $k=>$v)$rows[]=[MembershipApplications::FIELDS[$k]??($k==='payment'?'Zahlungsart':$k),$k==='payment'?(['invoice'=>'Rechnung','sepa'=>'Lastschrift'][$v]??(string)$v):(string)$v];$sections[]=$table('Übermittelte Angaben',['Feld','Angabe'],$rows);$consent=json_decode($r['consent_snapshot'],true);$sections[]=$table('Gespeicherter Antrag und Hinweise',['Angabe','Wert'],[['Eingang',$r['submitted_at']],['Status',['pending'=>'Zur Prüfung','accepted'=>'Angenommen','rejected'=>'Abgelehnt'][$r['status']]],['Hinweis',$consent['title']??''],['Hinweistext',$consent['text']??''],['Zustimmung',!empty($consent['accepted'])?'Ja':'Nein'],['Prüfvermerk',$r['review_note']]]);
        }else{$title='Terminnachweis';$fields=['Titel'=>$r['title'],'Beginn'=>$r['starts_at'],'Ende'=>$r['ends_at'],'Ort'=>$r['location'],'Beschreibung'=>$r['description']];$sections[]=$table('Termin',['Angabe','Wert'],array_map(fn($k,$v)=>[$k,(string)$v],array_keys($fields),array_values($fields)));}
        return ['source'=>$source,'snapshot'=>$r,'record'=>['title'=>$title,'club'=>(string)$club,'reference'=>$source['label'],'date'=>$source['document_date'],'created_at'=>gmdate('Y-m-d H:i:s').' UTC','warning'=>$warning,'sections'=>$sections]];
    }
    public function generate(string $type,string $id,string $key):string
    {
        $doc=$this->build($type,$id);$pdf=DocumentPreview::pdf($doc);$s=$doc['source'];$name=$doc['record']['title'].'-'.$s['document_date'].'-'.substr($id,0,8).'.pdf';
        $title=implode('',array_slice(preg_split('//u',$doc['record']['title'].' · '.$s['label'],-1,PREG_SPLIT_NO_EMPTY),0,70));
        return (new Documents($this->db,$this->actorId))->generated($name,$pdf,['title'=>$title,'kind'=>'record','document_date'=>$s['document_date'],'links'=>[$type.':'.$id]],$key,['type'=>$type,'id'=>$id,'data'=>$doc['snapshot']]);
    }
}
