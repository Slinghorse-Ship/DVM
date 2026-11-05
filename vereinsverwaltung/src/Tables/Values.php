<?php declare(strict_types=1);
namespace Kulturhus\Verein\Tables;

use DomainException;

/** One read only evaluation scope; never persists a result or a permission decision. */
final class Values
{
    private array $sources=[];
    private array $results=[];
    private array $visiting=[];
    private array $parsed=[];
    private array $unavailable=[];
    private int $fetched=0;
    private int $bytes=0;
    private int $budget=100000;
    public function __construct(private \Closure $source,private string $table,array $columns,array $rows)
    {
        $this->sources[$table]=['columns'=>$columns,'rows'=>array_column($rows,null,'id')];
    }
    private function source(string $id,string $row):array
    {
        if(isset($this->unavailable[$id]))throw new DomainException('Quelle fehlt, ist archiviert oder nicht freigegeben.');
        if(!isset($this->sources[$id])||!array_key_exists($row,$this->sources[$id]['rows'])){
            if(!isset($this->sources[$id])&&count($this->sources)+count($this->unavailable)>=64)throw new DomainException('Bis zu 64 Quelllisten pro Ansicht verwenden.');
            if(++$this->fetched>4096)throw new DomainException('Bis zu 4.096 zusätzliche Quellzeilen pro Ansicht verwenden.');
            if($this->bytes>=8388608)throw new DomainException('Quelltexte sind zu umfangreich. Kleinere Quelllisten verwenden.');
            try{$source=($this->source)($id,$row);}catch(DomainException){$this->unavailable[$id]=true;throw new DomainException('Quelle fehlt, ist archiviert oder nicht freigegeben.');}
            $bytes=strlen($source['row']['cells_json']??'');if($this->bytes+$bytes>8388608){$this->bytes=8388608;throw new DomainException('Quelltexte sind zu umfangreich. Kleinere Quelllisten verwenden.');}$this->bytes+=$bytes;
            $this->sources[$id]['columns']=$source['columns'];$this->sources[$id]['rows'][$row]=$source['row'];
        }
        return $this->sources[$id];
    }
    private function tree(string $raw,bool $link):array
    {
        $key=($link?'link:':'formula:').$raw;return $this->parsed[$key]??=Formula::parse($raw,$link);
    }
    private function cell(string $table,string $row,int $column,int $depth=0,bool $root=false):array
    {
        if(--$this->budget<0||$depth>32)throw new DomainException('Zu viele abhängige Zellen. Verweiskette verkürzen.');
        $source=$this->source($table,$row);$record=$source['rows'][$row]??null;$type=$source['columns'][$column]['type']??null;
        if(!$record||(!$root&&$record['archived'])||$type===null)throw new DomainException('Quelle fehlt, ist archiviert oder nicht freigegeben.');
        $key=$table.':'.$row.':'.$column;
        if(isset($this->visiting[$key]))throw new DomainException('Zirkelbezug zwischen berechneten Zellen.');
        if(isset($this->results[$key]))return $this->results[$key];
        $raw=$record['cells'][$column]??'';
        if(!in_array($type,['formula','link'],true)||$raw==='')return $this->results[$key]=['value'=>$raw,'kind'=>$type,'error'=>null];
        $this->visiting[$key]=true;
        try{
            $tree=$this->tree($raw,$type==='link');
            $read=fn(array$ref)=>$this->cell($ref[1]??$table,$ref[2]??$row,$ref[3],$depth+1);
            if($type==='link'){$result=$read($tree);if($result['error']!==null)throw new DomainException($result['error']);}
            else{
                $value=Formula::evaluate($tree,static function(array$ref)use($read){$result=$read($ref);if($result['error']!==null)throw new DomainException($result['error']);if($result['kind']!=='number')throw new DomainException('Für diese Berechnung eine Zahl oder ein Zahlenergebnis verknüpfen.');return Formula::number($result['value']);},$this->budget);
                $result=['value'=>Formula::display($value),'kind'=>'number','error'=>null];
            }
        }catch(DomainException $error){$result=['value'=>'#FEHLER','kind'=>$type,'error'=>$error->getMessage()];}
        finally{unset($this->visiting[$key]);}
        // A depth error belongs to this traversal; a nearer root may still resolve.
        return $result['error']===null?($this->results[$key]=$result):$result;
    }
    public function rows(array $rows):array
    {
        foreach($rows as&$row){$row['display_cells']=[];$row['cell_errors']=[];$row['cell_types']=[];
            foreach($this->sources[$this->table]['columns']as$i=>$column){
                try{$value=$this->cell($this->table,$row['id'],$i,0,true);}catch(DomainException $error){$value=['value'=>'#FEHLER','kind'=>$column['type'],'error'=>$error->getMessage()];}
                $row['display_cells'][]=$value['value'];$row['cell_errors'][]=$value['error'];$row['cell_types'][]=$value['kind'];
            }
        }unset($row);return $rows;
    }
}
