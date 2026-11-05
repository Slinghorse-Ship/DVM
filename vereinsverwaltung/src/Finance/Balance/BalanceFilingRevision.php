<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceClosingCorrections.php';

/** Filing status is distinct from internal journal corrections and finality. */
final class BalanceFilingRevision
{
    public const LABELS=['E'=>'Erstmalige Abgabe','B'=>'Berichtigte Bilanz','G'=>'Geänderte Bilanz','BG'=>'Berichtigte und geänderte Bilanz','sK'=>'Sonstige Korrektur außerhalb von Bilanz und GuV'];
    public function __construct(private PDO $db,private string $actor){}
    private function tax():TaxFilings{return new TaxFilings($this->db,$this->actor);}
    private static function hash(array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private static function identity(array $r):array
    {
        $p=$r['form_data']['balance_preparation']['profile']??[];
        // Labels, taxonomy versions and accounting captions must not create a new obligation.
        $identity=[$r['club_id'],$r['tax_year'],$r['environment'],$p['kind']??null,$p['source_scope']??null,
            $p['tax_privileged']??null,$p['company']['tax_number']??null];
        $b=$r['form_data']['balance_preparation'];
        if(array_key_exists('period_id',$b)||array_key_exists('period',$b)){
            BalancePeriodFilingSource::binding($b);
            // Preserve historical calendar identity hashes. A dated calendar
            // interval is still the SAME obligation; two short periods are not.
            if($b['period']!==BalancePeriod::calendar((int)$r['tax_year']))$identity[]=$b['period'];
        }
        return $identity;
    }
    private function journal(array $r,?string $hash=null):array
    {
        $b=$r['form_data']['balance_preparation'];
        if(array_key_exists('period_id',$b)||array_key_exists('period',$b)){
            $id=BalancePeriodFilingSource::binding($b);
            if($hash!==null&&in_array($b['profile']['kind'],['club_taxable_businesses',BalanceTaxFiling::COMBINED],true))return (new BalancePeriodClubFiling($this->db,$this->actor))->historical($b,$hash)['period_club_scope']['income']['scope']['source'];
            if($hash!==null)return (match($b['profile']['kind']){
                'whole_taxable_club'=>new BalancePeriodWholeFiling($this->db,$this->actor),
                'single_taxable_business'=>new BalancePeriodBusinessFiling($this->db,$this->actor),
                default=>new BalancePeriodFilingSource($this->db,$this->actor)
            })->historical($b,$hash);
            $report=(new BalancePeriodLedger($this->db,$this->actor))->report($id);
            if($report['period']!==$b['period']||$report['year']!==(int)$r['tax_year'])throw new DomainException('Zeitraum der Abgabe prüfen.');
            return $report;
        }
        $ledger=new BalanceLedger($this->db,$this->actor);
        return $hash===null?$ledger->report((int)$r['tax_year']):$ledger->archivedReport((int)$r['tax_year'],$hash);
    }
    private static function journalHash(array $r):string
    {
        $b=$r['form_data']['balance_preparation'];
        return $b['closing_source_sha256']??$b['whole_journal_sha256']??$b['source_binding'][0]['journal_sha256']??$b['journal_sha256'];
    }
    private function receipt(array $r):array
    {
        if($r['family']!=='balance'||$r['state']!=='sent_'.$r['environment'])throw new DomainException('Bestätigte frühere E-Bilanz auswählen.');
        BalanceTaxFiling::assertHistoricalApproval($r);
        $attempts=array_values(array_filter($this->tax()->attempts($r['id']),static fn($a)=>$a['receipt_bound']));
        if(count($attempts)!==1)throw new DomainException('Eindeutiger, unveränderter Übermittlungsnachweis fehlt.');
        $a=$attempts[0];$hash=self::journalHash($r);
        $this->journal($r,$hash);
        return ['filing_id'=>$r['id'],'attempt_id'=>$a['id'],'xml_sha256'=>$r['xml_sha256'],'transfer_ticket'=>$a['transfer_ticket'],
            'journal_sha256'=>$hash,'approval_sha256'=>$r['form_data']['balance_preparation']['approval']['sha256']];
    }
    /** Candidate lookup is club-isolated; no current-journal check on historical filings. */
    private function related(array $r):\Generator
    {
        $ids=DocumentCatalog::query($this->db,"SELECT id FROM tax_filings WHERE club_id=? AND family='balance' AND environment=? AND tax_year=? AND state IN ('sending','unknown','sent_test','sent_production') ORDER BY created_at,id",[$r['club_id'],$r['environment'],$r['tax_year']])->fetchAll(PDO::FETCH_COLUMN);
        // Validate the same complete evidence one record at a time. Keeping
        // every decoded correction in memory grows with the entire history.
        $identity=self::identity($r);
        foreach($ids as $id){if($id===$r['id'])continue;$other=$this->tax()->get($id);if(self::identity($other)===$identity)yield $id=>$other;unset($other);}
    }
    public function choices(array $r):array
    {
        $out=[];$superseded=[];foreach($this->related($r) as $other){
            if(!str_starts_with($other['state'],'sent_'))continue;
            try{
                $receipt=$this->receipt($other);$out[$other['id']]=$receipt+['at'=>$other['updated_at']];
                $parent=$other['form_data']['balance_preparation']['filing_revision']['source']['filing_id']??null;if($parent!==null)$superseded[$parent]=true;
            }catch(DomainException){}
        }return array_values(array_diff_key($out,$superseded));
    }
    /** Carry must use the latest unambiguous filing, not a superseded tax valuation. */
    public function assertLatestReceipt(array $r):void
    {
        $this->receipt($r);$probe=$r;$probe['id']='';$probe['state']='draft';
        $this->checkHead($probe,$r['id']);
    }
    private function checkHead(array $r,?string $source):void
    {
        if(str_starts_with($r['state'],'sent_'))return; // Historic evidence remains readable after a later amendment.
        $ids=[];$superseded=[];
        foreach($this->related($r) as $id=>$other){
            $ids[$id]=true;
            if(in_array($other['state'],['sending','unknown'],true))throw new DomainException('Eingang einer früheren Abgabe ist noch unklar. Nicht erneut übermitteln.');
            $this->receipt($other);
            $parent=$other['form_data']['balance_preparation']['filing_revision']['source']['filing_id']??null;
            if($parent!==null)$superseded[$parent]=true;
        }
        $heads=array_values(array_diff(array_keys($ids),array_keys($superseded)));
        if($source===null){if($heads)throw new DomainException('Für diesen Abschluss liegt bereits eine Abgabe vor. Korrekturart und vorherige Abgabe auswählen.');}
        elseif($heads!==[$source])throw new DomainException('Die letzte eindeutig bestätigte Abgabe auswählen. Andere oder unklare Abgabestände zuerst klären.');
    }
    public function prepare(array $r,array $input):array
    {
        if(array_diff(array_keys($input),['status','reason','legal_basis','source_id','confirmed'])||($input['confirmed']??null)!==true
            ||!is_string($input['status']??null)||!isset(self::LABELS[$input['status']]))throw new DomainException('Abgabeart auswählen und prüfen.');
        foreach(['reason','legal_basis','source_id'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Abgabeart und Nachweise prüfen.');
        $reason=BalanceClosingCorrection::note($input['reason']??'');
        $legal=$input['status']==='E'?'':BalanceClosingCorrection::note($input['legal_basis']??'');
        $identity=self::identity($r);$p=$r['form_data']['balance_preparation']['profile'];
        $business=in_array($p['kind']??null,[BalanceTaxFiling::COMBINED,BalanceTaxFiling::SUBSIDIARY],true)?($p['whole_business_name']??''):($p['business_name']??'');
        if(!preg_match('/^[0-9]{13}$/D',$p['company']['tax_number']??'')||!is_bool($p['tax_privileged']??null)||trim($business)==='')throw new DomainException('Zuerst Vereinsangaben und Geltungsbereich speichern.');
        $source=null;$id=$input['source_id']??'';
        if(!is_string($id)||($input['status']==='E'&&$id!==''))throw new DomainException('Eine erstmalige Abgabe hat keine vorherige Übermittlung.');
        if($input['status']!=='E'){
            $original=$this->tax()->get($id);
            if($id===$r['id']||self::identity($original)!==$identity)throw new DomainException('Vorherige Abgabe gehört zu einem anderen Jahr, Verein oder Geltungsbereich.');
            $source=$this->receipt($original);
            unset($original);
        }
        $this->checkHead($r,$source['filing_id']??null);
        $journal=$this->journal($r);
        if($journal['state']!=='closed'||$journal['issues'])throw new DomainException('Aktuellen Abschluss zuerst vollständig prüfen.');
        if($input['status']==='sK'){
            $original=$this->journal($this->tax()->get($source['filing_id']),$source['journal_sha256']);
            if($journal['calculation']!==$original['calculation']||$journal['asset_schedule']!==$original['asset_schedule'])throw new DomainException('Geänderte Bilanz- oder GuV-Werte sind keine sonstige Korrektur.');
        }
        return ['status'=>$input['status'],'reason'=>$reason,'legal_basis'=>$legal,'confirmed'=>true,'source'=>$source,
            'journal_sha256'=>$journal['closed_sha256'],'identity_sha256'=>self::hash($identity)];
    }
    public function current(array $r):array
    {
        $b=$r['form_data']['balance_preparation'];$revision=$b['filing_revision']??null;
        if($revision===null){
            $journal=$this->journal($r);
            if(isset($journal['correction']))throw new DomainException('Abgabeart des Korrekturabschlusses prüfen: erstmalig oder Korrektur einer früheren Übermittlung.');
            $this->checkHead($r,null);return ['status'=>'E'];
        }
        if(!is_array($revision))throw new DomainException('Abgabeart prüfen.');
        $input=['status'=>$revision['status']??null,'reason'=>$revision['reason']??null,'legal_basis'=>$revision['legal_basis']??null,
            'source_id'=>$revision['source']['filing_id']??'','confirmed'=>$revision['confirmed']??false];
        if($this->prepare($r,$input)!==$revision)throw new DomainException('Abgabeart, Abschluss oder vorheriger Übermittlungsnachweis wurden geändert. Erneut prüfen.');
        return $revision;
    }
    public function checkGenerated(array $r,string $xml):void
    {
        $revision=$this->current($r);if($revision['status']!=='sK')return;
        $old=$this->tax()->get($revision['source']['filing_id']);
        if(self::balanceFacts($old['xml_data'])!==self::balanceFacts($xml))throw new DomainException('Sonstige Korrektur darf weder Bilanz, GuV noch den Anlagenspiegel verändern.');
    }
    /** Compare values and dimensional contexts, not generated IDs or report status. */
    public static function balanceFacts(string $xml):array
    {
        if(strlen($xml)>16000000||preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$xml))throw new DomainException('E-Bilanz-Nachweis prüfen.');
        $d=new \DOMDocument();$d->preserveWhiteSpace=false;$old=libxml_use_internal_errors(true);
        try{$ok=$d->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        if(!$ok)throw new DomainException('E-Bilanz-Nachweis nicht lesbar.');
        $contexts=[];foreach($d->getElementsByTagNameNS('http://www.xbrl.org/2003/instance','context') as $ctx){
            $id=$ctx->getAttribute('id');if(isset($contexts[$id]))throw new DomainException('Doppelten E-Bilanz-Kontext prüfen.');
            $doc=new \DOMDocument();$copy=$doc->importNode($ctx,true);$doc->appendChild($copy);$copy->removeAttribute('id');$contexts[$id]=$copy->C14N();
            if(!is_string($contexts[$id])||$contexts[$id]===''||$id==='')throw new DomainException('E-Bilanz-Kontext nicht lesbar.');
        }
        $out=[];foreach($d->getElementsByTagName('*') as $node){
            if(!$node->hasAttribute('unitRef')||!preg_match('/^(bs\.|is\.|nt\.ass\.|DeterminationOfTaxableIncomeSpec\.forProfitOrganisationsAndUtilities\.(balanceSheet|IncomeStatement)\.)/',$node->localName))continue;
            $ctx=$contexts[$node->getAttribute('contextRef')]??null;if($ctx===null)throw new DomainException('E-Bilanz-Kontext fehlt.');
            $out[]=[$node->namespaceURI,$node->localName,$ctx,$node->getAttribute('unitRef'),$node->textContent,$node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance','nil')];
        }
        if(!$out)throw new DomainException('Bilanzwerte im Übermittlungsnachweis fehlen.');sort($out);return $out;
    }
}
