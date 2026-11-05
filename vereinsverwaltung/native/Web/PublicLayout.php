<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

/** Public club identity is supplied by the current manager profile. */
final class PublicLayout
{
    public static function render(string $title, string $description, string $main, array $navigation, string $current, string $origin, string $managerUrl = '/?view=website', array $identity = [], bool $pageNoindex = false, ?array $footerNavigation = null, int $pageNumber = 1, ?string $canonicalPath = null): string
    {
        if($pageNumber<1||$pageNumber>83334)throw new \InvalidArgumentException('Ungültige Seitennummer.');
        if($canonicalPath!==null&&(strlen($canonicalPath)>181||!preg_match('~\A/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*/?\z~D',$canonicalPath)))throw new \InvalidArgumentException('Kanonischen Websitepfad prüfen.');
        $canonical=$origin.($canonicalPath??('/site/'.$current.($pageNumber>1?'?page='.$pageNumber:'')));
        $framework=BootstrapAssets::styles();
        $styleVersion=substr(hash_file('sha256',__DIR__.'/assets/site/site.css'),0,16);
        $e=Html::e(...); $nav='';
        $design=\Kulturhus\Native\Cms\WebsiteDesign::template($identity['website_design']??'kulturhus');
        $robots = Indexing::directive($identity, $origin, $pageNoindex, $current);
        $clubName = trim((string)($identity['name'] ?? '')) ?: 'Mein Verein';
        $clubMark = $e(mb_strtoupper(mb_substr($clubName, 0, 1)));
        foreach($navigation as $item) {
            if($item['slug']==='start')continue;
            $active=$item['slug']===$current?' aria-current="page"':(str_starts_with($current,$item['slug'].'/')?' aria-current="true"':'');
            $target=$item['slug']==='newsletter'?'/newsletter/':'/site/'.$item['slug'];
            $nav.='<a href="'.$e($target).'"'.$active.'>'.$e($item['label']).'</a>';
        }
        $brand='<span class="brand__mark" aria-hidden="true">'.$clubMark.'</span><span class="brand__type">'.$e($clubName).'</span>';
        $membership=$current==='mitglied-werden';
        $assets=$current==='newsletter'?'<link rel="stylesheet" href="/assets/newsletter-forms.css">':($membership?'<link rel="stylesheet" href="/assets/membership.css"><link rel="stylesheet" href="/assets/application-form.css"><script defer src="/assets/application-form.js"></script>':'<link rel="stylesheet" href="/assets/gallery.css"><link rel="stylesheet" href="/assets/articles.css"><link rel="stylesheet" href="/assets/richtext.css"><script defer src="/assets/gallery.js"></script>');
        $footerLinks='';
        $footerAvailable=[];foreach($footerNavigation??$navigation as$item)$footerAvailable[$item['source_slug']??$item['slug']]=$item;
        foreach(['galerie'=>'Bildergalerie','aktuelles'=>'Aktuelles','newsletter'=>'Newsletter','kontakt'=>'Kontakt','impressum'=>'Impressum','datenschutz'=>'Datenschutz','satzung'=>'Satzung','datenloeschung'=>'Datenlöschung','nutzungsbedingungen'=>'Nutzungsbedingungen'] as $slug=>$label){
            if($slug==='satzung'&&isset($footerAvailable['verein/satzung']))$slug='verein/satzung';
            if($slug==='newsletter'||isset($footerAvailable[$slug]))$footerLinks.='<a href="'.($slug==='newsletter'?'/newsletter/':'/site/'.Html::e($footerAvailable[$slug]['slug'])).'"'.($current===$slug?' aria-current="page"':'').'>'.$label.'</a>';
        }
        $menu=$nav!==''?'<button class="site-menu-toggle" type="button" aria-expanded="false" aria-controls="nav">Menü</button><nav id="nav" aria-label="Hauptnavigation">'.$nav.'</nav>':'';
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($title).' | '.$e($clubName).'</title><meta name="description" content="'.$e($description).'"><meta name="robots" content="'.$e($robots).'"><link rel="canonical" href="'.$e($canonical).'">'.WebsiteManifest::links($identity).$framework.'<link rel="stylesheet" href="/assets/kulturhus-site.css?v='.$styleVersion.'"><link rel="stylesheet" href="/assets/kulturhus-themes.css"><link rel="stylesheet" href="/assets/kulturhus-enhancements.css">'.$assets.'<link rel="stylesheet" href="/assets/website-designs.css"><script defer src="/assets/public.js"></script><link rel="alternate" type="application/rss+xml" href="/feeds/all.rss.xml" title="Vereinsnachrichten"></head><body class="'.$e($design['class']).' public-site native-public"><a class="skip" href="#main">Zum Inhalt</a><header class="site-header"><a class="brand" href="/site/start" aria-label="'.$e($clubName).' Startseite">'.$brand.'</a>'.$menu.'</header>'.$main.'<footer class="site-footer"><div class="footer-intro"><a class="brand brand--footer" href="/site/start">'.$brand.'</a><p>Gemeinsam aktiv im Verein</p></div><div><h2>Mehr</h2>'.$footerLinks.'<nav class="footer-social" aria-label="Feeds und Suche"><a href="/feeds/all.rss.xml">RSS-Feed</a><a href="/feeds/events.ics">Kalender abonnieren</a><a href="/search">Suche</a></nav></div><div class="footer-login"><p>Unser Zugang für die gemeinsame Vereinsarbeit.</p><a class="footer-login__button" href="'.$e($managerUrl).'">Zum Vereinsmanager →</a></div><p class="copyright">© '.date('Y').' '.$e($clubName).'</p></footer></body></html>';
        return LanguageMarkup::apply($html,$identity,$origin);
    }
}
