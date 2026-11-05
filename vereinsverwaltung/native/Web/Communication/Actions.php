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
final class Actions extends BaseWorkspace
{
    use ConversationInterfaceText;
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function action(string $view, array $post, array $files): array
    {
        $this->validatePost($post);
        if (
            !in_array(
                $view,
                \Kulturhus\Native\Communication\Schema::MODULES,
                true,
            )
        ) {
            throw new \DomainException("Unbekannter Redaktionsbereich.");
        }
        foreach(['operation','id','version']as$field)if(isset($post[$field])&&!is_string($post[$field])&&!is_int($post[$field]))throw new \DomainException('Redaktionsformular erneut öffnen.');
        $op = (string) ($post["operation"] ?? "");
        $id = (string) ($post["id"] ?? "");
        $version = (int) ($post["version"] ?? 0);
        if ($view === 'social' && $op === 'social-reel-prepare') {
            $state = ReelRenderForm::restore($post);
            $messages = (new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
            try {
                $service = new \Kulturhus\Native\Communication\ReelDrafts($this->db,$this->access,$this->mediaRoot);
                if (array_key_exists('reel_review',$post)) {
                    if ($post['reel_review'] !== '1') throw new \DomainException('Ursprüngliches Videoformular öffnen.');
                    $state['result'] = $service->review($state['id'],$state['reel_request_key']);
                } else $state['result'] = $service->prepare($state['id'],(int)$state['version'],$state['animation'],$state['reel_request_key']);
            } catch (\DomainException $error) {
                $state['error'] = \Kulturhus\Native\Language\EventPreparationMessages::text($error->getMessage(),$messages);
            }
            return ['view'=>'social','item'=>$state['id'],'section'=>'compose','notice'=>'','compose_state'=>['reel_only'=>$state]];
        }
        if ($view === 'social' && in_array($op,['social-event-preparation-settings','social-event-preparation-retry','social-event-preparation-refresh','social-event-preparation-run'],true)) {
            $messages = (new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
            $text = static fn(string $s):string=>\Kulturhus\Native\Language\EventPreparationMessages::text($s,$messages);
            try {
                $service = new \Kulturhus\Native\Communication\EventPreparations($this->db,$this->mediaRoot);
                if (!is_string($post['version']??null) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$post['version'])) throw new \DomainException('Fassung der Veranstaltungsautomatik prüfen.');
                if ($op === 'social-event-preparation-run') {
                    $state=$service->settings($this->access);
                    if (!$state['enabled'] || $state['version']!==$version) throw new \DomainException('Veranstaltungsautomatik inzwischen geändert. Neu laden.');
                    $service->run($this->access->club(),3); $notice='Entwurfsvorbereitung abgeschlossen. Gespeicherten Stand prüfen.';
                } elseif ($op === 'social-event-preparation-refresh') {
                    if(!is_string($post['item_version']??null)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$post['item_version']))throw new \DomainException('Aktuelle Entwurfsfassung prüfen.');
                    $service->refresh($this->access,$id,(int)$post['item_version'],$version);$notice='Neue Gestaltung vorgemerkt. Gespeicherte Texte und Animation bleiben erhalten.';
                } elseif ($op === 'social-event-preparation-retry') {
                    $service->retry($this->access,$id,$version); $notice = 'Vorbereitung erneut vorgemerkt.';
                } else {
                    if (($post['event_preparation_complete']??null)!=='1' || !in_array($post['enabled']??'0',['0','1'],true) || !in_array($post['story_reminders']??'0',['0','1'],true)) throw new \DomainException('Ursprüngliches Veranstaltungsformular öffnen.');
                    $input = ['media'=>$post['event_media']??null, 'horizon_days'=>$post['horizon_days']??null, 'locale'=>$post['draft_locale']??null, 'story_reminders'=>($post['story_reminders']??'0')==='1', 'animation'=>$post['animation']??null, 'design'=>['layout'=>$post['image_layout']??null,'palette'=>$post['image_palette']??null]];
                    $prior=$service->settings($this->access)['config'];
                    foreach(['auto_stories','story_master','story_day_hint']as$choice){
                        if(($post['story_options_complete']??null)==='1'){
                            if(!in_array($post[$choice]??'0',['0','1'],true))throw new \DomainException('Story-Automatik, Bildvorlage und Tageshinweis prüfen.');
                            $input[$choice]=($post[$choice]??'0')==='1';
                        }else $input[$choice]=$prior[$choice];
                    }
                    $service->save($this->access,$input,($post['enabled']??'0')==='1',$version); $notice = 'Automatik gespeichert.';
                }
                return ['view'=>'social','section'=>'planning','planning_view'=>'automation','notice'=>$text($notice)];
            } catch (\DomainException $error) { throw new \DomainException($text($error->getMessage()),$error->getCode(),$error); }
        }
        if(PlanningFormState::operation($op)){
            $planningRoute=PlanningFormState::route($view,$post);
            $planningMessages=(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages();
            $planningText=static fn(string $source):string=>\Kulturhus\Native\Language\EditorialHubMessages::text($source,$planningMessages);
        }
        if($op==='social-canvas-review'&&$view==='social'){
            $this->access->require('social','edit');
            if(!is_string($post['canvas_request_key']??null))throw new \DomainException('Ursprüngliches Bildformular öffnen.');
            $saved=(new \Kulturhus\Native\Communication\CanvasSaveRequests($this->db,$this->access,$this->mediaRoot))->review($id,$post['canvas_request_key']);
            return ['view'=>'social','item'=>$id,'notice'=>'','canvas_result'=>$saved?['found'=>true]+$saved:['found'=>false]];
        }
        if(array_key_exists('save_review',$post)){
            if(!in_array($view,['newsletter','press','social'],true)||$op!=='communication-save'||$post['save_review']!=='1'||!is_string($post['save_request_key']??null))throw new \DomainException('Gespeicherten Stand prüfen.');
            $saved=(new \Kulturhus\Native\Communication\EditionSaveRequests($this->db,$this->access,$this->mediaRoot,$view))->review($post['save_request_key']);
            $this->access->require($view,'edit');$state=match($view){'press'=>PressComposerState::restore($post),'social'=>SocialComposerState::restore($post),default=>NewsletterComposerState::restore($post)};
            $lookup=$saved?['found'=>true,'id'=>$saved['id'],'version'=>$saved['version'],'url'=>'/preview?'.http_build_query(['module'=>$view,'item'=>$saved['id'],'revision'=>$saved['version']])]:['found'=>false];
            $state['save_lookup']=$lookup;
            if(($post['save_review_window']??null)==='1')$state['lookup_only']=true;
            return ['view'=>$view,'item'=>$id,'section'=>'compose','notice'=>$saved?$this->contentFormat('Fassung {version} gespeichert.',['version'=>$saved['version']]):$this->contentText('Kein gespeicherter Vorgang gefunden.'),'save_lookup'=>$lookup,'compose_state'=>$state];
        }
        if($op==='communication-template-manage')return(new TemplateActions($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($view,$post);
        if(in_array($op,['press-contacts','press-contact-profile'],true)){
            if($view!=='press')throw new \DomainException('Pressekontakte im Pressebereich bearbeiten.');$this->access->require('press','read');$state=PressContactManager::restore($post);\Kulturhus\Native\Communication\PressContactCatalogue::page($this->db,$this->access,$state);$notice='';
            if($op==='press-contact-profile'){
                if(($post['press_contact_complete']??null)!=='1'||!in_array($post['press_contact_active']??null,['0','1'],true)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$post['version']))throw new \DomainException('Kontaktformular nicht vollständig übertragen.');
                $saved=(new PressContacts($this->db,$this->access))->save(['id'=>$state['focus'],'name'=>$post['name'],'email'=>$post['email'],'outlet'=>$post['outlet'],'active'=>$post['press_contact_active']==='1'],(int)$post['version']);$state['focus']=$saved['id'];$state['new']=false;unset($state['profile']);if(isset($state['selected'][$saved['id']]))$state['selected'][$saved['id']]=$saved['version'];$notice='Kontakt gespeichert.';
            }elseif(array_key_exists('contact_activation',$post)){
                if(!in_array($post['contact_activation'],['0','1'],true)||isset($post['contact_browse']))throw new \DomainException('Eine Kontaktaktion auswählen.');$changed=(new PressContacts($this->db,$this->access))->setActive($state['selected'],$post['contact_activation']==='1',true);$state['selected']=[];$notice=$changed.' Kontakte geändert.';
            }else{$state=\Kulturhus\Native\Communication\PressContactSelection::state($post,true);}
            \Kulturhus\Native\Communication\PressContactCatalogue::page($this->db,$this->access,$state);return ['view'=>'press','item'=>'','section'=>'recipients','notice'=>$notice,'compose_state'=>['contacts'=>$state]];
        }
        if (in_array($op,['newsletter-contacts','newsletter-profile'],true)) {
            if($view!=='newsletter') throw new \DomainException('Empfänger im Newsletter bearbeiten.');
            $this->access->require('newsletter','manage');
            $state=NewsletterContacts::restore($post);
            \Kulturhus\Native\Communication\SubscriberCatalogue::page($this->db,$this->access,$state);
            if($state['focus']!=='')(new Subscribers($this->db,$this->access))->get($state['focus']);
            if($op==='newsletter-profile'){
                (new Subscribers($this->db,$this->access))->updateProfile($id,\Kulturhus\Native\Communication\SubscriberSelection::number($post['version']??'',4294967295),$post['name']??'',$post['purposes']??[]);
                unset($state['profile']);
                $notice='Empfänger gespeichert.';
            }elseif(array_key_exists('contact_unsubscribe',$post)){
                if($post['contact_unsubscribe']!=='1'||isset($post['contact_browse']))throw new \DomainException('Eine Empfängeraktion auswählen.');
                $count=(new Subscribers($this->db,$this->access))->unsubscribeSelected($state['selected'],$state['reason'],($post['confirm_unsubscribe']??'')==='1');
                $state['selected']=[];$state['reason']='';$notice=$count.' Empfänger abgemeldet.';
            }else{
                $state=\Kulturhus\Native\Communication\SubscriberSelection::state($post,true);$notice='';
            }
            // Browsing may deliberately open a different exact original.
            \Kulturhus\Native\Communication\SubscriberCatalogue::page($this->db,$this->access,$state);
            if($state['focus']!=='')(new Subscribers($this->db,$this->access))->get($state['focus']);
            return ['view'=>'newsletter','item'=>'','section'=>'recipients','notice'=>$notice,'compose_state'=>['contacts'=>$state]];
        }
        if(array_key_exists('social_format',$post)){
            if($view!=='social'||$op!=='communication-save'||$post['social_format']!=='1')throw new \DomainException('Format in der Redaktion auswählen.');
            $this->access->require('social','edit');if($id!=='')(new Communication($this->db,$this->access,'social',$this->mediaRoot))->get($id);
            $state=SocialComposerState::restore($post);if(in_array($state['document']['template'],['social-post','social-story','social-reel','social-carousel'],true))$state['document']['template']='social-'.$state['document']['kind'];
            return ['view'=>'social','item'=>$id,'section'=>'compose','notice'=>'','compose_state'=>$state];
        }
        if(array_key_exists('carousel_browse',$post)){
            if($view!=='social'||$op!=='communication-save')throw new \DomainException('Karussell in der Redaktion öffnen.');
            $this->access->require('social','edit');if($id!=='')(new Communication($this->db,$this->access,'social',$this->mediaRoot))->get($id);
            return ['view'=>'social','item'=>$id,'section'=>'compose','notice'=>'','compose_state'=>SocialComposerState::browse($post)];
        }
        if(array_key_exists('story_browse',$post)){
            if($view!=='social'||$op!=='communication-save')throw new \DomainException('Story in der Redaktion öffnen.');
            $this->access->require('social','edit');if($id!=='')(new Communication($this->db,$this->access,'social',$this->mediaRoot))->get($id);
            return ['view'=>'social','item'=>$id,'section'=>'compose','notice'=>'','compose_state'=>SocialComposerState::browseStory($post)];
        }
        if(array_key_exists('press_browse',$post)){
            if($view!=='press'||$op!=='communication-save')throw new \DomainException('Presseauswahl nur in der Presseredaktion öffnen.');
            $this->access->require('press','edit');
            if($id!=='')(new Communication($this->db,$this->access,'press',$this->mediaRoot))->get($id);
            $picker=\Kulturhus\Native\Communication\PressRecipientCatalogue::state($post);
            \Kulturhus\Native\Communication\PressRecipientCatalogue::page($this->db,$this->access,$picker);
            $state=PressComposerState::fromPost($post,$picker);
            $freshPhotos=\Kulturhus\Native\Web\Website\PagePhotoUploads::fromFiles($files,'press_photos');
            if($freshPhotos){$photos=(new \Kulturhus\Native\Cms\MediaUploads($this->db,new \Kulturhus\Native\Communication\PressUploadAccess($this->db,$this->access),$this->mediaRoot))->uploadMany($freshPhotos);$state['document']['attachment_media_ids']=\Kulturhus\Native\Communication\PressPhotoAttachments::ids([...$state['document']['attachment_media_ids'],...array_column($photos,'id')]);}
            return ['view'=>'press','item'=>$id,'section'=>'compose','notice'=>'','press_picker'=>$picker,'compose_state'=>$state];
        }
        if($view==='social'&&$op==='social-video-reuse'){
            $state=VideoReuseForm::restore($post);$actor=$this->access->require('social','edit');$service=new \Kulturhus\Native\Communication\VideoReuseRequests($this->db,$this->access,$this->mediaRoot);
            if(isset($post['reuse_review'])&&$post['reuse_review']!=='1'||isset($post['reuse_prepare'])&&$post['reuse_prepare']!=='1'||isset($post['reuse_review'],$post['reuse_prepare']))throw new \DomainException('Videoauswahl erneut öffnen.');
            $prepared=null;
            if(isset($post['reuse_prepare'])){$proposal=$service->prepare($state['id'],$state['reuse_video_id'],$state['source_id'],(int)$state['version'],(int)$state['identity_version']);$result=$proposal['original'];$prepared=$proposal['prepared'];if($prepared!==null){$state=array_replace($state,$prepared);$state['prepared']=true;$result=$service->review($state['id'],$state['reuse_video_id'],$state['source_id'],(int)$state['version'],(int)$state['identity_version']);if($result['found']){$prepared=null;$state['prepared']=false;}}}
            elseif(isset($post['reuse_review']))$result=$service->review($state['id'],$state['reuse_video_id'],$state['source_id'],(int)$state['version'],(int)$state['identity_version']);
            else$result=$service->save($state['id'],$state['reuse_video_id'],$state['source_id'],(int)$state['version'],(int)$state['identity_version']);
            $state['result']=$result;$parent=(new \Kulturhus\Native\Communication\Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($state['id']);$canEdit=$parent['kind']==='reel'&&in_array($parent['status'],['draft','review'],true);foreach(['read','edit']as$cap){$current=$this->access->require('social',$cap);if($current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new \DomainException('Person oder Vereinszuordnung geändert.');}
            if(!isset($post['reuse_review'])&&!$result['found']&&!$canEdit)throw new \DomainException('Video in einen eigenen Reel-Entwurf übernehmen.');
            return VideoReusePanel::original($state)+['notice'=>'','video_reuse_result'=>$result,'video_reuse_prepared'=>$prepared,'video_reuse_can_edit'=>$canEdit,'compose_state'=>['video_reuse_only'=>$state]];
        }
        if($view==='social'&&$op==='social-video-recover'){
            $state=VideoRecoveryForm::restore($post);$actor=$this->access->require('social','manage');$service=new \Kulturhus\Native\Communication\VideoRecoveryRequests($this->db,$this->access,$this->mediaRoot);
            if(isset($post['recovery_review'])&&$post['recovery_review']!=='1'||isset($post['recovery_prepare'])&&$post['recovery_prepare']!=='1'||isset($post['recovery_review'],$post['recovery_prepare']))throw new \DomainException('Video-Wiederherstellung erneut öffnen.');
            $prepared=null;
            if(isset($post['recovery_prepare'])){$prepared=$service->prepare($state['id'],$state['source_id'],$state['source_fingerprint'],$state['recovery_name']);$state=array_replace($state,$prepared);$state['prepared']=true;$result=$service->review($state['id'],$state['source_id'],$state['source_fingerprint'],$state['recovery_name']);if(($result['state']??null)==='complete'){$state['prepared']=false;$prepared=null;}}
            elseif(isset($post['recovery_review']))$result=$service->review($state['id'],$state['source_id'],$state['source_fingerprint'],$state['recovery_name']);
            else$result=$service->save($state['id'],$state['source_id'],$state['source_fingerprint'],$state['recovery_name'],(int)$state['version'],(int)$state['identity_version']);
            $parent=(new \Kulturhus\Native\Communication\Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($state['id']);$state['result']=$result;$edit=$parent['kind']==='reel'&&in_array($parent['status'],['draft','review'],true);try{$this->access->require('social','edit');}catch(\DomainException){$edit=false;}$reader=$this->access->require('social','read');$current=$this->access->require('social','manage');if($reader['id']!==$actor['id']||$reader['club_id']!==$actor['club_id']||$current['id']!==$actor['id']||$current['club_id']!==$actor['club_id'])throw new \DomainException('Speicherzugang geändert.');
            if((!isset($post['recovery_review'])&&(!isset($result['state'])||$result['state']!=='complete'))&&!$edit)throw new \DomainException('Speicherzugang geändert.');
            return VideoRecoveryPanel::query($state['id'],$state['source_id'],$state['video_recovery_after'])+['notice'=>'','video_recovery_result'=>$result,'video_recovery_prepared'=>$prepared,'video_recovery_can_manage'=>true,'video_recovery_can_edit'=>$edit,'compose_state'=>['video_recovery_only'=>$state]];
        }
        if($view==='social'&&VideoRemovalForm::operation($op)){
            $actor=$this->access->require('social','read');$state=VideoRemovalForm::restore($post);$review=array_key_exists('file_review',$post);$prepare=array_key_exists('file_prepare',$post);
            if(($review&&$post['file_review']!=='1')||($prepare&&(!is_string($post['file_prepare'])||!in_array($post['file_prepare'],['remove','restore','purge','resume'],true)))||($review&&$prepare))throw new \DomainException('Ursprünglichen Videodateivorgang erneut öffnen.');
            $service=new \Kulturhus\Native\Communication\VideoRemovalRequests($this->db,$this->access,$this->mediaRoot);$prepared=null;
            if($prepare){$prepared=$service->prepare($state['id'],$state['source_id'],$post['file_prepare']);$state['kind']=$prepared['kind'];$state['removal_version']=$prepared['removal_version'];$state['removal_operation']=$prepared['removal_operation'];$state['confirm_video']=false;$result=['found'=>false];}
            else{$saved=$review?$service->review($state['id'],$state['source_id'],$state['kind'],(int)$state['removal_version'],$state['removal_operation']):$service->save($state['id'],$state['source_id'],$state['kind'],(int)$state['removal_version'],$state['removal_operation'],$state['confirm_video']);$result=$saved?['found'=>true]+$saved:['found'=>false];if($saved&&$saved['complete'])$state['confirm_video']=false;}
            $state['result']=$prepare?null:$result;$state['prepared']=$prepare;$current=$service->status($state['id'],$state['source_id']);
            $manage=$this->can('social','manage');$usage=(new \Kulturhus\Native\Communication\VideoQuota($this->db,$this->access))->get();if(!$manage){$current=array_intersect_key($current,array_flip(['version','state','actor_name','changed_at']));if($result['found'])$service->review($state['id'],$state['source_id'],$state['kind'],(int)$state['removal_version'],$state['removal_operation']);}else$this->access->require('social','manage');$latest=$this->access->require('social','read');if($latest['id']!==$actor['id']||$latest['club_id']!==$actor['club_id'])throw new \DomainException('Speicherzugang geändert.');
            return ['view'=>'social','item'=>$state['id'],'section'=>'compose','video_page'=>(int)$state['video_page'],'video_archive'=>'1','notice'=>'','video_file_result'=>$result,'video_file_current'=>$current,'video_file_prepared'=>$prepared?['kind'=>$prepared['kind'],'operation'=>VideoRemovalForm::action($prepared['kind']),'removal_version'=>(int)$prepared['removal_version'],'removal_operation'=>$prepared['removal_operation']]:null,'video_file_can_manage'=>$manage,'video_file_usage'=>$usage,'compose_state'=>['video_file_only'=>$state]];
        }
        if($view==='social'&&$op==='social-video-archive'){
            $state=VideoArchiveForm::restore($post);$review=array_key_exists('archive_review',$post);$next=array_key_exists('archive_next',$post);
            if(($review&&$post['archive_review']!=='1')||($next&&$post['archive_next']!=='1')||($review&&$next))throw new \DomainException('Ursprüngliches Videoarchivformular öffnen.');
            $service=new \Kulturhus\Native\Communication\VideoArchiveSaveRequests($this->db,$this->access,$this->mediaRoot);
            if($review||$next){$saved=$service->review($state['id'],$state['source_id'],$state['archive_request_key']);$result=$saved?['found'=>true]+$saved:['found'=>false];
                if($next){$this->access->require('social','edit');if(!$saved)throw new \DomainException('Gespeicherten Videoarchivnachweis prüfen.');$state['archive_version']=(string)$saved['version'];$state['archived']=$saved['archived']?'0':'1';$state['archive_request_key']=bin2hex(random_bytes(16));$result=['found'=>false];}
            }else{$result=['found'=>true]+$service->save($state['id'],$state['source_id'],$state['archived']==='1',(int)$state['archive_version'],$state['archive_request_key']);}
            $state['result']=$next?null:$result;$state['prepared']=$next;$state['saved_now']=!$review&&!$next;
            $page=(new \Kulturhus\Native\Communication\Videos($this->db,$this->access,$this->mediaRoot))->page($state['id'],1,false);
            return ['view'=>'social','item'=>$state['id'],'section'=>'compose','notice'=>'','video_archive'=>$result['found']?($result['archived']?'1':'0'):($state['archived']==='1'?'0':'1'),'video_page'=>(int)$state['video_page'],'video_archive_result'=>$result,'video_archive_can_edit'=>$this->can('social','edit'),'video_archive_catalogue'=>['item_id'=>$state['id'],'active_total'=>$page['active_total'],'archived_total'=>$page['archived_total']],'compose_state'=>['video_archive_only'=>$state]];
        }
        if($view==='social'&&VideoPolicyForm::operation($op)){
            $state=VideoPolicyForm::restore($post);$review=array_key_exists('policy_review',$post);$next=array_key_exists('policy_next',$post);
            if(($review&&$post['policy_review']!=='1')||($next&&$post['policy_next']!=='1')||($review&&$next))throw new \DomainException('Ursprüngliches Videospeicherformular öffnen.');
            $service=new \Kulturhus\Native\Communication\VideoPolicySaveRequests($this->db,$this->access,$this->mediaRoot,$state['kind']);
            if($review||$next){$saved=$service->review($state['id'],$state['policy_request_key']);$result=$saved?['found'=>true]+$saved:['found'=>false];
                if($next){$this->access->require('social','manage');if(!$saved)throw new \DomainException('Gespeicherten Videospeichernachweis prüfen.');$state['policy_version']=(string)$saved['version'];$state['policy_request_key']=bin2hex(random_bytes(16));$result=['found'=>false];}
            }else{$result=['found'=>true]+$service->save($state['id'],$state['value'],(int)$state['policy_version'],$state['policy_request_key']);}
            $state['result']=$next?null:$result;$state['prepared']=$next;$state['saved_now']=!$review&&!$next;
            return ['view'=>'social','item'=>$state['id'],'section'=>'compose','notice'=>'','video_policy_result'=>$result,'video_policy_prepared'=>$next?['request_key'=>$state['policy_request_key'],'version'=>(int)$state['policy_version']]:null,'video_policy_can_manage'=>$this->can('social','manage'),'video_policy_usage'=>$state['kind']==='quota'?(new \Kulturhus\Native\Communication\VideoQuota($this->db,$this->access))->get():null,'compose_state'=>['video_policy_only'=>$state]];
        }
        if($view==='social'&&$op==='social-video-metadata-save'){
            $state=VideoMetadataForm::restore($post);$review=array_key_exists('metadata_review',$post);$next=array_key_exists('metadata_next',$post);
            if(($review&&$post['metadata_review']!=='1')||($next&&$post['metadata_next']!=='1')||($review&&$next))throw new \DomainException('Ursprüngliches Videoangabenformular öffnen.');
            $service=new \Kulturhus\Native\Communication\VideoMetadataSaveRequests($this->db,$this->access,$this->mediaRoot,!$review&&!$next&&$state['replace_proof']?$this->box():null);
            if($review||$next){
                $saved=$service->review($state['id'],$state['source_id'],$state['metadata_request_key']);$result=$saved?['found'=>true]+$saved:['found'=>false];
                if($next){$this->access->require('social','edit');if(!$saved)throw new \DomainException('Gespeicherten Videoangabennachweis prüfen.');$state['metadata_version']=(string)$saved['version'];$state['metadata_request_key']=bin2hex(random_bytes(16));$result=['found'=>false];}
            }else{
                $saved=$service->save($state['id'],$state['source_id'],$state['metadata'],(int)$state['metadata_version'],$state['replace_proof']?$state['private_proof']:null,$state['metadata_request_key']);$result=['found'=>true]+$saved;
                // Clear only an accepted proof. An unchecked local proof remains an unsaved draft.
                if($state['replace_proof']){$state['private_proof']='';$state['replace_proof']=false;}
            }
            $state['result']=$next?null:$result;$state['prepared']=$next;$state['saved_now']=!$review&&!$next&&$state['private_proof']==='';
            return ['view'=>'social','item'=>$state['id'],'section'=>'compose','notice'=>'','video_metadata_result'=>$result,'video_metadata_prepared'=>$next?['request_key'=>$state['metadata_request_key'],'version'=>(int)$state['metadata_version']]:null,'video_metadata_can_edit'=>$this->can('social','edit'),'video_metadata_can_proof'=>$this->can('social','manage'),'compose_state'=>['video_metadata_only'=>$state]];
        }
        if($view==='social'&&$op==='social-video-renew'){
            if(!is_string($post['video_id']??null)||!is_string($post['version']??null)||!preg_match('/^[1-9][0-9]{0,9}$/D',$post['version']))throw new \DomainException('Videofreigabe erneut öffnen.');
            (new Communication($this->db,$this->access,'social',$this->mediaRoot))->renewVideoApproval($id,(int)$post['version'],$post['video_id']);
            return ['view'=>'social','item'=>$id,'video_page'=>1,'notice'=>'Fassung für neue Freigabe vorbereitet.'];
        }
        if($view==='social'&&$op==='social-video-save'){
            $file=$files['video_file']??null;$input=$post;
            if(is_array($file)&&is_string($file['name']??null)&&$file['name']!=='')$input['original_name']=basename(str_replace('\\','/',$file['name']));
            $state=VideoUploadForm::restore($input);$service=new \Kulturhus\Native\Communication\VideoSaveRequests($this->db,$this->access,$this->mediaRoot);
            if(array_key_exists('video_review',$post)){
                if($post['video_review']!=='1')throw new \DomainException('Ursprüngliches Videoformular öffnen.');
                $saved=$service->review($state['id'],$state['video_request_key']);$result=$saved?['found'=>true]+$saved:['found'=>false];
            }else{
                if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))throw new \DomainException('MP4-Datei auswählen.');
                $result=['found'=>true]+$service->save($state['id'],(int)$state['version'],(int)$state['identity_version'],$file['tmp_name'],$file['name'],$state['video_request_key']);
            }
            $state['result']=$result;
            return ['view'=>'social','item'=>$state['id'],'section'=>'compose','notice'=>'','video_result'=>$result,'video_usage'=>(new \Kulturhus\Native\Communication\VideoQuota($this->db,$this->access))->get(),'video_can_edit'=>$this->can('social','edit'),'compose_state'=>['video_only'=>$state]];
        }
        if($view==='social'&&in_array($op,['social-mail-settings','social-mail-prepare','social-mail-send','social-mail-fetch','social-mail-use'],true)){
            foreach(['mail_id','mail_version','mail_answer','mail_note','mail_confirm','attention_id','attention_version','reply_id','reply_version','reply_text']as$field)if(isset($post[$field])&&!is_string($post[$field])&&!is_int($post[$field]))throw new \DomainException('Mailformular erneut öffnen.');
            if($op==='social-mail-settings'){
                if(!is_array($post['mail']??null))throw new \DomainException('Mailmodul-Einstellungen prüfen.');
                (new \Kulturhus\Native\Communication\Mail\Settings($this->db,$this->box()))->save($this->access,$post['mail'],(int)($post['mail_version']??0));
                return ['view'=>'social','section'=>'mail','notice'=>'Mailmodul gespeichert.'];
            }
            $mail=new \Kulturhus\Native\Communication\Mail\Bridge($this->db,$this->box());$reply=null;
            if($op==='social-mail-prepare'){
                $row=$mail->prepare($this->access,(string)($post['attention_id']??''),(int)($post['attention_version']??0),(string)($post['mail_note']??''),(string)($post['mail_id']??''),(int)($post['mail_version']??0));$source=$row['payload']['original'];$notice='Mail vorbereitet · nicht versendet.';
            }elseif($op==='social-mail-send'){
                $row=$mail->send($this->access,(string)($post['mail_id']??''),(int)($post['mail_version']??0),($post['mail_confirm']??null)==='1');$source=$row['payload']['original'];$notice=$row['status']==='accepted'?'Mail vom Server angenommen.':'Mailergebnis unklar. Nicht erneut senden.';
            }elseif($op==='social-mail-use'){
                if(($post['mail_confirm']??null)!=='1')throw new \DomainException('Übernahme der Mailantwort bestätigen.');
                $reply=$mail->useAnswer($this->access,(string)($post['mail_answer']??''),(string)($post['attention_id']??''),(int)($post['attention_version']??0),(string)($post['reply_text']??''),(string)($post['reply_id']??''),(int)($post['reply_version']??0));
                $source=['id'=>$reply['attention_id'],'kind'=>$reply['payload']['kind'],'channel'=>$reply['channel'],'parent_id'=>$reply['parent_id']];$notice='Mailantwort als Entwurf übernommen · nicht versendet.';
            }else{
                $result=$mail->fetch($this->access);$source=(new \Kulturhus\Native\Communication\Replies($this->db,$this->box()))->source($this->access,(string)($post['attention_id']??''));
                $notice=$result['status']==='disabled'?'Antwortabruf ausgeschaltet.':$this->conversationText(isset($result['delivery'])?'{new} neue Mailantworten · {sent} gesendet · {review} Versand prüfen · {failed} nicht übernommen.':'{new} neue Mailantworten · {failed} nicht übernommen.',['new'=>$result['new'],'failed'=>$result['failed'],'sent'=>$result['delivery']['sent']??0,'review'=>array_sum(array_intersect_key($result['delivery']??[],array_flip(['blocked','uncertain','rejected'])))]);
            }
            $route=['view'=>'social','notice'=>$notice];
            if($source['kind']==='message')$route+=['section'=>'inbox','thread'=>\Kulturhus\Native\Communication\MessageReplyProvider::threadId($this->access->club(),$source['channel'],$source['parent_id'])];
            else $route+=['section'=>$reply?'replies':'review','attention'=>$source['id']];
            if($reply){$route['reply']=$reply['id'];unset($route['attention']);}
            return $route;
        }
        $work = new Communication($this->db, $this->access, $view,$this->mediaRoot);
        if($op==='communication-photo-upload'){
            $this->access->require($view,'edit');
            $this->access->require('website','edit');
            if($id!=='')$work->get($id);
            $file=$files['photo']??null;
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_string($file['name']??null)||!is_uploaded_file($file['tmp_name']))throw new \DomainException('Fotoupload fehlgeschlagen.');
            $uploadId=$post['upload_id']??null;if($uploadId!==null)\Kulturhus\Native\Communication\Content::id($uploadId);
            $image=(new Media($this->db,$this->access,$this->mediaRoot))->upload($file['tmp_name'],$file['name'],'',null,null,$uploadId);
            return ['view'=>$view,'item'=>$id,'notice'=>'Foto hochgeladen.','image'=>['id'=>$image['id'],'alt'=>$image['alt'],'name'=>$image['original_name'],'url'=>'/media-preview/'.$image['id'].'/thumb']];
        }
        if (
            in_array(
                $op,
                [
                    "social-reply-save",
                    "social-reply-direct",
                    "social-reply-human-review",
                    "social-reply-send",
                    "social-reply-reconcile",
                ],
                true,
            ) &&
            $view === "social"
        ) {
            foreach (
                [
                    "attention_id",
                    "attention_version",
                    "reply_id",
                    "reply_version",
                    "reply_text",
                    "human_key",
                    "human_send",
                ]
                as $field
            ) {
                if (
                    isset($post[$field]) &&
                    !is_string($post[$field]) &&
                    !is_int($post[$field])
                ) {
                    throw new \DomainException("Antwort erneut auswählen.");
                }
            }
            $service = new \Kulturhus\Native\Communication\Replies(
                $this->db,
                $this->box(),
            );
            if($op==='social-reply-human-review')return HumanFormState::review($this->access,$service,$post);
            if($op==='social-reply-direct'||($op==='social-reply-save'&&($post['human_send']??null)==='1')){
                $reply=$service->sendHuman($this->access,(string)($post['attention_id']??''),(int)($post['attention_version']??0),(string)($post['reply_text']??''),(string)($post['human_key']??''),(string)($post['reply_id']??''),(int)($post['reply_version']??0));
                $notice=match($reply['status']){'sent'=>'Gesendet.','rejected'=>'Nicht gesendet · Anbieter abgelehnt.','draft','queued'=>'Noch nicht gesendet.',default=>'Ergebnis unklar · nicht erneut senden.'};
            } elseif ($op === "social-reply-save") {
                $reply = $service->save(
                    $this->access,
                    (string) ($post["attention_id"] ?? ""),
                    (int) ($post["attention_version"] ?? 0),
                    (string) ($post["reply_text"] ?? ""),
                    (string) ($post["reply_id"] ?? ""),
                    (int) ($post["reply_version"] ?? 0),
                );
                $notice = "Entwurf gespeichert.";
            } elseif ($op === "social-reply-send") {
                $reply = $service->send(
                    $this->access,
                    (string) ($post["reply_id"] ?? ""),
                    (int) ($post["reply_version"] ?? 0),
                    ($post["reply_confirm"] ?? null) === "1",
                );
                $notice = match ($reply["status"]) {
                    "sent" => "Antwort gesendet und bestätigt.",
                    "rejected"
                        => "Antwort abgelehnt. Rechte und Verbindung prüfen.",
                    default
                        => "Versand unklar. Ergebnis prüfen.",
                };
            } else {
                $proof = $service->reconcile(
                    $this->access,
                    (string) ($post["reply_id"] ?? ""),
                    (int) ($post["reply_version"] ?? 0),
                );
                $reply = $proof["reply"];
                $notice = $proof["confirmed"]
                    ? "Bestehende Antwort gesendet und bestätigt."
                    : "Antwort noch nicht bestätigt.";
            }
            $isMessage = ($reply['payload']['kind'] ?? 'comment') === 'message';
            return [
                "view" => "social",
                "section" => $isMessage ? "inbox" : "replies",
                "reply" => $reply["id"],
                "notice" => $notice,
            ] + ($isMessage ? ['thread'=>\Kulturhus\Native\Communication\MessageReplyProvider::threadId($this->access->club(),$reply['channel'],$reply['parent_id'])] : []);
        }
        if(in_array($op,['social-comment-moderate','social-comment-moderation-check','social-comment-moderation-close'],true)&&$view==='social'){
            $human=array_key_exists('reply_text',$post)?HumanFormState::restore($post):null;
            foreach(['attention_id','moderation_id','moderation_key','moderation_hidden','moderation_version','moderation_close_version','moderation_close_confirm']as$field)if(isset($post[$field])&&!is_string($post[$field]))throw new \DomainException('Moderationsformular erneut öffnen.');
            $service=new \Kulturhus\Native\Communication\CommentModeration($this->db,$this->box());
            if($op==='social-comment-moderate'){
                if(!in_array($post['moderation_hidden']??null,['0','1'],true)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$post['moderation_version']??''))throw new \DomainException('Ursprüngliche Moderation prüfen.');
                $row=$service->execute($this->access,$post['attention_id']??'',(int)$post['moderation_version'],$post['moderation_hidden']==='1',$post['moderation_key']??'');
            }else{
                $original=$service->original($this->access,$post['moderation_id']??'');
                if(($post['attention_id']??null)!==$original['id']||($human&&$human['attention_id']!==$original['id']))throw new \DomainException('Ursprünglichen Moderationsvorgang auswählen.');
                if($op==='social-comment-moderation-close'){
                    if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$post['moderation_close_version']??''))throw new \DomainException('Ursprünglichen Moderationsstand prüfen.');
                    $row=$service->close($this->access,$post['moderation_id'],(int)$post['moderation_close_version'],($post['moderation_close_confirm']??null)==='1');
                }else $row=$service->reconcile($this->access,$post['moderation_id']??'');
            }
            $source=$service->original($this->access,$row['id']);
            if($human&&$human['attention_id']!==$source['id'])throw new \DomainException('Antwort und Moderation gehören zu verschiedenen Originalen.');
            if($human&&$row['status']==='confirmed'&&$human['reply_id']===''&&$human['attention_version']===(int)$source['version']&&!\Kulturhus\Native\Shared\Db::run($this->db,'SELECT 1 FROM native_social_human_sends WHERE club_id=? AND request_key=?',[$this->access->club(),$human['human_key']])->fetchColumn()){
                $current=(new \Kulturhus\Native\Communication\Replies($this->db,$this->box()))->source($this->access,$source['id']);$before=$source['payload'];$after=$current['payload'];unset($before['hidden'],$after['hidden']);ksort($before);ksort($after);if($before===$after)$human['attention_version']=(int)$current['version'];
            }
            return ['view'=>'social','section'=>'review','attention'=>$source['id'],'notice'=>ModerationControls::label($row)]+($human?['compose_state'=>['human'=>$human]]:[]);
        }
        if(in_array($op,['social-post-correct','social-post-correction-check','social-post-correction-close'],true)&&$view==='social'){
            $this->access->require('social','edit');$this->access->require('social','publish');
            $fields=['object_id','correction_fingerprint','correction_key','correction_text','correction_confirm','correction_id','correction_version','correction_checked','correction_reload'];
            foreach($fields as$field)if(isset($post[$field])&&!is_string($post[$field]))throw new \DomainException('Beitragskorrektur erneut öffnen.');
            $service=new \Kulturhus\Native\Communication\PostCorrections($this->db,$this->box());$object=$post['object_id']??'';$service->source($this->access,$object);$state=[];$notice='';
            try{
                if($op==='social-post-correct'&&($post['correction_reload']??null)==='1'){
                    $current=$service->source($this->access,$object);$state=array_intersect_key($post,array_flip($fields));$state['correction_fingerprint']=$current['fingerprint'];$state['correction_key']=bin2hex(random_bytes(16));unset($state['correction_confirm'],$state['correction_reload']);
                    return ['view'=>'social','section'=>'library','notice'=>'Aktueller Originalstand geöffnet.','compose_state'=>['post_correction'=>$state]];
                }
                if($op==='social-post-correct'){
                    if(($post['correction_confirm']??null)!=='1')throw new \DomainException('Ersetzen des ausgewählten Facebook-Textes bestätigen.');
                    $row=$service->execute($this->access,$object,$post['correction_fingerprint']??'',$post['correction_text']??'',$post['correction_key']??'');
                }else{
                    $original=$service->get($this->access,$post['correction_id']??'');if($original['object_id']!==$object)throw new \DomainException('Vorgang gehört zu einem anderen Beitrag.');
                    if($op==='social-post-correction-close'){
                        if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$post['correction_version']??''))throw new \DomainException('Aktuelle Korrekturversion prüfen.');
                        $row=$service->close($this->access,$original['id'],(int)$post['correction_version'],($post['correction_checked']??null)==='1');
                    }else $row=$service->reconcile($this->access,$original['id']);
                }
                $notice=PostCorrectionControls::label($row);
            }catch(\DomainException $error){$state=array_intersect_key($post,array_flip($fields));$state['error']=$error->getMessage();}
            catch(\Throwable){$state=array_intersect_key($post,array_flip($fields));$state['error']='Speichern nicht bestätigt. Ursprünglichen Vorgang prüfen.';}
            return ['view'=>'social','section'=>'library','notice'=>$notice,'compose_state'=>['post_correction'=>$state]];
        }
        if ($op === "social-attention-state" && $view === "social") {
            (new \Kulturhus\Native\Communication\Attention(
                $this->db,
                $this->box(),
            ))->setState(
                $this->access,
                (string) ($post["attention_id"] ?? ""),
                (string) ($post["attention_state"] ?? ""),
                (int) ($post["attention_version"] ?? 0),
            );
            return [
                "view" => "social",
                "section" => "review",
                "attention" => (string) ($post["attention_id"] ?? ""),
                "notice" => "Status gespeichert.",
            ];
        }
        if($view==='social'&&in_array($op,['social-sync','social-insights','social-comments','stadtlandfunk-sync'],true))return(new SocialLibrary($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post);
        if($op==='social-check-plan'&&$view==='social')return(new CheckPlanEditor($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post);
        if(in_array($view,['social','press'],true)&&$op===$view.'-event-draft')return(new EventSources($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($view,$post);

        if ($op === 'social-inbox-sync' && $view === 'social')return(new InboxSync($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post);
        if ($op === "communication-save") {
            $data = [
                "id" => $id,
                "title" => $post["title"] ?? "",
                "summary" => $post["summary"] ?? "",
                "body" => $post["body"] ?? "",
                "template" => $post["template"] ?? "",
                "kind" => $post["kind"] ?? "",
            ];
            if (array_key_exists("body_html", $post)) {
                $data["body_html"] = $post["body_html"];
                $data["body"] = "";
            }
            if ($id) {
                $old = $work->get($id);
                foreach (["blocks", "channels", "story", "carousel"] as $field) {
                    if (isset($old[$field])) {
                        $data[$field] = $old[$field];
                    }
                }
            }
            if (trim((string) ($post["blocks_json"] ?? "")) !== "") {
                $data["blocks"] = in_array($view,['newsletter','press'],true) ? NewsletterComposerState::blocks($post['blocks_json']) : json_decode(
                    $post["blocks_json"],
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
            }
            if ($view === "newsletter") {
                $data["audience"] = $post["audience"] ?? "all";
                $documents=PdfAttachments::fromPost($post);if($documents!==null)$data['document_attachments']=$documents;
                if($documents!==null)$data['attachment_rights']=PdfAttachments::rights($post);
            }
            if ($view === "press") {
                if(array_key_exists('attachment_picker_present',$post)||array_key_exists('attachment_media_ids',$post))$data['attachment_media_ids']=\Kulturhus\Native\Communication\PressPhotoAttachments::selection($post);
                $data["recipient_ids"] = \Kulturhus\Native\Communication\PressRecipientCatalogue::selection($post);
            }
            if ($view === "social") {
                $data["story_link_requested"] = isset(
                    $post["story_link_requested"],
                );
                $data["destination_url"] = $post["destination_url"] ?? "";
                $data["image_design"] = [
                    "layout" => $post["image_layout"] ?? "editorial",
                    "palette" => $post["image_palette"] ?? "forest",
                    "focal_x" => $post["image_focal_x"] ?? 50,
                    "focal_y" => $post["image_focal_y"] ?? 50,
                ];
                $data["channels"] = array_values(
                    array_intersect((array) ($post["channels"] ?? []), [
                        "facebook",
                        "instagram",
                    ]),
                );
                if(array_key_exists('story_slides',$post))$data['story']=SocialComposerState::slides($post,true);
                elseif (trim((string) ($post["story_json"] ?? "")) !== "") {
                    $data["story"] = json_decode(
                        $post["story_json"],
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    );
                }
                if ($data["kind"] !== "story") {
                    $data["story"] = [];
                }
                if($data['kind']==='carousel')$data['carousel']=SocialComposerState::cards($post);
                else $data['carousel']=[];
            }
            if($view==='social'&&in_array($data['kind'],['post','story','reel','carousel'],true)&&in_array($data['template'],['social-post','social-story','social-reel','social-carousel'],true))$data['template']='social-'.$data['kind'];
            $newPhotos=$view==='press'?\Kulturhus\Native\Web\Website\PagePhotoUploads::fromFiles($files,'press_photos'):[];
            if($view==='social'&&array_key_exists('save_request_key',$post)){
                if(!is_string($post['save_request_key']))throw new \DomainException('Ursprüngliches Social-Formular öffnen.');
                $result=(new \Kulturhus\Native\Communication\EditionSaveRequests($this->db,$this->access,$this->mediaRoot,'social'))->save($data,$version,$post['save_request_key']);
            }elseif($view==='press'&&array_key_exists('save_request_key',$post)){
                if(!is_string($post['save_request_key']))throw new \DomainException('Ursprüngliches Presseformular öffnen.');
                $result=(new \Kulturhus\Native\Communication\EditionSaveRequests($this->db,$this->access,$this->mediaRoot,'press'))->save($data,$version,$post['save_request_key'],$newPhotos);
            }elseif($newPhotos)$result=(new \Kulturhus\Native\Cms\MediaUploads($this->db,new \Kulturhus\Native\Communication\PressUploadAccess($this->db,$this->access),$this->mediaRoot))->uploadMany($newPhotos,function(array $photos)use($work,$data,$version):array{$data['attachment_media_ids']=[...($data['attachment_media_ids']??[]),...array_column($photos,'id')];return $work->save($data,$version);});
            elseif($view==='newsletter'&&!array_key_exists('save_request_key',$post)&&PdfAttachments::uploads($files)){throw new \DomainException('Newsletterformular erneut öffnen.');}
            elseif($view==='newsletter'&&array_key_exists('save_request_key',$post)){
                if(!is_string($post['save_request_key']))throw new \DomainException('Ursprüngliches Newsletterformular öffnen.');
                $result=(new \Kulturhus\Native\Communication\EditionSaveRequests($this->db,$this->access,$this->mediaRoot))->save($data,$version,$post['save_request_key'],PdfAttachments::uploads($files));
            }
            else $result = $work->save($data, $version);
            $id = $result["id"];
        } elseif ($op === "social-canvas-save" && $view === "social") {
            if(!is_string($post['canvas_request_key']??null))throw new \DomainException('Ursprüngliches Bildformular öffnen.');
            $file = $files["canvas_image"] ?? [];
            if (
                ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
                !is_uploaded_file($file["tmp_name"] ?? "")
            ) {
                throw new \DomainException(
                    "Bildexport fehlt oder Upload fehlgeschlagen.",
                );
            }
            $json = \Kulturhus\Native\Communication\Content::text(
                $post["editor_document"] ?? "",
                100000,
                true,
            );
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                throw new \DomainException("Gestaltung fehlt.");
            }
            $storySources=\Kulturhus\Native\Communication\StorySeries::uploads($files,$post['story_template_complete']??null);
            if($storySources!==[]&&!in_array($post['story_template_hint']??null,['0','1'],true))throw new \DomainException('Story-Tageshinweis prüfen.');
            $imageId=(new \Kulturhus\Native\Communication\Images(
                $this->db,
                $this->access,
                $this->mediaRoot,
            ))->saveCanvas(
                $id,
                $version,
                (int) ($post["identity_version"] ?? 0),
                (int) ($post["slide"] ?? -1),
                $document,
                $file["tmp_name"],
                $post['canvas_request_key'],
                $storySources,
                $storySources!==[]?$post['story_template_hint']==='1':null,
            );
            return ['view'=>'social','item'=>$id,'notice'=>'Bildfassung gespeichert.','canvas_result'=>['found'=>true,'id'=>$imageId,'item_id'=>$id,'item_version'=>$version,'slide'=>(int)($post['slide']??-1),'url'=>'/social-image/'.$imageId]];
        } elseif ($op === "social-photo-upload" && $view === "social") {
            $this->access->require("social", "edit");
            $state=PhotoUploadForm::restore($post);$work->get($id);
            $photos=new \Kulturhus\Native\Communication\PhotoSaveRequests($this->db,$this->access,$this->mediaRoot);
            if(array_key_exists('photo_review',$post)){
                if($post['photo_review']!=='1')throw new \DomainException('Ursprüngliches Fotoformular öffnen.');
                $saved=$photos->review($id,$state['photo_request_key']);$state['result']=$saved?['found'=>true]+$saved:['found'=>false];
                return ['view'=>'social','item'=>$id,'section'=>'compose','notice'=>'','photo_result'=>$state['result'],'compose_state'=>['photo_only'=>$state]];
            }
            $file = $files["photo"] ?? [];
            if (
                ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
                !is_uploaded_file($file["tmp_name"] ?? "")
            ) {
                throw new \DomainException("Fotoupload fehlgeschlagen.");
            }
            $saved=$photos->save($id,(int)$state['version'],
                $file["tmp_name"],
                $file["name"],
                $state['alt'],$state['photo_request_key']);
            $state['result']=['found'=>true]+$saved;
            return ['view'=>'social','item'=>$id,'section'=>'compose','notice'=>'','photo_result'=>$state['result'],'compose_state'=>['photo_only'=>$state]];
        } elseif ($op === "social-render" && $view === "social") {
            (new \Kulturhus\Native\Communication\Images(
                $this->db,
                $this->access,
                $this->mediaRoot,
            ))->render($id);
        } elseif (
            in_array(
                $op,
                [
                    "story-handoff-prepare",
                    "story-handoff-complete",
                    "story-handoff-cancel",
                ],
                true,
            ) &&
            $view === "social"
        ) {
            $handoff = new \Kulturhus\Native\Communication\StoryHandoff(
                $this->db,
                $this->access,
                $this->mediaRoot,
            );
            if ($op === "story-handoff-prepare") {
                $handoff->prepare($id, $version);
            } elseif ($op === "story-handoff-complete") {
                $handoff->complete(
                    (string) ($post["handoff_id"] ?? ""),
                    (int) ($post["handoff_version"] ?? 0),
                    [
                        "images_checked" => isset($post["images_checked"]),
                        "link_added" => isset($post["link_added"]),
                        "published" => isset($post["published"]),
                    ],
                    (string) ($post["reference_url"] ?? ""),
                );
            } else {
                $handoff->cancel(
                    (string) ($post["handoff_id"] ?? ""),
                    (int) ($post["handoff_version"] ?? 0),
                    (string) ($post["reason"] ?? ""),
                );
            }
            return [
                "view" => "social",
                "item" => $id,
                "notice" =>
                    $op === "story-handoff-complete"
                        ? "Manuelle Veröffentlichung dokumentiert."
                        : ($op === "story-handoff-cancel"
                            ? "Übergabe abgebrochen."
                            : "App-Übergabe vorbereitet."),
            ];
        } elseif ($op === 'stadtlandfunk-schedule' && $view === 'social') {
            foreach(['id','version','city_scheduled_at','city_confirm']as$field)if(isset($post[$field])&&!is_string($post[$field])&&!is_int($post[$field]))throw new \DomainException('Planungsformular erneut öffnen.');
            if(($post['city_confirm']??null)!=='1')throw new \DomainException('StadtLand.Funk als Veröffentlichungsziel bestätigen.');
            $at=$this->localTime((string)($post['city_scheduled_at']??''));
            $job=\Kulturhus\Native\Shared\Db::atomic($this->db,function()use($work,$id,$at,$version){
                $item=$work->schedule($id,$at,$version);
                return (new \Kulturhus\Native\Communication\PersonalPublication($this->db,$this->box()))->queue($this->access,$id,$item['version'],\Kulturhus\Native\Communication\Content::time($at),$this->mediaRoot,true);
            });
            return ['view'=>'social','section'=>'planning','queue_job'=>$job,'notice'=>'StadtLand.Funk eingeplant.'];
        } elseif ($op === 'stadtlandfunk-reconcile' && $view === 'social') {
            if(!is_string($post['job_id']??null))throw new \DomainException('Auftrag erneut öffnen.');
            $proof=(new \Kulturhus\Native\Communication\PersonalPublication($this->db,$this->box()))->reconcile($this->access,$post['job_id']);
            return PlanningFormState::jobRoute($planningRoute,$post['job_id'],'stadtlandfunk')+['notice'=>$planningText($proof['publication_confirmed']?'Veröffentlichung bestätigt.':'Ergebnis weiterhin unklar.')];
        } elseif ($op === "meta-reconcile" && $view === "social") {
            if(!is_string($post['job_id']??null))throw new \DomainException('Auftrag erneut öffnen.');
            $proof=(new \Kulturhus\Native\Communication\Meta(
                $this->db,
                $this->box(),
                null,
                $this->mediaRoot,
            ))->reconcile($this->access, (string) $post["job_id"]);
            return PlanningFormState::jobRoute($planningRoute,$post['job_id'],'meta')+['notice'=>$planningText($proof['publication_confirmed']?'Veröffentlichung bestätigt.':(!empty($proof['resumed'])?'Vorbereitung wird fortgesetzt.':'Ergebnis noch nicht bestätigt.'))];
        } elseif ($op === 'meta-carousel-stop' && $view === 'social') {
            if(!is_string($post['job_id']??null)||($post['confirm_stop']??null)!=='1')throw new \DomainException('Vorbereitung ausdrücklich stoppen.');
            (new \Kulturhus\Native\Communication\Meta($this->db,$this->box(),null,$this->mediaRoot))->stopCarousel($this->access,$post['job_id']);
            return PlanningFormState::jobRoute($planningRoute,$post['job_id'],'meta')+['notice'=>$planningText('Vorbereitung gestoppt.')];
        } elseif ($op === "communication-approve") {
            if(isset($post['video_id'])&&!is_string($post['video_id']))throw new \DomainException('Videofassung erneut auswählen.');
            $work->approve($id, $version,$post['video_id']??null);
        } elseif ($op === "communication-schedule") {
            $at = $this->localTime((string) ($post["scheduled_at"] ?? ""));
            \Kulturhus\Native\Shared\Db::atomic($this->db, function () use (
                $work,
                $id,
                $at,
                $version,
                $view,
            ) {
                $work->schedule($id, $at, $version);
                if ($view === "social") {
                    (new \Kulturhus\Native\Communication\Meta(
                        $this->db,
                        $this->box(),
                    ))->queue(
                        $this->access,
                        $id,
                        \Kulturhus\Native\Communication\Content::time($at),
                        $this->mediaRoot,
                    );
                }
                if ($view === "newsletter") {
                    (new \Kulturhus\Native\Communication\Campaigns(
                        $this->db,
                        $this->access,
                        $this->box(),
                        $this->mediaRoot,
                    ))->queue(
                        $id,
                        \Kulturhus\Native\Communication\Content::time($at),
                    );
                }
                if($view==='press'){
                    $scheduled=$work->get($id,true);
                    (new \Kulturhus\Native\Communication\PressCampaigns($this->db,$this->access,$this->box(),$this->mediaRoot))->queue($id,$scheduled['version'],\Kulturhus\Native\Communication\Content::time($at));
                }
            });
        } elseif ($op === "newsletter-test" && $view === "newsletter") {
            (new \Kulturhus\Native\Communication\Campaigns(
                $this->db,
                $this->access,
                $this->box(),
                $this->mediaRoot,
            ))->queue(
                $id,
                gmdate("Y-m-d H:i:s"),
                (string) ($post["test_email"] ?? ""),
            );
        } elseif (
            $op === "newsletter-renew-confirmation" &&
            $view === "newsletter"
        ) {
            $jobs = (new \Kulturhus\Native\Communication\Confirmations\Renewals(
                $this->db,
                $this->access,
                $this->box(),
                $this->mediaRoot,
            ))->queue(
                (string) ($post["subscriber_id"] ?? ""),
                (string) ($post["preview_hash"] ?? ""),
                (string) ($post["request_id"] ?? ""),
                ($post["renewal_confirmed"] ?? "") === "1",
            );
            return [
                "view" => "newsletter",
                "item" => "",
                "section" => "recipients",
                "notice" =>
                    count($jobs) .
                    " erneute Bestätigungsanfrage(n) eingeplant.",
            ];
        } elseif ($op === "newsletter-reminder" && $view === "newsletter") {
            (new \Kulturhus\Native\Communication\Campaigns(
                $this->db,
                $this->access,
                $this->box(),
            ))->remind((string) $post["subscriber_id"]);
            $id = "";
        } elseif (
            $op === "newsletter-reminder-plan" &&
            $view === "newsletter"
        ) {
            (new \Kulturhus\Native\Communication\ConfirmationReminders(
                $this->db,
                $this->access,
                $this->box(),
            ))->savePlan(
                NewsletterPlanForm::flag($post['enabled']??null),
                NewsletterPlanForm::number($post['plan_version']??null),
            );
            $id = "";
        } elseif ($op === "newsletter-digest" && $view === "newsletter") {
            (new \Kulturhus\Native\Communication\Campaigns(
                $this->db,
                $this->access,
                $this->box(),
            ))->planDigest(
                NewsletterPlanForm::period($post['period']??null),
                NewsletterPlanForm::flag($post['paused']??null),
                NewsletterPlanForm::number($post['plan_version']??null),
                NewsletterPlanForm::number($post['prepare_days']??7,21),
                NewsletterPlanForm::number($post['send_hour']??10,23),
            );
            $id = "";
        } elseif ($op === "newsletter-period-pause" && $view === "newsletter") {
            (new \Kulturhus\Native\Communication\Digests\Plans(
                $this->db,
                $this->access,
            ))->pause(
                NewsletterPlanForm::period($post['period']??null),
                is_string($post['period_key']??null)?$post['period_key']:throw new \DomainException('Zeitraum prüfen',422),
                NewsletterPlanForm::flag($post['paused']??null),
                NewsletterPlanForm::number($post['period_version']??null),
            );
            $id = "";
        } elseif ($op === "communication-run") {
            throw new \DomainException(
                "Geplante Zustellungen werden vom privaten Worker verarbeitet.",
            );
        } elseif ($op === "communication-template") {
            if(!is_string($post['name']??null))throw new \DomainException('Vorlagenname prüfen.');
            $work->templateFromItem($id,TemplateFormState::number($post['version']??0,4294967295),$post['name']);
        } elseif ($op === "communication-from-template") {
            if(!is_string($post['template_id']??null))throw new \DomainException('Vorlage auswählen.');
            $created = array_key_exists('template_version',$post)?$work->draftFromTemplate($post['template_id'],TemplateFormState::number($post['template_version'],4294967295)):$work->fromTemplate($post['template_id']);
            $id = $created["id"];
        } elseif ($op === "communication-automation") {
            $work->planAutomation(
                $id,
                $this->localTime((string) ($post["scheduled_at"] ?? "")),
                $version,
            );
        } elseif ($op === "communication-pause") {
            $pause=PlanningFormState::pause($post);
            $work->pauseAutomation($pause['id'],$pause['version'],$pause['paused']);
            return $planningRoute+['item'=>'','notice'=>$planningText($pause['paused']?'Automatische Vorbereitung pausiert.':'Automatische Vorbereitung fortgesetzt.')];
        } elseif ($op === "newsletter-consent" && $view === "newsletter") {
            (new Subscribers($this->db, $this->access))->recordWrittenConsent(
                (string) $post["email"],
                (string) $post["name"],
                (array) ($post["purposes"] ?? []),
                (string) $post["evidence"],
            );
            $id = "";
        } elseif ($op === "press-contact" && $view === "press") {
            (new PressContacts($this->db, $this->access))->save(
                [
                    "id" => $id,
                    "email" => $post["email"] ?? "",
                    "name" => $post["name"] ?? "",
                    "outlet" => $post["outlet"] ?? "",
                    "active" => isset($post["active"]),
                ],
                $version,
            );
            $id = "";
        } else {
            throw new \DomainException("Unbekannte Redaktionsaktion.");
        }
        $result = [
            "view" => $view,
            "item" => $id,
            "notice" => "Änderung gespeichert.",
        ];
        if (
            in_array(
                $op,
                [
                    "newsletter-digest",
                    "newsletter-period-pause",
                    "newsletter-reminder-plan",
                    "communication-pause",
                ],
                true,
            )
        ) {
            $result["section"] = "planning";
        }
        if (in_array($op,['newsletter-consent','press-contact'],true)) $result['section']='recipients';
        return $result;
    }
}
