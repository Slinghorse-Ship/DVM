<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{Html,Workspace};
use Kulturhus\Native\Language\{Catalog,EditorialHubMessages as Messages};

/** One workspace with strictly module-bound destinations and unchanged form actions. */
final class EditorialHub extends Workspace
{
    public function navigation(string $module,string $section,string $planning):string
    {
        $messages=(new Catalog($this->db,$this->access))->messages();$t=static fn(string$s):string=>Html::e(Messages::text($s,$messages));$social=$this->can('social','read');$newsletter=$this->can('newsletter','read');$preferred=$social?'social':'newsletter';
        $context=array_intersect_key($_GET,array_flip(['plan_month','plan_channel','plan_state','plan_step']));$url=static fn(string$m,string$s,array$q=[]):string=>'/?'.http_build_query(['view'=>$m,'section'=>$s]+$q+$context);
        $tabs=[['Kalender',$preferred,'planning',['planning_view'=>'month'],$section==='planning'&&$planning==='month'],['Inhalte',$preferred,'contents',[],in_array($section,['contents','compose','library','templates','events'],true)],['Vorbereitung',$preferred,'planning',['planning_view'=>'automation'],$section==='planning'&&$planning==='automation'],['Verlauf',$preferred,'planning',['planning_view'=>'history'],$section==='planning'&&$planning==='history']];
        if($newsletter&&$this->can('newsletter','manage'))$tabs[]=['Empfänger','newsletter','recipients',[],$module==='newsletter'&&$section==='recipients'];
        if($social&&$this->can('social','manage'))$tabs[]=['Interaktionen','social','review',[],in_array($section,['review','replies','inbox','mail','sync'],true)];
        $h='<div class="editorial-hub-bar" data-editorial-hub><nav class="editorial-hub-nav" aria-label="'.$t('Social & Newsletter').'">';
        foreach($tabs as[$label,$m,$s,$q,$current])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url($m,$s,$q)).'"'.($current?' aria-current="page"':'').'>'.$t($label).'</a>';
        $h.='</nav>';
        if($this->can('social','edit')||$this->can('newsletter','edit')){
            $h.='<nav class="editorial-create" aria-label="'.$t('Neuer Inhalt').'">';
            if($this->can('social','edit'))foreach(['post'=>'Neuer Beitrag','story'=>'Neue Story','reel'=>'Neues Reel','carousel'=>'Neues Karussell']as$kind=>$label){if($kind==='reel'&&!(new \Kulturhus\Native\Communication\Videos($this->db,$this->access,$this->mediaRoot))->installed())continue;$h.='<a class="btn btn-solid theme-primary" data-nav data-social-create="'.$kind.'" href="'.Html::e($url('social','compose',['new_kind'=>$kind])).'">'.$t($label).'</a>';}
            if($this->can('newsletter','edit'))$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url('newsletter','compose')).'">'.$t('Newsletterausgabe').'</a>';
            $h.='</nav>';
        }
        $h.='</div>';$secondary=[];
        if(in_array($section,['contents','compose','library','templates','events'],true)){
            if($social){$secondary[]=['Eigene Inhalte','social','contents',['hub_type'=>'social']];$secondary[]=['Plattformbeiträge','social','library',[]];if((new \Kulturhus\Native\Communication\EventDrafts($this->db,$this->access,'social'))->sources())$secondary[]=['Veranstaltungen','social','events',[]];}
            if($social)$secondary[]=['Social-Vorlagen','social','templates',[]];
            if($newsletter)$secondary[]=['Newsletter-Vorlagen','newsletter','templates',[]];
        }
        if($section==='planning'&&in_array($planning,['history','automation'],true))foreach(['social'=>$social,'newsletter'=>$newsletter]as$m=>$allowed)if($allowed)$secondary[]=[$planning==='history'?($m==='social'?'Social-Verlauf':'Newsletter-Verlauf'):($m==='social'?'Social-Vorbereitung':'Newsletter-Vorbereitung'),$m,'planning',['planning_view'=>$planning]];
        if($secondary){$h.='<nav class="editorial-hub-secondary">';foreach($secondary as[$label,$m,$s,$q])$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url($m,$s,$q)).'"'.($module===$m&&$section===$s?' aria-current="page"':'').'>'.$t($label).'</a>';$h.='</nav>';}
        return$h;
    }
}
