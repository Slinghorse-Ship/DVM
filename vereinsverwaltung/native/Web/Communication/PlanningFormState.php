<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Communication\{EditorialMonth,PublicationCatalogue,NewsletterDeliveryCatalogue,Content};

/** Only validated read criteria accompany a planning action; no draft or provider payload. */
final class PlanningFormState
{
    public static function route(string $module,array $input):array
    {
        if(!in_array($module,['social','newsletter','press'],true))throw new \DomainException('Planungsformular prüfen.',422);
        $route=['view'=>$module,'section'=>'planning'];
        if($module==='press')return$route;
        $legacy=$module==='social'?['queue_job','queue_page','queue_provider','queue_state']:['newsletter_job','newsletter_page','newsletter_item','newsletter_kind','newsletter_state'];
        $view=$input['planning_view']??(($input['tab']??null)==='automation'?'automation':(array_intersect($legacy,array_keys($input))?'history':'month'));
        if(!is_string($view)||!in_array($view,['month','history','automation'],true))throw new \DomainException('Planungsansicht prüfen.',422);
        $route['planning_view']=$view;
        if(array_intersect(['plan_month','plan_channel','plan_state','plan_step'],array_keys($input))){
            EditorialMonth::query($input);
            foreach(['plan_month','plan_channel','plan_state','plan_step']as$key)if(isset($input[$key]))$route[$key]=$input[$key];
        }
        if($module==='social'&&($view==='history'||array_intersect($legacy,array_keys($input)))){
            $q=PublicationCatalogue::query($input);
            $route+=['queue_page'=>(string)$q['page'],'queue_provider'=>$q['provider'],'queue_state'=>$q['state']];
            if($q['focus']!=='')$route['queue_job']=$q['focus'];
        }
        if($module==='newsletter'&&($view==='history'||array_intersect($legacy,array_keys($input)))){
            $q=NewsletterDeliveryCatalogue::state($input);
            $route+=['newsletter_page'=>(string)$q['page'],'newsletter_kind'=>$q['kind'],'newsletter_state'=>$q['state']];
            if($q['item']!=='')$route['newsletter_item']=$q['item'];
            if($q['focus']!=='')$route['newsletter_job']=$q['focus'];
        }
        return$route;
    }
    public static function pause(array $input):array
    {
        try{
            if(!is_string($input['automation_id']??null)||!in_array($input['paused']??null,['0','1'],true))throw new \DomainException();
            $id=Content::id($input['automation_id']);$version=TemplateFormState::number($input['version']??null,4294967295);
            if($version<1)throw new \DomainException();
            return['id'=>$id,'version'=>$version,'paused'=>$input['paused']==='1'];
        }catch(\DomainException){throw new \DomainException('Planungsformular prüfen.',422);}
    }
    public static function operation(mixed $operation):bool{return in_array($operation,['communication-pause','meta-reconcile','stadtlandfunk-reconcile','meta-carousel-stop'],true);}
    public static function jobRoute(array $route,string $id,string $provider):array
    {
        // A reconciled or stopped job may have left the old status filter.
        return array_replace($route,['planning_view'=>'history','queue_job'=>Content::id($id),'queue_provider'=>$provider,'queue_state'=>'all']);
    }
    public static function url(string $module,array $input):string{return'/?'.http_build_query(self::route($module,$input),'','&',PHP_QUERY_RFC3986);}
    public static function failure(\PDO $db,\Kulturhus\Native\Shared\Access $access,string $module,array $input,string $message,bool $uncertain):array
    {
        $access->require($module,'read');
        try{$route=self::route($module,$input);}catch(\DomainException){$route=['view'=>$module,'section'=>'planning'];}
        if($uncertain)$message='Planung nicht bestätigt. Aktuellen gespeicherten Status prüfen, bevor du erneut handelst.';
        $messages=(new \Kulturhus\Native\Language\Catalog($db,$access))->messages();
        $message=\Kulturhus\Native\Language\EditorialHubMessages::text($message,$messages);
        return['route'=>$route,'error'=>$message,'label'=>\Kulturhus\Native\Language\EditorialHubMessages::text('Gespeicherten Planungsstatus prüfen',$messages)];
    }
    public static function recovery(array $failure):string
    {
        $e=\Kulturhus\Native\Web\Html::e(...);
        return'<section class="planning-recovery"><p role="alert" tabindex="-1">'.$e($failure['error']).'</p><a class="btn btn-outline theme-secondary" data-nav data-planning-read href="'.$e('/?'.http_build_query($failure['route'])).'">'.$e($failure['label']).'</a></section>';
    }
}
