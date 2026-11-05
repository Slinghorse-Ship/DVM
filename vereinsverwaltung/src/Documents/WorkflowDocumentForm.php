<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\DocumentWorkflow\{WorkflowStore,RiskRegister,WorkflowException};

/** Complete unsaved document intentions, bound to their immutable template fields. */
final class WorkflowDocumentForm
{
    public static function raw(array $post,array $document,bool $strict=false):array
    {
        if(self::text($post,'wf_values_complete')!=='1'||array_diff(['wf_id','wf_version','wf_title'],array_keys($post)))throw new \DomainException('Dokumentformular nicht vollständig übertragen. Eingaben prüfen.');
        foreach(['wf_id','wf_version','wf_title','wf_folder']as$key)self::text($post,$key);
        if(self::text($post,'wf_id')!==$document['id']||!preg_match('/\A[1-9][0-9]{0,9}\z/D',self::text($post,'wf_version')))throw new \DomainException('Die Formularversion ist ungültig. Bitte die Ansicht neu öffnen.');
        $shape=self::text($post,'wf_values_shape');$strict=$strict||$shape!=='';
        if($strict&&($shape!=='1'||!array_key_exists('wf_folder',$post)||self::text($post,'wf_field_count')!==(string)count($document['fields'])))throw new \DomainException('Dokumentformular nicht vollständig übertragen. Eingaben prüfen.');
        $values=$post['wf_values']??[];$counts=$post['wf_register_counts']??[];$present=$post['wf_field_present']??[];$references=$post['wf_option_refs']??[];
        if(!is_array($values)||!is_array($counts)||!is_array($present)||!is_array($references))throw new \DomainException('Formularwerte prüfen.');
        $keys=array_column($document['fields'],'key');
        foreach([$values,$counts,$present,$references]as$map)if(array_diff(array_keys($map),$keys))throw new \DomainException('Die Werte enthalten ein unbekanntes Formularfeld.');
        foreach($document['fields']as$field){
            $key=$field['key'];$type=$field['type'];
            if($strict&&self::text($present,$key)!=='1')throw new \DomainException('Dokumentfeld nicht vollständig übertragen. Eingaben prüfen.');
            if($type==='risk_register'){
                $rows=$values[$key]??[];
                if(!is_array($rows)||!array_is_list($rows)||count($rows)>RiskRegister::MAX_ROWS||($strict&&self::text($counts,$key)!==(string)count($rows)))throw new \DomainException('Gefährdungsregister nicht vollständig übertragen. Eingaben prüfen.');
                foreach($rows as$row){
                    $required=[...array_keys(RiskRegister::TEXT_LIMITS),'initial','residual',...($strict?[...array_keys(RiskRegister::DATE_LABELS),'__origin']:[])];
                    if(!is_array($row)||array_diff($required,array_keys($row))||array_diff(array_keys($row),[...array_keys(RiskRegister::COLUMN_LABELS),...array_keys(RiskRegister::DATE_LABELS),'__origin']))throw new \DomainException('Registerzeile nicht vollständig übertragen. Eingaben prüfen.');
                    foreach([...array_keys(RiskRegister::TEXT_LIMITS),...array_keys(RiskRegister::DATE_LABELS)]as$column)self::text($row,$column);
                    self::rawRisk($row['initial'],$strict);self::rawRisk($row['residual'],$strict);
                    if(array_key_exists('__origin',$row)&&self::text($row,'__origin')!==''&&!preg_match('/\A(?:0|[1-9]|[12][0-9])\z/D',self::text($row,'__origin')))throw new \DomainException('Ursprüngliche Registerzeile prüfen.');
                }
                continue;
            }
            if($type==='risk_matrix'){if(!array_key_exists($key,$values))throw new \DomainException('Dokumentfeld nicht vollständig übertragen. Eingaben prüfen.');self::rawRisk($values[$key],$strict);continue;}
            if($type!=='checkbox'&&!array_key_exists($key,$values))throw new \DomainException('Dokumentfeld nicht vollständig übertragen. Eingaben prüfen.');
            $value=self::text($values,$key);
            if($type==='checkbox'&&!in_array($value,['','1'],true))throw new \DomainException('Bestätigungsfeld prüfen.');
            if(array_key_exists($key,$references)&&($type!=='select'||self::text($references,$key)!=='1'))throw new \DomainException('Ursprüngliche Auswahl prüfen.');
        }
        return $values;
    }
    public static function compose(WorkflowStore $store,array $post):array
    {
        $document=$store->editingDocument(self::text($post,'wf_id'));$values=self::raw($post,$document,true);$command=self::text($post,'wf_edit');$focus=null;
        if($command==='preview')$message='Vorschau des ungespeicherten Formulars. Es wurde nichts gespeichert.';
        else{
            if(!preg_match('/\Aregister_(add|remove|up|down):([a-z][a-z0-9_]{0,63})(?::(0|[1-9]|[12][0-9]))?\z/D',$command,$match))throw new \DomainException('Registeraktion prüfen.');
            $field=array_column($document['fields'],null,'key')[$match[2]]??null;if(($field['type']??'')!=='risk_register')throw new \DomainException('Registeraktion prüfen.');$key=$match[2];$rows=$values[$key]??[];
            if($match[1]==='add'){
                if(isset($match[3])||count($rows)>=RiskRegister::MAX_ROWS)throw new \DomainException('Das Gefährdungsregister erlaubt höchstens 30 Zeilen.');
                $row=array_fill_keys([...array_keys(RiskRegister::TEXT_LIMITS),...array_keys(RiskRegister::DATE_LABELS),'__origin'],'');$row['initial']=['likelihood'=>'','severity'=>''];$row['residual']=['likelihood'=>'','severity'=>''];$rows[]=$row;$focus=['field'=>$key,'row'=>count($rows)-1];$message='Gefährdungszeile hinzugefügt. Entwurf bitte speichern.';
            }else{
                if(!isset($match[3])||!array_key_exists((int)$match[3],$rows))throw new \DomainException('Registeraktion prüfen.');$index=(int)$match[3];
                if($match[1]==='remove'){array_splice($rows,$index,1);$focus=['field'=>$key,'row'=>min($index,count($rows)-1)];$message='Gefährdungszeile aus dem Arbeitsstand entfernt. Entwurf bitte speichern.';}
                else{$other=$index+($match[1]==='up'?-1:1);if(!array_key_exists($other,$rows))throw new \DomainException('Registeraktion prüfen.');[$rows[$index],$rows[$other]]=[$rows[$other],$rows[$index]];$focus=['field'=>$key,'row'=>$other];$message='Zeilenreihenfolge geändert. Entwurf bitte speichern.';}
            }
            $values[$key]=$rows;$post['wf_register_counts'][$key]=(string)count($rows);
        }
        $post['wf_values']=$values;$post['action']='document-workflow-document-compose';unset($post['wf_edit']);return ['post'=>$post,'message'=>$message,'focus'=>$focus,'preview'=>$command==='preview'];
    }
    public static function data(array $post,array $document):array
    {
        $raw=self::raw($post,$document);$values=[];$references=$post['wf_option_refs']??[];
        foreach($document['fields']as$field){
            $key=$field['key'];$old=$document['values'][$key]??null;$hasOld=array_key_exists($key,$document['values']);$type=$field['type'];
            if($type==='risk_register'){
                $rows=[];foreach($raw[$key]??[]as$index=>$row){$origin=array_key_exists('__origin',$row)?self::text($row,'__origin'):(string)$index;if(array_key_exists('__origin',$row)&&$origin!==''&&(!is_array($old)||!array_key_exists((int)$origin,$old)))throw WorkflowException::conflict('Die ursprüngliche Registerzeile ist inzwischen geändert. Aktuelle Fassung öffnen und vergleichen.');$original=$origin!==''?($old[(int)$origin]??[]):[];$item=[];
                    foreach(array_keys(RiskRegister::TEXT_LIMITS)as$column)$item[$column]=self::originalText(self::text($row,$column),$original[$column]??null);
                    $item['initial']=self::risk($row['initial']);$item['residual']=self::risk($row['residual']);
                    foreach(array_keys(RiskRegister::DATE_LABELS)as$column){$date=self::text($row,$column);if($date!==''||(array_key_exists($column,$original)&&$original[$column]===''))$item[$column]=$date;}
                    $rows[]=$item;
                }
                if($hasOld||$rows!==[])$values[$key]=$rows;continue;
            }
            if($type==='risk_matrix'){$value=self::risk($raw[$key]);if($value===null&&$hasOld&&$old==='')$value='';}
            elseif($type==='checkbox'){$value=self::text($raw,$key)==='1';if(!$value&&$hasOld&&($old===null||$old===''))$value=$old;}
            else{
                $value=self::text($raw,$key);
                if($type==='select'&&isset($references[$key])&&$value!==''){
                    if(!preg_match('/\Ao:(0|[1-9][0-9]?)\z/D',$value,$match)||!array_key_exists((int)$match[1],$field['options']))throw new \DomainException('Ursprüngliche Auswahl prüfen.');$value=$field['options'][(int)$match[1]];
                }
                elseif(in_array($type,['text','textarea'],true))$value=self::originalText($value,is_string($old)?$old:null);
                if($value===''&&$hasOld&&$old===null)$value=null;
                if($type==='number'&&$hasOld&&is_scalar($old)&&(string)$old===$value)$value=$old;
            }
            if($hasOld||!in_array($value,[null,'',false],true))$values[$key]=$value;
        }
        return ['values'=>$values,'title'=>self::originalText(self::text($post,'wf_title'),$document['title']),'folder'=>array_key_exists('wf_folder',$post)?self::text($post,'wf_folder'):(string)($document['folder_id']??'')];
    }
    private static function rawRisk(mixed $value,bool $strict):void
    {
        if(!is_array($value)||($strict&&array_diff(['likelihood','severity'],array_keys($value)))||array_diff(array_keys($value),['likelihood','severity']))throw new \DomainException('Risikofeld nicht vollständig übertragen. Eingaben prüfen.');
        foreach(['likelihood','severity']as$axis)if(!preg_match('/\A(?:[1-5])?\z/D',self::text($value,$axis)))throw new \DomainException('Für die Risikomatrix beide Stufen von 1 bis 5 wählen oder beide leer lassen.');
    }
    private static function risk(array $value):?array
    {
        $likelihood=self::text($value,'likelihood');$severity=self::text($value,'severity');if($likelihood===''&&$severity==='')return null;
        if($likelihood===''||$severity==='')throw new \DomainException('Für die Risikomatrix beide Stufen von 1 bis 5 wählen oder beide leer lassen.');return ['likelihood'=>(int)$likelihood,'severity'=>(int)$severity];
    }
    private static function originalText(string $value,?string $original):string{return $original!==null&&str_replace(["\r\n","\r"],"\n",$original)===str_replace(["\r\n","\r"],"\n",$value)?$original:$value;}
    private static function text(array $input,string $key):string
    {
        if(!array_key_exists($key,$input))return '';if(!is_string($input[$key])||preg_match('//u',$input[$key])!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$input[$key]))throw new \DomainException('Formularwerte prüfen.');return $input[$key];
    }
}
