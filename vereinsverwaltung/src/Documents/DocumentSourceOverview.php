<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** Permission-filtered original links, without hydrating all document notes or file payloads. */
trait DocumentSourceOverview
{
    private function sourceDocumentCounts():array
    {
        $ids=array_column($this->libraryIndex(['state'=>'active']),'id');$counts=[];$club=$this->actor()['club_id'];
        foreach(array_chunk($ids,500)as$chunk){
            $rows=$this->q('SELECT source_type,source_id,COUNT(*) AS document_count FROM document_links WHERE club_id=? AND document_id IN ('.implode(',',array_fill(0,count($chunk),'?')).') GROUP BY source_type,source_id',[$club,...$chunk]);
            while($row=$rows->fetch()){$key=$row['source_type'].':'.$row['source_id'];$counts[$key]=($counts[$key]??0)+(int)$row['document_count'];}
        }
        return$counts;
    }
    public function sourceOverviewPage(array $filters=[],int $page=1):array
    {
        $query=self::text($filters['q']??'',150);$type=self::text($filters['source_kind']??'',30);$scope=self::text($filters['scope']??'',20);
        if($type!==''&&!isset(DocumentCatalog::TYPES[$type]))throw new \DomainException('Vorgangsart prüfen.');
        if($page<1||$page>999999999)throw new \DomainException('Vorgangssuche und Seite prüfen.');
        return$this->readContext(function()use($query,$type,$scope,$page):array{
            if(!$this->canRead())throw new \DomainException('Keine Berechtigung für Vereinsdokumente.',403);
            if($scope!==''&&(!isset(DocumentCatalog::SCOPES[$scope])||!$this->canScope($scope)))throw new \DomainException('Diese Dokumentansicht ist nicht verfügbar.',403);
            $counts=$this->sourceDocumentCounts();$total=0;$rows=[];$last=[];$offset=($page-1)*6;
            foreach($this->sourceIndexRows($type,false,true)as$row){
                if(($scope!==''&&$row['scope']!==$scope)||($counts[$row['source_type'].':'.$row['source_id']]??0)>0)continue;
                if($query!==''&&mb_stripos($row['label'].' '.$row['path'],$query,0,'UTF-8')===false)continue;
                unset($row['scope'],$row['available']);$row['document_count']=0;$ordinal=$total++;if($ordinal%6===0)$last=[];$last[]=$row;if($ordinal>=$offset&&$ordinal<$offset+6)$rows[]=$row;
            }
            $window=Pagination::window($total,$page,6);unset($window['offset']);if($page>$window['page'])$rows=$last;
            return ['rows'=>$rows]+$window;
        });
    }
}
