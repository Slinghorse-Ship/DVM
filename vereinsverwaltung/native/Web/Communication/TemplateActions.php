<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Kulturhus\Native\Web\Workspace as BaseWorkspace;
use Kulturhus\Native\Communication\{Workspace,TemplateCatalogue};

final class TemplateActions extends BaseWorkspace
{
    public function action(string $module,array $post): array
    {
        $this->validatePost($post);if(!in_array($module,['newsletter','press'],true))throw new DomainException('Vorlagenbereich prüfen.');
        $this->access->require($module,'read');$s=TemplateFormState::restore($post);TemplateCatalogue::page($this->db,$this->access,$module,$s);$w=new Workspace($this->db,$this->access,$module,$this->mediaRoot);
        if($s['focus']!=='')$w->getTemplate($s['focus']);$notice='';
        if(isset($post['template_action'])){
            if(!is_string($post['template_action'])||isset($post['template_browse']))throw new DomainException('Eine Vorlagenaktion auswählen.');$this->access->require($module,'manage');
            switch($post['template_action']){
                case 'save':
                    if(!isset($s['profile'])||($s['profile']['id']===''&&!$s['new']))throw new DomainException('Vollständiges Vorlagenformular öffnen.');
                    if((int)$s['profile']['version']!==$s['current_version'])throw new DomainException('Ursprüngliche Vorlagenfassung prüfen.');
                    $data=$s['profile'];$data['blocks']=json_decode($data['blocks_json'],true,64,JSON_THROW_ON_ERROR);unset($data['blocks_json']);$saved=$w->saveTemplate($data,(int)$data['version']);$notice='Vorlage gespeichert.';break;
                case 'restore':$saved=$w->restoreTemplate($s['focus'],$s['current_version'],$s['revision']);$notice='Neue Fassung gespeichert.';break;
                case 'copy':
                    $source=$w->getTemplate($s['focus']);if($source['version']!==$s['current_version'])throw new DomainException('Vorlage inzwischen geändert. Aktuellen Stand öffnen.');
                    $s['focus']='';$s['new']=true;$s['current_version']=0;$s['revision']=0;$s['block_index']=0;
                    $s['profile']=['id'=>'','version'=>'0','name'=>$source['name'],'title'=>$source['title'],'body_html'=>$source['body_html']??TemplateFormState::plainHtml($source['body']),'blocks_json'=>json_encode($source['blocks'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];break;
                default:throw new DomainException('Vorlagenaktion prüfen.');
            }
            if(isset($saved)){$s['focus']=$saved['id'];$s['new']=false;$s['current_version']=$saved['version'];$s['revision']=0;$s['block_index']=max(0,min($s['block_index'],count($saved['blocks'])-1));unset($s['profile']);}
        }else{
            if(!is_string($post['template_browse']??null))throw new DomainException('Vorlagenaktion prüfen.');
            if(($post['template_browse']==='new')||str_starts_with($post['template_browse'],'add:')||str_starts_with($post['template_browse'],'block:'))$this->access->require($module,'manage');
            $s=TemplateFormState::browse($s,$post['template_browse']);
        }
        if($s['focus']!=='')$w->getTemplate($s['focus']);TemplateCatalogue::page($this->db,$this->access,$module,$s);
        return['view'=>$module,'item'=>'','section'=>'templates','notice'=>$notice,'compose_state'=>['templates'=>$s]];
    }
}
