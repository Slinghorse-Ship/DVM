<?php declare(strict_types=1);
namespace Kulturhus\Native\Events;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};
use Kulturhus\Native\Integration\{Accounts,JsonHttp};

/** Selected read-only external source; CMS publication follows its own current policy. */
final class Sync
{
    public static function run(PDO $db,Access $access,SecretBox $box,?callable $request=null):array
    {
        if($db->inTransaction())throw new \DomainException('Veranstaltungsabgleich benötigt einen eigenen Vorgang.');
        $club=$access->require('website','edit')['club_id'];$workflow=(new Workflow($db,$access))->get();
        if($workflow['source']==='local')return ['source'=>'local','results'=>[]];
        if($workflow['source']==='facebook')return ['source'=>'facebook','results'=>(new Facebook($db,$access,$box,$request))->sync()];
        $revision=null;$accounts=new Accounts($db,$box);$account=$accounts->get($club,'eventbrite',$revision);
        $current=static function()use($db,$access,$accounts,$club,$workflow,$account,$revision):void{
            if($access->require('website','edit')['club_id']!==$club||(new Workflow($db,$access))->requireSource('eventbrite')['version']!==$workflow['version'])throw new \DomainException('Veranstaltungsablauf während der Abfrage geändert.');
            $actualRevision=null;$actual=$accounts->get($club,'eventbrite',$actualRevision);
            if($actualRevision!==$revision||!hash_equals(Accounts::binding($account),Accounts::binding($actual)))throw new \DomainException('Eventbrite-Verbindung während der Abfrage geändert.');
        };
        $http=$request??static fn($method,$path,$headers,$body)=>JsonHttp::request('eventbrite',$method,$path,$headers,$body);
        $events=(new Eventbrite($db,$access))->fetch($account['organization'],static function($path)use($http,$account,$current):array{
            $current();$r=$http('GET',$path,['Authorization: Bearer '.$account['token']],null);$current();
            if(($r['status']??null)!==200||!is_array($r['body']??null))throw new \DomainException('Eventbrite-Abfrage fehlgeschlagen.');
            return $r['body'];
        });
        return ['source'=>'eventbrite','results'=>(new SourceImporter($db,$access,'eventbrite'))->import($events,$club,$workflow['version'],$current)];
    }
}
