<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use DomainException;
use PDO;
use Kulturhus\Verein\{TaxAutoRequests,TaxAutoPreparation,TaxFilings,Permissions};
/** Complete raw form and explicit source context, shared by script and no-script operation. */
final class TaxAutoDraftForm
{
    public const COMPLETE='tax_draft_complete';
    public const ACTIONS=['tax-draft-save','tax-draft-period','tax-draft-rebase','tax-draft-package','tax-draft-create'];
    public static function requested(array $post):bool{return array_key_exists(self::COMPLETE,$post)||(is_string($post['action']??null)&&in_array($post['action'],array_merge(self::ACTIONS,['tax-auto-save','tax-auto-create']),true));}
    public static function source(array $query):int
    {
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='tax'||($query['tax_auto']??null)!=='1'||array_intersect(['tax_workpapers','balance','tax_filings','tax_review','tax_saved','tax_resume','tax_auto_package_outcome','tax_draft_outcome'],array_keys($query)))throw new DomainException('Steuerentwurf im ursprünglichen Finanzbereich erneut öffnen.',409);
        $year=$query['tax_year']??null;if(!is_string($year)||!preg_match('/\A[0-9]{4}\z/D',$year)||(int)$year<2000||(int)$year>(int)date('Y'))throw new DomainException('Ursprüngliches Erklärungsjahr vollständig prüfen.',422);return(int)$year;
    }
    public static function original(array $post):array{return array_intersect_key($post,array_flip(['action','tax_year','from_year','to_year','activity_report','tax_documents','tax_year_notes','tax_guide','auto_answers','answers','tax_draft_token','tax_draft_key','tax_step','annual_tax_attachment_search','annual_tax_attachment_page','annual_tax_browse','annual_tax_rows','environment','synthetic',self::COMPLETE]));}
    public static function fields(array $post):array
    {
        if(($post[self::COMPLETE]??null)!=='1')throw new DomainException('Steuerformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        foreach(['tax_year','tax_draft_token','tax_draft_key','from_year','to_year','activity_report']as$key)if(!is_string($post[$key]??null))throw new DomainException('Steuerformular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        if(!preg_match('/\A[0-9]{4}\z/D',$post['tax_year'])||$post['to_year']!==$post['tax_year']||!preg_match('/\A[a-f0-9]{64}\z/D',$post['tax_draft_token'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['tax_draft_key']))throw new DomainException('Ursprünglichen Steuerentwurf vollständig prüfen.',422);
        foreach(['answers','auto_answers']as$key){if(!is_array($post[$key]??null))throw new DomainException('Steuerfragen vollständig prüfen.',422);foreach($post[$key]as$value)if(!is_string($value))throw new DomainException('Steuerfragen vollständig prüfen.',422);}
        if(array_diff_key($post['answers'],\Kulturhus\Verein\TaxFilingScope::questions())||array_diff_key($post['auto_answers'],\Kulturhus\Verein\TaxAutoMapping2025::questions()))throw new DomainException('Steuerfragen vollständig prüfen.',422);
        foreach(['tax_step','environment','synthetic']as$key)if(array_key_exists($key,$post)&&!is_string($post[$key]))throw new DomainException('Steuerschritt vollständig prüfen.',422);
        $action=$post['action']??null;$row=array_key_exists('annual_tax_rows',$post);$browse=array_key_exists('annual_tax_browse',$post);
        if(!$row&&!$browse&&(!is_string($action)||!in_array($action,self::ACTIONS,true)))throw new DomainException('Steuerschritt vollständig prüfen.',422);
        $annual=$post;$annual[AnnualTaxForm::COMPLETE]='1';if(!$row&&!$browse)$annual['action']='tax-years';AnnualTaxForm::fields($annual);
        return self::original($post);
    }
    public static function open(PDO $db,string $actor,int $year,array &$session):array
    {
        $workspace=(new TaxAutoRequests($db,$actor))->workspace($year);$token=bin2hex(random_bytes(32));$key=bin2hex(random_bytes(16));$session['tax_draft_contexts']??=[];
        while(count($session['tax_draft_contexts'])>=80)array_shift($session['tax_draft_contexts']);
        $session['tax_draft_contexts'][$token]=['context'=>$workspace['context'],'key'=>$key,'issued'=>time(),'source_hash'=>(new TaxAutoPreparation($db,$actor))->preview($year)['hash']];
        return['workspace'=>$workspace,'fields'=>$workspace['case']['input']+['answers'=>$workspace['profile']['scope']['answers'],'tax_year'=>(string)$year,'tax_draft_token'=>$token,'tax_draft_key'=>$key,self::COMPLETE=>'1']];
    }
    public static function handle(PDO $db,string $actor,array $query,array $post,string $csrf,array &$session):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        $year=self::source($query);$fields=self::fields($post);if($fields['tax_year']!==(string)$year)throw new DomainException('Ursprünglichen Steuerentwurf vollständig prüfen.',409);
        try{Permissions::finance($db,$actor,false);}catch(DomainException$e){throw new DomainException($e->getMessage(),403,$e);}
        $source=$session['tax_draft_contexts'][$fields['tax_draft_token']]??null;
        if(!is_array($source)||($source['key']??null)!==$fields['tax_draft_key']||($source['context']['actor_id']??null)!==$actor||($source['context']['year']??null)!==$year)throw new DomainException('Ursprünglicher Steuerstand passt nicht. Zuerst den Ausgang mit derselben Kennung lesen.',409);
        $service=new TaxAutoRequests($db,$actor);$values=array_intersect_key($fields,array_flip(['from_year','to_year','activity_report','tax_documents','tax_year_notes','tax_guide','auto_answers']));foreach(['tax_documents','tax_year_notes','tax_guide']as$key)$values[$key]??=[];
        $input=['operation'=>'draft','context'=>$source['context'],'values'=>$values,'answers'=>$fields['answers']];
        $action=$fields['action']??'';
        if($action==='tax-draft-create'){$input['operation']='filing';$input['source_hash']=$source['source_hash']??'';$input['environment']=$fields['environment']??'production';$input['synthetic']=($fields['synthetic']??'')==='1';}
        if($action==='tax-draft-create'&&$service->receipt($fields['tax_draft_key'])!==null)return['fields'=>$fields,'stored'=>$service->prepareFiling($input,$fields['tax_draft_key'])];
        // A known accepted save is read before expiry, source changes or current normalisation.
        if($action==='tax-draft-save'&&$service->receipt($fields['tax_draft_key'])!==null)return['fields'=>$fields,'stored'=>$service->save($input,$fields['tax_draft_key'])];
        if(time()-($source['issued']??0)>14400)throw new DomainException('Ursprünglicher Steuerstand ist abgelaufen. Zuerst den Ausgang lesen und Angaben ausdrücklich vergleichen.',409);
        if(isset($fields['annual_tax_rows'])){
            $parts=explode(':',$fields['annual_tax_rows']);$notes=$fields['tax_year_notes']??[];
            if($parts[0]==='remove-year'){if(!array_key_exists($parts[1],$notes))throw new DomainException('Ursprüngliche Jahresangaben erneut prüfen.',409);unset($notes[$parts[1]]);}
            else{$y=$parts[2];if(!array_key_exists($y,$notes))throw new DomainException('Ursprüngliche Jahresangaben erneut prüfen.',409);$kind=$parts[1];$rows=$notes[$y][$kind]??[];if($parts[0]==='add'){if(count($rows)>=($kind==='reserves'?10:20))throw new DomainException('Die höchstmögliche Anzahl von Jahreszeilen ist erreicht.',422);$rows[]=[];}else{$i=(int)$parts[3];if(!array_key_exists($i,$rows))throw new DomainException('Ursprüngliche Jahreszeile erneut prüfen.',409);array_splice($rows,$i,1);}$notes[$y][$kind]=$rows;}$fields['tax_year_notes']=$notes;return compact('fields');
        }
        if(isset($fields['annual_tax_browse'])){$fields['annual_tax_attachment_page']=$fields['annual_tax_browse'];return compact('fields');}
        if($action==='tax-draft-period'){
            $current=$service->workspace($year,(int)$fields['from_year']);if($current['context']['case_version']!==$source['context']['case_version']||$current['context']['profile_version']!==$source['context']['profile_version'])throw new DomainException('Steuerentwurf, Steuerprofil oder Bericht wurde inzwischen geändert. Ganze Angaben mit dem aktuellen Stand vergleichen.',409);
            $session['tax_draft_contexts'][$fields['tax_draft_token']]['context']=$current['context'];$session['tax_draft_contexts'][$fields['tax_draft_token']]['source_hash']=(new TaxAutoPreparation($db,$actor))->preview($year)['hash'];return compact('fields');
        }
        if($action==='tax-draft-rebase'){
            $current=$service->workspace($year,(int)$fields['from_year']);$token=bin2hex(random_bytes(32));$key=bin2hex(random_bytes(16));while(count($session['tax_draft_contexts'])>=80)array_shift($session['tax_draft_contexts']);$session['tax_draft_contexts'][$token]=['context'=>$current['context'],'key'=>$key,'issued'=>time(),'source_hash'=>(new TaxAutoPreparation($db,$actor))->preview($year)['hash']];$fields['tax_draft_token']=$token;$fields['tax_draft_key']=$key;return['fields'=>$fields,'compared'=>true];
        }
        if($action==='tax-draft-save')return['fields'=>$fields,'stored'=>$service->save($input,$fields['tax_draft_key'])];
        // Continuing always submits the entire form, including without JavaScript.
        $current=$service->workspace($year,(int)$fields['from_year']);if($current['context']!==$source['context']||!TaxAutoRequests::sameDraft($year,$values,$current['case']['input'])||\Kulturhus\Verein\TaxFilingScope::normalize($fields['answers'])!==$current['profile']['scope']['answers'])throw new DomainException('Geänderte Angaben zuerst als Zwischenstand speichern und das Ergebnis prüfen.',409);
        $preview=(new TaxAutoPreparation($db,$actor))->preview($year);if((int)$current['case']['version']<1)throw new DomainException('Angaben zuerst speichern.',409);
        if($action==='tax-draft-package')return['fields'=>$fields,'package'=>['csrf'=>$csrf,'action'=>'tax-auto-package-review','tax_year'=>(string)$year,'version'=>(string)$current['case']['version'],'preview_hash'=>$preview['hash'],'tax_auto_package_key'=>(new TaxAutoPreparation($db,$actor))->packageRequestKey($year,(int)$current['case']['version'],$preview['hash']),TaxAutoPackageForm::COMPLETE=>'1']];
        try{Permissions::finance($db,$actor,true);}catch(DomainException$e){throw new DomainException($e->getMessage(),403,$e);}
        return['fields'=>$fields,'stored'=>$service->prepareFiling($input,$fields['tax_draft_key'])];

    }
}
