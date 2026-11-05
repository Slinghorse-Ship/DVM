<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
/** One manager URL space; the new chat remains its own top-level module. */
final class Routes
{
    public const VIEWS = [
        "overview",
        "website",
        "navigation",
        "events",
        "news",
        "media",
        "model",
        "settings",
        "newsletter",
        "social",
        "press",
        "chat",
    ];
    public static function view(array $query): ?string
    {
        $view = $query["view"] ?? "";
        $tab = $query["tab"] ?? "";
        if ($view === "website") {
            return match ($tab) {
                "" => !empty($query["native_item"]) ? "website" : "overview",
                "overview" => "overview",
                "pages" => "website",
                "navigation" => "navigation",
                "events" => "events",
                "news" => "news",
                "gallery" => "media",
                "technical" => "model",
                "integrations" => "settings",
                default => null,
            };
        }
        return in_array($view, ["newsletter", "social", "press", "chat"], true)
            ? $view
            : null;
    }
    public static function query(
        string $view,
        string $id = "",
        int $before = PHP_INT_MAX,
    ): array {
        if (!in_array($view, self::VIEWS, true)) {
            throw new \DomainException("Unbekanntes Modul.");
        }
        $query = match ($view) {
            "news" => ["view" => "website", "tab" => "news"],
            "media" => ["view" => "website", "tab" => "gallery"],
            "model" => ["view" => "website", "tab" => "technical"],
            "settings" => ["view" => "website", "tab" => "integrations"],
            "website" => ["view" => "website", "tab" => "pages"],
            "overview" => ["view" => "website", "tab" => "overview"],
            "navigation" => ["view" => "website", "tab" => "navigation"],
            "events" => ["view" => "website", "tab" => "events"],
            default => ["view" => $view],
        };
        if ($id !== "") {
            $query["native_item"] = $id;
        }
        if ($before < PHP_INT_MAX) {
            $query["chat_before"] = $before;
        }
        return $query;
    }
    public static function url(
        string $view,
        string $id = "",
        int $before = PHP_INT_MAX,
    ): string {
        return "/vereinsverwaltung/?" .
            http_build_query(self::query($view, $id, $before));
    }
    /** Preserve a service-selected workspace after saving, with a closed route allowlist. */
    public static function result(array $result): array
    {
        $query = self::query($result["view"], $result["item"] ?? "");
        if($result['view']==='events'&&isset($result['tickets'])){if($result['tickets']!=='1')throw new \DomainException('Ticketbereich prüfen.');$query['tickets']='1';}
        if($result['view']==='model')$query+=\Kulturhus\Native\Cms\ModelCatalogue::state($result);
        if($result['view']==='news')$query+=\Kulturhus\Native\Cms\ArticleCatalogue::filters($result);
        if($result['view']==='chat'){
            foreach(\Kulturhus\Native\Chat\Catalogue::state($result)as$key=>$value)$query[$key]=$value;
            if(isset($result['chat_before']))$query['chat_before']=\Kulturhus\Native\Web\ChatFormState::number($result['chat_before']);
        }
        if($result['view']==='settings'){
            if(isset($result['webhook_focus'])){$query['webhook_focus']=\Kulturhus\Native\Communication\Content::id($result['webhook_focus']);}
            if(isset($result['webhook_page'])){if(!is_int($result['webhook_page'])&&!is_string($result['webhook_page']))throw new \DomainException('Eingangsseite prüfen.');$number=filter_var($result['webhook_page'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);if($number===false)throw new \DomainException('Eingangsseite prüfen.');$query['webhook_page']=$number;}
        }
        if($result['view']==='social'&&isset($result['compose_state']['video_reuse_only'])){
            $state=\Kulturhus\Native\Web\Communication\VideoReuseForm::restore($result['compose_state']['video_reuse_only']);$original=\Kulturhus\Native\Web\Communication\VideoReusePanel::original($state);foreach(['video_use_window','video_use_check','video_use_query','video_use_page','video_use_original','video_use_source','video_use_version','video_use_identity']as$key)$query[$key]=$original[$key];
        }elseif($result['view']==='social'&&isset($result['video_use_check'])){
            if($result['video_use_check']!=='1'||!is_string($result['video_use_query']??null)||!is_string($result['video_use_page']??null)||!preg_match('/^[1-9][0-9]{0,6}$/D',$result['video_use_page'])||(int)$result['video_use_page']>1000000||!is_string($result['video_use_result']??null))throw new \DomainException('Videoauswahl erneut öffnen.');
            \Kulturhus\Native\Communication\Content::text($result['video_use_query'],200);\Kulturhus\Native\Communication\Content::id($result['video_use_result']);foreach(['video_use_check','video_use_query','video_use_page','video_use_result']as$key)$query[$key]=$result[$key];
        }
        if($result['view']==='social'&&isset($result['video_recovery_check'])){
            if($result['video_recovery_check']!=='1')throw new \DomainException('Video-Wiederherstellung erneut öffnen.');
            foreach(['video_recovery_source','video_recovery_after']as$key){if(!is_string($result[$key]??null))throw new \DomainException('Ursprünglichen Videovorgang auswählen.');if($result[$key]!=='')\Kulturhus\Native\Communication\Content::id($result[$key]);$query[$key]=$result[$key];}
            $query['video_recovery_check']='1';
        }
        if($result['view']==='social'&&isset($result['video_archive'])){
            if(!in_array($result['video_archive'],['0','1'],true)||(!is_string($result['video_page']??null)&&!is_int($result['video_page']??null))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$result['video_page'])||(int)$result['video_page']>1000000)throw new \DomainException('Videoarchiv-Seite prüfen.');
            $query['video_archive']=$result['video_archive'];$query['video_page']=$result['video_page'];
        }
        if($result['view']==='media'&&isset($result['album_page'])){
            if(!is_string($result['album_page'])||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$result['album_page']))throw new \DomainException('Gültige Bilderseite angeben.');
            $query['album_page']=$result['album_page'];
        }
        if($result['view']==='media'&&isset($result['album_list_page'])){
            $listPage=filter_var($result['album_list_page'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000000]]);
            if(!is_string($result['album_list_page'])||$listPage===false||!is_string($result['q']??null)||!in_array($result['status']??null,['all','draft','published'],true))throw new \DomainException('Albumlistenstand prüfen.');
            $query['album_list_page']=$result['album_list_page'];$query['q']=$result['q'];$query['status']=$result['status'];
        }
        if(($result['view']??null)==='website'&&($result['section']??null)==='comments'){
            $query['section']='comments';
            foreach(['comment','comments_page']as$key)if(isset($result[$key])&&$result[$key]!=='')$query[$key]=\Kulturhus\Native\Communication\Content::id($result[$key]);
            if(isset($result['comments_stream']))$query['comments_stream']=\Kulturhus\Native\Comments\Values::stream($result['comments_stream']);
            if(isset($result['comments_state'])){if(!in_array($result['comments_state'],['pending','approved','rejected','spam','removed','settings'],true))throw new \DomainException('Kommentarstatus prüfen.');$query['comments_state']=$result['comments_state'];}
            foreach(['comments_list_page'=>50001,'comments_history_page'=>100001]as$key=>$maximum)if(isset($result[$key])){$value=$result[$key];if((!is_int($value)&&!is_string($value))||!preg_match('/\A[1-9][0-9]{0,5}\z/D',(string)$value)||(int)$value>$maximum)throw new \DomainException('Kommentarseite prüfen.');$query[$key]=$value;}
            return $query;
        }
        if (isset($result["section"])) {
            if ($result['view'] === 'media' && in_array($result['section'],['library','removed','storage','files'],true)) { $query['section']=$result['section']; return $query; }
            if ($result['view'] === 'website' && in_array($result['section'],['trash','bulk','lister','copy-jobs'],true)) {
                $query['section'] = $result['section'];
                if (isset($result['batch'])) $query['batch'] = \Kulturhus\Native\Communication\Content::id($result['batch']);
                if (isset($result['copy_job'])) $query['copy_job'] = \Kulturhus\Native\Communication\Content::id($result['copy_job']);
                if (isset($result['list_id'])&&$result['list_id']!=='') $query['list_id'] = \Kulturhus\Native\Communication\Content::id($result['list_id']);
                return $query;
            }
            if (
                !in_array(
                    $result["view"],
                    ["newsletter", "social", "press"],
                    true,
                ) ||
                !in_array(
                    $result["section"],
                    [
                        "compose",
                        "recipients",
                        "templates",
                        "planning",
                        ...in_array($result['view'],['social','newsletter'],true)?['contents']:[],
                        ...$result["view"] === "social"
                            ? [
                                "library",
                                "inbox",
                                "events",
                                "review",
                                "replies",
                                "mail",
                                "sync",
                            ]
                            : [],
                    ],
                    true,
                )
            ) {
                throw new \DomainException("Unbekannter Arbeitsbereich.");
            }
            $query["section"] = $result["section"];
        }
        if(isset($result['planning_view'])){
            if(!in_array($result['view'],['social','newsletter'],true)||($result['section']??null)!=='planning'||!is_string($result['planning_view'])||!in_array($result['planning_view'],['month','history','automation'],true))throw new \DomainException('Planungsansicht prüfen.',422);
            $query['planning_view']=$result['planning_view'];
            foreach(\Kulturhus\Native\Web\Communication\PlanningFormState::route($result['view'],$result)as$key=>$value)if(!in_array($key,['view','section'],true))$query[$key]=$value;
        }
        if (isset($result["reply"])) {
            if (
                $result["view"] !== "social" ||
                !in_array($result["section"] ?? null, ['replies','inbox'], true)
            ) {
                throw new \DomainException(
                    "Antwortverlauf nur im Social-Arbeitsbereich öffnen.",
                );
            }
            $query["reply"] = \Kulturhus\Native\Communication\Content::id(
                $result["reply"],
            );
        }
        if (isset($result['attention'])) {
            if ($result['view'] !== 'social' || ($result['section'] ?? null) !== 'review') throw new \DomainException('Gespräch nur im Social-Arbeitsbereich öffnen.');
            $query['attention'] = \Kulturhus\Native\Communication\Content::id($result['attention']);
        }
        if (isset($result['thread'])) {
            if ($result['view'] !== 'social' || ($result['section'] ?? null) !== 'inbox') throw new \DomainException('Nachrichtengespräch nur im Social-Arbeitsbereich öffnen.');
            $query['thread'] = \Kulturhus\Native\Communication\Content::id($result['thread']);
        }
        if(isset($result['queue_job'])){
            if($result['view']!=='social'||($result['section']??null)!=='planning')throw new \DomainException('Veröffentlichungsauftrag nur im Social-Verlauf öffnen.');
            $query['queue_job']=\Kulturhus\Native\Communication\Content::id($result['queue_job']);
        }
        return $query;
    }
    public static function endpoint(string $name, array $args = []): string
    {
        return "/vereinsverwaltung/?" .
            http_build_query(["native_endpoint" => $name, ...$args]);
    }
    public static function translate(string $url): string
    {
        if (str_starts_with($url, "/?")) {
            parse_str(parse_url($url,PHP_URL_QUERY)??'', $q);
            $fragment=parse_url($url,PHP_URL_FRAGMENT);$fragment=is_string($fragment)&&preg_match('/\A[a-zA-Z][a-zA-Z0-9_.:-]{0,127}\z/D',$fragment)?'#'.$fragment:'';
            $view = $q["view"] ?? "";
            if (in_array($view, self::VIEWS, true)) {
                $args = self::query(
                    $view,
                    is_string($q["item"] ?? "") ? $q["item"] ?? "" : "",
                    (int) ($q["chat_before"] ?? PHP_INT_MAX),
                );
                if($view==='website'&&($q['section']??null)==='comments'){
                    $args=array_replace($args,self::result(['view'=>'website','section'=>'comments']+array_intersect_key($q,array_flip(['comment','comments_page','comments_stream','comments_state','comments_list_page','comments_history_page']))));
                    if(isset($q['revision'])){
                        $revision=is_string($q['revision'])?filter_var($q['revision'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>4294967294]]):false;
                        if($revision===false)throw new \DomainException('Kommentarversion auswählen.');
                        $args['revision']=$revision;
                    }
                }
                foreach (
                    [
                        "q",
                        'model_kind',
                        'model_q',
                        'model_page',
                        'model_key',
                        'model_new',
                        "status",
                        "period",
                        "year",
                        "sort",
                        "page",
                        "ticket_offset",
                        "tickets",
                        "children_page",
                        "fieldtypes_page",
                        "sidebar_page",
                        "versions_page",
                        "image_page",
                        "album_page",
                        "album_list_page",
                        "image_anchor",
                        "image_total",
                        "compare_from",
                        "compare_to",
                        "section",
                        "webhook_page",
                        "webhook_focus",
                        "worker_state",
                        "worker_page",
                        "batch",
                        "direction",
                        "kind",
                        "new_kind",
                        "event_plan",
                        "channel",
                        "source",
                        "archiv",
                        "seite",
                        "attention",
                        "reply",
                        "thread",
                        "message_page",
                        "focus_message",
                        "inbox_history",
                        "inbox_history_kind",
                        "inbox_history_page",
                        "inbox_connection",
                        "inbox_version",
                        "conversation_page",
                        "inbox_channel",
                        "review_page",
                        "review_channel",
                        "review_kind",
                        "original",
                        "original_page",
                        "chat_list",
                        "chat_query",
                        "chat_filter",
                        "chat_page",
                        "chat_rooms",
                        "state",
                        "queue_job",
                        "queue_page",
                        "queue_state",
                        "queue_provider",
                        "planning_view",
                        "plan_month",
                        "plan_channel",
                        "plan_state",
                        "plan_step",
                        "hub_query",
                        "hub_type",
                        "hub_page",
                        "press_item",
                        "press_state",
                        "press_page",
                        "press_job",
                        'newsletter_state',
                        'newsletter_kind',
                        'newsletter_page',
                        'newsletter_item',
                        'newsletter_job',
                        'template_focus',
                        'template_new',
                        'template_revision',
                        'template_history_page',
                        'edition_query',
                        'edition_state',
                        'edition_page',
                        'edition_open',
                        'edition_history_page',
                        'edition_history_open',
                        "media_q",
                        "video_page",
                        "video_archive",
                        "video_archive_source",
                        "video_archive_history",
                        "video_lifecycle_source",
                        "video_lifecycle_page",
                        "video_storage_window",
                        "video_storage_check",
                        "video_storage_after",
                        "video_storage_source",
                        "video_recovery_window",
                        "video_recovery_check",
                        "video_recovery_source",
                        "video_recovery_after",
                        "video_recovery_read",
                        "video_use_window",
                        "video_use_original",
                        "video_use_source",
                        "video_use_version",
                        "video_use_identity",
                        "video_use_check",
                        "video_use_query",
                        "video_use_page",
                        "video_use_result",
                        "video_use_read",
                        "check_channel",
                        "event_history_page","event_request","check_request",
                        "check_page",
                        "media_state",
                        "media_archive",
                        "media_archive_page",
                        "media_page",
                        "media_history_page",
                        "media_usage_page",
                        "media_usage_source",
                        "media_global_check",
                        "removed_page",
                        "storage_check",
                        "storage_after",
                        "recover_source",
                        "storage_recoveries",
                        "recoveries_after",
                        "files_page",
                        "file_history_page",
                        "file_group_page",
                        "file_storage",
                        "file_storage_after",
                        "recover_file",
                        "mine",
                        "tag",
                        "removed",
                    ]
                    as $key
                ) {
                    if (isset($q[$key]) && is_string($q[$key])) {
                        $args[$key] = $q[$key];
                    }
                }
                if($view==='website'&&($q['section']??null)==='lister'){
                    foreach(['list_id','list_page','list_apply','junction','filter_mode','selector_text','aggregate'] as $key){
                        if(isset($q[$key])){if(!is_string($q[$key])||strlen($q[$key])>16384)throw new \InvalidArgumentException('Inhaltslisten-Link prüfen.');$args[$key]=$q[$key];}
                    }
                    foreach(['columns','rules'] as $key)if(isset($q[$key])){
                        if(!is_array($q[$key])||count($q[$key])>20||strlen(json_encode($q[$key],JSON_THROW_ON_ERROR))>16384)throw new \InvalidArgumentException('Inhaltslisten-Link prüfen.');
                        $args[$key]=$q[$key];
                    }
                }
                return "/vereinsverwaltung/?" . http_build_query($args).$fragment;
            }
        }
        if (
            preg_match(
                '~^/(media-preview|media-removed|media-storage)/([a-f0-9]{32})/(original|thumb|medium|large)$~D',
                $url,
                $m,
            )
        ) {
            return self::endpoint($m[1], [
                "media" => $m[2],
                "variant" => $m[3],
            ]);
        }
        if (preg_match('~^/website-document-preview/([a-f0-9]{32})$~D',$url,$m)) return self::endpoint('website-document-preview',['document'=>$m[1]]);
        if (preg_match('~^/file-preview/([a-f0-9]{32})$~D',$url,$m)) return self::endpoint('file-preview',['revision'=>$m[1]]);
        if (preg_match('~^/social-video/([a-f0-9]{32})$~D', $url, $m)) return self::endpoint('social-video',['video'=>$m[1]]);
        if (preg_match('~^/social-image/([a-f0-9]{32})/thumb$~D', $url, $m)) {
            return self::endpoint('social-image',['image'=>$m[1],'variant'=>'thumb']);
        }
        if (preg_match('~^/social-image/([a-f0-9]{32})$~D', $url, $m)) {
            return self::endpoint("social-image", ["image" => $m[1]]);
        }
        if (
            preg_match(
                '~^/(chat-file|chat-image)/([a-f0-9]{32})/([1-9][0-9]*)(?:\?attachment=([1-9]|1[0-9]|20))?$~D',
                $url,
                $m,
            )
        ) {
            return self::endpoint($m[1], ["item" => $m[2], "seq" => $m[3]]+(isset($m[4])?["attachment"=>$m[4]]:[]));
        }
        if(str_starts_with($url,'/edition-document?')){parse_str(substr($url,strlen('/edition-document?')),$query);return self::endpoint('edition-document',$query);}
        if (str_starts_with($url, '/design-preview?')) {
            parse_str(substr($url,strlen('/design-preview?')),$q);
            return self::endpoint('design-preview',['template'=>$q['template']??'']);
        }
        if (str_starts_with($url, "/sections-preview?")) {
            parse_str(substr($url,strlen('/sections-preview?')),$q);
            return self::endpoint('sections-preview',['item'=>$q['item']??'']);
        }
        if (str_starts_with($url, '/social-credit?')) {
            parse_str(substr($url,strlen('/social-credit?')),$q);
            return self::endpoint('social-credit',['media'=>$q['media']??'','version'=>$q['version']??'']);
        }
        if (str_starts_with($url, '/social-qr?')) {
            parse_str(substr($url,strlen('/social-qr?')),$q);
            return self::endpoint('social-qr',['item'=>\Kulturhus\Native\Communication\Content::id($q['item']??'')]);
        }
        if (str_starts_with($url, "/home-preview?")) {
            parse_str(substr($url, strlen('/home-preview?')), $q);
            return self::endpoint('home-preview', ['item'=>$q['item'] ?? '']);
        }
        if (str_starts_with($url, "/preview?")) {
            parse_str(substr($url, 9), $q);
            return self::endpoint("preview", $q);
        }
        if(str_starts_with($url,'/edition-image?')){parse_str(substr($url,15),$q);return self::endpoint('edition-image',$q);}
        return $url;
    }
}
