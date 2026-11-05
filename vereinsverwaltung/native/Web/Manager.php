<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;
use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\Pages;
final class Manager extends Workspace
{
    private ?\Closure $facebookRequest;
    private ?\Closure $ticketRequest;
    private ?string $adminAppearanceAttributes=null;
    private ?array $interfaceMessages=null;
    private ?string $interfaceLocale=null;
    private function interfacePack():array
    {
        if($this->interfaceMessages===null){$catalog=new \Kulturhus\Native\Language\Catalog($this->db,$this->access);$this->interfaceLocale=$catalog->locale();$this->interfaceMessages=$catalog->messages($this->interfaceLocale);}
        return $this->interfaceMessages;
    }
    /** Fixed frame labels shared with the embedding adapter. */
    public function interfaceText(string $source):string
    {
        return \Kulturhus\Native\Language\ManagerMessages::text($source,$this->interfacePack());
    }
    public function contentInterfaceText(string $source):string
    {
        return \Kulturhus\Native\Language\ContentMessages::text($source,$this->interfacePack());
    }
    private function appearanceAttributes():string
    {
        return $this->adminAppearanceAttributes??=\Kulturhus\Native\Appearance\Values::attributes((new \Kulturhus\Native\Appearance\Preferences($this->db,$this->access))->state()['data']);
    }
    private const MODULES = [
        "overview" => "Website",
        "navigation" => "Menü",
        "events" => "Veranstaltungen",
        "website" => "Website",
        "news" => "Beiträge",
        "media" => "Galerie",
        "newsletter" => "Social Media & Newsletter",
        "social" => "Social Media & Newsletter",
        "press" => "Presse",
        "chat" => "Chat",
        "model" => "Felder & Vorlagen",
        "settings" => "Design & Vereinsdaten",
    ];
    public function __construct(
        PDO $db,
        Access $access,
        string $mediaRoot,
        string $csrf,
        private bool $allowDemoIdentity = false,
        private bool $websiteWorkspace = false,
        ?callable $facebookRequest=null,
        ?callable $ticketRequest=null,
    ) {
        parent::__construct($db, $access, $mediaRoot, $csrf);
        $this->facebookRequest=$facebookRequest===null?null:\Closure::fromCallable($facebookRequest);
        $this->ticketRequest=$ticketRequest===null?null:\Closure::fromCallable($ticketRequest);
    }
    private function comments():\Kulturhus\Native\Comments\ModerationView
    {
        $key=getenv('KH_NATIVE_PRIVATE_KEY_FILE')?:dirname(__DIR__,3).'/.local/native-cms-integration/private.key';
        return new \Kulturhus\Native\Comments\ModerationView($this->db,$this->access,\Kulturhus\Native\Shared\SecretBox::fromFile($key),$this->csrf);
    }
    public function action(string $view, array $post, array $files): array
    {
        $this->validatePost($post);
        $op = $post["operation"] ?? "";
        $id = $post["id"] ?? "";
        if (!is_string($op) || !is_string($id)) throw new \InvalidArgumentException("Aktion und Kennung müssen Text sein.");
        $version = (int) ($post["version"] ?? 0);
        if($view==='website'&&in_array($op,['website-comment-configure','website-comment-moderate','website-comment-edit'],true))return$this->comments()->action($post);
        if($view==='media'&&in_array($op,\Kulturhus\Native\Files\Web\Library::OPERATIONS,true))return(new \Kulturhus\Native\Files\Web\Library($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post,$files);
        if($view==='website'&&in_array($op,['content-list-save','content-list-remove','content-list-batch'],true))return(new Website\ContentListPanel($this->db,$this->access,$this->csrf))->action($post);
        if($view==='website'&&in_array($op,['tree-copy-advance','tree-copy-complete','tree-copy-cancel','tree-copy-forget'],true)){
            $jobId=$post['copy_job']??null;$expected=filter_var($post['version']??null,FILTER_VALIDATE_INT);
            if(!is_string($jobId)||$expected===false||$expected===null)throw new \InvalidArgumentException('Kopiervorgang und Fortschritt prüfen.');
            if($op==='tree-copy-complete'&&($post['copy_confirm']??null)!=='1')throw new \InvalidArgumentException('Vollständigen Kopierabschluss bestätigen.');
            $service=new \Kulturhus\Native\Cms\TreeCopyJobs($this->db,$this->access);
            if($op==='tree-copy-forget'){$service->forget($jobId,$expected,($post['copy_forget_confirm']??null)==='1');return['view'=>'website','section'=>'copy-jobs','notice'=>'Auftragsdaten entfernt.'];}
            $job=match($op){'tree-copy-advance'=>$service->advance($jobId,$expected),'tree-copy-complete'=>$service->complete($jobId,$expected,true),default=>$service->cancel($jobId,$expected)};
            return['view'=>'website','section'=>'copy-jobs','copy_job'=>$job['id'],'notice'=>'Kopiervorgang aktualisiert.'];
        }
        if(in_array($view,['website','news','events','media'],true)&&$op==='page-purge'){
            $expected=filter_var($post['version']??null,FILTER_VALIDATE_INT);
            if($expected===false||$expected===null)throw new \InvalidArgumentException('Originalversion prüfen.');
            (new \Kulturhus\Native\Cms\PagePurge($this->db,$this->access))->purge($id,$expected,($post['purge_confirm']??null)==='1',($post['purge_comments_confirm']??null)==='1');
            return['view'=>'website','section'=>'trash','notice'=>$this->contentInterfaceText('Seite endgültig entfernt.')];
        }
        if ($view === 'media' && in_array($op,['document-quota-save','media-storage-recover'],true))return (new MediaStorage($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post);
        if ($view === 'media' && in_array($op,['media-remove','media-restore','media-removal-recover','media-retention-save','media-purge','media-purge-resume'],true))return (new RemovedMedia($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post);
        if ($view === 'media' && in_array($op, ['media-upload','media-edit-copy','media-metadata-save','media-quota-save','media-policy-save','media-archive-save'], true)) return (new MediaLibrary($this->db,$this->access,$this->mediaRoot,$this->csrf))->action($post,$files);
        if($op==='page-photo-upload'&&in_array($view,['website','news','events'],true)){
            $this->access->require('website','edit');
            $expected=filter_var($post['version']??null,FILTER_VALIDATE_INT);
            if($expected===false||$expected===null||$expected<0||$expected>1000000000)throw new \InvalidArgumentException('Originale Seitenversion prüfen.');
            $check=function(bool $lock=false)use($id,$expected):void{
                $this->access->require('website','edit');
                if($id!==''){$page=(new Pages($this->db,$this->access))->get($id);$version=$lock?(int)\Kulturhus\Native\Shared\Db::run($this->db,'SELECT version FROM khcms_pages WHERE club_id=? AND id=? FOR SHARE',[$this->access->club(),$id])->fetchColumn():$page['version'];if($version!==$expected)throw new \Kulturhus\Native\Cms\Conflict('Seite inzwischen geändert. Aktuellen Entwurf prüfen.');}
                elseif($expected!==0)throw new \InvalidArgumentException('Neue Seite prüfen.');
            };
            $check();$file=$files['photo']??null;
            if(!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))throw new \InvalidArgumentException('Fotoupload fehlgeschlagen.');
            $uploadId=$post['upload_id']??null;
            if($uploadId!==null&&!is_string($uploadId))throw new \InvalidArgumentException('Upload-Kennung prüfen.');
            $copy=(new \Kulturhus\Native\Cms\Media($this->db,$this->access,$this->mediaRoot))->upload($file['tmp_name'],$file['name'],'',null,static function()use($check){$check(true);},$uploadId);
            return ['view'=>$view,'item'=>$id,'notice'=>'Foto hochgeladen.','image'=>['id'=>$copy['id'],'alt'=>$copy['alt'],'name'=>$copy['original_name'],'url'=>'/media-preview/'.$copy['id'].'/thumb']];
        }
        if ($op === 'page-sections-save' && $view === 'website') {
            $expected = filter_var($post['version'] ?? null,FILTER_VALIDATE_INT);
            if ($expected === false || $expected === null) throw new \InvalidArgumentException('Gespeicherte Seitenversion prüfen.');
            $sections = \Kulturhus\Native\Web\Website\ContentSectionsPanel::fromPost($post);
            $page = (new \Kulturhus\Native\Cms\PageSections($this->db,$this->access))->save($id,$expected,$sections);
            return ['view'=>'website','item'=>$page['id'],'notice'=>'Inhaltsabschnitte als Entwurf gespeichert. Die veröffentlichte Fassung bleibt erhalten.'];
        }
        if (in_array($op,['page-batch-create','page-batch-advance','page-batch-skip','page-batch-cancel'],true) && $view === 'website') {
            $service = new \Kulturhus\Native\Cms\PageBatches($this->db,$this->access);
            if ($op === 'page-batch-create') {
                if (($post['batch_confirm']??null) !== '1') throw new \InvalidArgumentException('Vorbereitung dieser gespeicherten Originalauswahl bestätigen.');
                $direction=$post['direction']??null;$scope=$post['batch_scope']??'selected';
                if (!is_string($direction)||!in_array($scope,['selected','all'],true)) throw new \InvalidArgumentException('Stapelaktion und Auswahl prüfen.');
                if ($scope === 'all') {
                    if (!is_string($post['batch_query']??null)) throw new \InvalidArgumentException('Stapel-Suche prüfen.');
                    $job=$service->createMatching($direction,$post['batch_query']);
                } else {
                    $selected=$post['batch_items']??[];
                    if(!is_array($selected)||!array_is_list($selected)||count($selected)>25)throw new \InvalidArgumentException('Bis zu 25 Originale dieser Trefferseite auswählen.');
                    $items=[];
                    foreach($selected as $value){if(!is_string($value)||!preg_match('/\A([a-f0-9]{32}):([1-9][0-9]{0,9})\z/D',$value,$match))throw new \InvalidArgumentException('Originalauswahl prüfen.');$items[]=['id'=>$match[1],'version'=>(int)$match[2]];}
                    $job=$service->create($items,$direction);
                }
            } else {
                $batch=$post['batch']??null;$expected=filter_var($post['version']??null,FILTER_VALIDATE_INT);
                if(!is_string($batch)||$expected===false||$expected===null)throw new \InvalidArgumentException('Stapel und Fortschrittsversion prüfen.');
                if($op==='page-batch-skip'&&($post['batch_confirm']??null)!=='1')throw new \InvalidArgumentException('Dieses Original bewusst unverändert überspringen.');
                $job=match($op){'page-batch-advance'=>$service->advance($batch,$expected),'page-batch-skip'=>$service->skip($batch,$expected),default=>$service->cancel($batch,$expected)};
            }
            return ['view'=>'website','section'=>'bulk','batch'=>$job['id'],'notice'=>'Stapelstand gespeichert. Bereits ausgeführte Originalschritte bleiben erhalten.'];
        }
        if (in_array($op, ['page-trash','page-trash-restore'], true) && in_array($view, ['website','events','news','media'], true)) {
            $expected = filter_var($post['version'] ?? null, FILTER_VALIDATE_INT);
            if ($expected === null || $expected === false) throw new \InvalidArgumentException('Gültigen gespeicherten Versionsstand angeben.');
            $trash = new \Kulturhus\Native\Cms\PageTrash($this->db, $this->access);
            if ($op === 'page-trash') {
                if (($post['trash_confirm'] ?? null) !== '1') throw new \InvalidArgumentException('Verschieben in den Papierkorb bewusst bestätigen.');
                $trash->move($id,$expected);
                return ['view'=>'website','section'=>'trash','notice'=>$this->contentInterfaceText('Seite im Papierkorb. Originale und Bilder bleiben erhalten.')];
            }
            $page = $trash->restore($id,$expected);
            return ['view'=>match($page['template_definition']['renderer']??$page['template']) {'event'=>'events','news'=>'news','gallery'=>'media',default=>'website'},'item'=>$page['id'],'notice'=>$this->contentInterfaceText('Seite privat zurückgeholt. Vor einer Veröffentlichung prüfen.')];
        }
        if ($op === 'page-place' && in_array($view, ['website', 'events', 'news', 'media'], true)) {
            $placementVersion = filter_var($post['version'] ?? null, FILTER_VALIDATE_INT);
            $position = filter_var($post['hierarchy_position'] ?? null, FILTER_VALIDATE_INT);
            $parent = $post['hierarchy_parent'] ?? null;
            if ($placementVersion === false || $placementVersion === null || $position === false || $position === null || !is_string($parent)) {
                throw new \InvalidArgumentException('Übergeordnete Seite, Versionsstand und Reihenfolge prüfen.');
            }
            $page = (new \Kulturhus\Native\Cms\PageHierarchy($this->db, $this->access))->place($id, $placementVersion, $parent === '' ? null : $parent, $position);
            return ['view' => match ($page['template']) {
                'event' => 'events', 'news' => 'news', 'gallery' => 'media', default => 'website',
            }, 'item' => $page['id'], 'notice' => 'Anordnung im gespeicherten Entwurf gesichert. Die veröffentlichte Fassung bleibt erhalten.'];
        }
        if($op==='page-fieldtypes-review'&&in_array($view,['website','events','news','media'],true)){
            $reviewVersion=filter_var($post['version']??null,FILTER_VALIDATE_INT);
            if($reviewVersion===null||$reviewVersion===false||!is_array($post['fieldtype_paths']??null))throw new \InvalidArgumentException('Gespeicherten Versionsstand und Typfelder auswählen.');
            $page=(new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->adoptFieldtypes($id,$reviewVersion,$post['fieldtype_paths'],($post['fieldtypes_confirm']??null)==='1');
            return ['view'=>'website','item'=>$page['id'],'notice'=>'Typfassungen im Entwurf übernommen.'];
        }
        if ($op === "page-copy" && in_array($view, ["website", "events", "news", "media"], true)) {
            $copyVersion = filter_var($post["version"] ?? null, FILTER_VALIDATE_INT);
            if ($copyVersion === null || $copyVersion === false) {
                throw new \InvalidArgumentException("Gültigen Versionsstand der Quelle angeben.");
            }
            $options = [
                "title" => $post["copy_title"] ?? null,
                "slug" => $post["copy_slug"] ?? null,
                "basis" => $post["basis"] ?? "draft",
            ];
            $job=$post["copy_job"]??"0";if(!in_array($job,["0","1"],true))throw new \InvalidArgumentException("Kopierweg prüfen.");
            $tree = $job==="1"?"1":($post["copy_tree"] ?? "0");
            if (!in_array($tree, ["0", "1"], true)) throw new \InvalidArgumentException("Unterseitenwahl prüfen.");
            $count = 1;
            if ($tree === "1") {
                $job=$post['copy_job']??'0';if(!in_array($job,['0','1'],true))throw new \InvalidArgumentException('Kopierweg prüfen.');
                if($job==='1'){$prepared=(new \Kulturhus\Native\Cms\TreeCopyJobs($this->db,$this->access))->create($id,$copyVersion,$options);return['view'=>'website','section'=>'copy-jobs','copy_job'=>$prepared['id'],'notice'=>'Kopiervorgang vorbereitet.'];}
                $result = (new \Kulturhus\Native\Cms\TreeCopies($this->db, $this->access))->create($id, $copyVersion, $options);
                $copy = $result["root"]; $count = count($result["pages"]);
            } else $copy = (new \Kulturhus\Native\Cms\PageCopies($this->db, $this->access))->create($id, $copyVersion, $options);
            return [
                "view" => match ($copy["template"]) {
                    "event" => "events", "news" => "news", "gallery" => "media", default => "website",
                },
                "item" => $copy["id"],
                "notice" => $tree === "1" ? strtr($this->contentInterfaceText('{count} Seiten als unveröffentlichten Teilbaum kopiert. Bitte Inhalte und Angaben prüfen.'),['{count}'=>(string)$count]) : $this->contentInterfaceText("Kopie als unveröffentlichten Entwurf angelegt. Bitte Inhalt und Angaben prüfen."),
            ];
        }
        if ($op === "identity") {
            if (!$this->allowDemoIdentity) {
                throw new \DomainException(
                    "Demorollen sind in der angemeldeten Anwendung nicht verfügbar.",
                );
            }
            if (
                !in_array(
                    $post["actor"] ?? "",
                    ["admin", "editor", "reader"],
                    true,
                )
            ) {
                throw new \DomainException("Unbekannte lokale Demorolle.");
            }
            session_regenerate_id(true);
            $_SESSION["native_actor"] = $post["actor"];
            return [
                "view" =>
                    $view === "model" && $post["actor"] !== "admin"
                        ? "website"
                        : $view,
                "notice" => "Lokale Demorolle gewechselt.",
            ];
        }
        if ($view === "settings") {
            return (new Settings\Workspace(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->action($post,$files);
        }
        if ($view === "navigation") {
            $navigationState=(new Navigation($this->db, $this->access, $this->csrf))->action(
                $post,
            );
            if($navigationState!==null)return ['view'=>'navigation','item'=>'','notice'=>'','compose_state'=>['navigation'=>$navigationState]];
            return [
                "view" => "navigation",
                "item" => "",
                "notice" => "Menü gespeichert.",
            ];
        }
        if (in_array($view, ["website", "events"], true)) {
            $pages = new Pages($this->db, $this->access);
            if($view==='events'&&in_array($op,['ticket-refund','ticket-refund-check'],true)){
                if(!is_string($post['ticket_order']??null))throw new \DomainException('Bestellung auswählen.');
                $orders=new \Kulturhus\Native\Events\Tickets\Orders($this->db,$this->access->club(),$this->box(),$this->access,$this->ticketRequest);
                if($op==='ticket-refund'){
                    if(($post['refund_confirm']??null)!=='1'||!is_string($post['refund_reason']??null))throw new \DomainException('Vollständiges Storno ausdrücklich bestätigen und begründen.');
                    $result=$orders->refundOnline($id,$post['ticket_order'],Settings\IndexingPanel::version($post),$post['refund_reason']);
                }else $result=$orders->reconcileOnlineRefund($id,$post['ticket_order']);
                return (($post['ticket_workspace']??null)==='1'?['tickets'=>'1']:[])+['view'=>'events','item'=>$id,'notice'=>match($result['status']){'refunded'=>'Vollständige Erstattung bestätigt.','cancelled'=>'Bestellung storniert.',default=>'Erstattung noch nicht bestätigt. Codes bleiben gesperrt. Ergebnis erneut prüfen; keine zweite Erstattung senden.'}];
            }
            if($view==='events'&&in_array($op,['ticket-offer-save','ticket-inspect','ticket-check-in'],true)){
                if($op==='ticket-offer-save'){
                    if(!is_string($post['ticket_capacity']??null)||!preg_match('/^[1-9][0-9]{0,5}$/D',$post['ticket_capacity'])||!is_string($post['ticket_price']??null)||(array_key_exists('ticket_active',$post)&&$post['ticket_active']!=='1'))throw new \DomainException('Ticketangebot prüfen.');
                    $price=\Kulturhus\Native\Payments\Money::cents(str_replace(',','.',trim($post['ticket_price'])));
                    (new \Kulturhus\Native\Events\Tickets\Offers($this->db,$this->access))->save($id,['capacity'=>(int)$post['ticket_capacity'],'price_cents'=>$price,'accounting_sphere'=>$post['ticket_sphere']??'zweck','active'=>isset($post['ticket_active'])],Settings\IndexingPanel::version($post));
                    return (($post['ticket_workspace']??null)==='1'?['tickets'=>'1']:[])+['view'=>'events','item'=>$id,'notice'=>'Ticketangebot gespeichert.'];
                }
                if(!is_string($post['ticket_code']??null))throw new \DomainException('Ticketcode prüfen.');
                $qr=\Kulturhus\Native\Events\Tickets\Qr::parse($post['ticket_code'],$id);if($qr['page_id']!==$id)throw new \DomainException('Ticket gehört zu einer anderen Veranstaltung.');
                $orders=new \Kulturhus\Native\Events\Tickets\Orders($this->db,$this->access->club(),$this->box(),$this->access);
                $pass=$op==='ticket-inspect'?$orders->pass($id,$qr['code']):$orders->checkIn($id,$qr['code'],Settings\IndexingPanel::version($post));
                return (($post['ticket_workspace']??null)==='1'?['tickets'=>'1']:[])+['view'=>'events','item'=>$id,'notice'=>$pass['status']==='checked_in'?'Bereits eingelassen.':'Ticket geprüft.'];
            }
            if($view==='events'&&in_array($op,['facebook-events-sync','facebook-event-link'],true)){
                $facebook=new \Kulturhus\Native\Events\Facebook($this->db,$this->access,$this->box(),$this->facebookRequest);
                if($op==='facebook-event-link'){
                    if(($post['link_confirm']??null)!=='1'||!is_string($post['facebook_event_id']??null))throw new \DomainException('Übernahme der Facebook-Angaben bestätigen.');
                    $version=Settings\IndexingPanel::version($post);
                    $facebook->link($post['facebook_event_id'],$id,$version);
                    return ['view'=>'events','item'=>$id,'notice'=>'Facebook-Veranstaltung zugeordnet.'];
                }
                $result=$facebook->sync();$conflicts=count(array_filter($result,static fn($row)=>str_contains($row['status'],'conflict')));
                return ['view'=>'events','item'=>'','notice'=>count($result).' Facebook-Termine abgeglichen; '.$conflicts.' Konflikte.'];
            }
            if ($op === "eventbrite-sync" && $view === "events") {
                $workflow=(new \Kulturhus\Native\Events\Workflow($this->db,$this->access))->requireSource('eventbrite');
                $syncClub=$this->access->require('website','edit')['club_id'];
                if(isset($post['eventbrite_images'])&&$post['eventbrite_images']!=='1')throw new \InvalidArgumentException('Anbieterbildauswahl prüfen.');
                $account = (new \Kulturhus\Native\Integration\Accounts(
                    $this->db,
                    $this->box(),
                ))->get($syncClub, "eventbrite");
                $service = new \Kulturhus\Native\Events\Eventbrite(
                    $this->db,
                    $this->access,
                );
                $events = $service->fetch(
                    $account["organization"],
                    static function ($path) use ($account) {
                        $r = \Kulturhus\Native\Integration\JsonHttp::request(
                            "eventbrite",
                            "GET",
                            $path,
                            ["Authorization: Bearer " . $account["token"]],
                        );
                        if ($r["status"] !== 200) {
                            throw new \DomainException(
                                "Eventbrite-Abfrage fehlgeschlagen.",
                            );
                        }
                        return $r["body"];
                    },
                );
                $currentAccount=(new \Kulturhus\Native\Integration\Accounts($this->db,$this->box()))->get($syncClub,'eventbrite');
                if($currentAccount['organization']!==$account['organization']||!hash_equals(\Kulturhus\Native\Integration\Accounts::binding($account),\Kulturhus\Native\Integration\Accounts::binding($currentAccount)))throw new \DomainException('Eventbrite-Verbindung während der Abfrage geändert. Neu abgleichen.');
                $result = $service->import($events,$syncClub,$workflow['version']);
                $photoNotice='';
                if(($post['eventbrite_images']??'')==='1'){
                    $photos=(new \Kulturhus\Native\Events\EventbriteImages($this->db,$this->access,$this->mediaRoot))->sync($result);
                    $photoNotice=' '.$photos['attached'].' Anbieterbilder übernommen.'.($photos['more']?' Weitere Bilder beim nächsten Abgleich übernehmen.':'').($photos['errors']?' '.implode(' · ',$photos['errors']):'');
                }
                $conflicts = count(
                    array_filter(
                        $result,
                        static fn($r) => $r["status"] === "conflict",
                    ),
                );
                return [
                    "view" => "events",
                    "item" => "",
                    "notice" =>
                        count($result) .
                        " Termine abgeglichen; " .
                        $conflicts .
                        " Konflikte.".($workflow['automatic_publication']?' Termine gemäß Veröffentlichungswahl übernommen.':' Neue Fassungen bleiben Entwürfe.').$photoNotice,
                ];
            }
            if ($op === "page-save") {
                $data = $post;
                unset($data["operation"], $data["csrf"], $data["version"]);
                if(array_key_exists('event_status',$post))$data['event_status_mode']=$post['event_status']==='automatic'?'automatic':'manual';
                unset($data['page_policy'],$data['page_policy_present'],$data['page_policy_inherit'],$data['page_policy_public'],$data['page_policy_read'],$data['page_policy_edit'],$data['page_policy_publish']);
                $pagePolicy=Website\PagePolicyPanel::submitted($post,$this->access);
                if ($pagePolicy!==null) $data['page_policy']=$pagePolicy;
                $documentLinks=Website\DocumentLinks::fromPost($post);
                unset($data['document_links'],$data['document_links_present'],$data['document_rows']);
                if ($documentLinks!==null) $data['document_links']=$documentLinks;
                $sections=null;
                if(array_key_exists('sections_present',$post)){
                    if($post['sections_present']!=='1')throw new \InvalidArgumentException('Inhaltsabschnitte prüfen.');
                    $sections=Website\ContentSectionsPanel::fromPost($post);
                }
                unset($data['content_sections'],$data['content_sections_text'],$data['section_rows'],$data['section_row_count'],$data['sections_present']);
                if($sections!==null)$data['content_sections']=$sections;
                $homeSubmitted = array_key_exists('home_layout_present', $post);
                unset(
                    $data['home_layout'], $data['home_layout_present'],
                    $data['home_layout_enabled'], $data['home_sections'],
                    $data['home_highlights'],
                );
                if ($homeSubmitted) {
                    $home = \Kulturhus\Native\Web\Website\HomeForm::fromPost($post);
                    if ($home !== null) {
                        $data['home_layout'] = $home;
                    }
                }
                $channels = \Kulturhus\Native\Web\Website\NewsChannelFields::fromPost($post);
                unset($data['news_channels_present']);
                if ($channels !== null) $data['news_channels'] = $channels;
                if (
                    $view === "events" &&
                    (new \Kulturhus\Native\Cms\DataModel(
                        $this->db,
                        $this->access,
                    ))->template((string) ($post["template"] ?? ""))[
                        "renderer"
                    ] !== "event"
                ) {
                    throw new \DomainException(
                        "Eine Veranstaltung benötigt eine Veranstaltungsvorlage.",
                    );
                }
                if (array_key_exists("body_html", $post)) {
                    $data["body"] = "";
                }
                $data["noindex"] = isset($post["noindex"]);
                $data["nav_visible"] = isset($post["nav_visible"]);
                $data["media_ids"] = array_values(
                    (array) ($post["media_ids"] ?? []),
                );
                if ($id) {
                    $previous = $pages->get($id);
                    if ($pagePolicy===null && array_key_exists('page_policy',$previous)) $data['page_policy']=$previous['page_policy'];
                    if ($documentLinks===null && array_key_exists('document_links',$previous)) $data['document_links']=$previous['document_links'];
                    if ($sections===null && array_key_exists('content_sections',$previous)) $data['content_sections']=$previous['content_sections'];
                    if (!$homeSubmitted && isset($previous['home_layout'])) {
                        $data['home_layout'] = $previous['home_layout'];
                    }
                    if ($channels === null && \Kulturhus\Native\Cms\NewsChannels::isNews($previous)) {
                        $data['news_channels'] = $previous['news_channels'] ?? \Kulturhus\Native\Cms\NewsChannels::ALL;
                    }
                    $data["gallery_items"] = $previous["gallery_items"] ?? [];
                    // Existing UTC anchors distinguish both occurrences of an autumn clock change.
                    if (
                        ($data["event_start"] ?? "") ===
                            ($previous["event_start"] ?? "") &&
                        ($data["event_timezone"] ?? "Europe/Berlin") ===
                            ($previous["event_timezone"] ?? "Europe/Berlin")
                    ) {
                        $data["event_start_utc"] =
                            $previous["event_start_utc"] ?? "";
                    }
                    if (
                        array_key_exists("event_end_local", $data) &&
                        ($previous["event_end"] ?? "") !== "" &&
                        ($data["event_timezone"] ?? "Europe/Berlin") ===
                            ($previous["event_timezone"] ?? "Europe/Berlin")
                    ) {
                        $oldEnd = (new \DateTimeImmutable(
                            $previous["event_end"],
                        ))
                            ->setTimezone(
                                new \DateTimeZone(
                                    $previous["event_timezone"] ??
                                        "Europe/Berlin",
                                ),
                            )
                            ->format("Y-m-d\TH:i");
                        if ($data["event_end_local"] === $oldEnd) {
                            $data["event_end"] = $previous["event_end"];
                            unset($data["event_end_local"]);
                        }
                    }
                }
                $data["custom_fields"] = (new ContentModel(
                    $this->db,
                    $this->access,
                    $this->csrf,
                ))->values(
                    (string) ($post["template"] ?? "standard"),
                    (array) ($post["custom_fields"] ?? []),
                    $previous['field_definitions']??null,
                );
                $data["nav_order"] = (int) ($post["nav_order"] ?? 0);
                $uploads=Website\DocumentLinks::uploads($files);
                if ($uploads && count($data['document_links']??[])+count($uploads)>20) throw new \InvalidArgumentException('Höchstens 20 Dokumente je Seite auswählen.');
                $photos=Website\PagePhotoUploads::fromFiles($files);
                if(count($data['media_ids'])+count($photos)>100)throw new \InvalidArgumentException('Maximal 100 Bilder je Seite auswählen.');
                $save=function(array $data,?\Kulturhus\Native\Cms\UploadTransaction $tx=null)use($uploads,$pages,$version){
                return $uploads ? (new \Kulturhus\Native\Cms\WebsiteDocuments($this->db,$this->access,dirname($this->mediaRoot).'/website-documents'))->uploadMany($uploads,function($copies)use($pages,$data,$version){
                    $data['document_links']=[...($data['document_links']??[]),...array_map(static fn($copy)=>['id'=>$copy['id'],'label'=>$copy['name']],$copies)];
                    return $pages->save($data,$version);
                },$tx) : $pages->save($data,$version);
                };
                $result=$photos?\Kulturhus\Native\Cms\UploadTransaction::run($this->db,fn($tx)=>(new \Kulturhus\Native\Cms\MediaUploads($this->db,$this->access,$this->mediaRoot))->uploadMany($photos,function($copies)use($data,$save,$tx){$data['media_ids']=[...$data['media_ids'],...array_column($copies,'id')];return $save($data,$tx);},$tx)):$save($data);
                $id = $result["id"];
            } elseif ($op === "page-publish") {
                $pages->publish($id, $version);
            } elseif ($op === "page-review") {
                $reviewStatus = $post["review_status"] ?? null;
                $reviewVersion = filter_var($post["version"] ?? null, FILTER_VALIDATE_INT);
                if (!is_string($reviewStatus) || $reviewVersion === false || $reviewVersion === null) throw new \InvalidArgumentException("Gültigen Redaktions- und Versionsstand angeben.");
                $pages->review($id, $reviewStatus, $reviewVersion);
            } elseif ($op === "page-unpublish") {
                $pages->unpublish($id, $version);
            } elseif ($op === "page-restore") {
                $pages->restore($id, (int) $post["revision"], $version);
            } elseif ($op === "page-path-add") {
                (new \Kulturhus\Native\Cms\PathHistory(
                    $this->db,
                    $this->access,
                ))->remember($id, (string) ($post["slug"] ?? ""), $version);
            } elseif ($op === "page-path-remove") {
                (new \Kulturhus\Native\Cms\PathHistory(
                    $this->db,
                    $this->access,
                ))->forget(
                    $id,
                    (string) ($post["history_id"] ?? ""),
                    $version,
                    ($post["confirmed"] ?? "") === "1",
                );
            } else {
                throw new \DomainException("Unbekannte Seitenaktion.");
            }
        } elseif ($view === "news") {
            $newsFilters=\Kulturhus\Native\Cms\ArticleCatalogue::filters($_GET);
            $id = (new Articles(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->action($post, $files);
            return ["view"=>"news","item"=>$id,...$newsFilters,"notice"=>"Änderung gespeichert."];
        } elseif ($view === "media") {
            $id = (new Gallery(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->action($post, $files);
            if (is_array($id)) {if(isset($id['notice']))$id['notice']=$this->contentInterfaceText($id['notice']);return $id;}
            return ['view'=>'media','item'=>$id,'notice'=>$this->contentInterfaceText('Änderung gespeichert.')];
        } elseif (in_array($view, ["newsletter", "social", "press"], true)) {
            return (new Communication\Workspace(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->action($view, $post, $files);
        } elseif ($view === "model") {
            (new ContentModel($this->db, $this->access, $this->csrf))->action(
                $post,
            );
            return ['view'=>'model','item'=>'','notice'=>'Änderung gespeichert.',...\Kulturhus\Native\Cms\ModelCatalogue::state(array_replace($post,['model_kind'=>$op==='model-field'?'field':'template','model_key'=>is_string($post['key']??null)?$post['key']:'','model_new'=>'0']))];
        } elseif ($view === "chat") {
            $chat = new ChatView(
                $this->db,
                $this->access,
                dirname($this->mediaRoot) . "/chat-files",
                $this->csrf,
            );
            if(isset($post['chat_browse'])){$state=$chat->browse($post);return ['view'=>'chat','item'=>$state['id'],'notice'=>'','compose_state'=>['chat'=>$state],...$state['context']];}
            $context=\Kulturhus\Native\Chat\Catalogue::state($post);if(isset($post['chat_before']))ChatFormState::number($post['chat_before']);$id=$chat->action($post,$files);if($op==='chat-send')$context['chat_rooms']='0';
            return ['view'=>'chat','item'=>$id,'notice'=>$op==='chat-send'?'Nachricht gesendet.':'Änderung gespeichert.',...$context,...($op!=='chat-send'&&isset($post['chat_before'])?['chat_before'=>ChatFormState::number($post['chat_before'])]:[])];
        } else {
            throw new \DomainException("Unbekannter Bereich.");
        }
        return [
            "view" => $view,
            "item" => $id,
            ...($view==='media'&&is_string($_GET['album_page']??null)&&preg_match('/\A[1-9][0-9]{0,8}\z/D',$_GET['album_page'])?['album_page'=>$_GET['album_page']]:[]),
            "notice" => $op === "album-restore" ? "Albumfassung als Entwurf geladen. Prüfe die Angaben vor der Veröffentlichung." : "Änderung gespeichert.",
        ];
    }
    public function shell(string $main,string $notice=''): string
    {
        $user = $this->access->user();
        $t=fn(string $source):string=>$this->interfaceText($source);
        $this->interfacePack();
        $interface=Html::e(json_encode(\Kulturhus\Native\Language\ManagerMessages::browser($this->interfaceMessages),JSON_THROW_ON_ERROR));
        $identity =
            $this->start("identity") .
            Html::select(
                "actor",
                $t("Synthetische Demorolle"),
                [
                    "admin" => $t("Administration"),
                    "editor" => $t("Redaktion"),
                    "reader" => $t("Leserechte"),
                ],
                $user["id"],
            ) .
            '<button class="btn btn-outline theme-secondary secondary">'.Html::e($t('Wechseln')).'</button></form>';
        if (!$this->allowDemoIdentity) {
            $identity = '<a href="/account">'.Html::e($t('Mein Konto & Sicherheit')).'</a>';
        }
        return '<!doctype html><html lang="'.Html::e($this->interfaceLocale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="manager-interface" content="'.$interface.'"><script src="/assets/manager-interface.js"></script><title>'.Html::e($t('DVM · Redaktion')).'</title>' . BootstrapAssets::tags() . '<link rel="stylesheet" href="/assets/file-library.css"><link rel="stylesheet" href="/assets/native.css"><link rel="stylesheet" href="/vereinsverwaltung/native-website-workspace.css"><link rel="stylesheet" href="/assets/meta-webhooks.css"><link rel="stylesheet" href="/assets/website-designs.css"><link rel="stylesheet" href="/assets/editor.css"><link rel="stylesheet" href="/assets/field-groups.css"><link rel="stylesheet" href="/assets/typed-fields.css"><link rel="stylesheet" href="/assets/template-settings.css"><link rel="stylesheet" href="/assets/page-versions.css"><link rel="stylesheet" href="/assets/content-lists.css"><link rel="stylesheet" href="/assets/content-sections.css"><link rel="stylesheet" href="/assets/richtext.css"><link rel="stylesheet" href="/assets/gallery.css"><link rel="stylesheet" href="/assets/media-library.css"><link rel="stylesheet" href="/assets/articles.css"><link rel="stylesheet" href="/assets/chat.css?v='.substr(hash_file('sha256',__DIR__.'/assets/chat.css'),0,16).'"><link rel="stylesheet" href="/assets/communication-contacts.css"><link rel="stylesheet" href="/assets/publications.css">' .
            '<link rel="stylesheet" href="/assets/photo-import.css"><script src="/assets/photo-import.js" defer></script><link rel="stylesheet" href="/assets/photo-editor.css"><script src="/assets/photo-editor.js" defer></script><script src="/assets/photo-copy.js" defer></script><script src="/assets/photo-library.js" defer></script>' .
            '<link rel="stylesheet" href="/assets/press-recipients.css"><script src="/assets/press-recipients.js" defer></script><link rel="stylesheet" href="/assets/compose-touch.css"><link rel="stylesheet" href="/assets/social-library.css"><link rel="stylesheet" href="/assets/social-calendar.css"><link rel="stylesheet" href="/assets/editorial-hub.css">' .
            ImageStudioAssets::tags("/assets/") . '<script src="/assets/video-recovery.js" defer></script><script src="/assets/video-reuse.js" defer></script><script src="/assets/video-file.js" defer></script><script src="/assets/video-archive.js" defer></script><script src="/assets/video-policy.js" defer></script><script src="/assets/video-metadata.js" defer></script><script src="/assets/video-upload.js" defer></script><script src="/assets/video-studio.js" defer></script>' .
            '<script src="/assets/editor.js" defer></script><script src="/assets/richtext.js" defer></script><link rel="stylesheet" href="/assets/page-live.css"><script src="/assets/page-live.js" defer></script><script src="/assets/reference-picker.js" defer></script><link rel="stylesheet" href="/assets/page-media-picker.css"><script src="/assets/page-media-picker.js" defer></script><script src="/assets/field-conditions.js" defer></script><script src="/assets/field-groups.js" defer></script><script src="/assets/typed-fields.js" defer></script><script src="/assets/publications.js" defer></script><script src="/assets/newsletter-save.js" defer></script><script src="/assets/native.js" defer></script><script src="/assets/gallery.js" defer></script><script src="/assets/chat.js" defer></script>' . \Kulturhus\Native\Appearance\Chrome::assets() . '<link rel="stylesheet" href="/assets/form-validation.css"><script src="/assets/form-validation.js" defer></script></head><body' . $this->appearanceAttributes() . '><a class="skip" href="#content">'.Html::e($t('Zum Inhalt')).'</a><div class="demo-banner"><span>'.Html::e($t('Redaktion')).'</span><a href="/site/start" target="_blank" rel="noopener">'.Html::e($t('Website ansehen ↗')).'</a></div><header class="app-header"><a class="brand" href="/?view=website" data-nav>DVM<span>'.Html::e($t('DeinVereinsManager · Redaktion')).'</span></a>' .
            $identity .
            '</header><div id="notice" class="notice" role="status" aria-live="polite">'.Html::e($notice).'</div>' .
            $main .
            '<dialog id="discard" aria-labelledby="discard-heading"><h2 id="discard-heading">'.Html::e($t('Änderungen verwerfen?')).'</h2><p>'.Html::e($t('Deine Eingaben wurden noch nicht gespeichert.')).'</p><div class="actions"><button class="btn btn-outline theme-secondary secondary" data-choice="stay">'.Html::e($t('Weiter bearbeiten')).'</button><button class="btn btn-solid theme-primary" data-choice="discard">'.Html::e($t('Verwerfen')).'</button></div></dialog>' .
            Gallery::lightbox($this->contentInterfaceText(...)) .
            "</body></html>";
    }
    public function main(
        string $view,
        string $id = "",
        int $before = PHP_INT_MAX,
        ?array $composeState = null,
    ): string {
        if (!isset(self::MODULES[$view])) {
            throw new \DomainException("Unbekannter Bereich.");
        }
        $this->access->require(
            in_array(
                $view,
                [
                    "media",
                    "model",
                    "news",
                    "overview",
                    "navigation",
                    "events",
                    "settings",
                ],
                true,
            )
                ? "website"
                : $view,
            in_array($view, ["model", "settings"], true) ? "manage" : "read",
        );
        $e = Html::e(...);
        $t=fn(string $source):string=>$this->interfaceText($source);
        $this->interfacePack();
        $html =
            '<main id="workspace" class="workspace" data-view="' .
            $e($view) .
            '" data-interface-locale="'.$e($this->interfaceLocale).'"'.$this->appearanceAttributes().'><nav class="sidebar" aria-label="' . $e($t('Redaktion')) . '"><small>' . $e($t('Arbeitsbereiche')) . '</small>';
        $editorialListed=false;
        foreach (self::MODULES as $key => $label) {
            if(in_array($key,['social','newsletter'],true)){if($editorialListed)continue;$socialAllowed=$this->can('social','read');$newsletterAllowed=$this->can('newsletter','read');if(!$socialAllowed&&!$newsletterAllowed)continue;$key=$socialAllowed?'social':'newsletter';$label='Social & Newsletter';$editorialListed=true;}
            if (
                $this->can(
                    in_array(
                        $key,
                        [
                            "media",
                            "model",
                            "news",
                            "overview",
                            "navigation",
                            "events",
                            "settings",
                        ],
                        true,
                    )
                        ? "website"
                        : $key,
                    in_array($key, ["model", "settings"], true)
                        ? "manage"
                        : "read",
                )
            ) {
                $html .=
                    '<a data-nav href="/?view=' .
                    $key .
                    (in_array($key,['social','newsletter'],true)?'&amp;section=planning':'') .
                    '"' .
                    (($view === $key || (in_array($view,['social','newsletter'],true)&&in_array($key,['social','newsletter'],true))) ? ' aria-current="page"' : "") .
                    ">" .
                    $e($t($label)) .
                    "</a>";
            }
        }
        $html.='</nav><section class="content" id="content" data-view="'.Html::e($view).'">'.(in_array($view,['social','newsletter'],true)?'':'<p class="eyebrow">'.$e($t('Verein gemeinsam gestalten')).'</p>').'<h1 tabindex="-1">'.$e($t(self::MODULES[$view])).'</h1>';
        if ($view === 'website' && $id === '' && ($_GET['section'] ?? '') === '') {
            $html .= '<p><a class="button secondary" href="/?view=website&amp;section=lister">'.$e($t('Inhalte suchen und filtern')).'</a></p>';
        }
        $trashPanel = new Website\PageTrashPanel($this->db,$this->access,$this->csrf);
        if($view==='website'&&$this->can('website','publish'))$html.='<p><a data-nav class="btn btn-outline theme-secondary" href="/?view=website&amp;section=comments">'.$e($t('Website-Kommentare')).'</a></p>';
        if($view==='website'&&isset($composeState['comment_correction']))return$html.$this->comments()->correction($composeState['comment_correction']['post'],$composeState['comment_correction']['error']).'</section></main>';
        if($view==='website'&&($_GET['section']??'')==='comments')return$html.$this->comments()->render($_GET).'</section></main>';
        if($view==='media')$html.='<p><a data-nav class="btn btn-outline theme-secondary" href="/?view=media&amp;section=files">'.$e($t('Dateien und Bildfassungen')).'</a></p>';
        if($view==='media'&&($_GET['section']??'')==='files')return$html.(new \Kulturhus\Native\Files\Web\Library($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager(array_replace($_GET,['item'=>$id])).'</section></main>';
        if ($view === 'media' && ($_GET['section']??'') === 'storage') return $html.(new MediaStorage($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($_GET).'</section></main>';
        if ($view === 'media' && ($_GET['section']??'') === 'removed') return $html.(new RemovedMedia($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($id,$_GET).'</section></main>';
        if ($view === 'media' && ($_GET['section']??'') === 'library') return $html.(new MediaLibrary($this->db,$this->access,$this->mediaRoot,$this->csrf))->manager($id,$_GET).'</section></main>';
        if ($view === 'website' && $id === '' && ($_GET['section']??'') === 'bulk') return $html.(new Website\PageBatchPanel($this->db,$this->access,$this->csrf))->render($_GET).'</section></main>';
        if ($view === 'website' && $id === '' && ($_GET['section']??'') === 'lister') return $html.(new Website\ContentListPanel($this->db,$this->access,$this->csrf))->render($_GET).'</section></main>';
        if ($view === 'website' && $id === '' && ($_GET['section']??'') === 'copy-jobs') return $html.(new Website\TreeCopyJobPanel($this->db,$this->access,$this->csrf))->render($_GET).'</section></main>';
        if ($view === 'website' && $id === '' && ($_GET['section']??'') === 'trash') return $html.$trashPanel->listing($_GET).'</section></main>';
        $page = null;
        if ($id !== '' && $id !== 'new' && in_array($view,['website','news','events','media'],true)) {
            $page = (new Pages($this->db,$this->access))->get($id);
            if ($page['trashed']) return $html.$trashPanel->render($page).'</section></main>';
        }
        $html .= match ($view) {
            "settings" => (new Settings\Workspace(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->manager($composeState['webhook']??[]),
            "overview" => (new WebsiteWorkspace(
                $this->db,
                $this->access,
                $this->csrf,
            ))->overview(),
            "navigation" => (new Navigation(
                $this->db,
                $this->access,
                $this->csrf,
            ))->manager($composeState['navigation']??null),
            "events" => $id === ""
                ? (new WebsiteWorkspace(
                    $this->db,
                    $this->access,
                    $this->csrf,
                ))->events($_GET)
                : (new Website\PageEditor(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                    $this->websiteWorkspace,
                ))->manager($id, "events"),
            "website" => $this->websiteWorkspace && $id === ""
                ? (new WebsiteWorkspace(
                    $this->db,
                    $this->access,
                    $this->csrf,
                ))->pages($_GET)
                : (new Website\PageEditor(
                    $this->db,
                    $this->access,
                    $this->mediaRoot,
                    $this->csrf,
                    $this->websiteWorkspace,
                ))->manager($id),
            "news" => (new Articles(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->manager($id),
            "media" => (new Gallery(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->manager($id),
            "model" => (new ContentModel(
                $this->db,
                $this->access,
                $this->csrf,
            ))->manager(),
            "chat" => (new ChatView(
                $this->db,
                $this->access,
                dirname($this->mediaRoot) . "/chat-files",
                $this->csrf,
            ))->manager($id, $before,$_GET,$composeState['chat']??null),
            default => (new Communication\Workspace(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->manager($view, $id,$composeState),
        };
        if ($page !== null) $html .= $trashPanel->render($page);
        elseif (in_array($view,['overview','website','news','events','media'],true)) $html .= '<p><a data-nav href="/?view=website&amp;section=lister">'.Html::e($this->contentInterfaceText('Inhaltsliste')).'</a> · <a data-nav href="/?view=website&amp;section=copy-jobs">'.Html::e($this->contentInterfaceText('Kopiervorgänge')).'</a> · <a data-nav href="/?view=website&amp;section=trash">'.Html::e($this->contentInterfaceText('Papierkorb öffnen')).'</a> · <a data-nav href="/?view=website&amp;section=bulk">'.Html::e($this->contentInterfaceText('Stapelweise aufräumen')).'</a></p>';
        return $html . "</section></main>";
    }
}
