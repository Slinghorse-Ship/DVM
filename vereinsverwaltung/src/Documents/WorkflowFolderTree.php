<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;

/** Read-only metadata projection. Every original remains reachable, including detached legacy paths. */
final class WorkflowFolderTree
{
    private array $byId=[];
    private array $children=[];
    private array $ordered=[];
    private array $labels=[];
    private array $ambiguous=[];

    public function __construct(array $folders)
    {
        foreach($folders as$folder){
            $id=$folder['id']??null;
            if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($this->byId[$id])||!is_string($folder['name']??null))throw new \DomainException('Arbeitsordner konnten nicht vollständig gelesen werden.');
            $this->byId[$id]=$folder;$this->children[(string)($folder['parent_id']??'')][]=$id;
        }
        $seen=[];
        $walk=function(array $roots,bool $detached)use(&$seen):void{
            $stack=[];foreach(array_reverse($roots)as$id)$stack[]=[$id,0,$id,[]];
            while($stack){
                [$id,$depth,$root,$trail]=array_pop($stack);if(isset($seen[$id]))continue;$seen[$id]=true;
                $trail=array_slice([...$trail,$id],-3);$row=$this->byId[$id];
                $node=$row+['depth'=>$depth,'detached'=>$detached,'root_id'=>$root,'trail'=>$trail];$this->ordered[]=$node;
                $names=array_map(fn(string $part):string=>$this->byId[$part]['name'],$trail);
                if($depth>=3){array_unshift($names,$this->byId[$root]['name'],...($depth>3?['…']:[]));}
                $this->labels[$id]=implode(' / ',$names);
                foreach(array_reverse($this->children[$id]??[])as$child)$stack[]=[$child,$depth+1,$root,$trail];
            }
        };
        $walk($this->children['']??[],false);
        foreach(array_keys($this->byId)as$id)if(!isset($seen[$id]))$walk([$id],true);
        // Keep normal names concise, but make duplicate native options distinguishable.
        $counts=array_count_values($this->labels);
        foreach($this->labels as$id=>$label)if($counts[$label]>1){$this->ambiguous[$id]=true;$this->labels[$id]=$label.' · #'.$id;}
        // An original name can itself contain a displayed reference. In that rare
        // collision, give every option its fixed-width unique identifier suffix.
        if(count(array_unique($this->labels))!==count($this->labels))foreach($this->labels as$id=>$label){$this->ambiguous[$id]=true;$this->labels[$id]=$label.' · #'.$id;}
    }

    public function rows(string $search=''):array
    {
        self::validateSearch($search);if($search==='')return $this->ordered;
        $pattern='~'.preg_quote($search,'~').'~iu';
        return array_values(array_filter($this->ordered,static fn(array $row):bool=>preg_match($pattern,$row['name'])===1||preg_match($pattern,$row['id'])===1));
    }
    public function get(string $id):?array{return $this->byId[$id]??null;}
    public function labels():array{return $this->labels;}
    public function ambiguous(string $id):bool{return isset($this->ambiguous[$id]);}
    public function detached():bool
    {
        foreach($this->ordered as$row)if($row['detached'])return true;return false;
    }
    public function blockedParents(string $id):array
    {
        if($id==='')return [];
        $blocked=[];$stack=[$id];
        while($stack){$cursor=array_pop($stack);if(isset($blocked[$cursor]))continue;$blocked[$cursor]=true;foreach($this->children[$cursor]??[]as$child)$stack[]=$child;}
        return $blocked;
    }
    public function path(string $id):array
    {
        $rows=[];$seen=[];$issue='';
        while($id!==''){
            if(isset($seen[$id])){$issue='cycle';break;}
            if(!isset($this->byId[$id])){$issue='missing';break;}
            $seen[$id]=true;$row=$this->byId[$id];$rows[]=$row;$id=(string)($row['parent_id']??'');
        }
        return ['rows'=>array_reverse($rows),'issue'=>$issue];
    }
    private static function validateSearch(string $search):void
    {
        if(preg_match('//u',$search)!==1||preg_match('/[\x00-\x1f\x7f]/',$search)||preg_match('/\A.{0,240}\z/usD',$search)!==1)throw new \DomainException('Ordnersuche mit höchstens 240 Zeichen prüfen.');
    }
}
