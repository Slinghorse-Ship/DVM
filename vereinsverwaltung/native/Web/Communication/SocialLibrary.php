<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use Throwable;
use Kulturhus\Native\Web\{Workspace as BaseWorkspace,Html};
use Kulturhus\Native\Communication\{Content,SocialHub,PersonalActivity};
use Kulturhus\Native\Language\{Catalog,ContentMessages,ConversationMessages};
use Kulturhus\Native\Integration\ManagerAccess;

/** Cached provider originals have a bounded, personal-language view; reads never fabricate measurements. */
final class SocialLibrary extends BaseWorkspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public static function applies(string $view,array $post,array $query=[]):bool
    {return $view==='social'&&(($query['section']??null)==='library'||in_array($post['operation']??null,['social-sync','social-insights','social-comments','stadtlandfunk-sync','social-post-correct','social-post-correction-check','social-post-correction-close'],true));}
    private function authority():string{return$this->access instanceof ManagerAccess?'manager':'native';}
    private function context():array{$a=$this->access->require('social','read');return['actor_id'=>$a['id'],'club_id'=>$a['club_id'],'authority'=>$this->authority()];}
    public static function filters(array $input):array
    {
        $out=[];foreach(['q','kind','channel']as$key){$v=$input[$key]??'';if(!is_string($v))throw new DomainException('Social-Inhaltefilter prüfen.');$out[$key]=$v;}
        Content::text($out['q'],120);
        if(!in_array($out['kind'],['','post','reel','video','carousel','story','event'],true)||!in_array($out['channel'],['','facebook','instagram','stadtlandfunk'],true))throw new DomainException('Bibliotheksfilter prüfen.');
        $raw=$input['page']??'1';if(!is_string($raw)&&!is_int($raw))throw new DomainException('Social-Inhaltsseite prüfen.');$page=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);if($page===false)throw new DomainException('Social-Inhaltsseite prüfen.');$out['page']=(string)$raw;return$out;
    }
    private function request(array $post):array
    {
        if(($post['library_context']??null)!=='1'||!is_array($post['library']??null))throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
        $raw=$post['library'];$keys=array_keys($raw);$expected=['actor_id','club_id','authority','q','kind','channel','page'];sort($keys);sort($expected);if($keys!==$expected)throw new DomainException('Ursprüngliches Abgleichformular erneut öffnen.');
        $context=$this->context();foreach($context as$key=>$value)if(($raw[$key]??null)!==$value)throw new DomainException('Person oder Vereinszuordnung geändert.');return self::filters($raw);
    }
    private function text(string $source,array $values=[]):string{return Html::e($this->contentFormat($source,$values));}
    private function errorText(string $source):string
    {$catalog=new Catalog($this->db,$this->access);return ConversationMessages::text(ContentMessages::text($source,$catalog->contentMessages()),$catalog->messages());}
    public function action(array $post):array
    {
        $this->validatePost($post);$filters=$this->request($post);$op=$post['operation']??null;if(!in_array($op,['social-sync','social-insights','social-comments','stadtlandfunk-sync'],true))throw new DomainException('Social-Abgleich erneut öffnen.');
        $state=['filters'=>$filters];
        try{
            if(in_array($op,['social-insights','social-comments'],true)&&!is_string($post['object_id']??null))throw new DomainException('Ursprünglichen Plattformbeitrag auswählen.');
            if($op==='stadtlandfunk-sync'){(new PersonalActivity($this->db,$this->box()))->sync($this->access);$state['notice']='Eigene StadtLand.Funk-Veranstaltungen und Aktivitäten abgeglichen.';}
            else{$hub=new SocialHub($this->db,$this->box());match($op){'social-sync'=>$hub->sync($this->access),'social-insights'=>$hub->insights($this->access,$post['object_id']),'social-comments'=>$hub->comments($this->access,$post['object_id'])};$state['notice']='Anbieterabgleich gespeichert. Verfügbarkeit und Zeitpunkt im jeweiligen Bereich prüfen.';}
        }catch(\Kulturhus\Native\Integration\Cooldown $pause){http_response_code(429);header('Retry-After: '.$pause->seconds);$state['error']='Anbieterpause. Bitte später erneut prüfen.';$state['retry_after']=$pause->seconds;}
        catch(DomainException $error){http_response_code(422);$state['error']=$error->getMessage();}
        catch(Throwable){http_response_code(503);$state['error']='Abgleich nicht bestätigt. Die ursprüngliche Suchansicht bleibt erhalten. Anbieterstatus prüfen.';}
        return ['view'=>'social','section'=>'library','notice'=>'','compose_state'=>['library'=>$state],'library_response'=>['ok'=>!isset($state['error']),'error'=>isset($state['error'])?$this->errorText($state['error']):null,'notice'=>isset($state['notice'])?$this->contentText($state['notice']):null,'filters'=>$filters,'retry_after'=>$state['retry_after']??null]];
    }
    private function at(mixed $utc,string $locale):string
    {
        if(!is_string($utc)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:Z|[+-][0-9]{2}:?[0-9]{2})?\z/D',$utc))return$this->contentText('Zeitpunkt nicht geliefert');
        try{$date=new \DateTimeImmutable($utc,new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))return$this->contentText('Zeitpunkt nicht geliefert');return$date->setTimezone(new \DateTimeZone('Europe/Berlin'))->format(explode('-',$locale,2)[0]==='en'?'Y-m-d H:i':'d.m.Y H:i').' Europe/Berlin';}catch(Throwable){return$this->contentText('Zeitpunkt nicht geliefert');}
    }
    private function startSync(string $operation,array $filters,array $context):string
    {$h='<form method="post" data-navigation-native data-library-sync target="_blank">'.Html::hidden('csrf',$this->csrf).Html::hidden('operation',$operation).Html::hidden('library_context','1');foreach($context+$filters as$key=>$value)$h.=Html::hidden('library['.$key.']',$value);return$h;}
    public function manager(?array $correction=null,?array $state=null):string
    {
        $context=$this->context();$catalog=new Catalog($this->db,$this->access);$locale=$catalog->locale();$messages=$catalog->contentMessages();$hub=new SocialHub($this->db,$this->box());$t=$this->text(...);$e=Html::e(...);
        $rawFilters=$state['filters']??$_GET;
        try{$filters=self::filters($rawFilters);$page=$hub->listing($this->access,$filters['q'],$filters['kind'],$filters['channel'],(int)$filters['page'],6);if($page['page']!==(int)$filters['page'])throw new DomainException('Social-Inhaltsseite prüfen.');}
        catch(DomainException $error){$this->context();http_response_code(422);$labels=[];foreach(['q'=>'Inhalte durchsuchen','kind'=>'Inhaltsart','channel'=>'Kanal','page'=>'Seite']as$key=>$label)if(array_key_exists($key,$rawFilters))$labels[$this->contentText($label)]=$rawFilters[$key];return'<section class="social-library-workspace"><h2>'.$t('Plattformbeiträge').'</h2><p role="alert">'.$e($this->errorText($error->getMessage())).'</p><details open><summary>'.$t('Ursprüngliche Suchangaben').'</summary><pre class="social-filter-original">'.$e(json_encode($labels,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)).'</pre></details><a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=library">'.$t('Filter zurücksetzen').'</a></section>';}
        $q=$filters['q'];$kind=$filters['kind'];$channel=$filters['channel'];$filters['page']=(string)$page['page'];$kinds=[''=>'Alle Arten','post'=>'Beiträge','reel'=>'Reels','carousel'=>'Karussells','video'=>'Videos','story'=>'Stories','event'=>'Veranstaltungen'];$channels=[''=>'Alle Kanäle','facebook'=>'Facebook','instagram'=>'Instagram','stadtlandfunk'=>'StadtLand.Funk'];
        $h='<section class="social-library-workspace"><header class="social-library-heading"><h2>'.$t('Plattformbeiträge').'</h2>';if($this->can('social','edit'))$h.='<a class="btn btn-solid theme-primary button" data-nav href="/?view=social&amp;section=compose">'.$t('Beitrag gestalten').'</a>';$h.='</header>';
        if(isset($state['error']))$h.='<p role="alert">'.$e($this->errorText($state['error'])).'</p>';if(isset($state['notice']))$h.='<p role="status">'.$t($state['notice']).'</p>';
        $h.='<nav class="social-library-links" aria-label="'.$t('Social-Bereiche').'">';if($this->can('website','read')&&$this->can('social','edit'))$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=events">'.$t('Veranstaltung teilen').'</a>';if($this->can('social','manage'))$h.='<a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=review">'.$t('Kommentare & Nachrichten').'</a>';$h.='</nav><details class="social-library-sync"><summary>'.$t('Abgleich').'</summary>';
        if($this->can('social','manage'))$h.=$this->startSync('stadtlandfunk-sync',$filters,$context).'<button class="btn btn-outline theme-secondary">'.$t('StadtLand.Funk abgleichen').'</button></form><a class="btn btn-outline theme-secondary" data-save-original data-navigation-native target="_blank" rel="noopener" href="/?view=social&amp;section=sync">'.$t('Automatische Abrufzeiten').'</a>';
        if($this->can('social','edit'))$h.=$this->startSync('social-sync',$filters,$context).'<button class="btn btn-outline theme-secondary">'.$t('Facebook & Instagram abgleichen').'</button></form>';
        $checks=$hub->checks($this->access);$features=['facebook-posts'=>'Facebook-Beiträge','facebook-reels'=>'Facebook-Reels','instagram-media'=>'Instagram-Beiträge & Reels','instagram-stories'=>'Instagram-Stories','facebook-messages'=>'Facebook-Nachrichten','instagram-messages'=>'Instagram-Nachrichten','stadtlandfunk-events'=>'StadtLand.Funk-Veranstaltungen'];
        if(!$checks)$h.='<p class="social-empty">'.$t('Noch kein bestätigter Meta-Abgleich. Konto und Berechtigungen einrichten.').' <a data-nav href="/?view=settings">'.$t('Vereinsdaten & Verbindungen').'</a></p>';
        else{$h.='<div class="social-checks" aria-label="'.$t('Letzter Anbieterabgleich').'">';foreach($checks as$c)$h.='<span>'.$t($features[$c['feature']]??$c['feature']).' · '.$t($c['state']==='available'?'Abruf bestätigt':'Abruf nicht bestätigt').' · '.$e($this->at($c['checked_at'],$locale)).'</span>';$h.='</div>';}
        $h.='</details><form method="get" class="social-filters">'.Html::hidden('view','social').Html::hidden('section','library').Html::input('q',$this->contentText('Inhalte durchsuchen'),$q).'<details class="social-filters-options"><summary>'.$t('Filter').($channel!==''?' · '.$e($channels[$channel]):'').($kind!==''?' · '.$t($kinds[$kind]):'').'</summary><div>'.Html::select('kind',$this->contentText('Inhaltsart'),$this->contentOptions($kinds),$kind).Html::select('channel',$this->contentText('Kanal'),$this->contentOptions($channels),$channel).'</div></details><button class="btn btn-solid theme-primary">'.$t('Suchen').'</button></form><p role="status">'.$t('{count} Inhalte · Seite {page} von {pages}',['count'=>$page['total'],'page'=>$page['page'],'pages'=>$page['pages']]).'</p><div class="social-content-grid">';
        foreach($page['rows']as$r){$data=$r['payload'];$type=['post'=>'Beitrag','reel'=>'Reel','carousel'=>'Karussell','video'=>'Video','story'=>'Story','event'=>'Veranstaltung'][$r['kind']]??$r['kind'];$h.='<article class="social-content-card" data-social-object="'.$e($r['id']).'" data-social-channel="'.$e($r['channel']).'">';
            if($data['thumbnail']!=='')$h.='<img loading="lazy" src="'.$e(\Kulturhus\Native\Integration\Routes::endpoint('social-remote-image',['object'=>$r['id']])).'" alt="'.$t('Anbietervorschau: {type}',['type'=>$this->contentText($type)]).'">';
            $h.='<div><span class="badge">'.$e($channels[$r['channel']]??$r['channel']).' · '.$t($type).'</span>'.SocialMetrics::render($r['channel'],$data,$messages,$locale).'<p class="social-caption">'.$e($r['caption']!==''?$r['caption']:$this->contentText('Ohne Anbieterbeschreibung')).'</p><p class="muted">'.$e($this->at($r['created_at'],$locale)).'</p>';
            if($r['caption']!=='')$h.='<details class="social-caption-full"><summary>'.$t('Text lesen').'</summary><p>'.$e($r['caption']).'</p></details>';
            if($data['permalink']!=='')$h.='<a class="btn btn-outline theme-secondary button secondary" href="'.$e($data['permalink']).'" target="_blank" rel="noopener noreferrer">'.$t('Beim Anbieter öffnen ↗').'</a>';
            $h.=(new PostCorrectionControls($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($r,$correction).'<details><summary>'.$t('Kennzahlen & Kommentare').'</summary>';
            if($this->can('social','edit')&&$r['channel']!=='stadtlandfunk')$h.=$this->startSync('social-insights',$filters,$context).Html::hidden('object_id',$r['id']).'<button class="btn btn-outline theme-secondary secondary">'.$t('Kennzahlen abrufen').'</button></form>';
            if($r['insights_json']!==null)foreach(json_decode($r['insights_json'],true,32,JSON_THROW_ON_ERROR)as$name=>$metric){$label=['views'=>'Aufrufe','reach'=>'Reichweite','saved'=>'Gespeichert','shares'=>'Geteilt','total_interactions'=>'Interaktionen','post_media_view'=>'Aufrufe','post_reactions_by_type_total'=>'Reaktionen'][$name]??$name;$value=$metric['data']['total_value']['value']??($metric['data']['values'][0]['value']??null);$h.='<p>'.$t($label).' · '.($metric['available']?$e(json_encode($value,JSON_UNESCAPED_UNICODE)):$t('Nicht vom Anbieter bestätigt')).'</p>';}
            if($this->can('social','manage')){if($r['channel']!=='stadtlandfunk')$h.=$this->startSync('social-comments',$filters,$context).Html::hidden('object_id',$r['id']).'<button class="btn btn-outline theme-secondary secondary">'.$t('Kommentare abrufen').'</button></form>';foreach($hub->privateComments($this->access,$r['id'])as$comment)$h.='<blockquote><strong>'.$e($comment['name']).'</strong>'.(($comment['incoming']??true)===false?'<small> · '.$t('Eigener Kommentar').'</small>':'').(($comment['reply_to']??'')!==''?'<small> · '.$t('Antwort im Kommentarstrang').'</small>':'').'<p>'.$e($comment['text']).'</p><small>'.$e($this->at($comment['created_at']??null,$locale)).' '.($comment['hidden']?' · '.$t('Ausgeblendet'):'').'</small></blockquote>';}
            $h.='</details><small>'.$t('Zuletzt gesehen: {time}',['time'=>$this->at($r['last_seen'],$locale)]).'</small></div></article>';
        }
        $h.='</div><nav class="website-pagination social-library-pagination" aria-label="'.$t('Inhaltsseiten').'">';foreach([-1=>'Zurück',1=>'Weiter']as$step=>$label){$next=$page['page']+$step;if($next>0&&$next<=$page['pages'])$h.='<a class="btn btn-outline theme-secondary button secondary" data-nav href="'.$e('/?'.http_build_query(['view'=>'social','section'=>'library','q'=>$q,'kind'=>$kind,'channel'=>$channel,'page'=>$next])).'">'.$t($label).'</a>';}
        $h.='</nav><p><a class="btn btn-outline theme-secondary" data-nav data-own-social-catalogue href="/?view=social&amp;section=contents&amp;hub_type=social">'.$t('Eigene Inhalte öffnen').'</a></p></section>';if($this->context()!==$context)throw new DomainException('Person oder Vereinszuordnung geändert.');return$h;
    }
    public function inbox(?array $humanState=null,?array $syncState=null):string{return(new Inbox($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($humanState,$syncState);}
}
