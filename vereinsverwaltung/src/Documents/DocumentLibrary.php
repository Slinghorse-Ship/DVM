<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/Documents.php';
require_once __DIR__.'/ManagerDocumentWorkflows.php';

/** One catalogue, multiple protected source views; authoritative records stay in place. */
final class DocumentLibrary
{
    public const KINDS=Documents::KINDS+['assessment'=>'Gefährdungsbeurteilung','assessment-attachment'=>'HSE-Anlage'];
    public function __construct(private Documents $documents,private ?ManagerDocumentWorkflows $workflows=null){}
    public function canHse():bool
    {
        try{return $this->workflows!==null&&$this->workflows->allows();}catch(DomainException){return false;}
    }
    private function catalogue(array $filters):array
    {
        $scope=$filters['scope']??'';if(!is_string($scope))throw new DomainException('Ungültige Dokumentansicht.');
        if($scope!==''&&$scope!=='hse'&&(!isset(DocumentCatalog::SCOPES[$scope])||!$this->documents->canScope($scope)))throw new DomainException('Diese Dokumentansicht ist nicht freigegeben.');
        $search=$filters['q']??'';if(!is_string($search)||preg_match('//u',$search)!==1||mb_strlen($search,'UTF-8')>150||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$search))throw new DomainException('Dokumentsuche prüfen.');
        $kind=$filters['kind']??'';if(!is_string($kind)||($kind!==''&&!isset(self::KINDS[$kind])))throw new DomainException('Dokumentart prüfen.');
        $state=$filters['state']??'active';if(!in_array($state,['active','archived','all','trash'],true))throw new DomainException('Dokumentstatus prüfen.');
        if($scope==='hse'&&!$this->canHse())throw new DomainException('Diese HSE-Dokumentansicht ist nicht freigegeben.');
        $index=($scope==='hse'||in_array($kind,['assessment','assessment-attachment'],true))?[]:$this->documents->libraryIndex($filters);$hseRows=[];
        // A central-folder or concrete-source filter remains a view of that source.
        $hse=($scope===''||$scope==='hse')&&($filters['folder']??'')===''&&($filters['source_type']??'')===''&&($filters['unassigned']??'')!=='1'
            &&in_array($state,['active','all'],true)&&($kind===''||in_array($kind,['assessment','assessment-attachment'],true));
        if($hse&&$this->canHse())foreach($this->workflows->library() as $row){
            if($kind!==''&&$row['kind']!==$kind)continue;
            if(trim($search)!==''&&mb_stripos($row['title'].' '.$row['name'].' '.$row['links'][0]['label'],trim($search),0,'UTF-8')===false)continue;
            $hseRows[$row['id']]=$row;$index[]=['id'=>$row['id'],'document_date'=>$row['document_date'],'created_at'=>$row['created_at']];
        }
        usort($index,static fn($a,$b)=>strcmp($b['document_date'],$a['document_date'])?:strcmp($b['created_at'],$a['created_at'])?:strcmp($a['id'],$b['id']));
        return ['index'=>$index,'hse'=>$hseRows,'state'=>$state];
    }
    public function count(array $filters=[]):int {return count($this->catalogue($filters)['index']);}
    public function page(array $filters=[],int $page=1,int $size=25):array
    {
        if($page<1||!in_array($size,[6,25,50,100],true))throw new DomainException('Ungültige Dokumentseite.');
        $catalogue=$this->catalogue($filters);$index=$catalogue['index'];$hseRows=$catalogue['hse'];$state=$catalogue['state'];
        $window=Pagination::window(count($index),$page,$size);$chosen=array_slice($index,$window['offset'],$size);unset($window['offset']);$rows=[];
        foreach($chosen as$key){if(isset($hseRows[$key['id']])){$rows[]=$hseRows[$key['id']];continue;}$row=$this->documents->document($key['id'],false,$state==='trash');if($state==='trash')$row['state']='trash';$rows[]=$row;}
        return ['rows'=>$rows]+$window;
    }

}
