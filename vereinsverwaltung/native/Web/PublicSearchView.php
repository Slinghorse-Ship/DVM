<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Native links and forms keep the entire search usable without JavaScript. */
final class PublicSearchView
{
    /** Shared by both real HTTP dispatchers; the locale comes from explicit club configuration. */
    public static function layout(array $list,\PDO $db,\Kulturhus\Native\Shared\Access $access,\Kulturhus\Native\Cms\Pages $pages,string $origin,string $managerUrl,array $identity,string $action='/search'):string
    {
        $navigation=$pages->navigation();$footer=$pages->footerNavigation();$locale=$list['locale']??null;
        if($locale!==null){
            $settings=new \Kulturhus\Native\Language\Settings($db,$access);$settings->language($locale);$config=$settings->state()['config'];
            $resolver=new \Kulturhus\Native\Language\PublicResolver($db,$access);
            foreach($navigation as&$link)$link['source_slug']=$link['slug'];unset($link);
            foreach($footer as&$link)$link['source_slug']=$link['slug'];unset($link);
            $navigation=$resolver->navigation($navigation,$locale);$footer=$resolver->navigation($footer,$locale);
            $identity['language']=$locale;$identity['content_language']=$locale;
            $identity['language_path']=($locale===$config['source']?'':$locale.'/').'search';
            $identity['language_alternates']=[];foreach($config['languages']as$language)if($language['enabled'])$identity['language_alternates'][$language['code']]='search';
            $identity['language_search']=['query'=>$list['query'],'source'=>$config['source']];
        }
        return PublicLayout::render('Suche','Veröffentlichte Vereinsinhalte durchsuchen.',self::render($list,$action),$navigation,'search',$origin,$managerUrl,$identity,true,$footer,1,'/search');
    }

    public static function render(array $list,string $action='/search'):string
    {
        $e=Html::e(...);$query=$list['query'];$locale=$list['locale']??null;
        $html='<main id="main" class="subpage" data-public-search><header class="page-hero page-hero--basic"><h1>Suche</h1></header><section class="section"><form class="form-card" method="get" action="'.$e($action).'">'.($locale===null?'':Html::hidden('lang',$locale)).Html::input('q','Website durchsuchen',$query,'search','maxlength="120"').'<button class="button">Suchen</button></form>';
        if($query!=='')$html.=$list['total']?'<p role="status">'.$list['total'].' Treffer · Seite '.$list['page'].' von '.$list['pages'].'</p>':'<p role="status">Keine veröffentlichten Inhalte gefunden.</p>';
        $html.=self::pagination($list,$action,'top');
        foreach($list['rows']as$row){
            preg_match('/\A.{0,180}/us',$row['excerpt'],$match);$excerpt=$match[0]??'';if(strlen($row['excerpt'])>strlen($excerpt))$excerpt.=' …';
            $html.='<article class="card" data-search-result="'.$e($row['id']).'"><h2><a href="'.$e($row['url']).'">'.$e($row['title']).'</a></h2><p>'.$e($excerpt).'</p></article>';
        }
        return $html.self::pagination($list,$action,'bottom').'</section></main>';
    }

    private static function pagination(array $list,string $action,string $position):string
    {
        $e=Html::e(...);$query=$list['query'];$html='';
        if($list['pages']>1){
            $html.='<nav class="program-filters" data-search-pagination="'.$position.'" aria-label="Weitere Suchergebnisse">';
            $link=static fn(int $page):string=>$action.'?'.http_build_query(['q'=>$query,'seite'=>$page]+(isset($list['locale'])?['lang'=>$list['locale']]:[]),'','&',PHP_QUERY_RFC3986);
            if($list['page']>1)$html.='<a class="button button--outline" rel="prev" href="'.$e($link($list['page']-1)).'">Zurück</a>';
            $numbers=array_unique([1,...range(max(1,$list['page']-1),min($list['pages'],$list['page']+1)),$list['pages']]);sort($numbers);$previous=0;
            foreach($numbers as$number){
                if($previous&&$number>$previous+1)$html.='<span aria-hidden="true">…</span>';
                $html.='<a class="button button--outline" href="'.$e($link($number)).'" aria-label="Seite '.$number.'"'.($number===$list['page']?' aria-current="page"':'').'>'.$number.'</a>';$previous=$number;
            }
            if($list['page']<$list['pages'])$html.='<a class="button button--outline" rel="next" href="'.$e($link($list['page']+1)).'">Weiter</a>';
            $html.='</nav>';
        }
        return $html;
    }
}
