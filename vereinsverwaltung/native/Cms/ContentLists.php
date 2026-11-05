<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Private saved queries reuse the existing append-only definition store; reads never run DDL. */
final class ContentLists
{
    use ClubPublicationLock;
    public const CELL_CHARACTERS=1000;
    public const BUILTINS=['title'=>'Titel','slug'=>'Adresse','template'=>'Vorlage','renderer'=>'Inhaltsart','status'=>'Stand','parent_id'=>'Elternseite','nav_order'=>'Reihenfolge','excerpt'=>'Kurztext','event_day'=>'Veranstaltungstag','event_start'=>'Beginn','event_start_utc'=>'Beginn (UTC)','event_end'=>'Ende (UTC)','archived'=>'Archiviert','news_date'=>'Beitragsdatum','created_at'=>'Erstellt (UTC)','updated_at'=>'Bearbeitet (UTC)','published_at'=>'Veröffentlicht (UTC)'];
    public function __construct(private PDO $db,private Access $access){}
    public function fields():array
    {
        $this->access->require('website','read');$result=self::BUILTINS;
        $fields=FieldPolicy::modelDefinitions($this->db,$this->access,array_column((new DataModel($this->db,$this->access))->fields(),null,'key'));
        foreach($fields as$field){
            if(!empty($field['multiple'])||in_array($field['type'],['password','repeater','pagetable','comments'],true))continue;
            $result['custom.'.$field['key']]=$field['label'];
        }
        return $result;
    }
    /** Filter paths may traverse repeated values and explicit readable page links. */
    public function filterFields():array
    {
        $this->access->require('website','read');$result=self::BUILTINS;
        $definitions=FieldPolicy::modelDefinitions($this->db,$this->access,array_column((new DataModel($this->db,$this->access))->fields(),null,'key'));
        $walk=function(array $fields,string $prefix,string $label,int $depth)use(&$walk,&$result):void{
            if($depth>3)return;
            foreach($fields as $field){if(in_array($field['type'],['password','comments'],true))continue;$path=$prefix.$field['key'];$name=$label.$field['label'];$result[$path]=$name;
                if($field['type']==='repeater')$walk($field['item_definitions']??[],$path.'.',$name.' → ',$depth+1);
                if(in_array($field['type'],['page-reference','pagetable'],true))foreach(['title'=>'Titel','slug'=>'Adresse','template'=>'Vorlage'] as $key=>$text)$result[$path.'.'.$key]=$name.' → '.$text;
            }
        };
        $walk($definitions,'custom.','',0);foreach(['title'=>'Titel','slug'=>'Adresse','template'=>'Vorlage'] as $key=>$text)$result['parent_id.'.$key]='Elternseite → '.$text;
        return $result;
    }
    private function allowedPath(string $path,array $fields,int $depth=0):bool
    {
        if($depth>3)return false;if(isset($fields[$path]))return true;
        $relation=(new QueryField(new DataModel($this->db,$this->access)))->relation($path);
        return $relation!==null&&isset($fields[$relation[0]])&&$this->allowedPath($relation[1],$fields,$depth+1);
    }
    public function normalize(array $input):array
    {
        if(array_diff(array_keys($input),['columns','selectors','expression','order']))throw new \InvalidArgumentException('Unbekannte Listeneinstellung.');
        $columns=$input['columns']??['title','slug','status'];$fields=$this->fields();
        if(!is_array($columns)||!array_is_list($columns)||!$columns||count($columns)>8||count(array_unique($columns,SORT_REGULAR))!==count($columns))throw new \InvalidArgumentException('1–8 eindeutige Listenspalten auswählen.');
        foreach($columns as$column)if(!is_string($column)||!isset($fields[$column]))throw new \InvalidArgumentException('Unbekannte oder geschützte Listenspalte.');
        if(array_key_exists('expression',$input)&&array_key_exists('selectors',$input))throw new \InvalidArgumentException('Entweder Suchausdruck oder Filterbedingungen angeben.');
        $selectors=array_key_exists('expression',$input)?SelectorGrammar::parse(is_string($input['expression'])?$input['expression']:throw new \InvalidArgumentException('Suchausdruck alsText angeben.')):($input['selectors']??[]);$order=$input['order']??[['field'=>'title','direction'=>'asc','case_fold'=>true]];
        if(!is_array($selectors)||!is_array($order))throw new \InvalidArgumentException('Filter und Sortierung prüfen.');
        $model=new DataModel($this->db,$this->access);(new Selectors($model))->compile($selectors);(new QueryOrder($model))->compile($order);
        // No secret/write-only or nested payloads can be requested through a saved query either.
        $filterFields=$this->filterFields();
        $validate=function(array $terms)use(&$validate,$filterFields):void{foreach($terms as$term){if(isset($term['any']))$validate($term['any']);elseif(isset($term['all']))$validate($term['all']);elseif(!$this->allowedPath($term['field']??'',$filterFields))throw new \InvalidArgumentException('Dieses Feld ist nicht für Inhaltslisten freigegeben.');}};
        $validate($selectors);foreach($order as$term)if(!$this->allowedPath($term['field']??'',$filterFields))throw new \InvalidArgumentException('Sortierfeld nicht freigegeben.');
        $result=['columns'=>$columns,'selectors'=>$selectors,'order'=>$order];
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR))>16384)throw new \InvalidArgumentException('Suchansicht ist zu groß.');return $result;
    }
    /** Selected scalar projections only: large draft bodies never enter the result set. */
    public function listing(array $input,int $page=1,int $size=25):array
    {
        if($page<1||$page>40001||$size<1||$size>100)throw new \InvalidArgumentException('Trefferseite und Listengröße prüfen.');
        return Db::atomic($this->db,function()use($input,$page,$size):array{
            $actor=$this->access->require('website','read');
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR SHARE',[$actor['club_id']])->fetchColumn();
            $config=$this->normalize($input);$model=new DataModel($this->db,$this->access);
            $selectors=new Selectors($model);$ordering=new QueryOrder($model);
            [$where,$whereArgs]=$selectors->compile($config['selectors']);[$order,$orderArgs]=$ordering->compile($config['order']);
            // Count, order and scalar page columns share ONE native JSON evaluation per original.
            $rowDocument=QueryField::usesDocument($where.$order);
            foreach($config['columns']as$column){[$expression,,,,$guard]=(new QueryField($model))->resolve($column);$rowDocument=$rowDocument||QueryField::usesDocument($expression.$guard);}
            if($rowDocument){[$where,$whereArgs]=$selectors->compile($config['selectors'],'qpage');[$order,$orderArgs]=$ordering->compile($config['order'],'qpage');}
            [$policy,$policyArgs]=TemplatePolicy::readFilter($this->db,$this->access,'p',false,'read',$rowDocument?'qpage':null);
            $base=' FROM khcms_pages p'.($rowDocument?' JOIN '.QueryField::document('p',true).' qpage ON TRUE':'').' LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE p.club_id=? AND '.PageTrash::activeSql().' AND ('.$policy.')'.($where!==''?' AND '.$where:'');
            $select=['p.id','p.version'];$selectArgs=[];
            foreach($config['columns']as$i=>$column){[$expression,$args,$type,, $guard,$guardArgs]=(new QueryField($model,'p',$rowDocument?'qpage':null))->resolve($column);if($guard!==''){$expression='CASE WHEN '.$guard.' THEN '.$expression.' ELSE NULL END';array_push($selectArgs,...$guardArgs);} $select[]=(in_array($type,['text','textarea','richtext','email','url','select'],true)?'LEFT(('.$expression.'),'.self::CELL_CHARACTERS.')':$expression).' AS c'.$i;array_push($selectArgs,...$args);}
            [$sortColumns,$sortArgs,$windowOrder]=$ordering->window($config['order'],$rowDocument?'qpage':null);
            $chosen=['chosen.id','chosen.version'];foreach($config['columns']as$i=>$_)$chosen[]='chosen.c'.$i;
            // The materialized window contains only these allowed scalar columns and scalar order keys.
            // Both windows see the identical authorized selection, even for a clamped final page.
            $sql='SELECT ranked.* FROM (SELECT /*+ NO_MERGE(chosen) */ '.implode(',',$chosen).',ROW_NUMBER() OVER(ORDER BY '.$windowOrder.') AS dvm_row,COUNT(*) OVER() AS dvm_total FROM (SELECT '.implode(',',[...$select,...$sortColumns]).$base.') chosen) ranked WHERE ((ranked.dvm_row-1) DIV '.$size.')=LEAST('.($page-1).',(ranked.dvm_total-1) DIV '.$size.') ORDER BY ranked.dvm_row LIMIT '.$size;
            $rows=QueryOrder::run($this->db,$sql,[...$selectArgs,...$sortArgs,$actor['club_id'],...$policyArgs,...$whereArgs],$ordering->requiresTextBudget())->fetchAll(PDO::FETCH_ASSOC);
            $total=$rows?(int)$rows[0]['dvm_total']:0;$last=max(1,(int)ceil($total/$size));$page=min($page,$last);
            if(($page-1)*$size>1000000)throw new \InvalidArgumentException('Treffer bitte weiter eingrenzen.');
            foreach($rows as&$row)unset($row['dvm_total'],$row['dvm_row']);unset($row);
            $this->access->require('website','read');
            return ['rows'=>$rows,'total'=>$total,'page'=>$page,'last'=>$last,'config'=>$config];
        });
    }
    public function presets():array
    {
        $actor=$this->access->require('website','read');$rows=Db::run($this->db,"SELECT definition_key,version,definition_json FROM khcms_definitions WHERE club_id=? AND kind='list' AND JSON_UNQUOTE(JSON_EXTRACT(definition_json,'$.owner'))=? AND JSON_UNQUOTE(JSON_EXTRACT(definition_json,'$.deleted'))='false' ORDER BY updated_at DESC,definition_key LIMIT 50",[$actor['club_id'],$actor['id']])->fetchAll();$result=[];
        foreach($rows as$row){$data=json_decode($row['definition_json'],true,32,JSON_THROW_ON_ERROR);if(($data['owner']??null)===$actor['id']&&empty($data['deleted']))$result[]=['id'=>$row['definition_key'],'version'=>(int)$row['version'],'name'=>$data['name']];}return $result;
    }
    public function get(string $id):array
    {
        $actor=$this->access->require('website','read');if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('Suchansicht auswählen.');
        $row=Db::run($this->db,"SELECT version,definition_json FROM khcms_definitions WHERE club_id=? AND kind='list' AND definition_key=?",[$actor['club_id'],$id])->fetch();$data=$row?json_decode($row['definition_json'],true,32,JSON_THROW_ON_ERROR):[];
        if(($data['owner']??null)!==$actor['id']||!empty($data['deleted']))throw new \DomainException('Suchansicht nicht verfügbar.');return ['id'=>$id,'version'=>(int)$row['version'],'name'=>$data['name'],'config'=>$data['config']];
    }
    public function save(?string $id,int $expected,string $name,array $input,bool $remove=false):array
    {
        $name=trim($name);if(!preg_match('//u',$name)||preg_match('/[\x00-\x1f\x7f]/',$name)||mb_strlen($name)<1||mb_strlen($name)>80||$expected<0)throw new \InvalidArgumentException('Ansichtsname und gespeicherten Stand prüfen.');
        $config=$this->normalize($input);
        return Db::atomic($this->db,function()use($id,$expected,$name,$config,$remove):array{
            $actor=$this->access->require('website','read');$this->lockClub();
            if($id!==null){$old=$this->get($id);if($expected!==$old['version'])throw new Conflict('Suchansicht wurde inzwischen geändert. Neu laden.');$version=$expected+1;}
            else{if($expected!==0||$remove)throw new \InvalidArgumentException('Neue Suchansicht prüfen.');if(count($this->presets())>=50)throw new \DomainException('Höchstens 50 eigene Suchansichten speichern.');$id=bin2hex(random_bytes(16));$version=1;}
            $json=json_encode(['owner'=>$actor['id'],'name'=>$name,'config'=>$config,'deleted'=>$remove],JSON_THROW_ON_ERROR);
            Db::run($this->db,"INSERT INTO khcms_definitions(club_id,kind,definition_key,version,definition_json,updated_by,updated_at) VALUES(?,'list',?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),definition_json=VALUES(definition_json),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)",[$actor['club_id'],$id,$version,$json,$actor['id']]);
            Db::run($this->db,"INSERT INTO khcms_definition_revisions(club_id,kind,definition_key,version,definition_json,actor_id,created_at) VALUES(?,'list',?,?,?,?,UTC_TIMESTAMP(6))",[$actor['club_id'],$id,$version,$json,$actor['id']]);
            return ['id'=>$id,'version'=>$version];
        });
    }
    public function batch(array $config,array $selection):array
    {
        $this->access->require('website','publish');if(!$selection||!array_is_list($selection)||count($selection)>25)throw new \InvalidArgumentException('1–25 Originale dieser Trefferseite auswählen.');
        return Db::atomic($this->db,function()use($config,$selection):array{
            $this->access->require('website','publish');$this->lockClub();$config=$this->normalize($config);$items=[];
            foreach($selection as$value){if(!is_string($value)||!preg_match('/^([a-f0-9]{32}):([1-9][0-9]{0,9})$/D',$value,$match))throw new \InvalidArgumentException('Gespeicherte Originalauswahl prüfen.');$items[]=['id'=>$match[1],'version'=>(int)$match[2]];}
            $ids=array_column($items,'id');if(count(array_unique($ids))!==count($ids))throw new \InvalidArgumentException('Original nur einmal auswählen.');
            // Recheck that selected originals still match the reviewed query, under the publication mutex.
            $actor=$this->access->require('website','publish');[$where,$args]=(new Selectors(new DataModel($this->db,$this->access)))->compile($config['selectors']);
            [$policy,$policyArgs]=TemplatePolicy::readFilter($this->db,$this->access,'p',false,'publish');
            $count=(int)Db::run($this->db,'SELECT COUNT(*) FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE p.club_id=? AND '.PageTrash::activeSql().' AND ('.$policy.') AND p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($where!==''?' AND '.$where:''),[$actor['club_id'],...$policyArgs,...$ids,...$args])->fetchColumn();
            if($count!==count($ids))throw new Conflict('Auswahl passt nicht mehr zur Suchansicht. Originale neu laden.');return(new PageBatches($this->db,$this->access))->create($items,'trash');
        });
    }
}
