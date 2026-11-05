<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
use PDO;
require_once __DIR__.'/BalanceTaxFiling.php';

/** Read model only. A displayed mapping never grants XML approval or sending rights. */
final class BalanceXmlOverview
{
    public function __construct(private PDO $db,private string $actor){}

    public static function unused(array $account):bool
    {
        // A zero closing balance alone hides cleared accounts with real turnover.
        foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $key)
            if(!array_key_exists($key,$account)||$account[$key]!==0)return false;
        return true;
    }

    public static function sameWholeSource(array $report,array $filing):bool
    {
        $b=$filing['form_data']['balance_preparation']??[];
        return ($report['state']??null)==='closed'&&is_string($report['closed_sha256']??null)
            &&preg_match('/^[a-f0-9]{64}$/D',$report['closed_sha256'])===1
            &&($filing['family']??null)==='balance'&&($filing['environment']??null)==='test'
            &&(int)($filing['tax_year']??0)===$report['year']&&($b['year']??null)===$report['year']
            &&!array_key_exists('period',$b)&&!array_key_exists('period_id',$b)
            &&($b['profile']['kind']??null)==='whole_taxable_club'
            &&($b['profile']['source_scope']??null)==='complete_club_ledger'
            &&($b['profile']['asset_scope']??null)==='whole_club'
            &&($b['profile']['tax_privileged']??null)===false
            &&($b['journal_sha256']??null)===$report['closed_sha256'];
    }

    public static function validationCurrent(array $filing,string $runtime):bool
    {
        $v=$filing['validation']??[];$xml=$filing['xml_data']??null;$hash=$filing['xml_sha256']??null;
        return ($filing['state']??null)==='validated'&&is_string($xml)&&$xml!==''&&is_string($hash)
            &&hash_equals(hash('sha256',$xml),$hash)&&($filing['runtime_sha256']??null)===$runtime
            &&($v['code']??null)===0&&($v['xml_sha256']??null)===$hash
            &&($v['environment']??null)==='test'&&($v['sent']??null)===false&&($v['transport_attempted']??null)===false;
    }

    public static function mapping(array $report,?array $saved=null):array
    {
        $accounts=$report['calculation']['accounts'];
        $out=BalanceTaxMapping::preview($report['year'],$accounts,$report['asset_schedule']??null);
        $out['unused_count']=0;$out['saved_count']=0;$out['review_count']=0;
        foreach($out['rows'] as $id=>&$row){
            if(self::unused($accounts[$id])){
                $row['status']='unused';$row['label']='Keine Anfangsbestände oder Buchungen';++$out['unused_count'];
                continue;
            }
            $target=$saved[$id]??null;
            if(is_string($target)){
                $choices=BalanceTaxMapping::choices($report['year'],$accounts[$id],BalanceTaxFiling::targets());
                if(isset($choices[$target])){
                    $row['status']='saved';$row['target']=$target;$row['label']=$choices[$target];
                    $out['mapping'][$id]=$target;++$out['saved_count'];
                }
            }
            if(!in_array($row['status'],['assigned','saved'],true))++$out['review_count'];
        }unset($row);
        return $out;
    }

    public function read(array $report):array
    {
        return BalanceReadSnapshot::run($this->db,function()use($report):array{
            $tax=new TaxFilings($this->db,$this->actor);$a=Permissions::actor($this->db,$this->actor);
            // Enforce read permissions even when no filing exists.
            $tax->canWrite();$service=new BalanceTaxFiling($this->db,$this->actor);
            $ids=DocumentCatalog::query($this->db,"SELECT id FROM tax_filings WHERE club_id=? AND tax_year=? AND family='balance' ORDER BY created_at DESC,id",[$a['club_id'],$report['year']])->fetchAll(PDO::FETCH_COLUMN);
            $items=[];$matching=[];$runtime=null;
            foreach($ids as $id){
                $r=$tax->get($id);$b=$r['form_data']['balance_preparation']??[];
                $item=['id'=>$id,'state'=>$r['state'],'current'=>false,'message'=>'Entwurf öffnen und Geltungsbereich prüfen.'];
                if(self::sameWholeSource($report,$r)){
                    try{
                        // Source replay is independent of cached XML and stored state labels.
                        $service->sourceReport($r,true);$matching[]=$b['mapping'];
                        $values=$service->assertCurrent($r,true);
                        if($r['state']==='validated'){
                            $runtime??=TaxFilings::runtimeHash();
                            $item['current']=($b['values_sha256']??null)===$values['sha256']&&self::validationCurrent($r,$runtime);
                        }
                        $item['message']=$item['current']?'XML vollständig erzeugt und amtlich geprüft · aktueller Abschluss und Programmstand · nicht übermittelt.'
                            :'Zuordnungen gespeichert. XML mit dem aktuellen Abschluss und Programmstand erneut prüfen.';
                    }catch(DomainException $e){$item['message']=$e->getMessage();}
                }
                if(in_array($r['state'],['sending','unknown','sent_test','sent_production'],true))
                    $item['message']='Übermittlungsstand im gespeicherten Auftrag und Protokoll prüfen. Keine erneute Übermittlung aus dieser Übersicht.';
                $items[]=$item;
            }
            require_once __DIR__.'/BalanceMappingReview.php';
            $preparation=(new BalanceMappingReview($this->db,$this->actor))->read($report);
            return ['mapping'=>self::mapping($report,count($matching)===1?$matching[0]:(count($matching)===0&&$preparation['current']?$preparation['mapping']:null)),'preparation'=>$preparation,'filings'=>$items,
                'ambiguous'=>count($matching)>1,'closed'=>($report['state']??null)==='closed'];
        });
    }
}
