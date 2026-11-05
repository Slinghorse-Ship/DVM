<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Specific recognition rules; a donation promise is never treated as an invoice. */
trait BalanceNonInvoiceSources
{
    /** Shared by preview, POST and retained forms. Empty options preserve existing source hashes. */
    public static function formOptions(string $type,array $input):array
    {
        $keys=$type==='invoice'?['recognised_on','private_paid_on']:($type==='transaction'?['donation_use']:[]);
        $out=[];
        foreach($keys as $key){
            $value=$input[$key]??'';
            if(!is_string($value))throw new DomainException('Angaben zum Beleg prüfen.');
            if($type==='invoice'||$value!=='')$out[$key]=$value;
        }
        if($type==='invoice'){
            $classification=BalanceInvoiceClassification::form($input);
            if($classification!==null)$out['invoice_classification']=$classification;
        }
        return $out;
    }

    /** A genuine annual assessment is itself evidence, not a missing supplier PDF. */
    private function contributionProposal(array &$p,string $club,?array $period=null):void
    {
        $s=$p['snapshot'];$e=$s['entry'];
        if($period!==null)self::date($p['options']['recognised_on'],$p['year']);
        if($p['options']['private_paid_on']!=='')throw new DomainException('Ein Mitgliedsbeitrag ist keine private Vorleistung.');
        $raw=$this->rows('SELECT notes,request_key FROM finance_entries WHERE club_id=? AND id=?',[$club,$p['id']])[0];
        $assessment=json_decode($raw['notes'],true,64,JSON_THROW_ON_ERROR);
        $rate=is_array($assessment)?($assessment['contribution_rate']??null):null;
        if(!is_array($assessment)||($assessment['year']??null)!==$p['year']||!is_array($rate)
            ||!is_string($rate['id']??null)||$rate['id']===''||!is_int($rate['version']??null)||$rate['version']<1
            ||($rate['amount_cents']??null)!==(int)$e['amount_cents']||!is_string($assessment['payer']??null)
            ||!array_key_exists('family',$assessment)||($assessment['family']!==null&&(!is_string($assessment['family'])||$assessment['family']===''))
            ||!is_array($assessment['covered_contacts']??null)||!array_is_list($assessment['covered_contacts'])||!$assessment['covered_contacts'])
            throw new DomainException('Gespeicherte Jahresforderung, Beitragsjahr und damaligen Beitragssatz prüfen.');
        $people=$assessment['covered_contacts'];
        foreach($people as $person)if(!is_string($person)||$person==='')throw new DomainException('Personen der Beitragsforderung prüfen.');
        sort($people,SORT_STRING);
        $rows=$this->rows('SELECT contact_id,year FROM member_dues_people WHERE club_id=? AND entry_id=? ORDER BY contact_id,year',[$club,$p['id']]);
        if(count(array_unique($people))!==count($people)||array_column($rows,'contact_id')!==$people
            ||array_filter($rows,fn($r)=>(int)$r['year']!==$p['year']))throw new DomainException('Jahresforderung und zugeordnete Mitglieder stimmen nicht überein.');
        $payer=$this->rows('SELECT id FROM contacts WHERE club_id=? AND id=?',[$club,$assessment['payer']]);
        $origin=$assessment['family']===null?'person:'.$assessment['payer']:'family:'.$assessment['family'];
        if(!$payer||($assessment['family']===null&&$people!==[$assessment['payer']])
            ||$raw['request_key']!==substr(hash('sha256','annual-dues:'.$club.':'.$origin.':'.$p['year']),0,32))
            throw new DomainException('Ursprung der Jahresforderung ist nicht eindeutig belegt.');
        $revenue=$this->untaxedRevenue($p,$s,'40000',$period);
        // Historical tariff, not today's possibly changed rate. Keep the original assessment in the journal proof.
        $p['snapshot']['assessment']=$assessment;$p['snapshot']['covered_members']=$rows;
        $receivable=self::account($p,self::accountId('recv',$p['id']),'Forderung '.$e['display_number'],'receivables');
        $p['snapshot']['settlement_account']=$receivable;$p['snapshot']['asset_accounts']=[];
        self::voucher($p,$p['options']['recognised_on'],'Mitgliedsbeitrag '.$e['display_number'].' · '.$p['year'],[
            self::line($receivable,(int)$e['amount_cents'],true),self::line($revenue,(int)$e['amount_cents'],false,'1')]);
    }

