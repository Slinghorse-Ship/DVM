<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Communication\{VideoCatalogue,Videos,VideoReuseRequests};
use Kulturhus\Native\Shared\{Identity,Db};
use Kulturhus\Native\Language\{Catalog,VideoReuseMessages};
use Kulturhus\Native\Web\{Workspace,Html};

final class VideoReusePanel extends Workspace
{
    public static function query(string $item,string $search='',int $page=1,string $result=''):array
    {return['view'=>'social','section'=>'compose','item'=>$item,'video_use_window'=>'1','video_use_check'=>'1','video_use_query'=>$search,'video_use_page'=>(string)$page]+($result!==''?['video_use_result'=>$result]:[]);}
    public static function original(array $state):array
    {return self::query($state['id'],$state['video_use_query'],(int)$state['video_use_page'])+['video_use_original'=>$state['reuse_video_id'],'video_use_source'=>$state['source_id'],'video_use_version'=>$state['version'],'video_use_identity'=>$state['identity_version']];}
    private function url(array $query):string{return'/?'.http_build_query($query).'#social-video-reuse';}
    public function page(string $id):string
    {
        $actor=$this->access->require('social','edit');$this->access->require('social','read');$catalog=new Catalog($this->db,$this->access);$messages=$catalog->messages();
        try{if(($_GET['video_use_window']??null)!=='1')throw new InvalidArgumentException('Videoauswahl erneut öffnen.');$item=(new \Kulturhus\Native\Communication\Workspace($this->db,$this->access,'social',$this->mediaRoot))->get($id);$h='<section class="panel video-reuse-window">'.$this->render($item).'</section>';}
        catch(DomainException|InvalidArgumentException $error){$h='<section class="panel"><h2>'.Html::e(VideoReuseMessages::text('Vorhandene Videofassung nutzen',$messages)).'</h2><p role="alert">'.Html::e(VideoReuseMessages::text($error->getMessage(),$messages)).'</p></section>';}
        return$this->finish($h,$actor);
    }
    private function finish(string $h,array $actor):string
    {foreach(['read','edit']as$cap){$now=$this->access->require('social',$cap);if($now['id']!==$actor['id']||$now['club_id']!==$actor['club_id'])throw new DomainException('Person oder Vereinszuordnung geändert.');}return$h;}
    public function render(array $item):string
    {
        if(!$this->can('social','edit'))return'';$own=($_GET['video_use_window']??null)==='1';if(!$own&&$item['kind']!=='reel')return'';
        $catalog=new Catalog($this->db,$this->access);$messages=$catalog->messages();$locale=$catalog->locale();$t=static fn(string$s,array$v=[]):string=>Html::e(VideoReuseMessages::text($s,$messages,$v));
        foreach(['video_use_check','video_use_window','video_use_read']as$key){$value=$_GET[$key]??'';if(!is_string($value)||!in_array($value,['','0','1'],true)||$key!=='video_use_read'&&$value==='0')throw new InvalidArgumentException('Videoauswahl erneut öffnen.');}
        $candidate=$_GET['video_use_result']??'';if(!is_string($candidate)||$candidate!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$candidate))throw new InvalidArgumentException('Ursprüngliche Videoübernahme auswählen.');
        $search=$_GET['video_use_query']??'';$page=$_GET['video_use_page']??'1';if(!is_string($search))throw new InvalidArgumentException('Videoauswahl erneut öffnen.');if((!is_string($page)&&!is_int($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000)throw new InvalidArgumentException('Videoauswahlseite prüfen.');
        $link=' data-save-original data-navigation-native'.($own?'':' target="_blank" rel="noopener"');$h='<details class="social-video-reuse" id="social-video-reuse"'.($own?' open':'').'><summary>'.$t('Vorhandene Videofassung nutzen').'</summary><p>'.$t($own?'Verwendet dieselbe Videodatei. Andere Beiträge behalten ihre eigene Freigabe.':'Die Videoauswahl öffnet ein eigenes Fenster. Dein ungespeicherter Beitrag bleibt erhalten.').'</p><a class="btn btn-outline theme-secondary"'.$link.' href="'.Html::e($this->url(self::query($item['id'],$search,(int)$page))).'">'.$t('Videos auswählen').'</a>';
        if(!$own)return$h.'</details>';$actor=$this->access->require('social','edit');$this->access->require('social','read');$service=new VideoReuseRequests($this->db,$this->access,$this->mediaRoot);$identity=Identity::forClub($this->db,$actor['club_id'])['version'];
        if(isset($_GET['video_use_original'])){
            $state=VideoReuseForm::restore(['id'=>$item['id'],'reuse_video_id'=>$_GET['video_use_original'],'source_id'=>$_GET['video_use_source']??null,'version'=>$_GET['video_use_version']??null,'identity_version'=>$_GET['video_use_identity']??null,'video_use_query'=>$search,'video_use_page'=>(string)$page]);$state['result']=$service->review($item['id'],$state['reuse_video_id'],$state['source_id'],(int)$state['version'],(int)$state['identity_version']);
            return$this->finish($h.VideoReuseForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$state).'</details>',$actor);
        }
        if(isset($_GET['video_use_result'])){
            $result=$_GET['video_use_result'];if(!is_string($result))throw new InvalidArgumentException('Ursprüngliche Videoübernahme auswählen.');\Kulturhus\Native\Communication\Content::id($result);$saved=(new Videos($this->db,$this->access,$this->mediaRoot))->reuseCheck($item['id'],$result);
            if($saved){$state=['id'=>$item['id'],'reuse_video_id'=>$result,'source_id'=>$saved['source_id'],'version'=>(string)$saved['item_version'],'identity_version'=>(string)$saved['identity_version'],'video_use_query'=>$search,'video_use_page'=>(string)$page];$state['result']=$service->review($item['id'],$result,$saved['source_id'],(int)$state['version'],(int)$state['identity_version']);return$this->finish($h.VideoReuseForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$state).'</details>',$actor);}
            $h.='<p role="status">'.$t('Übernahme derzeit nicht bestätigt. Ursprüngliche Auswahl behalten.').'</p>';
        }
        $list=(new VideoCatalogue($this->db,$this->access))->page($search,$page);$canUse=$item['kind']==='reel'&&in_array($item['status'],['draft','review'],true);$posted=($_POST['operation']??null)==='social-video-reuse'&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']);
        $h.='<form method="get" action="/#social-video-reuse" data-navigation-native>'.Html::hidden('view','social').Html::hidden('section','compose').Html::hidden('item',$item['id']).Html::hidden('video_use_window','1').Html::hidden('video_use_check','1').Html::hidden('video_use_page','1').Html::input('video_use_query',VideoReuseMessages::text('Videos suchen',$messages),$list['query'],'search','maxlength="200"').'<button class="btn btn-outline theme-secondary">'.$t('Suchen').'</button></form><p>'.$t('{count} Quellen · Seite {page} von {pages}',['count'=>$list['total'],'page'=>$list['page'],'pages'=>$list['pages']]).'</p><div class="video-reuse-list">';
        foreach($list['rows']as$row){
            $p=$row['profile'];$h.='<article data-video-reuse-source="'.$row['source_id'].'"><strong data-original-content>'.Html::e($row['original_name']).'</strong><span>'.Html::e($this->mediaNumber($p['duration_ms']/1000,1)).' s · '.$t($p['audio']?'Mit Ton':'Ohne Ton').'</span>';
            $recent=Db::run($this->db,'SELECT id,item_version,identity_version FROM native_social_videos WHERE club_id=? AND item_id=? AND source_id=? ORDER BY created_at DESC,id DESC LIMIT 2',[$actor['club_id'],$item['id'],$row['source_id']])->fetchAll();foreach($recent as$earlier){if($earlier['id']!==Videos::reuseIdentity($actor['club_id'],$item['id'],(int)$earlier['item_version'],(int)$earlier['identity_version'],$row['source_id']))continue;$original=['id'=>$item['id'],'reuse_video_id'=>$earlier['id'],'source_id'=>$row['source_id'],'version'=>(string)$earlier['item_version'],'identity_version'=>(string)$earlier['identity_version'],'video_use_query'=>$list['query'],'video_use_page'=>(string)$list['page']];$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native href="'.Html::e($this->url(self::original($original))).'">'.$t('Ursprüngliche Videoübernahme öffnen').'</a>';break;}
            if($row['identity_version']!==$identity){$h.='<span>'.$t('Früherer Vereinsstand').'</span></article>';continue;}
            $state=$posted&&($_POST['reuse_video_id']??null)===$row['video_id']?VideoReuseForm::restore($_POST):VideoReuseForm::initial($item,$row,$identity,$list['query'],$list['page']);$state['original_name']=$row['original_name'];$observed=$service->review($item['id'],$state['reuse_video_id'],$row['source_id'],(int)$state['version'],(int)$state['identity_version']);if($observed['found']||$posted)$state['result']=$observed;$h.='<details class="video-reuse-choice" name="reuse-source-'.Html::e($item['id']).'"><summary>'.$t($observed['found']?'Bereits übernommen':'Diese Fassung nutzen').'</summary>'.VideoReuseForm::render($state,$this->csrf,$messages,$locale,false,$canUse,true).'</details></article>';
        }
        $h.='</div><nav class="social-video-pager" aria-label="'.$t('Vorhandene Videoquellen').'">';foreach([-1=>'Vorherige Quellen',1=>'Weitere Quellen']as$direction=>$label){$next=$list['page']+$direction;if($next>=1&&$next<=$list['pages'])$h.='<a class="btn btn-outline theme-secondary" data-save-original data-navigation-native href="'.Html::e($this->url(self::query($item['id'],$list['query'],$next))).'">'.$t($label).'</a>';}
        if(!$list['rows'])$h.='<p>'.$t('Keine passenden eigenen Videos.').'</p>';return$this->finish($h.'</nav></details>',$actor);
    }
}
