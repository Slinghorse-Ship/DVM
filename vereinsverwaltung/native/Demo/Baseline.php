<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

/** Journaled application baseline in the marker-bound demo DB, without resetting any table. */
final class Baseline
{
    public static function install(\PDO $db,array &$journal,callable $persist,?callable $checkpoint=null):void
    {
        if(PHP_SAPI!=='cli'||!Runtime::active()||$db->inTransaction())throw new \DomainException('Eigene private Demo-Baseline erforderlich.');
        if($journal['baseline_complete']??false)return;
        $file=dirname(__DIR__,2).'/migrations/mysql/086_baseline.sql';$source=(string)file_get_contents($file);$hash=hash('sha256',$source);
        if(isset($journal['baseline_source'])&&!hash_equals($journal['baseline_source'],$hash))throw new \DomainException('Begonnene Demo-Baseline gehört zu einer anderen Paketfassung.');
        $journal['baseline_source']=$hash;$persist('baseline-source');
        preg_match_all('/^CREATE TABLE `([a-z0-9_]+)` \(.*?^\) ENGINE=[^;]+;/ms',$source,$matches,PREG_SET_ORDER);
        $definitions=[];foreach($matches as $match)$definitions[$match[1]]=$match[0];
        $remaining=preg_replace('/^--[^\n]*\n/m','',$source);$remaining=preg_replace('/^CREATE TABLE `[a-z0-9_]+` \(.*?^\) ENGINE=[^;]+;/ms','',$remaining);
        $remaining=preg_replace('/SET FOREIGN_KEY_CHECKS=[01];/','',$remaining);
        if(!$definitions||trim($remaining)!==''||count($definitions)!==preg_match_all('/^CREATE TABLE /m',$source))throw new \DomainException('Gepackte Demo-Baseline muss vollständig geprüft werden.');
        $probes=$journal['baseline_probes']??[];$actual=$db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        if(array_diff($actual,[...array_keys($definitions),'dvm_demo_instance',...array_keys($probes)]))throw new \DomainException('Unbekannte Demo-Tabellen bleiben unverändert.');
        $original=(int)$db->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();$db->exec('SET FOREIGN_KEY_CHECKS=0');
        try{
            if(!($journal['baseline_tables_ready']??false))foreach($definitions as $name=>$sql){
                $exists=in_array($name,$actual,true);
                if($exists&&$db->query('SELECT 1 FROM `'.$name.'` LIMIT 1')->fetchColumn()!==false)throw new \DomainException('Begonnene Demo-Baseline enthält unerwartete Fachdaten.');
                if(!$exists){$db->exec($sql);if($checkpoint)$checkpoint('baseline-created:'.$name);}
                $create=self::show($db,$name);$known=$journal['baseline_tables'][$name]??null;
                if($known!==null){if(!hash_equals($known,hash('sha256',$create)))throw new \DomainException('Begonnene Demo-Tabellenstruktur wurde verändert.');}
                elseif($exists){
                    // A process can die after DDL and before its receipt. Compare against
                    // a newly executed authoritative definition, not a guessed SQL format.
                    $probe='dvm_demo_probe_'.bin2hex(random_bytes(12));
                    if(in_array($probe,$db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN),true))throw new \DomainException('Demo-Prüftabelle bereits vorhanden.');
                    $aliases=[];$probeSql=preg_replace('/\ACREATE TABLE `'.preg_quote($name,'/').'`/','CREATE TABLE `'.$probe.'`',$sql);
                    $probeSql=preg_replace_callback('/CONSTRAINT `([^`]+)`/',static function($match)use(&$aliases,$probe){$alias='dvm_probe_'.substr(hash('sha256',$probe.':'.$match[1]),0,40);$aliases[$alias]=$match[1];return 'CONSTRAINT `'.$alias.'`';},$probeSql);
                    $journal['baseline_probes'][$probe]=['table'=>$name,'aliases'=>$aliases];$persist('baseline-probe-intent');$db->exec($probeSql);
                    $expected=self::normalize(self::show($db,$probe),$probe,$name,$aliases);
                    if(!hash_equals(hash('sha256',$expected),hash('sha256',$create)))throw new \DomainException('Nicht bestätigte Demo-Tabelle stimmt nicht mit der gepackten Struktur überein.');
                    foreach($journal['baseline_probes'] as $old=>$metadata){
                        if($metadata['table']!==$name)continue;
                        if(in_array($old,$db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN),true)){
                            $proof=self::normalize(self::show($db,$old),$old,$name,$metadata['aliases']);
                            if($db->query('SELECT 1 FROM `'.$old.'` LIMIT 1')->fetchColumn()!==false||!hash_equals(hash('sha256',$expected),hash('sha256',$proof)))throw new \DomainException('Unbekannter Inhalt einer Demo-Prüftabelle bleibt unverändert.');
                            $db->exec('DROP TABLE `'.$old.'`');
                        }
                        unset($journal['baseline_probes'][$old]);
                    }
                }
                $journal['baseline_tables'][$name]=hash('sha256',$create);$persist('baseline-table:'.$name);
            }
            $journal['baseline_tables_ready']=true;$persist('baseline-tables-ready');
            \Kulturhus\Verein\Database::finishBaseline($db);$journal['baseline_complete']=true;$persist('baseline-complete');
        }finally{$db->exec('SET FOREIGN_KEY_CHECKS='.$original);}
    }
    private static function show(\PDO $db,string $name):string{return $db->query('SHOW CREATE TABLE `'.$name.'`')->fetch(\PDO::FETCH_NUM)[1];}
    private static function normalize(string $sql,string $from,string $to,array $aliases):string
    {
        $sql=preg_replace('/\ACREATE TABLE `'.preg_quote($from,'/').'`/','CREATE TABLE `'.$to.'`',$sql);
        foreach($aliases as $alias=>$original)$sql=str_replace('CONSTRAINT `'.$alias.'`','CONSTRAINT `'.$original.'`',$sql);
        return $sql;
    }
}
