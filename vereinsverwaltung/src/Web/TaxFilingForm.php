<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{TaxFilingCommands,TaxFilings,TaxFormSchema,TaxAttachments,TaxFilingScope,Permissions};
/** A complete local form, including no-script navigation. Secrets belong to the separate send form. */
final class TaxFilingForm
{
    public const COMPLETE='tax_local_complete';
    public const ACTIONS=['tax-filing-profile','tax-filing-create','tax-filing-save','tax-filing-row','tax-filing-attachments','tax-filing-validate','tax-filing-evidence','tax-local-browse','tax-local-rebase','tax-local-send-review','tax-local-year'];
    public static function requested(array$p):bool{return array_key_exists(self::COMPLETE,$p)||array_key_exists('tax_local_create',$p)||array_key_exists('tax_local_browse',$p)||array_key_exists('tax_local_row',$p)||(is_string($p['action']??null)&&in_array($p['action'],self::ACTIONS,true));}
    public static function source(array$q):array
    {
        if(($q['view']??null)!=='finance'||($q['tab']??null)!=='tax'||($q['tax_filings']??null)!=='1'||array_intersect(['tax_auto','tax_workpapers','balance','tax_review','tax_saved','tax_resume','tax_auto_package_outcome','tax_draft_outcome','tax_local_outcome','tax_local_pdf','tax_filing_download','tax_certificate'],array_keys($q)))throw new DomainException('Steuerformular im ursprünglichen Erklärungsbereich erneut öffnen.',409);
        $year=$q['tax_year']??null;$id=$q['filing']??'';if(!is_string($year)||!preg_match('/\A[0-9]{4}\z/D',$year)||(int)$year<2000||(int)$year>(int)date('Y')||!is_string($id)||($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id)))throw new DomainException('Ursprüngliches Erklärungsjahr und Formular vollständig prüfen.',422);return['year'=>(int)$year,'id'=>$id===''?null:$id];
    }
    public static function original(array$p):array{return array_intersect_key($p,array_flip(['action','tax_year','filing','tax_local_year','tax_local_token','tax_local_key','tax_local_fields','fields','header','answers','tax_documents','tax_local_documents','tax_local_visible','tax_local_section','tax_local_page','tax_local_search','tax_local_document_page','tax_local_create','tax_local_browse','tax_local_row','data_version','environment',self::COMPLETE]));}
    public static function fields(array$p):array
    {
        if(($p[self::COMPLETE]??null)!=='1')throw new DomainException('Erklärungsformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        foreach(['tax_year','filing','tax_local_year','tax_local_token','tax_local_key','tax_local_fields','tax_local_documents','tax_local_visible','tax_local_section','tax_local_page','tax_local_search','tax_local_document_page','environment']as$key)if(!is_string($p[$key]??null))throw new DomainException('Erklärungsformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$p['tax_local_token'])||!preg_match('/\A[a-f0-9]{32}\z/D',$p['tax_local_key'])||strlen($p['tax_local_fields'])>2000000||strlen($p['tax_local_documents'])>10000||strlen($p['tax_local_search'])>200||!mb_check_encoding($p['tax_local_search'],'UTF-8'))throw new DomainException('Ursprüngliche Formularangaben vollständig prüfen.',422);
        foreach(['fields','header','answers','tax_documents']as$key){if(isset($p[$key])&&!is_array($p[$key]))throw new DomainException('Formularfelder vollständig prüfen.',422);foreach($p[$key]??[]as$value)if(!is_string($value))throw new DomainException('Formularfelder vollständig prüfen.',422);}
        foreach(['action','tax_local_create','tax_local_browse','tax_local_row']as$key)if(array_key_exists($key,$p)&&!is_string($p[$key]))throw new DomainException('Formularschritt vollständig prüfen.',422);
        if(count(array_intersect(['tax_local_create','tax_local_browse','tax_local_row'],array_keys($p)))>1)throw new DomainException('Formularschritt vollständig prüfen.',422);
        if(!isset($p['tax_local_create'])&&!isset($p['tax_local_browse'])&&!isset($p['tax_local_row'])&&!in_array($p['action']??null,self::ACTIONS,true))throw new DomainException('Formularschritt vollständig prüfen.',422);
        foreach(['tax_local_page','tax_local_document_page']as$key)if(!preg_match('/\A[1-9][0-9]{0,5}\z/D',$p[$key]))throw new DomainException('Formularseite vollständig prüfen.',422);
        try{$visible=json_decode($p['tax_local_visible'],true,32,JSON_THROW_ON_ERROR);}catch(\Throwable$e){throw new DomainException('Formularfelder vollständig prüfen.',422,$e);}if(!is_array($visible)||!array_is_list($visible)||count($visible)>50||count(array_filter($visible,'is_string'))!==count($visible))throw new DomainException('Formularfelder vollständig prüfen.',422);$posted=array_keys($p['fields']??[]);sort($posted,SORT_STRING);sort($visible,SORT_STRING);if($visible!==$posted)throw new DomainException('Erklärungsformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        if($p['filing']===''&&(array_diff_key(TaxFilingScope::questions(),$p['answers']??[])||array_diff_key($p['answers']??[],TaxFilingScope::questions())))throw new DomainException('Steuerfragen vollständig prüfen.',422);
        self::values($p);return self::original($p);
    }
    public static function values(array$p):array
    {
        try{$fields=json_decode($p['tax_local_fields'],true,128,JSON_THROW_ON_ERROR);$documents=json_decode($p['tax_local_documents'],true,32,JSON_THROW_ON_ERROR);}catch(\Throwable$e){throw new DomainException('Formularfelder vollständig prüfen.',422,$e);}
        if(!is_array($fields)||!is_array($documents)||count($fields)>12000||count($documents)>20)throw new DomainException('Formularfelder vollständig prüfen.',422);foreach($fields as$key=>$value)if(!is_string($key)||!is_string($value))throw new DomainException('Formularfelder vollständig prüfen.',422);foreach($documents as$value)if(!is_string($value))throw new DomainException('Nachweisauswahl vollständig prüfen.',422);
        $fields=array_replace($fields,$p['fields']??[]);$documents=array_values(array_unique(array_merge($documents,$p['tax_documents']??[]),SORT_STRING));sort($documents,SORT_STRING);return['fields'=>$fields,'header'=>$p['header']??[],'documents'=>$documents];
    }
    public static function choices(PDO$db,string$actor,int$year):array
    {
        $service=new TaxAttachments($db,$actor);$out=[];foreach($service->choices($year)as$row){$selection=$row['selection'];if(str_starts_with($selection,'report:')){$item=$service->select([$selection],$year)[0];}else{$item=['id'=>$row['id'],'kind'=>'document','revision'=>(int)$row['revision'],'sha256'=>$row['sha256']];}$out[$selection]=['title'=>$row['title'],'source'=>array_intersect_key($item,array_flip(['id','kind','revision','sha256']))];}return$out;
    }
    public static function issue(array$workspace,array$choices,array&$session):array
    {
        $attachmentForm=isset($workspace['state']['filing'])&&isset(TaxFormSchema::load($workspace['state']['filing']['data_version'])->profile()['attachment_version']);
        // Complete source binding stays in the hash; XML and filing snapshots belong in the journal.
        $sessionWorkspace=['context'=>$workspace['context'],'state'=>['profile'=>$workspace['state']['profile']]];
        $token=bin2hex(random_bytes(32));$key=bin2hex(random_bytes(16));$session['tax_local_contexts']??=[];while(count($session['tax_local_contexts'])>=40)array_shift($session['tax_local_contexts']);$session['tax_local_contexts'][$token]=['workspace'=>$sessionWorkspace,'choices'=>$choices,'attachments'=>$attachmentForm,'key'=>$key,'issued'=>time()];return['tax_local_token'=>$token,'tax_local_key'=>$key];
    }
    public static function open(PDO$db,string$actor,int$year,?string$id,array&$session):array
    {
        $w=(new TaxFilingCommands($db,$actor))->workspace($year,$id);$r=$w['state']['filing'];$choices=$r===null||!isset(TaxFormSchema::load($r['data_version'])->profile()['attachment_version'])?[]:self::choices($db,$actor,$year);$issued=self::issue($w,$choices,$session);$data=$r['form_data']??[];$selected=array_map(static fn($a)=>(($a['kind']??'document')==='report'?'report:':'').$a['id'],$data['attachments']??[]);
        // Existing selected revisions stay explicit even when a newer PDF exists.
        foreach($data['attachments']??[]as$item){$selection=(($item['kind']??'document')==='report'?'report:':'').$item['id'];$session['tax_local_contexts'][$issued['tax_local_token']]['choices'][$selection]=['title'=>$item['title'],'source'=>array_intersect_key($item,array_flip(['id','kind','revision','sha256']))];}
        return['workspace'=>$w,'fields'=>$issued+['tax_year'=>(string)$year,'tax_local_year'=>(string)$year,'filing'=>$id??'','tax_local_fields'=>TaxFilingCommands::json($data['fields']??[]),'fields'=>[],'header'=>$data['header']??[],'answers'=>$w['state']['profile']['scope']['answers'],'tax_local_documents'=>TaxFilingCommands::json($selected),'tax_documents'=>[],'tax_local_visible'=>'[]','tax_local_section'=>'','tax_local_page'=>'1','tax_local_search'=>'','tax_local_document_page'=>'1','environment'=>$r['environment']??'production',self::COMPLETE=>'1']];
    }
    private static function unchanged(array$w,array$values):bool
    {
        $r=$w['state']['filing'];if($r===null)return false;$data=$r['form_data'];$bound=isset($data['auto_preparation'])||isset($data['parent_binding']);$s=TaxFormSchema::load($r['data_version']);$fields=$s->normalize($values['fields'],$data['counts']);$header=$values['header'];foreach(['office','supplier']as$key)if(isset($header[$key]))$header[$key]=trim($header[$key]);$actual=$data['header'];foreach($header as$key=>$value)if(($actual[$key]??'')!==$value)return false;
        $selected=array_map(static fn($a)=>(($a['kind']??'document')==='report'?'report:':'').$a['id'],$data['attachments']??[]);sort($selected,SORT_STRING);return($bound?$fields===[]:$fields===$data['fields'])&&$selected===$values['documents'];
    }
    public static function handle(PDO$db,string$actor,array$q,array$p,string$csrf,array&$session):array
    {
        if(!is_string($p['csrf']??null)||!hash_equals($csrf,$p['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        $route=self::source($q);$fields=self::fields($p);if($fields['tax_year']!==(string)$route['year']||$fields['filing']!==($route['id']??''))throw new DomainException('Ursprüngliches Erklärungsjahr und Formular vollständig prüfen.',409);
        try{Permissions::finance($db,$actor,false);}catch(DomainException$e){throw new DomainException($e->getMessage(),403,$e);}
        $source=$session['tax_local_contexts'][$fields['tax_local_token']]??null;if(!is_array($source)||($source['key']??null)!==$fields['tax_local_key']||($source['workspace']['context']['actor_id']??null)!==$actor||($source['workspace']['context']['year']??null)!==$route['year']||($source['workspace']['context']['filing']['id']??null)!==$route['id'])throw new DomainException('Ursprünglicher Formularstand passt nicht. Zuerst den Ausgang mit derselben Kennung lesen.',409);
        $service=new TaxFilingCommands($db,$actor);$values=self::values($fields);$action=$fields['action']??'';$op=match($action){'tax-filing-profile'=>'profile','tax-filing-create'=>'create','tax-filing-save','tax-filing-row','tax-filing-attachments'=>'save','tax-filing-validate'=>'validate','tax-filing-evidence'=>'evidence',default=>null};if(isset($fields['tax_local_create']))$op='create';if(isset($fields['tax_local_row']))$op='save';
        $documents=null;$expected=[];if(($source['attachments']??false)===true){$documents=$values['documents'];foreach($documents as$id){if(!isset($source['choices'][$id]))throw new DomainException('Ursprüngliche Nachweisauswahl vollständig prüfen.',409);$expected[]=$source['choices'][$id]['source'];}}
        $command=['operation'=>$op??'save','context'=>$source['workspace']['context'],'values'=>match($op){'profile'=>['answers'=>$fields['answers']??[]],'create'=>['data_version'=>$fields['tax_local_create']??$fields['data_version']??'','environment'=>$fields['environment']],'save'=>['fields'=>$values['fields'],'header'=>$values['header'],'add_group'=>$fields['tax_local_row']??null,'documents'=>$documents]+($documents===null?[]:['document_sources'=>$expected]),default=>[]}];
        // Read an accepted local command before checking current form catalogues or expiry.
        if($op!==null&&$service->receipt($fields['tax_local_key'])!==null)return['fields'=>$fields,'stored'=>$service->run($command,$fields['tax_local_key'])];
        if(time()-($source['issued']??0)>14400)throw new DomainException('Ursprünglicher Formularstand ist abgelaufen. Ausgang lesen und Angaben ausdrücklich vergleichen.',409);
        if($action==='tax-local-rebase'){
            $w=$service->workspace($route['year'],$route['id']);$choices=$route['id']===null?[]:self::choices($db,$actor,$route['year']);$issued=self::issue($w,$choices,$session);$fields=array_replace($fields,$issued);return['fields'=>$fields,'compared'=>true];
        }
        if($action==='tax-local-year'){
            $target=$fields['tax_local_year'];if(!preg_match('/\A[0-9]{4}\z/D',$target)||(int)$target<2000||(int)$target>(int)date('Y')||$route['id']!==null)throw new DomainException('Gültiges Erklärungsjahr auswählen.',422);$current=$service->workspace($route['year']);if($current['context']!==$source['workspace']['context']||TaxFilingScope::normalize($fields['answers']??[])!==$current['state']['profile']['scope']['answers'])throw new DomainException('Geänderte Formularangaben zuerst speichern und den gespeicherten Ausgang prüfen.',409);return['fields'=>$fields,'navigate'=>['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>$target]];
        }
        if(isset($fields['tax_local_browse'])){
            if($service->workspace($route['year'],$route['id'])['context']!==$source['workspace']['context'])throw new DomainException('Steuerprofil oder Erklärung inzwischen geändert. Ganze Angaben mit dem aktuellen Stand vergleichen.',409);
            $part=explode(':',$fields['tax_local_browse'],2);if(count($part)!==2||!in_array($part[0],['section','page','documents'],true))throw new DomainException('Formularseite vollständig prüfen.',422);if($part[0]==='section'){$fields['tax_local_section']=$part[1];$fields['tax_local_page']='1';}else{if(!preg_match('/\A[1-9][0-9]{0,5}\z/D',$part[1]))throw new DomainException('Formularseite vollständig prüfen.',422);$fields[$part[0]==='page'?'tax_local_page':'tax_local_document_page']=$part[1];}$fields['tax_local_fields']=TaxFilingCommands::json($values['fields']);$fields['fields']=[];$fields['tax_local_documents']=TaxFilingCommands::json($values['documents']);$fields['tax_documents']=[];return compact('fields');
        }
        if($op==='create'&&TaxFilingScope::normalize($fields['answers']??[])!==$source['workspace']['state']['profile']['scope']['answers'])throw new DomainException('Geänderte Formularangaben zuerst speichern und den gespeicherten Ausgang prüfen.',409);
        if(in_array($action,['tax-filing-validate','tax-filing-evidence','tax-local-send-review'],true)){
            $current=$service->workspace($route['year'],$route['id']);if($current['context']!==$source['workspace']['context']||!self::unchanged($current,$values))throw new DomainException('Geänderte Formularangaben zuerst speichern und den gespeicherten Ausgang prüfen.',409);
            if($action==='tax-local-send-review')return['fields'=>$fields,'send_review'=>true];
        }
        if($op===null)throw new DomainException('Formularschritt vollständig prüfen.',422);return['fields'=>$fields,'stored'=>$service->run($command,$fields['tax_local_key'])];
    }
}
