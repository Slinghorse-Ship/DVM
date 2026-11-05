<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\{Access,Db};

/** Small model metadata pages; complete original definitions are loaded only for selection. */
final class ModelCatalogue
{
    public const SIZE=6;
    public function __construct(private readonly \PDO $db,private readonly Access $access){}
    public static function state(array $query):array
    {
        $kind=$query['model_kind']??'field';$search=$query['model_q']??'';$page=$query['model_page']??1;$key=$query['model_key']??'';$new=$query['model_new']??'0';
        if(!in_array($kind,['field','template'],true)||!is_string($search)||mb_strlen($search)>100||!is_string($key)||!in_array($new,['0','1'],true)||(!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000001)throw new \InvalidArgumentException('Modellauswahl prüfen.');
        if($key!=='')CustomFields::key($key);if($new==='1'&&$key!=='')throw new \InvalidArgumentException('Neues oder vorhandenes Modell auswählen.');
        return ['model_kind'=>$kind,'model_q'=>trim($search),'model_page'=>(int)$page,'model_key'=>$key,'model_new'=>$new];
    }
    public function listing(array $query=[]):array
    {
        $this->access->require('website','manage');$this->access->require('website','read');$state=self::state($query);$parameters=[];
        if($state['model_kind']==='field'){
            [$filter,$parameters]=FieldPolicy::definitionFilter($this->db,$this->access,'d');
            $source="SELECT d.definition_key AS model_key,JSON_UNQUOTE(JSON_EXTRACT(d.definition_json,'$.label')) AS label,JSON_UNQUOTE(JSON_EXTRACT(d.definition_json,'$.type')) AS model_type,d.version FROM khcms_definitions d WHERE $filter";
        }else{
            $source="SELECT d.definition_key AS model_key,JSON_UNQUOTE(JSON_EXTRACT(d.definition_json,'$.label')) AS label,JSON_UNQUOTE(JSON_EXTRACT(d.definition_json,'$.renderer')) AS model_type,d.version FROM khcms_definitions d WHERE d.club_id=? AND d.kind='template'";$parameters=[$this->access->club()];$model=new DataModel($this->db,$this->access);
            foreach(Templates::all()as$key=>$unused){$builtin=$model->template($key);$source.=" UNION ALL SELECT ?,?,?,0 WHERE NOT EXISTS(SELECT 1 FROM khcms_definitions b WHERE b.club_id=? AND b.kind='template' AND b.definition_key=?)";array_push($parameters,$key,$builtin['label'],$builtin['renderer'],$this->access->club(),$key);}
        }
        $where='';if($state['model_q']!==''){$pattern='%'.strtr(mb_strtolower($state['model_q']),['='=>'==','%'=>'=%','_'=>'=_']).'%';$where=" WHERE (LOWER(m.label) LIKE ? ESCAPE '=' OR LOWER(m.model_key) LIKE ? ESCAPE '=')";array_push($parameters,$pattern,$pattern);}
        $total=(int)Db::run($this->db,'SELECT COUNT(*) FROM ('.$source.') m'.$where,$parameters)->fetchColumn();$last=max(1,(int)ceil($total/self::SIZE));$state['model_page']=min($state['model_page'],$last);$offset=($state['model_page']-1)*self::SIZE;
        $rows=Db::run($this->db,'SELECT m.model_key,m.label,m.model_type,m.version FROM ('.$source.') m'.$where.' ORDER BY m.label,m.model_key LIMIT '.self::SIZE.' OFFSET '.$offset,$parameters)->fetchAll();
        foreach($rows as&$row){$row['version']=(int)$row['version'];$row['key']=$row['model_key'];$row['type']=$row['model_type'];unset($row['model_key'],$row['model_type']);}unset($row);
        return [...$state,'total'=>$total,'pages'=>$last,'size'=>self::SIZE,'items'=>$rows];
    }
    public function selected(string $kind,string $key):array
    {
        $this->access->require('website','manage');$this->access->require('website','read');$state=self::state(['model_kind'=>$kind,'model_key'=>$key]);if($key==='')throw new \InvalidArgumentException('Vorhandenes Modell auswählen.');$model=new DataModel($this->db,$this->access);
        try{$definition=$kind==='field'?$model->field($key):$model->template($key);}catch(\RuntimeException){throw new \DomainException('Modell nicht verfügbar.');}
        $fields=$kind==='field'?[$definition]:$model->templateFields($definition);foreach($fields as$field)$this->assertReadable($field);return $definition;
    }
    private function assertReadable(array $field):void
    {
        if(!FieldPolicy::canRead($this->db,$this->access,$field))throw new \DomainException('Modell nicht vollständig lesbar.');foreach($field['item_definitions']??[]as$child)$this->assertReadable($child);
    }
}
