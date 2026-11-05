<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use DomainException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db,SecretBox};

/** One read-only editorial calendar; each contributing module keeps its own grant. */
final class EditorialMonth
{
    public function __construct(private PDO $db,private SecretBox $box){}
    public static function query(array $input,?\DateTimeImmutable $now=null):array
    {
        $channel=$input['plan_channel']??'all';$state=$input['plan_state']??'all';
        if(!is_string($channel)||!in_array($channel,['all','facebook','instagram','stadtlandfunk','newsletter','local'],true)||!is_string($state)||!in_array($state,['all','pending','processing','sent','uncertain','cancelled','ready','failed','enabled','paused','prepared','outdated','partial'],true))throw new DomainException('Monat, Kanal und Status prüfen.');
        $source=$input;$source['plan_channel']=$channel==='newsletter'?'all':$channel;$source['plan_state']=$state==='partial'?'all':$state;
        return array_replace(PublicationMonth::query($source,$now),['channel'=>$channel,'state'=>$state]);
    }
    private static function allowed(Access $access,string $module):?array
    {
        try{return$access->require($module,'read');}catch(DomainException){return null;}
    }
    public function listing(Access $access,array $input=[],?\DateTimeImmutable $now=null):array
    {
        $now??=new \DateTimeImmutable();$q=self::query($input,$now);
        return Db::atomic($this->db,function()use($access,$input,$q,$now):array{
            $social=self::allowed($access,'social');$newsletter=self::allowed($access,'newsletter');
            if(!$social&&!$newsletter)throw new DomainException('Keine Berechtigung für den Redaktionskalender.',403);
            $club=($social??$newsletter)['club_id'];foreach([$social,$newsletter]as$scope)if($scope!==null&&$scope['club_id']!==$club)throw new DomainException('Vereinszuordnung während Planung geändert.',409);
            if(($q['channel']==='newsletter'&&!$newsletter)||(in_array($q['channel'],['facebook','instagram','stadtlandfunk'],true)&&!$social))throw new DomainException('Für diesen Kanal fehlt die Leseberechtigung.',403);
            $rows=[];
            if($social&&$q['channel']!=='newsletter'&&$q['state']!=='partial'){
                foreach(EventPreparationMonth::rows($this->db,$access,$q,$now)as$row){$row['module']='social';$rows[]=$row;}
                $source=$input;if($q['channel']==='local')$source['plan_channel']='all';
                foreach((new PublicationMonth($this->db,$this->box))->listing($access,$source)['rows']as$row){
                    $row['module']='social';$row['phase']=$row['step'];$row['needs_approval']=empty($row['automatic_story']);
                    if($row['transport']==='automation'&&$row['status']==='prepared'){$status=Db::run($this->db,'SELECT status FROM khn_social_items WHERE club_id=? AND id=?',[$club,$row['target_item_id']])->fetchColumn();$row['needs_approval']=!in_array($status,['approved','scheduled','local_ready'],true);}
                    $rows[]=$row;
                }
            }
            if($newsletter&&!in_array($q['channel'],['facebook','instagram','stadtlandfunk'],true))array_push($rows,...(new NewsletterMonth($this->db))->rows($access,$q,$now));
            $rows=array_values(array_filter($rows,static fn(array$row):bool=>($q['channel']==='all'||($q['channel']==='local'?$row['step']==='local':in_array($q['channel'],$row['channels'],true)||$row['channel']===$q['channel']))&&($q['state']==='all'||$row['status']===$q['state'])&&($q['step']==='all'||$row['step']===$q['step'])));
            usort($rows,static fn(array$a,array$b):int=>[$a['due_at'],$a['module'],$a['id']]<=>[$b['due_at'],$b['module'],$b['id']]);
            if(count($rows)>5000)throw new DomainException('Zu viele Einträge für eine vollständige Monatsanzeige. Auswahl eingrenzen.',422);
            foreach(['social'=>$social,'newsletter'=>$newsletter]as$module=>$scope)if($scope!==null&&$access->require($module,'read')['club_id']!==$club)throw new DomainException('Vereinszuordnung während Planung geändert.',409);
            return$q+['rows'=>$rows,'modules'=>['social'=>$social!==null,'newsletter'=>$newsletter!==null]];
        });
    }
}