    /** Only an unambiguous, untaxed single purpose can be derived automatically. */
    private function untaxedRevenue(array &$p,array $s,string $code,?array $period=null):string
    {
        if($s['entry']['cancelled']||$s['claim']||$s['assets']||count($s['positions'])!==1)throw new DomainException('Belegart, Erstattung und Aufteilung gesondert prüfen.');
        $pos=$s['positions'][0];$catalog=TaxCatalog::accounts($p['year']);$account=$catalog[$code]??null;
        if(!$account||!$account['active']||$account['category']!=='revenue')throw new DomainException('Geprüftes SKR42-Konto für das gewählte Jahr fehlt.');
        if((int)$pos['gross_cents']!==(int)$s['entry']['amount_cents']||(int)$pos['gross_cents']<=0
            ||(int)$pos['tax_rate']!==0||(int)$pos['tax_cents']!==0||$pos['booking_key']!==''||$pos['input_tax']==='deductible'
            ||!in_array(TaxCatalog::sphereOf((string)$pos['sphere']),['','1'],true)||($pos['sphere']!==''&&TaxCatalog::sphereOf((string)$pos['sphere'])==='')||!in_array($pos['account_code'],['',$code],true)
            ||($pos['account_code']!==''&&($pos['account_category']!=='revenue'||(int)$pos['catalog_year']!==$p['year'])))
            throw new DomainException('Die gespeicherte Zuordnung passt nicht zu einem echten Mitgliedsbeitrag bzw. einer freien Geldspende. Sonderfall prüfen.');
        $p['snapshot']['automatic_revenue_reference']=TaxCatalog::sourceStatus($p['year']);
        $name=$account['name'];
        if($period!==null){
            $target=TaxCatalog::accounts((int)substr($period['end'],0,4))[$code]??null;
            // Verified 2025/2026 editions differ only in spaces around this slash.
            // Keep a stable display name; neither original catalog is rewritten.
            $giftNames=['Erträge aus Spenden / Zuwendungen','Erträge aus Spenden/Zuwendungen'];
            $sameName=$target&&($target['name']===$name||($code==='40400'&&in_array($name,$giftNames,true)&&in_array($target['name'],$giftNames,true)));
            if(!$target||!$target['active']||!$sameName||array_intersect_key($target,array_flip(['category','function']))!==array_intersect_key($account,array_flip(['category','function'])))throw new DomainException('Das Beitrags- oder Spendenkonto hat sich zum Abschlussjahr geändert. Kontenüberleitung prüfen.');
            if($code==='40400'&&in_array($name,$giftNames,true))$name=$giftNames[0];
            $p['snapshot']['period_catalog'][$code]=$target;
        }
        return self::account($p,'src_code_'.$code,'SKR42 '.$code.' · '.$name,'revenue',$code);
    }

    private function donationPayment(array &$p,string $club,array $tx,array $allocation,array &$lines,?array $period=null):void
    {
        $s=$this->invoice($club,$allocation['entry_id']);
        // Save facts even for an incomplete preview so the form can ask the relevant factual question.
        $p['snapshot']['donations'][$allocation['entry_id']]=$s;
        if(($p['options']['donation_use']??'')!=='unrestricted')throw new DomainException('Dürfen die zugeordneten Geldspenden frei für die Satzungszwecke verwendet werden? Zweckbindung oder Gegenleistung zuerst klären.');
        if((((int)$tx['cents']<0)!==((int)$allocation['cents']<0))||$s['entry']['kind']!=='donation')throw new DomainException('Nur tatsächlich eingegangene Geldspenden übernehmen. Erstattungen gesondert prüfen.');
        if($this->binding('invoice',$allocation['entry_id']))throw new DomainException('Zu dieser Spende besteht bereits eine Forderungsbuchung. Zuerst abstimmen.');
        if($period!==null&&$this->rows("SELECT period_id FROM balance_period_sources WHERE club_id=? AND source_type='invoice' AND source_id=?",[$club,$allocation['entry_id']]))throw new DomainException('Zu dieser Spende besteht bereits eine Zeitraum-Forderung. Zuerst abstimmen.');
        if(PaymentFunding::paid($this->db,$club,$allocation['entry_id'],(int)$s['entry']['amount_cents'],$this->lock)>(int)$s['entry']['amount_cents'])throw new DomainException('Zahlungszuordnung überschreitet den erfassten Spendenbetrag.');
        $account=$this->untaxedRevenue($p,$s,'40400',$period);
        $lines[]=self::line($account,abs((int)$allocation['cents']),(int)$allocation['cents']<0,'1');
    }

    /** A changed donation can affect several partial receipts, including earlier allocation links. */
    private function donationCorrectionTargets(string $club,array $root):array
    {
        $rows=$this->rows("SELECT source_id FROM balance_sources WHERE club_id=? AND source_type='transaction' ORDER BY source_id LIMIT 10001",[$club]);
        if(count($rows)>10000)throw new DomainException('Zu viele Zahlungen für eine gemeinsame Korrekturprüfung.');
        $all=[];$links=[];
        foreach($rows as $row){
            $id=$row['source_id'];$b=$this->binding('transaction',$id);$all[$id]=$b;
            $current=$this->rows("SELECT a.entry_id FROM finance_allocations a JOIN finance_entries e ON e.club_id=a.club_id AND e.id=a.entry_id WHERE a.club_id=? AND a.transaction_id=? AND a.voided_at IS NULL AND e.kind='donation' ORDER BY a.entry_id",[$club,$id]);
            $links[$id]=array_unique(array_merge(array_keys($b['snapshot']['donations']??[]),array_column($current,'entry_id')));
        }
        $selected=[$root['source_id']=>$root];$donations=$links[$root['source_id']]??[];
        do{
            $added=false;
            foreach($all as $id=>$b)if(!isset($selected[$id])&&array_intersect($donations,$links[$id])){
                $selected[$id]=$b;$donations=array_unique(array_merge($donations,$links[$id]));$added=true;
                if(count($selected)>100)throw new DomainException('Mehr als 100 zusammenhängende Spendenzahlungen. Korrektur gesondert abstimmen.');
            }
        }while($added);
        return array_values($selected);
    }
}
