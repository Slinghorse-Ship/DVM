<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
use Kulturhus\Native\Web\{Manager,Gallery,Html};
use Kulturhus\Native\Shared\Access;
/** Uses the existing manager shell; no additional sidebar, account chooser or router. */
final class EmbeddedManager
{
    public function __construct(private Manager $manager,private Access $access){}
    public function action(string $view,array $post,array $files): array
    {
        if(($post['operation']??'')==='identity')throw new \DomainException('Eine angemeldete Identität kann hier nicht gewechselt werden.');
        try { return $this->manager->action($view,$post,$files); }
        catch (\Kulturhus\Native\Cms\Conflict|\InvalidArgumentException|\DomainException $error) {
            if (PHP_SAPI !== 'cli') http_response_code($error instanceof \Kulturhus\Native\Cms\Conflict?409:422);
            if($error instanceof \DomainException)throw $error;
            throw new \DomainException($error->getMessage(), 0, $error);
        }
    }
    public function main(string $view,string $id='',int $before=PHP_INT_MAX,?array $composeState=null): string
    {
        $dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
        try{$dom->loadHTML('<?xml encoding="UTF-8">'.$this->manager->main($view,$id,$before,$composeState),LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $content=$dom->getElementById('content');if(!$content)throw new \RuntimeException('Native Ansicht unvollständig.');
        $xpath=new \DOMXPath($dom);
        if(in_array($view,['overview','website','navigation','news','media','events','model'],true))foreach($xpath->query('./h1|./p[@class="eyebrow"]',$content) as $heading)$heading->parentNode->removeChild($heading);
        foreach($xpath->query('.//textarea[@data-compose-live-seed]',$content) as $seedNode){
            $seed=json_decode($seedNode->textContent,true,64,JSON_THROW_ON_ERROR);foreach($seed['media']as&$source)$source['url']=Routes::translate($source['url']);unset($source);
            while($seedNode->firstChild)$seedNode->removeChild($seedNode->firstChild);$seedNode->appendChild($dom->createTextNode(json_encode($seed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)));
        }
        foreach($xpath->query('.//textarea[@data-canvas-seed]',$content) as $seedNode){
            $seed=json_decode($seedNode->textContent,true,64,JSON_THROW_ON_ERROR);
            foreach($seed['media'] as &$source)foreach(['url','thumb']as $key)$source[$key]=Routes::translate($source[$key]);unset($source);
            foreach($seed['media'] as &$source)if(isset($source['credit']['url']))$source['credit']['url']=Routes::translate($source['credit']['url']);unset($source);
            $seed['qr']=Routes::translate($seed['qr']);
            while($seedNode->firstChild)$seedNode->removeChild($seedNode->firstChild);
            $seedNode->appendChild($dom->createTextNode(json_encode($seed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)));
        }
        foreach($xpath->query('.//*[@data-native-chat]',$content) as $chat){
            $chat->setAttribute('data-status-url',Routes::endpoint('chat-status'));
            $chat->setAttribute('data-view-url',Routes::endpoint('chat-view'));
            $chat->setAttribute('data-room-url','/vereinsverwaltung/?view=chat&native_item=');
        }
        foreach($xpath->query('.//*[@data-page-live-html]',$content) as $seedNode){
            $preview=new \DOMDocument();$oldErrors=libxml_use_internal_errors(true);try{$preview->loadHTML('<?xml encoding="utf-8" ?>'.$seedNode->textContent,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($oldErrors);}
            foreach((new \DOMXPath($preview))->query('//*[@src or @href]') as $node)foreach(['src','href']as$attribute)if($node->hasAttribute($attribute))$node->setAttribute($attribute,Routes::translate($node->getAttribute($attribute)));
            while($seedNode->firstChild)$seedNode->removeChild($seedNode->firstChild);$seedNode->appendChild($dom->createTextNode($preview->saveHTML()));
        }
        foreach($xpath->query('.//*[@data-page-live-media]',$content) as $panel)$panel->setAttribute('data-page-live-media',Routes::translate($panel->getAttribute('data-page-live-media')));
        foreach($xpath->query('.//*[@data-page-media-preview]',$content) as $picker) $picker->setAttribute('data-page-media-preview',Routes::translate($picker->getAttribute('data-page-media-preview')));
        foreach($xpath->query('.//*[@data-page-photo-status]',$content) as $picker) $picker->setAttribute('data-page-photo-status',Routes::endpoint('page-photo-status'));
        foreach($xpath->query('.//*[@data-photo-copy-status-url]',$content) as $form) $form->setAttribute('data-photo-copy-status-url',Routes::endpoint('page-photo-status'));
        foreach($xpath->query('.//*[@data-library-photo-status-url]',$content) as $form) $form->setAttribute('data-library-photo-status-url',Routes::endpoint('page-photo-status'));
        foreach($xpath->query('.//*[@data-album-photo-status-url]',$content) as $form) $form->setAttribute('data-album-photo-status-url',Routes::endpoint('album-photo-status'));
        foreach($xpath->query('.//*[@href or @src or @data-full]',$content) as $node){
            // User content has no app navigation/media attributes and is not rewritten.
            foreach(['href','src','data-full','data-original'] as $attr)if($node->hasAttribute($attr)){
                if($node->tagName==='link'&&$attr==='href'&&$node->getAttribute('rel')==='stylesheet'&&$node->getAttribute('href')==='/assets/comments.css'){
                    $node->setAttribute('href','native-comments.css');continue;
                }
                if($attr==='href'&&!$node->hasAttribute('data-edition-document')&&!$node->hasAttribute('data-save-original')&&!$node->hasAttribute('data-nav')&&!($node->hasAttribute('data-native-media')&&str_starts_with($node->getAttribute($attr),'/media-preview/'))&&!str_starts_with($node->getAttribute($attr),'/design-preview?')&&!str_starts_with($node->getAttribute($attr),'/sections-preview?')&&!str_starts_with($node->getAttribute($attr),'/home-preview?')&&!str_starts_with($node->getAttribute($attr),'/preview?')&&!str_starts_with($node->getAttribute($attr),'/social-image/')&&!str_starts_with($node->getAttribute($attr),'/social-video/')&&!str_starts_with($node->getAttribute($attr),'/chat-file/')&&!($node->hasAttribute('data-native-document')&&(str_starts_with($node->getAttribute($attr),'/website-document-preview/')||str_starts_with($node->getAttribute($attr),'/file-preview/'))))continue;
                $node->setAttribute($attr,Routes::translate($node->getAttribute($attr)));
            }
        }
        foreach($xpath->query('.//form',$content) as $form){
            if(strtolower($form->getAttribute('method'))!=='post'){
                $fragment=parse_url($form->getAttribute('action'),PHP_URL_FRAGMENT);$fragment=is_string($fragment)&&preg_match('/\A[a-zA-Z][a-zA-Z0-9_.:-]{0,127}\z/D',$fragment)?'#'.$fragment:'';
                $form->setAttribute('method','get');$form->setAttribute('action','/vereinsverwaltung/'.$fragment);
                $formItem='';foreach($xpath->query('.//input[@name="item" or @name="native_item"]',$form) as $field){$value=$field->getAttribute('value');if(preg_match('/\A[a-f0-9]{32}\z/D',$value))$formItem=$value;}
                foreach($xpath->query('.//input[@name="view" or @name="tab" or @name="item" or @name="native_item"]',$form) as $field)$field->parentNode->removeChild($field);
                foreach(Routes::query($view,$formItem) as $name=>$value){$field=$dom->createElement('input');$field->setAttribute('type','hidden');$field->setAttribute('name',$name);$field->setAttribute('value',$value);$form->appendChild($field);}
                continue;
            }
            $field=$dom->createElement('input');$field->setAttribute('type','hidden');$field->setAttribute('name','action');$field->setAttribute('value','native-module');$form->appendChild($field);
            if($form->hasAttribute('action')&&$form->getAttribute('action')!=='')$form->setAttribute('action',Routes::translate($form->getAttribute('action')));
            $form->setAttribute('data-native-form','');
        }
        $html='';foreach($content->childNodes as $child)$html.=$dom->saveHTML($child);
        return '<section id="workspace" class="native-manager-embedded" data-view="'.Html::e($view).'" data-model-url="'.Html::e(Routes::endpoint('model-fields')).'" data-reference-url="'.Html::e(Routes::endpoint('reference-options')).'">'.$this->websiteTabs($view,$id).$html.'</section>'.($view==='media'?Gallery::lightbox($this->manager->contentInterfaceText(...)):'');
    }
    private function websiteTabs(string $view,string $id): string
    {
        if(!in_array($view,['overview','website','navigation','news','media','events','model','settings'],true))return '';
        $this->access->require('website','read');
        $tabs=['overview'=>'Übersicht','website'=>'Seiten','navigation'=>'Menü','news'=>'Aktuelles','media'=>'Fotogalerie','events'=>'Veranstaltungen'];
        try{$this->access->require('website','manage');$tabs['model']='Felder & Vorlagen';$tabs['settings']='Design & Verbindungen';}catch(\DomainException){}
        $compact=$view!=='overview';
        $t=$this->manager->interfaceText(...);
        $html='<header class="website-workspace-header"'.($compact?' data-website-detail':'').'><div><h1 tabindex="-1">'.Html::e($t('Website')).'</h1><p>'.Html::e($t('Website & Redaktion · Inhalte, Menü und Veröffentlichungen')).'</p></div><a class="btn btn-solid theme-secondary button secondary" href="/" target="_blank" rel="noopener"'.($compact?' aria-label="'.Html::e($t('Website ansehen ↗')).'"':'').'>'.Html::e($t($compact?'Website ↗':'Website ansehen ↗')).'</a></header><nav class="nav module-tabs section-sub-nav" aria-label="'.Html::e($t('Website verwalten')).'">';
        $ticketWorkspace=$view==='events'&&($_GET['tickets']??null)==='1';
        foreach($tabs as $target=>$label){$selected=$target===$view&&!($target==='events'&&$ticketWorkspace);$html.='<a class="nav-link'.($selected?' active':'').'" data-nav href="'.Html::e(Routes::url($target)).'"'.($selected?' aria-current="page"':'').'>'.Html::e($t($label)).'</a>';if($target==='events')$html.='<a class="nav-link'.($ticketWorkspace?' active':'').'" data-nav href="'.Html::e(Routes::url('events').'&tickets=1').'"'.($ticketWorkspace?' aria-current="page"':'').'>'.Html::e($t('Tickets verwalten')).'</a>';}

        return $compact||($view==='media'&&in_array($_GET['section']??'',['library','removed','storage','files'],true)) ? str_replace('<nav class="nav module-tabs section-sub-nav"', '<details class="website-area-menu"><summary>'.Html::e($t($ticketWorkspace?'Tickets verwalten':($tabs[$view]??'Website'))).' · '.Html::e($t('Websitebereiche')).'</summary><nav class="nav module-tabs section-sub-nav"', $html).'</nav></details>' : $html.'</nav>';
    }
}
