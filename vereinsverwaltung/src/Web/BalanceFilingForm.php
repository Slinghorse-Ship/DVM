<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use PDO;
use Kulturhus\Verein\{BalanceFilingCommands,BalanceFilingActions,TaxFilings,Permissions};

/** Complete local forms use the exact rendered controls, source and personal first-result key. */
final class BalanceFilingForm
{
    public const COMPLETE='balance_local_complete';
    public const MAIN_ACTIONS=['tax-filing-balance-save','tax-filing-balance-generate','tax-filing-validate','tax-filing-balance-approve','balance-local-details','balance-local-send-review','balance-local-rebase'];
    public static function requested(array $post):bool
    {
        return array_key_exists(self::COMPLETE,$post)||array_key_exists('balance_local_token',$post)
            ||(is_string($post['action']??null)&&(str_starts_with($post['action'],'tax-filing-balance-')||str_starts_with($post['action'],'balance-local-')));
    }
    public static function source(array $query):array
    {
        if(($query['view']??null)!=='finance'||($query['tab']??null)!=='tax'||(($query['balance']??null)!=='1'&&($query['tax_filings']??null)!=='1')||array_intersect(['tax_auto','tax_workpapers','tax_review','tax_certificate','tax_filing_download','tax_local_outcome'],array_keys($query)))throw new DomainException('E-Bilanz im ursprünglichen Bilanzbereich erneut öffnen.',409);
        $source=array_intersect_key($query,array_flip(['view','tab','balance','balance_year','periods','period_id','areas','period_tax','tax_reconciliation','tax_filings','tax_year','filing','balance_local_details','balance_local_task','balance_local_page']));
        foreach($source as $value)if(!is_string($value))throw new DomainException('Ursprünglichen E-Bilanz-Bereich vollständig prüfen.',422);
        ksort($source,SORT_STRING);return $source;
    }
    public static function original(array $post):array
    {
        return array_diff_key($post,array_flip(['csrf','certificate_pin','certificate_source','certificate_revision','tax_certificate']));
    }
    public static function value(array $data,string $name):mixed
    {
        if(!preg_match('/\A([a-zA-Z_][a-zA-Z0-9_-]*)(.*)\z/D',$name,$match))return null;
        $keys=[$match[1]];if($match[2]!==''){if(!preg_match('/\A(?:\[[^\[\]]+\])+\z/D',$match[2]))return null;preg_match_all('/\[([^\[\]]+)\]/',$match[2],$parts);$keys=array_merge($keys,$parts[1]);}
        $value=$data;foreach($keys as $key){if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return $value;
    }
    private static function leaves(array $values,string $prefix=''):array
    {
        $out=[];foreach($values as $key=>$value){$name=$prefix===''?(string)$key:$prefix.'['.$key.']';if(is_array($value))$out=array_merge($out,self::leaves($value,$name));else $out[]=$name;}return $out;
    }
    public static function issue(array $workspace,array $route,array $fields,array $optional,array $bound,array $actions,string $marker,array &$session,?array $initial=null):array
    {
        $token=bin2hex(random_bytes(32));$key=bin2hex(random_bytes(16));$session['balance_local_contexts']??=[];
        while(count($session['balance_local_contexts'])>=100)array_shift($session['balance_local_contexts']);
        $session['balance_local_contexts'][$token]=['context'=>$workspace['context'],'key'=>$key,'route'=>$route,'fields'=>$fields,'optional'=>$optional,'bound'=>$bound,'actions'=>$actions,'marker'=>$marker,'issued'=>time()];
        if($initial!==null){$session['balance_local_contexts'][$token]['initial_fields']=array_keys($initial);$session['balance_local_contexts'][$token]['initial_sha256']=self::navigationHash($initial);}
        return ['balance_local_token'=>$token,'balance_local_key'=>$key];
    }
    /** Browser textarea submission normalizes line endings; original saved input remains exact. */
    public static function navigationHash(array $values):string
    {
        foreach($values as &$value)if(is_string($value))$value=str_replace(["\r\n","\r"],"\n",$value);unset($value);
        ksort($values,SORT_STRING);return hash('sha256',BalanceFilingCommands::json($values));
    }
    private static function same(mixed $left,mixed $right):bool
    {
        if(!is_array($left)||!is_array($right))return $left===$right;
        if(count($left)!==count($right))return false;
        foreach($left as $key=>$value)if(!array_key_exists($key,$right)||!self::same($value,$right[$key]))return false;
        return true;
    }
    private static function unchanged(array $filing,array $post):bool
    {
        $b=$filing['form_data']['balance_preparation'];$expected=$b['profile'];$profile=$post['profile']??null;
        if(!is_array($profile)||!is_array($post['mapping']??null))return false;
        foreach(['scope_confirmed','asset_values_unchanged','asset_tax_values_reviewed']as $key)$profile[$key]=($profile[$key]??'')==='1';
        $profile['tax_privileged']=match($profile['tax_privileged']??''){ 'yes'=>true,'no'=>false,default=>null };
        // The service owns kind, source scope and other original policy values, never hidden client copies.
        foreach($profile as $key=>$value)if(!array_key_exists($key,$expected)||!self::same($expected[$key],$value))return false;
        foreach(['business_name','supplier','company','whole_business_name','whole_book_standard']as $key)if(array_key_exists($key,$expected)&&!($key==='business_name'&&($expected['kind']??'')===\Kulturhus\Verein\BalanceTaxFiling::SUBSIDIARY)&&!array_key_exists($key,$profile))return false;
        $mapping=$b['mapping'];$posted=array_filter($post['mapping'],static fn($value)=>$value!=='');ksort($mapping);ksort($posted);if($mapping!==$posted)return false;
        if(array_key_exists('whole_mapping',$b)){$actual=$b['whole_mapping'];$posted=$post['whole_mapping']??[];if(!is_array($posted))return false;$posted=array_filter($posted,static fn($value)=>$value!=='');ksort($actual);ksort($posted);if($actual!==$posted)return false;}
        return (($post['values_reviewed']??'')==='1')===$b['review']['complete'];
    }
    public static function handle(PDO $db,string $actor,array $query,array $post,string $csrf,array &$session):array
    {
        if(!is_string($post['csrf']??null)||!hash_equals($csrf,$post['csrf']))throw new DomainException('Sitzung abgelaufen. Bitte neu anmelden; Ihre Eingaben bleiben erhalten.',403);
        $route=self::source($query);
        foreach([self::COMPLETE,'balance_local_token','balance_local_key','action']as $key)if(!is_string($post[$key]??null))throw new DomainException('E-Bilanz-Formular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        if($post[self::COMPLETE]!=='1'||!preg_match('/\A[a-f0-9]{64}\z/D',$post['balance_local_token'])||!preg_match('/\A[a-f0-9]{32}\z/D',$post['balance_local_key']))throw new DomainException('E-Bilanz-Formular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        try{Permissions::finance($db,$actor,false);}catch(DomainException $e){throw new DomainException($e->getMessage(),403,$e);}
        $source=$session['balance_local_contexts'][$post['balance_local_token']]??null;
        if(!is_array($source)||$source['key']!==$post['balance_local_key']||$source['route']!==$route||$source['context']['actor_id']!==$actor||!in_array($post['action'],$source['actions'],true))throw new DomainException('Ursprünglicher E-Bilanz-Formularstand passt nicht. Zuerst den Ausgang mit derselben Kennung lesen.',409);
        foreach($source['fields']as $name)if(!is_string(self::value($post,$name)))throw new DomainException('E-Bilanz-Formular ist unvollständig. Bekannte Angaben bleiben erhalten.',422);
        foreach($source['optional']as $name){$value=self::value($post,$name);if($value!==null&&!is_string($value))throw new DomainException('E-Bilanz-Felder vollständig prüfen.',422);}
        $allowed=array_merge($source['fields'],$source['optional'],['csrf','action',self::COMPLETE,'balance_local_token','balance_local_key','reviewed']);
        if(array_diff(self::leaves($post),$allowed))throw new DomainException('E-Bilanz-Felder vollständig prüfen.',422);
        foreach($source['bound']as $name=>$value)if(self::value($post,$name)!==$value)throw new DomainException('Ursprüngliche E-Bilanz-Auswahl wurde ersetzt. Ganze Angaben ausdrücklich vergleichen.',409);
        $c=$source['context'];$values=array_diff_key($post,array_flip(['csrf','action','filing','version','tax_year','period_id',self::COMPLETE,'balance_local_token','balance_local_key','balance_local_discard_confirmed']));
        $input=['action'=>$post['action'],'context'=>$c,'values'=>$values];$service=new BalanceFilingCommands($db,$actor);
        if(in_array($post['action'],['balance-local-specialist-back','balance-local-specialist-discard'],true)){
            if(!isset($source['initial_fields'],$source['initial_sha256'])||!array_key_exists(BalanceFilingSpecialists::TASK,$route))throw new DomainException('Fachformular mit seinem ursprünglichen Ausgang erneut öffnen.',409);
            if($post['action']==='balance-local-specialist-discard'){
                if(($post['balance_local_discard_confirmed']??null)!=='1')throw new DomainException('Das Verwerfen der eigenen ungespeicherten Angaben ausdrücklich bestätigen.',422);
            }else{
                $actual=[];foreach($source['initial_fields']as$name)$actual[$name]=self::value($post,$name);
                if(!hash_equals($source['initial_sha256'],self::navigationHash($actual)))throw new DomainException('Geänderte Fachangaben zuerst speichern oder ausdrücklich verwerfen. Die eigenen Eingaben bleiben erhalten.',409);
            }
            return ['navigate'=>BalanceFilingSpecialists::listing($route)];
        }

        if(in_array($post['action'],BalanceFilingActions::ACTIONS,true)&&$service->receipt($post['balance_local_key'])!==null)return ['stored'=>$service->run($input,$post['balance_local_key'])];
        if(time()-$source['issued']>14400)throw new DomainException('Ursprünglicher E-Bilanz-Stand ist abgelaufen. Ausgang lesen und Angaben ausdrücklich vergleichen.',409);
        if($post['action']==='balance-local-rebase'){
            $workspace=$service->workspace($c['year'],$c['filing']['id']??null,$c['period_id']);$issued=self::issue($workspace,$route,$source['fields'],$source['optional'],$source['bound'],$source['actions'],$source['marker'],$session);
            // Fassung is the only derived hidden value that is deliberately advanced.
            if(isset($session['balance_local_contexts'][$issued['balance_local_token']]['bound']['version']))$session['balance_local_contexts'][$issued['balance_local_token']]['bound']['version']=(string)$workspace['context']['filing']['version'];
            return ['fields'=>array_replace($post,$issued,['version'=>(string)($workspace['context']['filing']['version']??0)]),'compared'=>true];
        }
        if($post['action']!=='tax-filing-balance-save'&&isset($post['profile'])){
            $workspace=$service->workspace($c['year'],$c['filing']['id']??null,$c['period_id']);
            if($workspace['context']!==$c||!self::unchanged($workspace['filing'],$post))throw new DomainException('Geänderte E-Bilanz-Angaben zuerst speichern und den gespeicherten Ausgang prüfen.',409);
        }
        if($post['action']==='balance-local-details')return ['navigate'=>['view'=>'finance','tab'=>'tax','tax_filings'=>'1','tax_year'=>(string)$c['year'],'filing'=>$c['filing']['id'],'balance_local_details'=>'1']];
        if($post['action']==='balance-local-send-review')return ['fields'=>$post,'send_review'=>true];
        return ['stored'=>$service->run($input,$post['balance_local_key'])];
    }
}
