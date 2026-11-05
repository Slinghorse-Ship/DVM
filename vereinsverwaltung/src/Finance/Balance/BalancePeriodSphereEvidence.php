<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/BalancePeriodSphereChange.php';

/** Links a transfer to immutable document bytes belonging to this club and asset. */
final class BalancePeriodSphereEvidence
{
    public function __construct(private PDO $db,private string $actor){}
    /** Never override a known separate original tax plan with a checked checkbox. */
    public function originalValues(string $club,string $item,string $firstOn):void
    {
        $a=Permissions::actor($this->db,$this->actor);
        if($a['club_id']!==$club||!in_array($a['finance_access'],['read','write'],true))throw new DomainException('Finanzberechtigung für diesen Verein erforderlich.');
        foreach(['balance_tax_reconciliation_reviews'=>'tax_year','balance_period_tax_reviews'=>'period_id'] as $table=>$identity){
            if(!Database::tableExists($this->db,$table))continue;
            $within=$identity==='tax_year'?'r.tax_year<=?':'LEFT(r.period_id,8)<=?';$boundary=$identity==='tax_year'?(int)substr($firstOn,0,4):str_replace('-','',$firstOn);
            $rows=DocumentCatalog::query($this->db,"SELECT r.payload FROM $table r WHERE r.club_id=? AND $within AND NOT EXISTS (SELECT 1 FROM $table n WHERE n.club_id=r.club_id AND n.$identity=r.$identity AND n.revision>r.revision) LIMIT 501",[$club,$boundary])->fetchAll();
            if(count($rows)>500)throw new DomainException('Bisherige Steuerpläne vor dem Bereichswechsel abstimmen.');
            foreach($rows as $row){$data=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new DomainException('Gespeicherten Steuerplan prüfen.');
                if(isset($data['values']['review']['asset_tax_plans'][$item]))throw new DomainException('Für diesen Gegenstand gibt es einen abweichenden Steuerplan. Den Bereichswechsel mit diesem ursprünglichen Steuerplan abstimmen.');}
        }
        if(Database::tableExists($this->db,'tax_filings')){
            $rows=DocumentCatalog::query($this->db,"SELECT tax_year,form_data FROM tax_filings WHERE club_id=? AND family='balance' AND tax_year<=? LIMIT 501",[$club,(int)substr($firstOn,0,4)+1])->fetchAll();
            if(count($rows)>500)throw new DomainException('Bisherige Abgaben vor dem Bereichswechsel abstimmen.');
            foreach($rows as $row){$data=json_decode($row['form_data'],true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new DomainException('Gespeicherte Steuerabgabe prüfen.');
                $b=$data['balance_preparation']??[];if(!is_array($b))throw new DomainException('Gespeicherten Bilanzentwurf prüfen.');
                $start=$b['period']['start']??sprintf('%04d-01-01',(int)$row['tax_year']);
                if(!is_string($start)||!TaxCatalog::documentYear($start))throw new DomainException('Zeitraum der bisherigen Steuerabgabe prüfen.');
                if($start>$firstOn)continue;
                if(isset($b['review']['asset_tax_plans'][$item])||isset($b['carry_forward']['asset_tax_plans'][$item]))throw new DomainException('Abweichenden Steuerplan aus der bisherigen Abgabe für diesen Bereichswechsel berücksichtigen.');
                if(($b['profile']['tax_privileged']??null)===false)throw new DomainException('Bei einem nicht steuerbegünstigten Gesamtverein keine steuerfreien Vereinsbereiche annehmen. Steuerprofil des Wechsels zuerst abstimmen.');
            }
        }
    }
    public function fromForm(array $form,array $existing=[]):array
    {
        if(($form['sphere_preview']??'')!=='1')return [];
        $get=static function(string $k)use($form):string{if(!is_string($form[$k]??null))throw new DomainException('Angaben zum Bereichswechsel vollständig prüfen.');return $form[$k];};
        $index=$get('sphere_index');
        if(!array_is_list($existing)||($index!=='new'&&(!preg_match('/^(?:0|[1-9][0-9]?)$/D',$index)||!isset($existing[(int)$index]))))throw new DomainException('Bereichswechsel erneut auswählen.');
        if(($form['sphere_action']??'')==='remove'){
            if($index==='new')throw new DomainException('Vorhandenen Wechsel auswählen.');
            array_splice($existing,(int)$index,1);return ['sphere_changes'=>$existing];
        }
        $d=(new Documents($this->db,$this->actor))->document($get('sphere_document'));$case=$get('sphere_case');
        $change=['on'=>$get('sphere_on'),'to_sphere'=>$get('sphere_to'),'case'=>$case,
            'value_cents'=>$case==='exempt_to_exempt'?0:Finance::cents($get('sphere_value')),'remaining_months'=>$case==='taxable_value'?filter_var($get('sphere_months'),FILTER_VALIDATE_INT):0,
            'residual_cents'=>$case==='taxable_value'?Finance::cents($get('sphere_residual')):0,'document_id'=>$d['id'],'revision'=>(int)$d['revision'],'sha256'=>$d['sha256'],'reason'=>$get('sphere_reason'),
            'charitable_use'=>($form['sphere_charitable']??'')==='1','funds_reviewed'=>($form['sphere_funds']??'')==='1','vat_reviewed'=>($form['sphere_vat']??'')==='1',
            'tax_reviewed'=>($form['sphere_tax']??'')==='1','history_reviewed'=>($form['sphere_history']??'')==='1'];
        if($index==='new')$existing[]=$change;else $existing[(int)$index]=$change;
        usort($existing,static fn(array $a,array $b):int=>strcmp($a['on'],$b['on']));
        return ['sphere_changes'=>$existing];
    }
    public function check(string $club,string $item,array $changes,bool $lock):void
    {
        if($lock&&!$this->db->inTransaction())throw new DomainException('Nachweisprüfung benötigt einen Speichervorgang.');
        foreach($changes as $c){
            if($lock)DocumentCatalog::query($this->db,'SELECT id FROM documents WHERE club_id=? AND id=? FOR UPDATE',[$club,$c['document_id']])->fetch();
            $d=(new Documents($this->db,$this->actor))->document($c['document_id']);$linked=false;
            foreach($d['links'] as $l)if($l['source_type']==='item'&&$l['source_id']===$item)$linked=true;
            if(!$linked||$d['club_id']!==$club||$d['state']!=='active'||$d['origin']!=='upload'||!in_array('finance',$d['scopes'],true)
                ||(int)$d['revision']!==$c['revision']||!hash_equals($d['sha256'],$c['sha256']))throw new DomainException('Bereichsnachweis muss unverändert mit diesem Gegenstand verknüpft sein.');
            $file=DocumentCatalog::query($this->db,'SELECT sha256,SHA2(bytes,256) actual_sha256 FROM document_files WHERE club_id=? AND document_id=? AND revision=?'.($lock?' FOR UPDATE':''),[$club,$c['document_id'],$c['revision']])->fetch();
            if(!$file||!hash_equals($c['sha256'],$file['sha256'])||!hash_equals($c['sha256'],$file['actual_sha256']))throw new DomainException('Datei des Bereichsnachweises ist beschädigt. Original prüfen.');
        }
    }
}
