<?php declare(strict_types=1);
namespace Kulturhus\Verein;

/** A compact source index; unrelated original records and document bytes stay unopened. */
trait DocumentSourceChoices
{
    public function sourceChoicePage(array $filters=[],array $selected=[],string $document=''):array
    {
        $query=self::text($filters['query']??'',150);$type=$filters['type']??'';$page=$filters['page']??'1';
        if(!is_string($type)||($type!==''&&!isset(DocumentCatalog::TYPES[$type]))||!is_string($page)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$page))throw new \DomainException('Vorgangssuche und Seite prüfen.');
        if(!array_is_list($selected)||count($selected)>330)throw new \DomainException('Dokumentzuordnungen vollständig prüfen.');
        foreach($selected as$key)if(!is_string($key)||strlen($key)>150||!preg_match('/\A(entry|goods|contact|item|loan|account|appointment|application):[^\x00-\x20]+\z/D',$key))throw new \DomainException('Dokumentzuordnungen vollständig prüfen.');
        return $this->readContext(function()use($query,$type,$page,$selected,$document):array{
            if(!$this->canWrite())throw new \DomainException('Keine Berechtigung für diese Dokumentzuordnung.',403);
            $total=0;$rows=[];$last=[];$offset=((int)$page-1)*6;
            foreach($this->sourceIndexRows($type,true)as$row){
                if($query!==''&&mb_stripos($row['label'],$query,0,'UTF-8')===false)continue;
                unset($row['folder_id'],$row['scope']);$ordinal=$total++;if($ordinal%6===0)$last=[];$last[]=$row;if($ordinal>=$offset&&$ordinal<$offset+6)$rows[]=$row;
            }
            $window=Pagination::window($total,(int)$page,6);unset($window['offset']);if((int)$page>$window['page'])$rows=$last;$window=['rows'=>$rows]+$window;$chosen=[];$previous=[];
            if($document!=='')foreach($this->document($document,true)['links']as$link)$previous[$link['source_type'].':'.$link['source_id']]=$link;
            foreach(array_unique($selected)as$key){
                [$kind,$id]=explode(':',$key,2);
                try{$row=$this->source($kind,$id,true);}
                catch(\DomainException){$row=$previous[$key]??null;if($row&&!$row['available'])$this->allow([$row['scope']],true,$kind==='application');else$row=['source_type'=>$kind,'source_id'=>$id,'label'=>$key,'available'=>0,'unavailable'=>true];}
                $chosen[$key]=array_intersect_key($row,array_flip(['source_type','source_id','label','available','unavailable']));
            }
            return $window+['selected'=>$chosen,'query'=>$query,'type'=>$type];
        });
    }
    /** Complete metadata stream with the same source/ancestor policy as source(); only within readContext. */
    private function sourceIndexRows(string $type='',bool $write=false,bool $withPaths=false):\Generator
    {
        $club=$this->actor()['club_id'];$exists=[];
        foreach(['entry'=>'finance_entries','goods'=>'goods_donations','contact'=>'contacts','item'=>'inventory_items','loan'=>'inventory_loans','account'=>'finance_accounts','appointment'=>'appointments','application'=>'membership_applications']as$kind=>$table){
            $condition="EXISTS(SELECT 1 FROM $table original WHERE original.club_id=s.club_id AND original.id=s.source_id".($kind==='item'?' AND original.deleted_at IS NULL':'').')';
            if($kind==='appointment'&&Database::tableExists($this->db,'finance_event_assignments'))$condition="((BINARY s.source_id NOT LIKE 'website:%' AND $condition) OR (BINARY s.source_id LIKE 'website:%' AND EXISTS(SELECT 1 FROM finance_event_assignments original WHERE original.club_id=s.club_id AND original.event_id=s.source_id)))";
            $exists[]="(s.source_type='$kind' AND $condition)";
        }
        $sql='SELECT s.source_type,s.source_id,s.label,s.document_date,s.folder_id,s.scope,s.available FROM document_sources s WHERE s.club_id=? AND s.available=1 AND ('.implode(' OR ',$exists).')';$args=[$club];
        if($type!==''){$sql.=' AND s.source_type=?';$args[]=$type;}
        $sql.=' ORDER BY s.document_date DESC,s.label,s.source_type,s.source_id';
        $allowed=[];$writable=[];$paths=[];$statement=$this->q($sql,$args);
        while($row=$statement->fetch()){
            $kind=$row['source_type'];
            if(!array_key_exists($kind,$allowed)){
                $scope=match($kind){'entry','goods','account'=>'finance','contact','application'=>'members','item','loan'=>'inventory','appointment'=>'orga'};
                try{$this->allow([$scope],$write,$kind==='application');$allowed[$kind]=true;}catch(\DomainException){$allowed[$kind]=false;}
                if($withPaths){try{$this->allow([$scope],true,$kind==='application');$writable[$kind]=true;}catch(\DomainException){$writable[$kind]=false;}}
            }
            if(!$allowed[$kind])continue;$folder=$row['folder_id'];
            if(!array_key_exists($folder,$paths)){try{$paths[$folder]=$this->path($folder);}catch(\DomainException){$paths[$folder]=false;}}
            if($paths[$folder]===false)continue;if($withPaths){$row['path']=$paths[$folder];$row['writable']=$writable[$kind];}yield$row;
        }
    }

}
