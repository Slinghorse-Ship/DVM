<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Accept only the frozen original of an actually issued invoice, never arbitrary generated PDFs. */
trait BalanceIssuedInvoices
{
    public function issuedEvidence(string $entry,string $date,bool $lock=false):array
    {
        $a=$this->actor();
        if($lock&&!$this->db->inTransaction())throw new DomainException('Quellensperre benötigt einen Speichervorgang.');
        $previous=$this->lock;$this->lock=$lock;
        try{
            $p=['snapshot'=>$this->invoice($a['club_id'],$entry),'options'=>['recognised_on'=>$date]];
            if($p['snapshot']['entry']['cancelled']||!$this->issuedOriginal($p,$a['club_id']))throw new DomainException('Aktive ausgegebene Verkaufsrechnung auswählen.');
            return $p['snapshot'];
        }finally{$this->lock=$previous;}
    }
    private function issuedOriginal(array &$proposal,string $club):?string
    {
        $snapshot=$proposal['snapshot'];$entry=$snapshot['entry'];
        $issued=$this->rows('SELECT id,kind,parent_id,state,entry_id,document_id,number,payload FROM outgoing_invoices WHERE club_id=? AND entry_id=? ORDER BY id',[$club,$entry['id']]);
        if(!$issued)return null; // Preserve existing uploaded-source hashes.
        if(count($issued)!==1)throw new DomainException('Ausgangsrechnung ist nicht eindeutig zugeordnet.');
        $invoice=$issued[0];
        if($entry['kind']!=='income'||$invoice['kind']!=='invoice'||$invoice['parent_id']!==null||$invoice['state']!=='issued')
            throw new DomainException('Nur ausgegebene Originalrechnungen übernehmen. Stornos und Gutschriften gesondert abstimmen.');
        if($this->rows("SELECT id FROM outgoing_invoices WHERE club_id=? AND parent_id=? AND state IN ('issued','cancelled')",[$club,$invoice['id']]))
            throw new DomainException('Zur Ausgangsrechnung gibt es eine Korrektur. Original und Gutschrift gemeinsam abstimmen.');
        $data=json_decode($invoice['payload'],true,64,JSON_THROW_ON_ERROR);
        $history=$this->rows("SELECT snapshot FROM outgoing_invoice_history WHERE club_id=? AND invoice_id=? AND action='issued' ORDER BY version",[$club,$invoice['id']]);
        if(count($history)!==1||json_decode($history[0]['snapshot'],true,64,JSON_THROW_ON_ERROR)!==$data)
            throw new DomainException('Ausgangsrechnung und gespeicherter Ausgabestand stimmen nicht überein.');
        if(!is_array($data)||!is_int($data['total_cents']??null)||$data['total_cents']!==(int)$entry['amount_cents']
            ||($data['document_date']??null)!==$entry['document_date']||$invoice['number']!==$entry['display_number']
            ||!is_string($data['delivery_date']??null)||!TaxCatalog::documentYear($data['delivery_date'])
            ||!is_array($data['positions']??null)||!array_is_list($data['positions'])||count($data['positions'])!==count($snapshot['positions']))
            throw new DomainException('Ausgegebene Rechnung und Buchungsdaten stimmen nicht überein.');
        if($proposal['options']['recognised_on']!==$data['delivery_date'])
            throw new DomainException('Leistungsdatum der ausgegebenen Rechnung verwenden: '.$data['delivery_date'].'.');
        foreach($snapshot['positions'] as $index=>$position){
            $original=$data['positions'][$index];
            if(!is_array($original)||(int)$position['ordinal']!==$index+1||($original['description']??null)!==$position['description']
                ||!is_int($original['gross']??null)||$original['gross']!==(int)$position['gross_cents']
                ||!is_int($original['tax']??null)||$original['tax']!==(int)$position['tax_cents']
                ||!in_array($original['rate']??null,['0','7','19'],true)||(int)$original['rate']!==(int)$position['tax_rate'])
                throw new DomainException('Positionen weichen von der ausgegebenen Rechnung ab. Originalstand prüfen.');
        }
        $originals=array_values(array_filter($snapshot['documents'],static fn($d)=>$d['id']===$invoice['document_id']));
        $document=$originals[0]??null;
        if(count($originals)!==1||$document['state']!=='active'||$document['kind']!=='invoice'||$document['origin']!=='generated'
            ||$document['mime']!=='application/pdf'||(int)$document['revision']!==1)
            throw new DomainException('Unverändertes Original-PDF der ausgegebenen Rechnung fehlt.');
        // Verify the bytes as well as the metadata. Generated previews are not issuance evidence.
        $file=(new Documents($this->db,$this->actorId))->file($document['id'],1);
        if(!str_starts_with($file['bytes'],'%PDF-')||!hash_equals($document['sha256'],hash('sha256',$file['bytes'])))
            throw new DomainException('Original-PDF der ausgegebenen Rechnung ist beschädigt.');
        $rendered=json_decode($file['snapshot'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($rendered)||($rendered['draft']??null)!==false||($rendered['general_invoice']??null)!==true
            ||($rendered['payment_reference']??null)!==$invoice['number'])
            throw new DomainException('Ausgabe des Original-PDF ist nicht nachgewiesen.');
        $invoice['payload']=$data;$proposal['snapshot']['issued_invoice']=$invoice;
        return $document['id'];
    }
}
