<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodDisposal.php';
require_once __DIR__.'/BalancePeriodSale.php';

/** Original references for dated disposals; previews never alter inventory or invoices. */
final class BalancePeriodDisposalEvidence
{
    public function __construct(private PDO $db,private string $actor){}
    public function choices(string $item,string $invoice):array
    {
        $out=['disposal_document'=>[],'part_document'=>[]];
        foreach((new Documents($this->db,$this->actor))->listing(['state'=>'active']) as $d){
            if($d['origin']!=='upload'||!in_array('finance',$d['scopes'],true))continue;
            $onItem=false;$onInvoice=false;
            foreach($d['links'] as $link){
                $onItem=$onItem||($link['source_type']==='item'&&$link['source_id']===$item);
                $onInvoice=$onInvoice||($invoice!==''&&$link['source_type']==='entry'&&$link['source_id']===$invoice);
            }
            if($onItem)$out['disposal_document'][]=$d;
            if($onItem||$onInvoice)$out['part_document'][]=$d;
        }
        return $out;
    }
    private function reference(string $id):array
    {
        $d=(new Documents($this->db,$this->actor))->document($id,true);
        if($d['state']!=='active'||$d['origin']!=='upload'||!in_array('finance',$d['scopes'],true))throw new DomainException('Aktiven Finanzbeleg als Nachweis wählen.');
        return ['document_id'=>$id,'revision'=>(int)$d['revision'],'sha256'=>$d['sha256']];
    }
    public function fromForm(array $plan,array $form):array
    {
        $kind=$form['disposal_kind']??'';if($kind==='')return [];
        if($kind==='sale')return (new BalancePeriodSale($this->db,$this->actor))->fromForm($plan,$form);
        if(!in_array($kind,['scrap','software_end','partial_scrap'],true))throw new DomainException('Art des Anlagenabgangs prüfen.');
        foreach(['disposal_date','disposal_reason','disposal_document'] as $key)if(!is_string($form[$key]??null))throw new DomainException('Abgangsdatum, Grund und Nachweis vollständig angeben.');
        $p=['kind'=>$kind,'date'=>$form['disposal_date'],'reason'=>trim($form['disposal_reason'])]+$this->reference($form['disposal_document']);
        $p+=['no_proceeds'=>true,'complete'=>$kind!=='partial_scrap'];
        if($kind==='software_end')$p['rights_ended']=($form['rights_ended']??'')==='1';
        if($kind==='partial_scrap'){
            foreach(['part_cost','part_residual','part_document','part_reason'] as $key)if(!is_string($form[$key]??null))throw new DomainException('Teilkosten und ihren Nachweis vollständig angeben.');
            $p+=['schema'=>1,'plan_sha256'=>AssetPartialDisposal::hash($plan),'allocation'=>[
                'basis'=>'documented_component_cost','cost_cents'=>Finance::cents($form['part_cost']),
                'residual_cents'=>Finance::cents($form['part_residual'])]+$this->reference($form['part_document'])+[
                'reason'=>trim($form['part_reason']),'same_use_and_life'=>($form['same_use_and_life']??'')==='1','confirmed'=>($form['part_confirmed']??'')==='1']];
        }
        AssetDisposal::check($plan,$p);return ['disposal'=>$p];
    }
    public function check(string $club,string $item,string $invoice,array $plan,array $proof,bool $lock):void
    {
        if($lock&&!$this->db->inTransaction())throw new DomainException('Nachweisprüfung benötigt einen Speichervorgang.');
        AssetDisposal::check($plan,$proof);
        if($proof['kind']==='sale'){(new BalancePeriodSale($this->db,$this->actor))->check($club,$item,$plan,$proof,$lock);return;}
        if(!in_array($proof['kind'],['scrap','software_end','partial_scrap'],true))throw new DomainException('Anlagenverkauf und zugehörige Ausgangsrechnung gesondert abstimmen.');
        $this->document($club,$item,$invoice,$proof,false,$lock);
        if($proof['kind']==='partial_scrap')$this->document($club,$item,$invoice,$proof['allocation'],true,$lock);
    }
    private function document(string $club,string $item,string $invoice,array $ref,bool $allocation,bool $lock):void
    {
        // Lock metadata as well as immutable file bytes during journal adoption.
        // Reading proof does not require permission to edit the original document.
        if($lock)DocumentCatalog::query($this->db,'SELECT id FROM documents WHERE club_id=? AND id=? FOR UPDATE',[$club,$ref['document_id']])->fetch();
        $d=(new Documents($this->db,$this->actor))->document($ref['document_id']);
        if($d['club_id']!==$club||$d['state']!=='active'||$d['origin']!=='upload'||!in_array('finance',$d['scopes'],true)
            ||(int)$d['revision']!==$ref['revision']||!hash_equals($d['sha256'],$ref['sha256']))throw new DomainException('Abgangsnachweis fehlt oder wurde geändert. Original erneut prüfen.');
        $linked=false;
        foreach($d['links'] as $link)if(($link['source_type']==='item'&&$link['source_id']===$item)
            ||($allocation&&$link['source_type']==='entry'&&$link['source_id']===$invoice))$linked=true;
        if(!$linked)throw new DomainException($allocation?'Teilkosten-Nachweis mit dem Gegenstand oder seiner Anschaffungsrechnung verknüpfen.':'Abgangsnachweis mit diesem Gegenstand verknüpfen.');
        $f=DocumentCatalog::query($this->db,'SELECT sha256,SHA2(bytes,256) actual_sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=?'.($lock?' FOR UPDATE':''),[$club,$ref['document_id'],$ref['revision']])->fetch();
        if(!$f||!hash_equals($ref['sha256'],$f['sha256'])||!hash_equals($ref['sha256'],$f['actual_sha256']))throw new DomainException('Datei zum Abgangsnachweis ist beschädigt. Original prüfen.');
    }
}
