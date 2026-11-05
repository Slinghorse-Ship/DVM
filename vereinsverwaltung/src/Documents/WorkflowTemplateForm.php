<?php declare(strict_types=1);
namespace Kulturhus\Verein\Documents;
use Kulturhus\Verein\DocumentWorkflow\{WorkflowException,WorkflowStore};

/** Complete, transient form intentions. Field operations never persist a template. */
final class WorkflowTemplateForm
{
    public static function raw(array $post):array
    {
        $rows=$post['wf_fields']??[];
        if(self::text($post,'wf_template_complete')!=='1'||!is_array($rows)||!array_is_list($rows)||count($rows)>100||!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',self::text($post,'wf_field_count'))||(int)self::text($post,'wf_field_count')!==count($rows))throw new \DomainException('Vorlagenformular nicht vollständig übertragen. Eingaben prüfen.');
        if(array_diff(['wf_id','wf_version','wf_title','wf_description'],array_keys($post)))throw new \DomainException('Vorlagenformular nicht vollständig übertragen. Eingaben prüfen.');
        foreach(['wf_id','wf_version','wf_title','wf_description']as$key)self::text($post,$key);
        if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',self::text($post,'wf_version')))throw new \DomainException('Die Formularversion ist ungültig. Bitte die Ansicht neu öffnen.');
        foreach($rows as$row){
            if(!is_array($row)||array_diff(['key','label','type','help'],array_keys($row)))throw new \DomainException('Vorlagenfeld nicht vollständig übertragen. Eingaben prüfen.');
            if(array_diff(array_keys($row),['key','label','type','help','required','options','matrix_profile','origin_key','replace_options']))throw new \DomainException('Vorlagenfelder prüfen.');
            foreach($row as$key=>$value)self::text($row,$key);
            if(!in_array(self::text($row,'required'),['','1'],true)||!in_array(self::text($row,'replace_options'),['','1'],true))throw new \DomainException('Pflichtangabe im Vorlagenfeld prüfen.');
            if(self::text($row,'type')==='select'&&!array_key_exists('options',$row))throw new \DomainException('Auswahlmöglichkeiten nicht vollständig übertragen. Eingaben prüfen.');
            $origin=self::text($row,'origin_key');if($origin!==''&&!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$origin))throw new \DomainException('Ursprüngliche Feldkennung prüfen.');
        }
        return $rows;
    }
    public static function compose(WorkflowStore $store,array $post):array
    {
        if($store->actor()['access']!=='write')throw new WorkflowException('Für diese Aktion fehlen Schreibrechte im Dokumentenmodul.');
        $rows=self::raw($post);$id=self::text($post,'wf_id');
        if($id!=='')$store->template($id);
        elseif(self::text($post,'wf_creation_complete')!=='1'||!preg_match('/\A[a-f0-9]{32}\z/D',self::text($post,'wf_request')))throw new \DomainException('Anlegeformular nicht vollständig übertragen. Eingaben prüfen.');
        $command=self::text($post,'wf_edit');
        if($command==='add'){
            if(count($rows)>=100)throw new \DomainException('Eine Vorlage darf höchstens 100 Felder enthalten.');
            $keys=array_column($rows,'key');$number=count($rows)+1;while(in_array('feld_'.$number,$keys,true))$number++;
            $rows[]=['key'=>'feld_'.$number,'label'=>'','type'=>'text','help'=>'','options'=>'','matrix_profile'=>'','origin_key'=>''];$focus=count($rows)-1;$message='Formularfeld hinzugefügt. Änderungen bitte speichern.';
        }else{
            if(!preg_match('/\A(remove|up|down):(0|[1-9][0-9]?)\z/D',$command,$match)||!array_key_exists((int)$match[2],$rows))throw new \DomainException('Feldaktion prüfen.');
            $index=(int)$match[2];
            if($match[1]==='remove'){array_splice($rows,$index,1);$focus=min($index,count($rows)-1);$message='Formularfeld aus dem Arbeitsstand entfernt. Änderungen bitte speichern.';}
            else{$other=$index+($match[1]==='up'?-1:1);if(!array_key_exists($other,$rows))throw new \DomainException('Feldaktion prüfen.');[$rows[$index],$rows[$other]]=[$rows[$other],$rows[$index]];$focus=$other;$message='Feldreihenfolge geändert. Änderungen bitte speichern.';}
        }
        $post['wf_fields']=$rows;$post['wf_field_count']=(string)count($rows);$post['action']='document-workflow-template-compose';unset($post['wf_edit']);
        return ['post'=>$post,'message'=>$message,'focus'=>$focus];
    }
    public static function data(array $post,?array $previous):array
    {
        $rows=self::raw($post);$originals=array_column($previous['fields']??[],null,'key');$fields=[];
        foreach($rows as$row){
            $origin=array_key_exists('origin_key',$row)?self::text($row,'origin_key'):self::text($row,'key');$original=$originals[$origin]??null;
            if(array_key_exists('origin_key',$row)&&$origin!==''&&$original===null)throw WorkflowException::conflict('Das ursprüngliche Vorlagenfeld ist inzwischen geändert. Aktuelle Fassung öffnen und vergleichen.');
            $type=self::text($row,'type');$field=['key'=>trim(self::text($row,'key')),'label'=>trim(self::originalText(self::text($row,'label'),$original['label']??null)),'type'=>$type,'required'=>self::text($row,'required')==='1','help'=>self::originalText(self::text($row,'help'),$original['help']??null)];
            if($type==='select'){
                $options=self::text($row,'options');$oldOptions=($original['type']??'')==='select'?$original['options']:null;
                if($oldOptions!==null&&self::text($row,'replace_options')!=='1'&&self::lines(implode("\n",$oldOptions))===self::lines($options))$field['options']=$oldOptions;
                else{
                    if($oldOptions!==null&&self::multilineOptions($oldOptions)&&self::text($row,'replace_options')!=='1')throw new \DomainException('Mehrzeilige ursprüngliche Auswahlmöglichkeiten vollständig ersetzen bestätigen.');
                    $field['options']=array_values(array_filter(array_map('trim',preg_split('/\R/u',$options)?:[]),static fn(string $value):bool=>$value!==''));
                }
            }
            $profile=self::text($row,'matrix_profile');
            if($profile!==''&&!($type!=='risk_register'&&($original['type']??'')==='risk_register'&&($original['matrix_profile']??'')===$profile))$field['matrix_profile']=$profile;
            $fields[]=$field;
        }
        return ['title'=>self::originalText(self::text($post,'wf_title'),$previous['title']??null),'description'=>self::originalText(self::text($post,'wf_description'),$previous['description']??null),'fields'=>$fields,'provenance'=>$previous['provenance']??[]];
    }
    public static function multilineOptions(array $options):bool
    {
        foreach($options as$option)if(str_contains($option,"\n")||str_contains($option,"\r"))return true;return false;
    }
    private static function originalText(string $value,?string $original):string{return $original!==null&&self::lines($original)===self::lines($value)?$original:$value;}
    private static function lines(string $value):string{return str_replace(["\r\n","\r"],"\n",$value);}
    private static function text(array $data,string $key):string
    {
        if(!array_key_exists($key,$data))return '';
        if(!is_string($data[$key])||preg_match('//u',$data[$key])!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$data[$key]))throw new \DomainException('Vorlagenfelder prüfen.');
        return $data[$key];
    }
}
