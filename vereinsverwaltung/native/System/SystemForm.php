<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
/** Exact non-secret request values, separate from freshly read schedule defaults. */
final class SystemForm
{
    public const FIELDS=['action','task','version','enabled','interval','event'];
    public const QUERY=['system_section','journal_state','journal_level','journal_page','run_task','run_state','run_page','migration_page','receipt_after'];
    public static function capture(array $post):array
    {
        $fields=[];foreach(self::FIELDS as$key){$present=array_key_exists($key,$post);$fields[$key]=['present'=>$present,'value'=>$present?$post[$key]:null];}
        $allowed=match($post['action']??null){'system-task-save'=>['action','csrf','task','version','enabled','interval'],'system-task-request'=>['action','csrf','task','version'],'system-notice-ack'=>['action','csrf','event'],default=>['csrf']};
        return ['original'=>true,'fields'=>$fields,'extra_names'=>array_values(array_diff(array_keys($post),$allowed))];
    }
    public static function value(array $draft,string $key):mixed{return $draft['fields'][$key]['value']??null;}
    public static function representable(array $draft):bool
    {
        if(!($draft['original']??false)||($draft['extra_names']??[])!==[]||self::value($draft,'action')!=='system-task-save')return false;
        $task=self::value($draft,'task');$version=self::value($draft,'version');$interval=self::value($draft,'interval');
        if(!is_string($task)||!isset(Catalog::TASKS[$task])||!is_string($version)||!preg_match('/^[0-9]{1,10}$/D',$version)||(float)$version>2147483646||!is_string($interval)||!preg_match('/^[0-9]{1,10}$/D',$interval)||!isset(Catalog::INTERVALS[(int)$interval])||(string)(int)$interval!==$interval)return false;
        if($draft['fields']['event']['present']??false)return false;
        if($draft['fields']['enabled']['present']??false){$enabled=self::value($draft,'enabled');if(!is_string($enabled)||!in_array($enabled,['0','1'],true))return false;}
        return true;
    }
    public static function query(array $query):array
    {
        $value=static fn(string $key,mixed $default):mixed=>array_key_exists($key,$query)?$query[$key]:$default;
        $section=$value('system_section','tasks');if(!is_string($section)||!isset(View::SECTIONS[$section]))throw new RequestRejected('Systembereich auswählen.');
        $out=['system_section'=>$section];
        if($section==='journal'){
            $state=$value('journal_state','all');$level=$value('journal_level','all');$page=$value('journal_page',1);
            if(!is_string($state)||!in_array($state,['all','unread','acknowledged'],true)||!is_string($level)||!in_array($level,['all','info','warning','error'],true))throw new RequestRejected('Systemhinweise filtern.');
            Journal::pageNumber($page);$out+=['journal_state'=>$state,'journal_level'=>$level,'journal_page'=>$page];
        }elseif($section==='runs'){
            $task=$value('run_task','');$state=$value('run_state','all');$page=$value('run_page',1);
            if(!is_string($task)||($task!==''&&!isset(Catalog::TASKS[$task]))||!is_string($state)||!in_array($state,['all','running','completed','failed','abandoned','cancelled'],true))throw new RequestRejected('Lokalen Laufverlauf filtern.');
            Journal::pageNumber($page);$out+=['run_task'=>$task,'run_state'=>$state,'run_page'=>$page];
        }elseif($section==='package'){
            $page=$value('migration_page',1);$after=$value('receipt_after','');Journal::pageNumber($page);
            if(!is_string($after)||($after!==''&&!preg_match('/^[a-f0-9]{48}$/D',$after)))throw new RequestRejected('Ursprünglichen Belegcursor prüfen.');
            $out+=['migration_page'=>$page,'receipt_after'=>$after];
        }
        return self::originalQuery($query)+['system_section'=>$section];
    }
    public static function originalQuery(array $query):array{return array_intersect_key($query,array_flip(self::QUERY));}
}
