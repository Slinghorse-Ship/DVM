<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;

/** Read-only projection of an issued sales credit: money out, but revenue/output VAT correction. */
final class DatevOutgoingCredit
{
    private string $club;
    private array $cache=[];
    private array $catalogs=[];
    public function __construct(private PDO $db,private string $actor)
    {
        $a=Permissions::actor($db,$actor);if(!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung erforderlich.');$this->club=$a['club_id'];
    }
    private function q(string $sql,array $args=[]):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
    private static function data(string $json):array
    {
        try{$data=json_decode($json,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new DomainException('Der ausgegebene Belegstand ist nicht lesbar.');}
        if(!is_array($data)||!is_array($data['positions']??null)||!array_is_list($data['positions'])||!$data['positions']||count($data['positions'])>50)throw new DomainException('Der ausgegebene Positionsstand ist unvollständig.');
        foreach($data['positions'] as $position)if(!is_array($position)||!is_int($position['gross']??null)||!is_int($position['tax']??null)||!is_string($position['description']??null)||$position['gross']<1||$position['gross']>100000000000||$position['tax']<0||$position['tax']>$position['gross'])throw new DomainException('Der ausgegebene Positionsstand ist unvollständig.');return $data;
    }
    private function context(string $entry):?array
    {
        if(array_key_exists($entry,$this->cache))return $this->cache[$entry];
        $credit=$this->q("SELECT * FROM outgoing_invoices WHERE club_id=? AND entry_id=? AND kind='credit'",[$this->club,$entry])->fetch();
        if(!$credit)return $this->cache[$entry]=null;
        $parent=$this->q('SELECT * FROM outgoing_invoices WHERE club_id=? AND id=?',[$this->club,$credit['parent_id']])->fetch();
        $positions=$this->q('SELECT * FROM accounting_positions WHERE club_id=? AND entry_id=? ORDER BY ordinal',[$this->club,$entry])->fetchAll();
        $original=$parent?$this->q('SELECT * FROM accounting_positions WHERE club_id=? AND entry_id=? ORDER BY ordinal',[$this->club,$parent['entry_id']])->fetchAll():[];
        $context=compact('credit','parent','positions','original');$context['proof_hash']=hash('sha256',json_encode($context,JSON_THROW_ON_ERROR));
        try{$context['data']=self::data($credit['payload']);$context['parent_data']=$parent?self::data($parent['payload']):null;}catch(DomainException $error){$context['error']=$error->getMessage();}
        return $this->cache[$entry]=$context;
    }
    private static function vat(int $gross,int $rate):int{return $rate===0?0:intdiv($gross*$rate+intdiv(100+$rate,2),100+$rate);}
    private static function matching(array $posted,array $frozen):bool
    {
        return isset($frozen['gross'],$frozen['tax'],$frozen['rate'],$frozen['description'])
            &&(int)$posted['gross_cents']===(int)$frozen['gross']&&(int)$posted['tax_cents']===(int)$frozen['tax']
            &&(int)$posted['tax_rate']===(int)$frozen['rate']&&$posted['description']===$frozen['description'];
    }
    /** Does not write a booking key or any financial record. Source accounting is explicit evidence. */
    public function project(array $row,array $catalog,array $keys):array
    {
        if(!$row['entry_id'])return ['row'=>$row,'issues'=>[]];$context=$this->context($row['entry_id']);if(!$context)return ['row'=>$row,'issues'=>[]];
        $c=$context['credit'];$p=$context['parent'];$row['outgoing_credit_id']=$c['id'];$row['original_entry_id']=$p['entry_id']??null;$row['original_invoice_number']=$p['number']??'';$row['export_tax_kind']='income';
        // Bind the preview to original invoice and correction, including subsequent reviewed accounting.
        $row['credit_source_hash']=$context['proof_hash'];
        try{
            if(isset($context['error']))throw new DomainException($context['error']);
            if($c['state']!=='issued'||!$p||$p['kind']!=='invoice'||$p['state']!=='issued'||!$c['document_id']||!$p['document_id'])throw new DomainException('Ausgegebene Gutschrift und zugehörige Originalrechnung sind erforderlich.');
            $cd=$context['data'];$pd=$context['parent_data'];$ordinal=$row['position_ordinal']??0;
            $position=$context['positions'][$ordinal-1]??null;$frozen=$cd['positions'][$ordinal-1]??null;
            if(!$position||!$frozen||$ordinal!==(int)$position['ordinal']||($row['position_id']??'')!==$row['entry_id'].':'.$ordinal||count($context['positions'])!==count($cd['positions']))throw new DomainException('Die Gutschriftposition ist nicht eindeutig mit dem ausgegebenen Beleg verknüpft.');
            $originalOrdinal=$frozen['original_ordinal']??null;if(!is_int($originalOrdinal)||$originalOrdinal<0)throw new DomainException('Die Verknüpfung zur Originalposition fehlt.');
            $original=$context['original'][$originalOrdinal]??null;$originalFrozen=$pd['positions'][$originalOrdinal]??null;
            if(!$original||!$originalFrozen||(int)$original['ordinal']!==$originalOrdinal+1||!self::matching($original,$originalFrozen)||!self::matching($position,$frozen))throw new DomainException('Positionsbeträge oder Umsatzsteuer weichen vom ausgegebenen Original-/Gutschriftbeleg ab. Rundung oder Belegstand fachlich prüfen.');
            $rate=(int)$position['tax_rate'];if(!in_array($rate,[0,7,19],true)||$rate!==(int)$original['tax_rate']||(int)$frozen['gross']>(int)$originalFrozen['gross']||(int)$frozen['tax']>(int)$originalFrozen['tax'])throw new DomainException('Unterstützt sind nur 0 %, 7 % oder 19 % mit unverändertem Steuersatz der Originalleistung.');
            if(self::vat((int)$frozen['gross'],$rate)!==(int)$frozen['tax'])throw new DomainException('Die Umsatzsteuer dieser Gutschrift benötigt eine gesonderte Rundungskorrektur; der DATEV-Automatismus würde einen anderen Betrag buchen.');
            $originalYear=TaxCatalog::documentYear($pd['document_date']??'');if(!TaxCatalog::hasYear($originalYear)||(int)$original['catalog_year']!==$originalYear||!TaxCatalog::hasYear(TaxCatalog::documentYear($cd['document_date']??'')))throw new DomainException('Original und Gutschrift benötigen die geprüfte Kontierung für 2025 oder 2026.');
            $code=$position['account_code'];$this->catalogs[$originalYear]??=(new Finance($this->db,$this->actor))->taxAccounts($originalYear);$sourceAccount=$this->catalogs[$originalYear][$original['account_code']]??[];
            if($code===''||$code!==$original['account_code']||$position['sphere']!==$original['sphere'])throw new DomainException('Erlöskonto und Sphäre der Gutschrift müssen zur geprüften Originalposition passen.');
            if(($catalog[$code]['category']??'')!=='revenue'||empty($catalog[$code]['active'])||empty($sourceAccount['active'])||($sourceAccount['category']??'')!=='revenue'||preg_match('/AM|AV/',$sourceAccount['function']??'')||preg_match('/AM|AV/',$catalog[$code]['function']??''))throw new DomainException('Ein aktives Erlöskonto ohne Steuerautomatik ist erforderlich. Anlagenverkäufe und Automatikkonten separat prüfen.');
            if(($position['booking_key']??'')!==''||($position['input_tax']??'')!=='none')throw new DomainException('Eine eigene Gutschrift mindert Erlös und Umsatzsteuer. Am Rückzahlungsbeleg keinen Vorsteuerschlüssel und keinen Vorsteuerabzug auswählen.');
            $key=(string)$original['booking_key'];
            if($rate>0){
                $rule=TaxCatalog::supportedKeys($originalYear)[$key]??null;
                if(!in_array($key,['2','3','101','102'],true)||!$rule||$rule['direction']!=='income'||(int)$rule['local_rate']!==$rate||!isset($keys[$key])||(int)$keys[$key]['local_rate']!==$rate)throw new DomainException('An der Originalrechnung zuerst den passenden geprüften Inlands-Umsatzsteuerschlüssel 2/3 oder 102/101 hinterlegen. Sonderfälle wie Reverse Charge und Ausland bleiben gesperrt.');
            }elseif($key!==''||trim((string)($cd['vat_reason']??''))===''||trim((string)($pd['vat_reason']??''))==='')throw new DomainException('Bei 0 % müssen der Steuerbefreiungsgrund im Original und eine Kontierung ohne BU-Schlüssel vorhanden sein.');
            if($row['entry_kind']!=='expense'||($row['reversal_of']?($row['signed_cents']<=0):($row['signed_cents']>=0)))throw new DomainException('Die zugeordnete Zahlung ist keine nachvollziehbare Rückzahlung.');
            $before=$row['position_paid_before_cents']??null;$after=$row['position_paid_after_cents']??null;
            if(!is_int($before)||!is_int($after)||$before<0||$after>$position['gross_cents']||$after-$before!==$row['amount_cents'])throw new DomainException('Der Teilrückzahlungsbetrag ist nicht eindeutig zur Belegposition zugeordnet.');
            $tax=self::vat($after,$rate)-self::vat($before,$rate);
            if($tax!==self::vat($row['amount_cents'],$rate))throw new DomainException('Diese Teilrückzahlung erzeugt eine Umsatzsteuer-Rundungsabweichung in DATEV. Die Buchhaltung muss den Centausgleich separat prüfen; keine zusätzliche Zahlung anlegen.');
            $row['stored_booking_key']=$row['booking_key'];$row['booking_key']=$key;$row['credit_tax_cents']=$tax;$row['credit_net_cents']=$row['amount_cents']-$tax;
            $row['export_explanation']='Erlösminderung zu '.$p['number'].' · Umsatzsteuerkorrektur';
            return ['row'=>$row,'issues'=>[]];
        }catch(DomainException $error){return ['row'=>$row,'issues'=>['Ausgangsgutschrift: '.$error->getMessage()]];}
    }
}
