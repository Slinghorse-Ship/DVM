<?php
declare(strict_types=1);

namespace Kulturhus\Native\Integration;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Media, Pages};
use Kulturhus\Native\Shared\{Access,HttpValidators};
use Kulturhus\Native\Web\{Articles, Cache, Feeds, FeedResponse, Gallery, Html, Indexing, Membership, PublicLayout, PublicSearchView, Site};
use PDO;

/** Public projection for the existing manager host. Authentication/session/origin guards belong to its router. */
final class PublicSite
{
    /** Returns true once a public response was handled; caller must then return/exit. Never terminates PHP itself. */
    public static function serve(PDO $db, Access $publicAccess, string $mediaRoot, string $runtime, string $managerClub, string $origin, ?string $siteBase=null): bool
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($path) || $path === '') return false;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if(\Kulturhus\Native\Web\MemberCardPublicPage::serve($db,ManagerBridge::clubKey($managerClub)))return true;
        if (ApiRoutes::serve($db,$managerClub,$origin,$mediaRoot)) return true;
        if (CommentsRoutes::serve($db,$publicAccess,$runtime,$managerClub,$origin)) return true;
        $requestOrigin=$origin;$origin=$siteBase??$origin;
        $asset = self::assets()[$path] ?? null;
        if ($asset !== null) {
            if (!self::method($method, false)) return true;
            self::headers();
            $mime = \Kulturhus\Native\Web\PublicAssets::mime($asset);
            if (!is_file($asset)) return self::error(404, 'Datei nicht gefunden.', $method);
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=0, must-revalidate');
            return self::file($asset, $method);
        }
        // These spaces are exclusively owned by the authenticated manager/account dispatcher.
        if (preg_match('~^/(?:vereinsverwaltung|login|logout|account|accounts|invite|reset|forgot|design-preview|home-preview|sections-preview|preview|media-preview|media-removed|media-storage|website-document-preview|chat-file|chat-image|chat-status|model-fields|reference-options|page-photo-status|album-photo-status|social-credit)(?:/|$)~D', $path)) return false;

        $route = rtrim($path, '/') ?: '/';
        // Published event links from the earlier website used repeated hyphens.
        // Resolve the strict canonical path first; private and missing owners never redirect.
        $legacyEventPath = str_contains($route, '--') && preg_match('~\A/veranstaltungen/[a-z0-9]+(?:-+[a-z0-9]+)+\z~D', $route)
            ? preg_replace('/-{2,}/', '-', $route) . (str_ends_with($path, '/') ? '/' : '') : null;
        $feedRoute = match ($route) { '/feeds/termine' => '/feeds/events.ics', '/feeds/veranstaltungen' => '/feeds/events.json', default => $route };
        $isMedia = str_starts_with($route, '/media/');
        $isDocument = str_starts_with($route, '/website-document/');
        $isFile = str_starts_with($route, '/file/');
        $isFeed = str_starts_with($route, '/feeds/');
        $isSearch = in_array($route, ['/search', '/suche'], true);
        $isTickets = $route==='/tickets'||str_starts_with($route,'/tickets/');
        $isWebhook = str_starts_with($path, '/meta-webhook/');
        $isStatus=$route==='/status';
        $isExtension=$route==='/extensions'||str_starts_with($route,'/extensions/');
        $special = in_array($route, ['/robots.txt', '/sitemap.xml', '/sitemap'], true) || \Kulturhus\Native\Web\WebsiteManifest::owns($route);
        $siteAlias = $route === '/site' || str_starts_with($route, '/site/');
        $slug = $route === '/' || $route === '/site' ? 'start' : ($siteAlias ? substr($route, 6) : ltrim($route, '/'));
        if (!$isWebhook && !$isMedia && !$isDocument && !$isFile && !$isFeed && !$isSearch && !$special && (($legacyEventPath === null && !preg_match('~\A[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\z~D', $slug)) || strlen($path) > \Kulturhus\Native\Routing\RequestPath::MAX_LENGTH)) {
            return $siteAlias ? self::error(404, 'Seite nicht gefunden.', $method) : false;
        }
        $owned = $isExtension || $isStatus || $siteAlias || $route === '/' || $isMedia || $isDocument || $isFile || $isFeed || $isSearch || $special || $isTickets || $isWebhook || \Kulturhus\Native\Web\PublicRoutes::ownsAlias($route);
        try {
            $origin = rtrim($origin, '/');
            $publicAccess->require('website', 'read');
            if ($managerClub === '' || !hash_equals(ManagerBridge::clubKey($managerClub), $publicAccess->club())) throw new DomainException('Öffentlicher Vereinszugang nicht verfügbar.');
            if(FileRoutes::serve($db,$publicAccess,$mediaRoot,$route,$method,true))return true;
            $extensions=null;
            if(\Kulturhus\Native\Shared\Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_extensions'")->fetchColumn())$extensions=new \Kulturhus\Native\Extensions\Runtime($db,$managerClub);
            if($isExtension){
                if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');return self::error(405,'Diese Anfrage ist nicht erlaubt.',$method);}
                $response=$extensions?->url($method,$route);if($response===null)return self::error(404,'Erweiterung nicht verfügbar.',$method);
                return self::extensionResponse($response,$method);
            }
            if($isStatus)return \Kulturhus\Native\Status\PublicPage::serve($db,$managerClub,$mediaRoot,$method,$publicAccess,(string)($_SERVER['REQUEST_URI']??'/status'));
            if(str_starts_with($path,'/meta-webhook/')){
                $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE');if(!$key||!is_file($key))return self::error(503,'Empfang nicht eingerichtet.',$method);
                return \Kulturhus\Native\Web\MetaWebhookEndpoints::serve($db,$publicAccess->club(),\Kulturhus\Native\Shared\SecretBox::fromFile($key),$path);
            }
            if($isTickets){
                $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE');if(!$key||!is_file($key))return self::error(503,'Ticketservice nicht eingerichtet.',$method);
                if(\Kulturhus\Native\Web\Tickets\PublicRoutes::serve($db,$publicAccess->club(),\Kulturhus\Native\Shared\SecretBox::fromFile($key),$requestOrigin,$publicAccess))return true;
                return self::error(404,'Ticketseite nicht gefunden.',$method);
            }
            if(preg_match('~^/(?:newsletter(?:/|$)|newsletter-confirm/?$|newsletter-unsubscribe/?$)~D',$path)){
                $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE');if(!$key||!is_file($key))return self::error(503,'Newsletter noch nicht eingerichtet.',$method);
                if(\Kulturhus\Native\Web\NewsletterEndpoints::serve($db,$publicAccess->club(),\Kulturhus\Native\Shared\SecretBox::fromFile($key),$mediaRoot,$requestOrigin,$publicAccess))return true;
            }
            if(str_starts_with($route,'/publication-video/')){
                if(!self::method($method,false))return true;
                $video=\Kulturhus\Native\Communication\VideoPublication::publicFile($db,$publicAccess->club(),substr($route,19),$mediaRoot);
                if(!$video)return self::error(404,'Video nicht verfügbar.',$method);self::headers();\Kulturhus\Native\Web\VideoResponse::serve($video,$method,$_SERVER['HTTP_RANGE']??null);return true;
            }
            if(str_starts_with($route,'/publication-image/')){
                if(!self::method($method,false))return true;
                $image=\Kulturhus\Native\Communication\Images::publicFile($db,$publicAccess->club(),substr($route,19),$mediaRoot);
                if(!$image)return self::error(404,'Bild nicht verfügbar.',$method);self::headers();header('Content-Type: '.$image['mime']);header('Cache-Control: private, no-store');return self::file($image['path'],$method);
            }
            if (\Kulturhus\Native\Web\WebsiteManifest::serve($db, $publicAccess, $route, $method)) return true;
            $pages = new Pages($db, $publicAccess);
            $identity = \Kulturhus\Native\Shared\Identity::forClub($db, $publicAccess->club());
            $identity['website_design'] = \Kulturhus\Native\Cms\WebsiteDesign::forClub($db, $publicAccess->club())['template'];
            $identity['website_indexing'] = \Kulturhus\Native\Cms\WebsiteSettings::forClub($db, $publicAccess->club())['enabled'];
            // Also validates the fixed, server-supplied origin; never derives it from Host or a query parameter.
            $feeds = new Feeds($pages, $requestOrigin, (string) ($identity['name'] ?? ''), null, $siteBase!==null?'/demo':'');
            if ($route === '/robots.txt') {
                if (!self::method($method, false)) return true;
                return self::respond(Indexing::robots($identity, $origin), 'text/plain; charset=utf-8', $method);
            }
            if ($isDocument) {
                if (!self::method($method,false)) return true;
                $doc=null;
                if (preg_match('~^/website-document/([a-f0-9]{32})$~D',$route,$match)) {
                    try {$doc=(new \Kulturhus\Native\Cms\WebsiteDocuments($db,$publicAccess,dirname($mediaRoot).'/website-documents'))->publicFile($match[1]);} catch (DomainException) {}
                }
                \Kulturhus\Native\Web\DocumentResponse::send($doc,$method);return true;
            }
            if ($isMedia) {
                if (!self::method($method, false)) return true;
                if (!preg_match('~^/media/([a-f0-9]{32})/(original|large|medium|thumb)$~D', $route, $match)) return self::error(404, 'Bild nicht gefunden.', $method);
                $image = (new Media($db, $publicAccess, $mediaRoot))->publicVariant($match[1], $match[2]);
                if (!$image || !is_file($image['path'])) return self::error(404, 'Bild nicht gefunden.', $method);
                self::headers(); header('Content-Type: ' . $image['mime']); header('Cache-Control: private, no-cache');
                return self::file($image['path'], $method);
            }
            if ($isFeed) {
                $json=$feedRoute==='/feeds/events.json';
                self::headers();if(FeedResponse::methodResponse($method,$json))return true;
                $feeds = new Feeds($pages,$requestOrigin,(string)($identity['name']??''),new Media($db,$publicAccess,$mediaRoot),$siteBase!==null?'/demo':'');
                $event=null;
                if($feedRoute==='/feeds/events.ics' && array_key_exists('event',$_GET)) {
                    $event=$_GET['event'];
                    if(!is_string($event)||!preg_match('/^[a-f0-9]{32}$/D',$event))throw new InvalidArgumentException('Ungültiger Kalendertermin.');
                    if(!$pages->events()->publicId($event))return self::error(404,'Veranstaltung nicht veröffentlicht.',$method);
                }
                return match ($feedRoute) {
                    '/feeds/all.rss.xml' => FeedResponse::sendDocument($feeds->document('rss'), 'application/rss+xml; charset=utf-8', $method),
                    '/feeds/alle' => FeedResponse::sendDocument($feeds->document('rss','mixed'), 'application/rss+xml; charset=utf-8', $method),
                    '/feeds/news', '/feeds/news.rss.xml' => FeedResponse::sendDocument($feeds->document('rss','news'), 'application/rss+xml; charset=utf-8', $method),
                    '/feeds/events.ics' => FeedResponse::sendDocument($feeds->document('ics','all',$event), 'text/calendar; charset=utf-8', $method,false,$event===null?null:'termin-'.$event.'.ics'),
                    '/feeds/events.json' => FeedResponse::sendDocument($feeds->document('json'), 'application/json; charset=utf-8', $method,true),
                    default => self::error(404, 'Feed nicht gefunden.', $method),
                };
            }
            if ($isSearch) {
                if (!self::method($method, false)) return true;
                $query = $_GET['q'] ?? '';
                if (!is_string($query)) throw new InvalidArgumentException('Suchtext muss Text sein.');
                $locale=$_GET['lang']??null;if($locale!==null&&!is_string($locale))throw new InvalidArgumentException('Eine Website-Sprache auswählen.');
                $results = $feeds->searchPage($query,$_GET['seite']??1,$locale);
                $main=PublicSearchView::layout($results,$db,$publicAccess,$pages,$origin,'/vereinsverwaltung/',$identity,$route);
                return self::htmlResponse($main,$origin,$method,$extensions);
            }
            $routes=new \Kulturhus\Native\Routing\Resolver($db,$publicAccess,new \Kulturhus\Native\Routing\Transport($requestOrigin,false,parse_url($requestOrigin,PHP_URL_SCHEME)==='https'));
            $site = new Site($pages, new Media($db, $publicAccess, $mediaRoot), new Cache($runtime . '/public-cache'), $origin, $publicAccess->club(), new Gallery($db, $publicAccess, $mediaRoot), new Articles($db, $publicAccess, $mediaRoot), '/vereinsverwaltung/', $identity, static fn()=>\Kulturhus\Native\Cms\WebsiteDesign::forClub($db,$publicAccess->club())['template'],new \Kulturhus\Native\Language\PublicResolver($db,$publicAccess),$routes,commentLinks:static fn(array $page):string=>\Kulturhus\Native\Comments\PublicLink::page($db,$publicAccess,$page),templateRenderers:new \Kulturhus\Native\TemplateRenderers\Runtime($db,$publicAccess->club(),$extensions?->renderers()??new \Kulturhus\Native\TemplateRenderers\Registry()));
            if (in_array($route, ['/sitemap.xml', '/sitemap'], true)) {
                if (!self::method($method, false)) return true;
                $xml = $site->sitemap();
                $xml = preg_replace_callback('~<loc>([^<]*)</loc>~', static fn (array $m): string => '<loc>' . Html::e(self::url(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), $origin)) . '</loc>', $xml);
                return self::respond($xml, 'application/xml; charset=utf-8', $method);
            }
            $requestTarget=(string)($_SERVER['REQUEST_URI']??'/');
            if($legacyEventPath!==null){$query=strstr($requestTarget,'?');$requestTarget=$legacyEventPath.($query===false?'':$query);}
            $resolved=$routes->resolve($requestTarget);
            if($resolved->kind==='unclaimed')return $owned?self::error(404,'Seite nicht veröffentlicht.',$method):false;
            if($resolved->kind==='missing')return self::error(404,'Seite nicht veröffentlicht.',$method);
            if($resolved->kind==='redirect'||$legacyEventPath!==null){
                if(!self::method($method,false))return true;self::headers();header('Cache-Control: no-store');header('Location: '.($resolved->location??\Kulturhus\Native\Routing\Links::path($resolved->context)),true,308);return true;
            }
            $routing=$resolved->context;$published=$resolved->published;$slug=$routing->basePath;
            $membership=$routing->sourceSlug==='mitglied-werden'&&$routing->parameters===[]&&$routing->page===1;
            if(!self::method($method,$membership))return true;
            if ($membership) {
                self::headers(); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
                if ($method === 'HEAD') return true;
                ManagerSession::start();
                $csrf = $_SESSION['csrf'] ?? '';
                if (!is_string($csrf) || $csrf === '') throw new DomainException('Formularsitzung nicht verfügbar.');
                if ($method === 'POST' && (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf']))) return self::error(403, 'Formular abgelaufen. Bitte Seite neu öffnen.', $method);
                require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
                $form = new Membership(new \Kulturhus\Verein\MembershipApplications($db, $managerClub), $identity);
                $local = !\Kulturhus\Verein\ProductionRuntime::enabled() || getenv('KULTURHUS_DISABLE_MAIL') === '1' || getenv('KULTURHUS_LOCAL_DEMO') === '1' || in_array(getenv('APP_ENV'), ['local', 'local-synthetic'], true);
                $main = $form->render($_POST, $csrf, $method === 'POST', $local);
                echo self::html(PublicLayout::render($published['title'], $published['excerpt'], $main, $pages->navigation(), $slug, $origin, '/vereinsverwaltung/', $identity, false, $pages->footerNavigation()), $origin);
                return true;
            }
            $html = $site->page($slug,$routing->page,$routing->eventQuery($_GET),$routing);
            if ($html === null) return self::error(404, 'Seite nicht veröffentlicht.', $method);
            return self::htmlResponse($html,$origin,$method,$extensions,Indexing::directive($identity, $origin, $published['noindex'] ?? false, $slug) === 'index,follow');
        } catch (InvalidArgumentException) {
            return self::error(400, 'Ungültige öffentliche Anfrage.', $method);
        } catch (DomainException) {
            return self::error(403, 'Öffentlicher Inhalt nicht verfügbar.', $method);
        } catch (\Throwable $error) {
            error_log('Public site error: ' . $error::class . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
            return self::error(500, 'Die Website ist vorübergehend nicht verfügbar.', $method);
        }
    }

    /** Fixed project-authored assets only; paths never come from request interpolation. */
    public static function assets(): array
    {
        return \Kulturhus\Native\Web\PublicAssets::sources();
    }

    private static function method(string $method, bool $membership): bool
    {
        if (in_array($method, $membership ? ['GET', 'HEAD', 'POST'] : ['GET', 'HEAD'], true)) return true;
        self::headers(); http_response_code(405); header('Allow: ' . ($membership ? 'GET, HEAD, POST' : 'GET, HEAD')); header('Cache-Control: no-store');
        return false;
    }

    private static function headers(bool $indexable = false): void
    {
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: ' . ($indexable ? 'index, follow' : 'noindex, nofollow'));
        header("Content-Security-Policy: default-src 'none'; manifest-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src 'self'; frame-ancestors 'self'");
    }

    private static function respond(string $body, string $type, string $method, bool $indexable = false): bool
    {
        self::headers($indexable); header('Content-Type: ' . $type); header('Cache-Control: private, no-cache');
        $etag = '"' . hash('sha256', $body) . '"'; header('ETag: ' . $etag);
        if (HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??'',$etag)) { http_response_code(304); return true; }
        header('Content-Length: '.strlen($body));
        if ($method !== 'HEAD') echo $body;
        return true;
    }

    private static function file(string $path, string $method): bool
    {
        $etag = '"' . hash_file('sha256', $path) . '"'; header('ETag: ' . $etag);
        if (HttpValidators::unchanged($method,$_SERVER['HTTP_IF_NONE_MATCH']??'',$etag)) { http_response_code(304); return true; }
        header('Content-Length: ' . filesize($path));
        if ($method !== 'HEAD') readfile($path);
        return true;
    }

    private static function error(int $status, string $message, string $method): bool
    {
        self::headers(); http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
        if ($method !== 'HEAD') echo $message;
        return true;
    }

    private static function htmlResponse(string $html,string $origin,string $method,?\Kulturhus\Native\Extensions\Runtime $extensions,bool $indexable=false):bool
    {
        $body=self::html($html,$origin,$extensions);$response=['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8'],'body'=>$body];
        if($extensions)$response=$extensions->response($response);return self::extensionResponse($response,$method,$indexable);
    }
    private static function extensionResponse(array $response,string $method,bool $indexable=false):bool
    {
        if(!\Kulturhus\Native\Extensions\Runtime::validResponse($response))throw new DomainException('Ungültige Erweiterungsantwort.');
        $status=$response['status']??200;$headers=array_change_key_case($response['headers'],CASE_LOWER);$type=$headers['content-type']??'text/html; charset=utf-8';
        if(isset($headers['content-language']))header('Content-Language: '.$headers['content-language']);
        if($status===200)return self::respond($response['body'],$type,$method,$indexable);
        self::headers();http_response_code($status);header('Content-Type: '.$type);header('Cache-Control: no-store');if($method!=='HEAD'&&$status!==204)echo$response['body'];return true;
    }
    /** Rewrite app links, leaving external destinations and HTML content untouched. */
    private static function html(string $html, string $origin,?\Kulturhus\Native\Extensions\Runtime $extensions=null): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8'); $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $dom->encoding = 'UTF-8';
        if($extensions)foreach($dom->getElementsByTagName('title')as$title){$value=$extensions->property('site.title',$title->textContent);while($title->firstChild)$title->removeChild($title->firstChild);$title->appendChild($dom->createTextNode($value));}
        foreach ($dom->childNodes as $node) if ($node->nodeType === XML_PI_NODE) $dom->removeChild($node);
        // RichText is persisted user content; only app-composed links are adapted.
        foreach ((new \DOMXPath($dom))->query('//*[@href][not(ancestor-or-self::*[contains(concat(" ", normalize-space(@class), " "), " kh-richtext ")])]') as $node) $node->setAttribute('href', self::url($node->getAttribute('href'), $origin));
        foreach ((new \DOMXPath($dom))->query('//form[@data-public-gallery-page and @action][not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " kh-richtext ")])]') as $node) $node->setAttribute('action', self::url($node->getAttribute('action'), $origin));
        return $dom->saveHTML();
    }

    private static function url(string $url, string $origin): string
    {
        if (str_starts_with($url, '/?')) return Routes::translate($url);
        $absolute = str_starts_with($url, rtrim($origin, '/') . '/');
        $relative = $absolute ? substr($url, strlen(rtrim($origin, '/'))) : $url;
        if (!str_starts_with($relative, '/site/')) return $url;
        $parts = parse_url($relative);
        if (!is_array($parts)) return $url;
        $slug = trim(substr($parts['path'] ?? '', 6), '/');
        $target = $slug === 'start' || $slug === '' ? '/' : '/' . $slug . '/';
        if (isset($parts['query'])) $target .= '?' . $parts['query'];
        if (isset($parts['fragment'])) $target .= '#' . $parts['fragment'];
        return ($absolute ? rtrim($origin, '/') : '') . $target;
    }
}
