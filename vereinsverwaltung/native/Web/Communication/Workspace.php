<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{
    Workspace as BaseWorkspace,
    Html,
    Editor,
    RichTextEditor,
    ImageEditor,
    VideoEditor,
};
use Kulturhus\Native\Communication\Workspace as Communication;
use Kulturhus\Native\Communication\{Subscribers, PressContacts};
use Kulturhus\Native\Cms\Media;
/** Newsletter, social and press editing; shell and other modules stay separate. */
final class Workspace extends BaseWorkspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    use ConversationInterfaceText;
    public function action(string $view, array $post, array $files): array
    {
        $result=(new Actions($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($view,$post,$files);
        if(in_array($view,['social','newsletter'],true)&&($_GET['planning_view']??null)==='automation'&&($result['section']??null)==='planning'&&!isset($result['planning_view']))$result['planning_view']='automation';
        if(SocialLibrary::applies($view,$post)&&is_string($result['notice']??null))$result['notice']=$this->contentText($result['notice']);
        if(ConversationRequest::applies($view,$post)&&is_string($result['notice']??null))$result['notice']=$this->conversationText($result['notice']);
        return$result;
    }
    public function manager(string $module, string $id,?array $composeState=null): string
    {
        $isHub=in_array($module,['social','newsletter'],true);
        if(array_key_exists('section',$_GET)&&!is_string($_GET['section']))throw new \DomainException('Unbekannter Arbeitsbereich.',422);
        $legacyTab=is_string($_GET['tab']??null)?$_GET['tab']:'';
        $legacySection=$isHub?match($legacyTab){'social'=>'library','newsletter'=>'compose','recipients'=>'recipients','design'=>'templates','automation'=>'planning',default=>null}:null;
        $section=$_GET['section']??$legacySection??(($id!==''||$composeState!==null)?'compose':($isHub?'planning':'compose'));
        $legacyKeys=$module==='social'?['queue_job','queue_page','queue_provider','queue_state']:['newsletter_job','newsletter_page','newsletter_item','newsletter_kind','newsletter_state'];
        $planning=$_GET['planning_view']??($legacyTab==='automation'?'automation':(array_intersect($legacyKeys,array_keys($_GET))?'history':'month'));
        if($isHub&&(!is_string($planning)||!in_array($planning,['month','history','automation'],true)))throw new \DomainException('Planungsansicht prüfen.',422);
        if (
            !in_array(
                $section,
                [
                    "compose",
                    "recipients",
                    "templates",
                    "planning",
                    ...$isHub?['contents']:[],
                    ...$module==='press'?['events']:[],
                    ...$module === "social"
                        ? ["library", "inbox", "events", "review", "replies", "mail", "sync"]
                        : [],
                ],
                true,
            )
        ) {
            throw new \DomainException("Unbekannter Arbeitsbereich.");
        }
        if($isHub){$nav=(new EditorialHub($this->db,$this->access,$this->mediaRoot,$this->csrf))->navigation($module,$section,$planning);}
        else{
            $tabs=['compose'=>'Redaktion','events'=>'Veranstaltungen','recipients'=>'Empfänger','templates'=>'Vorlagen','planning'=>'Planung & Verlauf'];$nav='<nav class="workspace-tabs nav nav-pills" aria-label="'.Html::e($this->contentText('Kommunikation')).'">';
            foreach($tabs as$key=>$label){if($key==='events'&&!(new \Kulturhus\Native\Communication\EventDrafts($this->db,$this->access,$module))->sources())continue;$nav.='<a class="nav-link'.($section===$key?' active':'').'" data-nav href="/?view='.$module.'&amp;section='.$key.'"'.($section===$key?' aria-current="page"':'').'>'.Html::e($this->contentText($label)).'</a>';}$nav.='</nav>';$current=$tabs[$section]??'Kommunikation';$nav='<details class="communication-area-menu"><summary>'.Html::e($this->contentText($current)).' <span>'.Html::e($this->contentText('Bereiche')).'</span></summary>'.$nav.'</details>';
        }
        if($isHub&&$section==='contents')return$nav.(new EditorialLibrary($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($module,$_GET);
        if($module==='social'&&$section==='sync')return$nav.(new CheckPlanEditor($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($composeState['check_plan']??null);
        if($module==='social'&&$section==='mail')return$nav.(new Mail($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager();
        if ($section === "replies" && $module === "social") {
            return $nav .
                (new Replies(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($composeState['human']??null);
        }
        if ($section === "review" && $module === "social") {
            return $nav .
                (new Review(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($composeState['human']??null);
        }
        if ($section === "events" && in_array($module,['social','press'],true)) {
            return $nav .
                (new EventSources(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($module,$composeState['event_selection']??null);
        }
        if ($section === "library") {
            return $nav .
                (new SocialLibrary(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($composeState['post_correction']??null,$composeState['library']??null);
        }
        if ($section === "inbox") {
            return $nav .
                (new SocialLibrary(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->inbox($composeState['human']??null,$composeState['inbox_sync']??null);
        }
        if ($section === "recipients") {
            return $nav .
                (new Contacts(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($module,$composeState['contacts']??null);
        }
        if ($section === "templates") {
            return $nav .
                (new Templates(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($module,$composeState['templates']??null);
        }
        if ($section === "planning") {
            return $nav .
                (new Planning(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                ))->manager($module,$composeState['planning_failure']??null);
        }
        return $nav .
            (new Compose(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->manager($module, $id,$composeState);
    }
}
