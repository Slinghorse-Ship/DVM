<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
use Kulturhus\Native\Web\Html;
/** One bounded workspace at a time. Native POST forms remain usable without JavaScript. */
final class View
{
    /** Only a local read route survives a rejection; no submitted operation or credential. */
    public static function recoveryQuery(array $input): array
    {
        $query=['view'=>'settings','tab'=>'languages'];
        if(in_array($input['mode']??null,['pages','edit','history','settings','rights','pack'],true))$query['mode']=$input['mode'];
        if(is_string($input['locale']??null))try{$query['locale']=Settings::code($input['locale']);}catch(\InvalidArgumentException){}
        if(is_string($input['page']??null)&&preg_match('/\A[a-f0-9]{32}\z/D',$input['page']))$query['page']=$input['page'];
        if(is_string($input['subject']??null)&&preg_match('/\A[a-zA-Z0-9:_-]{1,160}\z/D',$input['subject']))$query['subject']=$input['subject'];
        if(is_string($input['pack_page']??null)&&preg_match('/\A(?:0|[1-9][0-9]{0,6})\z/D',$input['pack_page'])&&(int)$input['pack_page']<=intdiv(count(Catalog::keys())-1,10))$query['pack_page']=$input['pack_page'];
        if(in_array($query['mode']??null,['edit','history'],true)&&!isset($query['page']))$query['mode']='pages';
        return $query;
    }
    public static function failure(string $message,array $messages,array $context=[],bool $document=false,string $locale='de',bool $post=false): string
    {
        $e=Html::e(...);$text=static fn(string $source):string=>WorkspaceMessages::text($source,$messages);
        $heading=$messages['languages']??$text('Sprachen');
        $content='<section class="content native-languages" data-language-failure><h1>'.$e($heading).'</h1><p role="alert">'.$e($text($message)).'</p>';
        if($post)$content.='<p>'.$e($text('Prüfe den gespeicherten Stand, bevor du erneut speicherst.')).'</p>';
        $query=self::recoveryQuery($context);$root=['view'=>'settings','tab'=>'languages'];
        $content.='<div class="language-actions"><a class="btn btn-solid theme-primary" href="/vereinsverwaltung/?'.$e(http_build_query($query)).'">'.$e($text('Neu laden')).'</a>';
        if($query!==$root)$content.='<a class="btn btn-outline theme-secondary" href="/vereinsverwaltung/?'.$e(http_build_query($root)).'">'.$e($text('Sprachübersicht')).'</a>';
        $content.='</div></section>';
        if(!$document)return $content;
        return '<!doctype html><html lang="'.$e($locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($heading).' · DVM</title>'.\Kulturhus\Native\Web\BootstrapAssets::styles('/vereinsverwaltung/').'<link rel="stylesheet" href="/vereinsverwaltung/style.css"><link rel="stylesheet" href="/vereinsverwaltung/languages.css"></head><body><main id="main" class="container py-4">'.$content.'</main></body></html>';
    }
    public static function render(array $state,string $csrf,string $endpoint='/vereinsverwaltung/?view=settings&tab=languages'):string
    {
        if(!str_starts_with($endpoint,'/')||str_starts_with($endpoint,'//')||preg_match('/[\x00-\x20\\\\]/',$endpoint))throw new \InvalidArgumentException('Lokalen Formularpfad verwenden.');
        $e=Html::e(...);$m=$state['messages'];$url=static fn(array $query=[]):string=>$endpoint.(str_contains($endpoint,'?')?'&':'?').http_build_query($query);$locale=$state['locale'];
        $fixed=static fn(string $text):string=>WorkspaceMessages::text($text,$m);
        $hidden=static function(array $values)use($e):string{$html='';foreach($values as $key=>$value)$html.='<input type="hidden" name="'.$e($key).'" value="'.$e((string)$value).'">';return $html;};
        $form=static fn(array $values=[]):string=>'<form method="post" action="'.$e($endpoint).'">'.$hidden(['csrf'=>$csrf,'locale'=>$locale,'mode'=>$state['mode']]+$values);
        $button=static fn(string $label,string $action):string=>'<button class="btn btn-solid theme-primary" name="action" value="'.$e($action).'" type="submit">'.$e($label).'</button>';
        $h='<link rel="stylesheet" href="languages.css"><section class="content native-languages"><div class="page-heading"><h1>'.$e($m['languages']).'</h1><nav aria-label="'.$e($m['languages']).'">';
        foreach(['pages'=>'translation']+($state['can_manage']?['settings'=>'configuration','rights'=>'rights','pack'=>'admin_language']:[]) as $mode=>$label)$h.='<a class="btn btn-outline theme-secondary" href="'.$e($url(['mode'=>$mode,'locale'=>$locale])).'"'.($state['mode']===$mode?' aria-current="page"':'').'>'.$e($m[$label]).'</a>';
        $h.='</nav></div><details class="language-preference"><summary>'.$e($m['admin_language']).'</summary>'.$form().'<label>'.$e($m['language']).'<select class="form-control" name="admin_locale">';
        foreach($state['admin_languages'] as $language)$h.='<option value="'.$e($language['code']).'"'.($state['admin_locale']===$language['code']?' selected':'').'>'.$e($language['label']).'</option>';
        $h.='</select></label>'.$button($m['save'],'language-preference').'</form></details>';
        if(in_array($state['mode'],['pages','rights','pack'],true)&&$state['languages']){
            $h.='<form method="get" action="'.$e(strtok($endpoint,'?')).'">';parse_str(parse_url($endpoint,PHP_URL_QUERY)??'',$baseQuery);$h.=$hidden($baseQuery+['mode'=>$state['mode']]).'<label>'.$e($m['language']).'<select name="locale">';
            foreach($state['languages'] as $language)$h.='<option value="'.$e($language['code']).'"'.($locale===$language['code']?' selected':'').'>'.$e($language['label']).'</option>';
            $h.='</select></label>';if($state['mode']==='pages')$h.='<label>'.$e($m['page']).'<input type="search" name="q" value="'.$e($state['query']??'').'" maxlength="120"></label>';
            $h.='<button class="btn btn-outline theme-secondary" type="submit">'.$e($m['next']).'</button></form>';
        }
        if($state['mode']==='pages'){
            if(!$state['languages'])$h.='<p>'.$e($m['empty']).'</p>';
            $h.='<ul class="language-page-list">';foreach($state['pages']??[] as $page)$h.='<li><a href="'.$e($url(['mode'=>'edit','locale'=>$locale,'page'=>$page['id']])).'"><strong>'.$e($page['title']).'</strong><span>'.$e($page['slug']).'</span><span>'.$e($m['edit']).' →</span></a></li>';$h.='</ul>';
            if($state['has_more']??false)$h.='<a class="btn btn-outline theme-secondary" href="'.$e($url(['mode'=>'pages','locale'=>$locale,'q'=>$state['query'],'after'=>end($state['pages'])['id']])).'">'.$e($m['next']).'</a>';
        }elseif(in_array($state['mode'],['edit','history'],true)){
            $t=$state['translation'];$source=$state['source'];$fields=$t['payload']['fields'];$common=['page'=>$t['page_id'],'version'=>$t['version']];$h.='<div class="language-edit-heading"><h2>'.$e($source['title']).' · '.$e($locale).'</h2><p>'.$e($m['source']).' '.$e((string)$t['source_revision']).' · '.$e($m['version']).' '.$e((string)$t['version']).'</p>'.(!empty($t['payload']['requires_review'])?'<p role="status">'.$e($m['review_copy']).'</p>':'').'<a class="btn btn-outline theme-secondary" href="'.$e($url(['mode'=>$state['mode']==='history'?'edit':'history','locale'=>$locale,'page'=>$t['page_id']])).'">'.$e($state['mode']==='history'?$m['edit']:$m['history']).'</a></div>';
            if($state['mode']==='history'){
                $h.='<ol class="language-history">';foreach($state['history'] as $revision)$h.='<li><strong>'.$e($m['version']).' '.$e((string)$revision['revision']).'</strong><span>'.$e($revision['changed_at']).'</span>'.$form($common+['revision'=>$revision['revision']]).$button($m['restore'],'language-restore').'</form></li>';$h.='</ol>';
                if(count($state['history'])===20)$h.='<a class="btn btn-outline theme-secondary" href="'.$e($url(['mode'=>'history','locale'=>$locale,'page'=>$t['page_id'],'before'=>end($state['history'])['revision']])).'">'.$e($m['next']).'</a>';
            }else{
                $h.='<form method="get" action="'.$e(strtok($endpoint,'?')).'">';parse_str(parse_url($endpoint,PHP_URL_QUERY)??'',$baseQuery);$h.=$hidden($baseQuery+['mode'=>'edit','locale'=>$locale,'page'=>$t['page_id']]).'<label>'.$e($m['source']).'<select name="source_revision">';foreach(array_unique(array_filter([$t['source_revision'],$t['source_published_revision'],$t['source_version']])) as $revision)$h.='<option value="'.$e((string)$revision).'"'.($revision===$t['source_revision']?' selected':'').'>'.$e($m['version']).' '.$e((string)$revision).'</option>';$h.='</select></label><button class="btn btn-outline theme-secondary" type="submit">'.$e($m['next']).'</button></form>';
                $h.=$form($common+['source_revision'=>$t['source_revision'],'translation_selection'=>'1']).'<label>'.$e($m['slug']).'<input name="slug" required maxlength="180" value="'.$e($t['payload']['slug']).'"></label>';
                foreach(['title'=>$m['title'],'excerpt'=>$m['excerpt'],'body_html'=>$m['body'],'nav_label'=>$fixed('Navigationsbeschriftung'),'seo_title'=>$fixed('SEO-Titel'),'seo_description'=>$fixed('SEO-Beschreibung')] as $key=>$label){
                    $compact=!in_array($key,['title','body_html'],true);if($compact)$h.='<details'.(array_key_exists($key,$fields)?' open':'').'><summary>'.$e($label).'</summary>';
                    $value=$fields[$key]??$source[$key]??'';$h.='<fieldset><legend><label class="language-field-choice"><input type="checkbox" name="translated_fields[]" value="'.$e($key).'"'.(array_key_exists($key,$fields)?' checked':'').'>'.$e($label).'</label></legend><details><summary>'.$e($m['source']).'</summary><pre>'.$e($source[$key]??'').'</pre></details><label class="visually-hidden" for="language-field-'.$e($key).'">'.$e($m['translation']).' '.$e($label).'</label>';
                    $h.=in_array($key,['body_html','excerpt','seo_description'],true)?'<textarea id="language-field-'.$e($key).'" name="fields['.$e($key).']" rows="'.($key==='body_html'?'8':'3').'">'.$e($value).'</textarea>':'<input id="language-field-'.$e($key).'" name="fields['.$e($key).']" value="'.$e($value).'">';$h.='</fieldset>';
                    if($compact)$h.='</details>';
                }
                if(($source['slug']??'')==='start'){$home=array_replace(\Kulturhus\Native\Cms\HomeLayout::defaults(),$source['home_layout']??[]);$h.='<details><summary>'.$e($m['website']).' · '.$e($fixed('Startseite')).'</summary>';foreach(\Kulturhus\Native\Cms\HomeLayout::LABELS as $key=>$label){if($key==='sections')continue;$h.='<fieldset><legend><label class="language-field-choice"><input type="checkbox" name="selected_home[]" value="'.$e($key).'"'.(array_key_exists($key,$fields['home_layout']??[])?' checked':'').'>'.$e($fixed($label)).'</label></legend><details><summary>'.$e($m['source']).'</summary><pre>'.$e(is_array($home[$key])?implode("\n",$home[$key]):$home[$key]).'</pre></details>';if($key==='highlights'){foreach($home['highlights'] as $i=>$highlight)$h.='<label>'.$e($m['translation']).' '.($i+1).'<input name="home_text[highlights]['.$i.']" value="'.$e($fields['home_layout']['highlights'][$i]??$highlight).'" maxlength="80"></label>';}else{$h.='<label>'.$e($m['translation']).'<textarea name="home_text['.$e($key).']" rows="'.($key==='hero_html'?'4':'2').'">'.$e($fields['home_layout'][$key]??$home[$key]).'</textarea></label>';}$h.='</fieldset>';}$h.='</details>';}
                $h.='<details><summary>'.$e($m['custom_fields']).'</summary>';foreach($source['field_definitions']??[] as $key=>$definition){if(!in_array($definition['type'],['text','textarea','richtext'],true)||\Kulturhus\Native\Cms\FieldValues::collection($definition)||($definition['display']??'visible')!=='visible'||($definition['public']??true)!==true)continue;$h.='<fieldset><legend><label class="language-field-choice"><input type="checkbox" name="selected_custom[]" value="'.$e($key).'"'.(array_key_exists($key,$fields['custom_fields']??[])?' checked':'').'>'.$e($definition['label']).'</label></legend><details><summary>'.$e($m['source']).'</summary><pre>'.$e($source['custom_fields'][$key]??'').'</pre></details><label>'.$e($m['translation']).'<textarea name="custom_text['.$e($key).']" rows="3">'.$e($fields['custom_fields'][$key]??$source['custom_fields'][$key]??'').'</textarea></label></fieldset>';}$h.='</details>';
                $h.='<details><summary>'.$e($m['sections']).'</summary>';foreach($source['content_sections']??[] as $section){$id=$section['id'];$h.='<details><summary>'.$e($section['heading']?:$m['sections']).'</summary>';foreach(['heading','body_html','alt','caption','button_label'] as $key){$h.='<label class="language-field-choice"><input type="checkbox" name="selected_sections['.$e($id).'][]" value="'.$e($key).'"'.(array_key_exists($key,$fields['content_sections'][$id]??[])?' checked':'').'>'.$e($key==='body_html'?$m['body']:($key==='heading'?$m['title']:$fixed(['alt'=>'Bildbeschreibung','caption'=>'Bildunterschrift','button_label'=>'Schaltflächentext'][$key]))).'</label><label>'.$e($m['translation']).'<textarea name="section_text['.$e($id).']['.$e($key).']" rows="'.($key==='body_html'?'4':'2').'">'.$e($fields['content_sections'][$id][$key]??$section[$key]??'').'</textarea></label>';}$h.='</details>';}$h.='</details><div class="language-actions">'.$button($m['save'],'language-save').'</div></form>';
                if($state['can_publish']&&$t['version']>0)$h.=$form($common).'<div class="language-actions">'.$button($m['publish'],'language-publish').($t['published_revision']!==null?$button($m['unpublish'],'language-unpublish'):'').'</div></form>';
            }
        }elseif($state['mode']==='settings'){
            $h.=$form(['version'=>$state['settings']['version']]);foreach($state['settings']['config']['languages'] as $i=>$language){$prefix='languages['.$i.']';$h.='<fieldset><legend>'.$e($language['label']).' · '.$e($language['code']).'</legend>'.$hidden([$prefix.'[code]'=>$language['code'],$prefix.'[messages]'=>json_encode($language['messages']?:new \stdClass(),JSON_THROW_ON_ERROR)]).'<label>'.$e($m['title']).'<input name="'.$prefix.'[label]" value="'.$e($language['label']).'" maxlength="80" required></label><label class="language-field-choice"><input type="checkbox" name="'.$prefix.'[enabled]" value="1"'.($language['enabled']?' checked':'').'>'.$e($m['enabled']).'</label><label>'.$e($m['fallback']).'<select name="'.$prefix.'[fallback]"><option value="">—</option>';
                foreach($state['settings']['config']['languages'] as $fallback)if($fallback['code']!==$language['code'])$h.='<option value="'.$e($fallback['code']).'"'.($language['fallback']===$fallback['code']?' selected':'').'>'.$e($fallback['label']).'</option>';$h.='</select></label></fieldset>';
            }
            $h.='<fieldset><legend>+</legend><label>'.$e($m['language']).'<input name="new_code" maxlength="16" placeholder="en"></label><label>'.$e($m['title']).'<input name="new_label" maxlength="80" placeholder="English"></label></fieldset>'.$button($m['save'],'language-settings').'</form>';
        }elseif($state['mode']==='pack'){
            $h.=$form(['version'=>$state['settings']['version'],'pack_page'=>$state['pack_page']]);foreach($state['pack_keys'] as $key)$h.='<label>'.$e($m[$key]).'<input name="message_pack['.$e($key).']" value="'.$e($state['pack'][$key]??'').'" placeholder="'.$e($state['pack_defaults'][$key]).'" maxlength="500"></label>';$h.=$button($m['save'],'language-pack').'</form><nav aria-label="'.$e($m['admin_language']).'">';$last=(int)floor((count(Catalog::keys())-1)/10);foreach(array_values(array_unique(array_filter([$state['pack_page']-1,$state['pack_page'],$state['pack_page']+1],static fn(int $page):bool=>$page>=0&&$page<=$last))) as $page)$h.='<a class="btn btn-outline theme-secondary" href="'.$e($url(['mode'=>'pack','locale'=>$locale,'pack_page'=>$page])).'"'.($page===$state['pack_page']?' aria-current="page"':'').'>'.($page+1).'</a>';$h.='</nav>';
        }elseif($state['mode']==='rights'){
            $h.='<form method="get" action="'.$e(strtok($endpoint,'?')).'">';parse_str(parse_url($endpoint,PHP_URL_QUERY)??'',$baseQuery);$h.=$hidden($baseQuery+['mode'=>'rights','locale'=>$locale]).'<label>'.$e($m['rights']).'<select name="subject">';foreach($state['people'] as $person)$h.='<option value="'.$e($person['id']).'"'.($state['subject']===$person['id']?' selected':'').'>'.$e($person['name']).'</option>';$h.='</select></label><button class="btn btn-outline theme-secondary" type="submit">'.$e($m['next']).'</button></form>';
            if($state['subject']!==''){$h.=$form(['subject'=>$state['subject'],'version'=>$state['rights']['version']]);foreach(['edit','publish'] as $cap)$h.='<label class="language-field-choice"><input name="'.$cap.'" type="checkbox" value="1"'.(in_array($cap,$state['rights']['capabilities'],true)?' checked':'').'>'.$e($m[$cap]).'</label>';$h.=$button($m['save'],'language-rights').'</form>';}
        }return $h.'</section>';
    }
}
