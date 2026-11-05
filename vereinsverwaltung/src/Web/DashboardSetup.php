<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

use DomainException;
use Kulturhus\Verein\{Finance,MembershipApplications,Registry,Settings,WorkspaceAccess,BoardAccess};

trait DashboardSetup
{
    private function setup(array $query,array $actor,Registry $registry,Finance $finance,Settings $settings,WorkspaceAccess $access):array
    {
        $groups=[];$configured=$settings->setupStatus();$ack=null;
        $append=static function(string $group,string $groupLabel,string $label,array $route,?bool $done)use(&$groups,$access):void{
            if(!$access->allows($route))return;$groups[$group]??=['label'=>$groupLabel,'items'=>[]];
            $groups[$group]['items'][]=['label'=>$label,'route'=>$route,'state'=>$done===null?'optional':($done?'done':'pending')];
        };
        if($registry->canWrite()){
            $ack=(new MembershipApplications($this->db,$actor['club_id'],$actor['id']))->introAcknowledgement();
            $append('setup','Einrichtung','Einführung in eure Vereinsverwaltung',['view'=>'dashboard','setup'=>'setup'],$ack!==null);
            $append('general','Allgemeines','Mitglieder importieren',['view'=>'members','import'=>1],null);
        }
        if($settings->canManage()){
            $append('general','Allgemeines','Individuelle Felder ergänzen',['view'=>'settings','section'=>'fields'],null);
            $append('donations','Beiträge und Spenden','Textbausteine bearbeiten',['view'=>'settings','tab'=>'texts'],$configured['texts']);
            $append('donations','Beiträge und Spenden','Unterschrift hinterlegen',['view'=>'settings','tab'=>'paper'],$configured['signature']);
        }
        if($finance->canWrite()){
            foreach(['Beiträge anlegen'=>'rates','Beiträge zuweisen'=>'assigned','Nummernkreise definieren'=>'numbers']as$label=>$flag)$append('donations','Beiträge und Spenden',$label,['view'=>'settings','tab'=>$flag==='assigned'?'rates':($flag==='numbers'?'numbers':'rates')],$configured[$flag]);
            $append('finance','Finanzen','Ausgabe erfassen',['view'=>'finance','tab'=>'receipts','new_entry'=>1,'kind'=>'expense'],$finance->hasRecordedExpense());
            $append('finance','Finanzen','Leistungen einrichten',['view'=>'settings','tab'=>'services'],$configured['services']);
        }
        if($registry->canWriteCalendar())$append('organisation','Organisation','Ersten Termin erstellen',['view'=>'calendar','new_event'=>1],$registry->hasDashboardAppointment());
        if(BoardAccess::allowed($this->db,$actor['id']))$append('organisation','Organisation','Mitgliedsantrag einrichten',['view'=>'portal','tab'=>'application','configure'=>1],(bool)(new MembershipApplications($this->db,$actor['club_id'],$actor['id']))->settings()['config']['enabled']);
        foreach($groups as&$group){$group['pending']=count(array_filter($group['items'],static fn(array $item):bool=>$item['state']==='pending'));$group['optional']=count(array_filter($group['items'],static fn(array $item):bool=>$item['state']==='optional'));}unset($group);
        if(!$groups&&array_key_exists('setup',$query))throw new DomainException('Keine Berechtigung für die Einrichtungsführung.',403);
        $selected=$query['setup']??array_key_first($groups);
        $error=$selected!==null&&(!is_string($selected)||!isset($groups[$selected]))?'Verfügbaren Einrichtungsbereich auswählen.':'';if($error!=='')$selected=null;
        return ['error'=>$error,'query'=>is_string($query['setup']??null)?$query['setup']:'','groups'=>$groups,'selected'=>$selected,'open'=>array_key_exists('setup',$query),'acknowledgement'=>$ack];
    }
}
