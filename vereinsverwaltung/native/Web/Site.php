<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Cms\Media;
final class Site
{
    private ?string $initialDesign;
    public function __construct(
        private Pages $pages,
        private Media $media,
        private Cache $cache,
        private string $origin,
        private string $club,
        private ?Gallery $albums = null,
        private ?Articles $articles = null,
        private string $managerUrl = "/?view=website",
        private array $identity = [],
        private ?\Closure $designResolver = null,
        private ?\Kulturhus\Native\Language\PublicResolver $languages = null,
        private ?\Kulturhus\Native\Routing\Resolver $routes = null,
        private ?\Closure $commentLinks = null,
        private ?\Kulturhus\Native\TemplateRenderers\Runtime $templateRenderers = null,
    ) {$this->initialDesign=$identity['website_design']??null;}

    /** The authenticated dispatcher sets no-store; this path never consults the public cache. */
    public function preview(string $id,int $version,callable $privateMediaUrl):string
    {
        $payload=$this->pages->previewSource($id,$version);
        $identity=$this->identity;
        foreach(['language','language_path','language_alternates','content_language']as$key)unset($identity[$key]);
        if($this->designResolver)$identity['website_design']=($this->designResolver)();
        elseif($this->initialDesign!==null)$identity['website_design']=$this->initialDesign;
        else unset($identity['website_design']);
        $presentation=$this->pages->presentation($payload);
        if($presentation['theme']!==null)$identity['website_design']=$presentation['theme'];
        $identity['origin']=$this->origin;
        return (new Website\DraftPagePreview($this->pages,$this->media,$identity,$this->managerUrl,$this->articles))->render($payload,$privateMediaUrl);
    }
    public function page(
        string $slug,
        int $pageNumber = 1,
        array $eventQuery = [],
        ?\Kulturhus\Native\Routing\Context $routing = null,
    ): ?string {
        // Check generation on both sides: a concurrent publication must never put
        // an old snapshot under the new generation's cache key.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $generation = $this->pages->generation();
            if ($this->designResolver) $this->identity['website_design']=($this->designResolver)();
            elseif($this->initialDesign!==null)$this->identity['website_design']=$this->initialDesign;
            else unset($this->identity['website_design']);
            foreach(['language','language_path','language_alternates','content_language']as$key)unset($this->identity[$key]);
            $language=$this->languages?->resolve($slug)??$this->languages?->contextBySourceSlug($slug);
            $page = $language['page'] ?? $this->pages->publicPage($slug);
            if($language){$this->identity['language']=$language['locale'];$this->identity['content_language']=$language['content_locale'];$this->identity['language_path']=$language['canonical_path'];$this->identity['language_alternates']=$language['alternates'];}
            if (!$page || \Kulturhus\Native\Cms\EventDetails::isEvent($page)) {
                $event = $this->pages->events()->publicPage($page['source_slug']??$page['slug']??$slug);
                if($event!==null)$page=(new PublishedLanguage($this->languages,$this->identity['language']??null))->event($event);
            }
            if($routing!==null){
                if($this->routes===null||!$page||$routing->generation!==$generation||!$routing->accepts($page,$this->routes->policy($page))||!$this->routes->isCurrent($routing))return null;
                $pageNumber=$routing->page;$eventQuery=$routing->eventQuery($eventQuery);
            }
            $presentation=$page?$this->pages->presentation($page):['theme'=>null,'cache'=>'inherit'];
            $rendererStamp=$page?$this->templateRenderers?->stamp($page):'native';
            if($page&&isset($page['template_definition']['frontend_renderer'])&&$rendererStamp===null)return null;
            $rendererListing=$page?$this->templateRenderers?->listing($page):'none';
            if($presentation['theme']!==null)$this->identity['website_design']=$presentation['theme'];
            $day = (new \DateTimeImmutable(
                "now",
                new \DateTimeZone("Europe/Berlin"),
            ))->format("Y-m-d");
            $key = implode("|", [
                "renderer-8",
                $rendererStamp??'native',
                hash('sha256',json_encode($eventQuery,JSON_THROW_ON_ERROR)),
                $routing?->fingerprint()??"exact",
                hash_file("sha256", dirname(__DIR__) . "/Routing/Markup.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/Links.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/Policy.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/Context.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/Resolver.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/RequestPath.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/TemplatePolicies.php"),
                hash_file("sha256", dirname(__DIR__) . "/Routing/Transport.php"),
                hash_file("sha256", __FILE__),
                hash_file("sha256", __DIR__ . "/MediaCredit.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/MediaMetadata.php"),
                hash_file("sha256", __DIR__ . "/Website/HomePage.php"),
                hash_file("sha256", __DIR__ . "/Website/ContentSectionsView.php"),
                hash_file("sha256", __DIR__ . "/Website/ContentSectionCrop.php"),
                hash_file("sha256", __DIR__ . "/Website/PublishedFields.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/HomeLayout.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/Navigation.php"),
                hash_file("sha256", __DIR__ . "/PublicLayout.php"),
                hash_file("sha256", __DIR__ . "/LanguageMarkup.php"),
                hash_file("sha256", __DIR__ . "/PublishedLanguage.php"),
                hash_file("sha256", dirname(__DIR__) . "/Language/PublicResolver.php"),
                hash_file("sha256", __DIR__ . "/WebsiteManifest.php"),
                hash_file("sha256", __DIR__ . "/BootstrapAssets.php"),
                hash_file("sha256", __DIR__ . "/Indexing.php"),
                Indexing::enabled($this->identity, $this->origin)
                    ? "indexable"
                    : "blocked",
                hash_file("sha256", __DIR__ . "/Events.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/EventDetails.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/Events.php"),
                hash_file("sha256", __DIR__ . "/Gallery.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/PublishedAlbums.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/AlbumOrder.php"),
                hash_file("sha256", __DIR__ . "/Articles.php"),
                hash_file("sha256", dirname(__DIR__) . "/Cms/Articles.php"),
                hash_file("sha256", __DIR__ . "/Html.php"),
                hash_file("sha256", dirname(__DIR__) . "/Shared/RichText.php"),
                $this->origin,
                $this->club,
                hash(
                    "sha256",
                    json_encode($this->identity, JSON_THROW_ON_ERROR),
                ),
                $generation,
                $slug,
                max(1, $pageNumber),
                $day,
                $page&&\Kulturhus\Native\Cms\EventDetails::isEvent($page)?\Kulturhus\Native\Cms\EventDetails::status($page):'no-event-clock',
                $this->managerUrl,
            ]);
            try{$html = $page
                ? ($page['slug'] === "veranstaltungen"||$rendererListing==='events'||$presentation['cache']==='off'
                    ? $this->renderRouted($page, max(1, $pageNumber), $eventQuery,$routing)
                    : $this->cache->remember(
                        $key,
                        fn() => $this->renderRouted($page, max(1, $pageNumber),[],$routing),
                        $this->club,
                        ['publication'=>$generation],
                        ['website','page:'.$page['id']],
                        $slug,
                    ))
                : null;}catch(\DomainException $error){if($page&&isset($page['template_definition']['frontend_renderer']))return null;throw $error;}
            if ($generation === $this->pages->generation()) {
                if($routing!==null&&!$this->routes->isCurrent($routing))return null;
                if($page&&isset($page['template_definition']['frontend_renderer'])&&$rendererStamp!==$this->templateRenderers?->stamp($page))return null;
                if($html!==null&&$this->commentLinks!==null){$invitation=($this->commentLinks)($page);if(!is_string($invitation))throw new \DomainException('Öffentliche Diskussion nicht verfügbar.');if($invitation!=='')$html=preg_replace_callback('~</main\s*>~i',static fn():string=>$invitation.'</main>',$html,1);}
                return $html;
            }
        }
        throw new \RuntimeException(
            "Website wird gerade veröffentlicht. Bitte erneut laden.",
        );
    }
    private function renderRouted(array $page,int $number,array $query,?\Kulturhus\Native\Routing\Context $routing):string
    {
        $link=$routing!==null&&$this->routes!==null?fn(int $n):string=>$this->routes->pageLink($routing,$n):null;
        $actual=$number;
        $html=$this->render($page,$number,$query,$link,static function(int $resolved)use(&$actual):void{$actual=$resolved;},$routing);
        if($routing!==null)$routing=$routing->withPage($actual);
        return $routing!==null&&$this->routes!==null?\Kulturhus\Native\Routing\Markup::apply($html,$routing,$this->routes):$html;
    }
    private function render(
        array $p,
        int $pageNumber = 1,
        array $eventQuery = [],
        ?\Closure $pageLink = null,
        ?\Closure $resolvedPage = null,
        ?\Kulturhus\Native\Routing\Context $routing = null,
    ): string {
        $e = Html::e(...);
        $language=new PublishedLanguage($this->languages,$this->identity['language']??null);
        $renderer = $p["template_definition"]["renderer"] ?? $p["template"];
        $isGallery = $this->albums && ($p["slug"] === "galerie" || $renderer === "gallery");
        $gallery = "";
        foreach ($isGallery ? [] : ($p["media_ids"] ?? []) as $id) {
            $m = $this->media->publicVariant($id, "medium", $p);
            $thumb = $this->media->publicVariant($id, "thumb", $p);
            if (!$m || !$thumb) {
                continue;
            }
            $gallery .=
                '<figure><img loading="lazy" decoding="async" src="/media/' .
                $e($id) .
                '/medium" srcset="/media/' .
                $e($id) .
                "/thumb " .
                $e($thumb["width"]) .
                "w, /media/" .
                $e($id) .
                "/medium " .
                $e($m["width"]) .
                'w" sizes="(max-width: 700px) 90vw, 45vw" width="' .
                $e($m["width"]) .
                '" height="' .
                $e($m["height"]) .
                '" alt="' .
                $e($m["alt"]) .
                '"><figcaption>' .
                MediaCredit::render($m) .
                "</figcaption></figure>";
        }
        if ($isGallery) {
            $gallery = $this->albums->publicContent(
                $p["slug"] === "galerie" ? null : $p,
                $pageNumber,
                $language,
                $pageLink,
            );
            $resolvedPage?->__invoke($this->albums->publicPageNumber());
        }
        $localized=PublishedLanguage::pagePath($p)!==$p['slug'];
        $title = $p["slug"] === "galerie"&&!$localized ? "Bildergalerie" : $p["title"];
        $intro =
            $p["slug"] === "galerie"&&!$localized
                ? "Bilder aus dem Vereinsleben."
                : $p["excerpt"];
        $custom = (new Website\PublishedFields(
            $this->pages,
            $this->media,
        ))->render($p);
        if(isset($p['template_definition']['frontend_renderer'])){
            if($this->templateRenderers===null)throw new \DomainException('Frontenddarstellung derzeit nicht verfügbar.');
            $listing='';$actualPage=$isGallery?$this->albums->publicPageNumber():$pageNumber;$listingKind=$this->templateRenderers->listing($p);$listingPath='/site/'.PublishedLanguage::pagePath($p);
            if($listingKind==='news'&&$this->articles!==null)$listing=$this->articles->publicContent(null,$pageNumber,$language,$pageLink,$listingPath);
            elseif($listingKind==='events'){
                $eventQuery['seite']=(string)$pageNumber;
                $eventList=(new Events($this->pages->events(),$this->media,$this->origin,$this->identity,$language))->index($p,$eventQuery,$pageLink,static function(int $resolved)use(&$actualPage,$resolvedPage):void{$actualPage=$resolved;$resolvedPage?->__invoke($resolved);},$listingPath);
                $listing=\Kulturhus\Native\TemplateRenderers\HostFragments::listing($eventList);
            }
            $publicBody=Html::content($p);$fragments=['title'=>'<h1>'.$e($title).'</h1>','excerpt'=>$intro===''?'':'<p class="lead">'.$e($intro).'</p>','body'=>$publicBody===''?'':'<div class="prose kh-richtext">'.$publicBody.'</div>','fields'=>$custom,'media'=>$gallery===''?'':'<div class="gallery">'.$gallery.'</div>','documents'=>Website\DocumentLinks::render($p),'sections'=>(new Website\ContentSectionsView($this->media))->render($p)];
            $fragments['listing']=$listing;
            $context=$this->templateRenderers->context($p,$fragments,$this->pages,$this->media,$this->identity['language']??'de',$this->identity['content_language']??'de',$routing?->withPage($actualPage),$this->languages,$this->routes);
            $main='<main id="main" class="subpage"><section class="section site-content">'.$this->templateRenderers->render($p,$context).'</section></main>';
            $html=PublicLayout::render($p['seo_title']?:$title,$p['seo_description']?:$intro,$main,$this->navigation(),$p['slug'],$this->origin,$this->managerUrl,$this->identity,(bool)($p['noindex']??false),$this->navigation(true),$actualPage);
            return str_replace('</head>',$this->templateRenderers->styles($p).'</head>',$html);
        }
        if ($renderer === "event" || $p["slug"] === "veranstaltungen") {
            $events = new Events(
                $this->pages->events(),
                $this->media,
                $this->origin,
                $this->identity,
                $language,
            );
            $main =
                $p["slug"] === "veranstaltungen"
                    ? $events->index($p, $eventQuery,$pageLink,$resolvedPage)
                    : $events->detail($p, $custom);
            return PublicLayout::render(
                $p["seo_title"] ?: $title,
                $p["seo_description"] ?: $intro,
                $main,
                $this->navigation(),
                $p["slug"],
                $this->origin,
                $this->managerUrl,
                $this->identity,
                (bool) ($p["noindex"] ?? false),
                $this->navigation(true),
            );
        }
        $body =
            '<div class="prose kh-richtext">' . Html::content($p) . "</div>" . Website\DocumentLinks::render($p) . (new \Kulturhus\Native\Web\Website\ContentSectionsView($this->media))->render($p);
        $content = $isGallery
            ? ($p["slug"] === "galerie" ? "" : $body . $custom) . $gallery
            : $body . $custom . '<div class="gallery">' . $gallery . "</div>";
        if ($this->articles) {
            if ($p["slug"] === "aktuelles") {
                $content = $this->articles->publicContent(null, $pageNumber,$language,$pageLink);
            } elseif ($p["template"] === "news") {
                $content = $this->articles->publicContent($p,1,$language) . $custom;
            }
        }
        if ($p["slug"] === "start") {
            $main = (new \Kulturhus\Native\Web\Website\HomePage(
                $this->pages,
                $this->media,
                $this->articles,
                $this->identity,
                $language,
            ))->render($p, $body . $custom);
        } else {
            $isNews = $p["slug"] === "aktuelles" || $p["template"] === "news";
            $variant = $isNews
                ? "stories"
                : ($isGallery
                    ? "gallery"
                    : ($renderer === "event"
                        ? "event"
                        : "basic"));
            $eyebrow = $isNews
                ? "02 · Geschichten"
                : ($isGallery
                    ? "Verein in Bildern"
                    : ($renderer === "event"
                        ? "Veranstaltung"
                        : "Unser Verein"));
            $back = "";
            if ($p["template"] === "news") {
                $back =
                    '<a class="back-link" href="/site/'.Html::e($language->path('aktuelles')).'">← Alle Beiträge</a>';
                $eyebrow =
                    '<time datetime="' .
                    $e($p["news_date"]) .
                    '">' .
                    $e(
                        substr($p["news_date"], 8, 2) .
                            "." .
                            substr($p["news_date"], 5, 2) .
                            "." .
                            substr($p["news_date"], 0, 4),
                    ) .
                    "</time>";
            }
            $main =
                '<main id="main" class="subpage' .
                ($isGallery ? " gallery-page" : "") .
                '"><header class="page-hero page-hero--' .
                $variant .
                '">' .
                $back .
                '<p class="eyebrow">' .
                $eyebrow .
                "</p><h1>" .
                $e($title) .
                '</h1><p class="lead">' .
                $e($intro) .
                '</p></header><section class="section site-content' .
                ($isGallery ? " gallery-content" : "") .
                '">' .
                $content .
                "</section></main>";
        }
        return PublicLayout::render(
            $p["seo_title"] ?: $title,
            $p["seo_description"] ?: $intro,
            $main . ($isGallery ? Gallery::lightbox() : ""),
            $this->navigation(),
            $p["slug"],
            $this->origin,
            $this->managerUrl,
            $this->identity,
            (bool) ($p["noindex"] ?? false),
            $this->navigation(true),
            $isGallery ? $this->albums->publicPageNumber() : 1,
        );
    }

    private function navigation(bool $footer=false):array
    {
        $links=$footer?$this->pages->footerNavigation():$this->pages->navigation();
        foreach($links as&$link)$link['source_slug']=$link['slug'];unset($link);
        return $this->languages&&isset($this->identity['language'])?$this->languages->navigation($links,$this->identity['language']):$links;
    }
    private function publicAddress(string $path):string
    {
        if($this->routes!==null){$resolved=$this->routes->resolve('/site/'.$path);if($resolved->context!==null)return $this->routes->transport->absolute($this->routes->pageLink($resolved->context,1),$resolved->context->policy->definition['https']==='https');}
        return $this->origin.'/site/'.$path;
    }
    public function sitemap(): string
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $generation = $this->pages->generation();
            $xml =
                '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            for ($offset = 0; ; $offset += 100) {
                $batch = $this->pages->publicList(100, $offset);
                foreach ($batch as $p) {
                    if ($p["noindex"]) {
                        continue;
                    }
                    $lastmod = (new \DateTimeImmutable(
                        $p["published_at"],
                        new \DateTimeZone("UTC"),
                    ))->format(DATE_ATOM);
                    $xml .=
                        "<url><loc>" .
                        Html::e($this->publicAddress($p["slug"])) .
                        "</loc><lastmod>" .
                        Html::e($lastmod) .
                        "</lastmod></url>";
                    foreach($this->languages?->sourceContext($p)['alternates']??[]as$path){
                        if($path===$p['slug'])continue;
                        $xml.='<url><loc>'.Html::e($this->publicAddress($path)).'</loc></url>';
                    }
                }
                if (count($batch) < 100) {
                    break;
                }
            }
            if ($generation === $this->pages->generation()) {
                return $xml . "</urlset>";
            }
        }
        throw new \RuntimeException(
            "Website wird gerade veröffentlicht. Sitemap erneut laden.",
        );
    }
}
