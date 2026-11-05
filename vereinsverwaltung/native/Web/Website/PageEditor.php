<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use PDO;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Web\{
    Workspace as BaseWorkspace,
    Html,
    ContentModel,
    Editor,
    RichTextEditor,
    ReferencePicker,
};
final class PageEditor extends BaseWorkspace
{
    public function __construct(
        PDO $db,
        Access $access,
        string $mediaRoot,
        string $csrf,
        private bool $workspace = false,
    ) {
        parent::__construct($db, $access, $mediaRoot, $csrf);
    }
    public function manager(string $id, string $section = "website"): string
    {
        $id = $id === "new" ? "" : $id;
        $pages = new Pages($this->db, $this->access);
        $e = Html::e(...);
        $write = $this->can("website", "edit");
        $publish = $this->can("website", "publish");
        $saveFailed=http_response_code()>=400&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['operation']??'')==='page-save'&&($_POST['id']??null)===$id&&is_string($_POST['csrf']??null)&&hash_equals($this->csrf,$_POST['csrf']);
        $h = $this->workspace
            ? ""
            : '<p class="intro">Seiten gestalten, Versionen vergleichen und bewusst veröffentlichen. Entwürfe bleiben in der Redaktion.</p><div class="columns">' .
                (new PageSidebar($this->db, $this->access))->render($id) .
                "<div>";
        if ($this->workspace) {
            $h =
                '<a class="back-link" data-nav href="/?view=' .
                $section .
                '">← Zur Liste</a><div class="native-page-editor"><div>';
        }
        $p = $id
            ? $pages->get($id)
            : [
                "id" => "",
                "version" => 0,
                "title" => "",
                "slug" => "",
                "template" => $section === "events" ? "event" : "standard",
                "body" => "",
                "excerpt" => "",
                "seo_title" => "",
                "seo_description" => "",
                "noindex" => false,
                "nav_visible" => $section !== "events",
                "nav_label" => "",
                "nav_order" => 0,
                "parent_id" => "",
                "media_ids" => [],
                "event_start" => "",
                "event_timezone" => "Europe/Berlin",
                "event_location" => "",
            ];
        if($section==='events'&&$id!==''&&($_GET['tickets']??null)==='1'){
            if(!\Kulturhus\Native\Cms\EventDetails::isEvent($p))throw new \DomainException('Veranstaltung für die Ticketverwaltung auswählen.');
            return '<a class="back-link" data-nav href="/?view=events&amp;tickets=1">← Veranstaltung wählen</a><section class="native-page-editor ticket-workspace"><h2>Tickets verwalten · '.Html::e($p['title']).'</h2><p><a data-nav href="/?view=events&amp;item='.Html::e($id).'">Veranstaltung bearbeiten</a></p>'.(new TicketPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($p,true).'</section>';
        }
        if($section==='events'&&$id===''){
            $workflow=(new \Kulturhus\Native\Events\Workflow($this->db,$this->access))->get();
            if(in_array($workflow['source'],['facebook','eventbrite'],true)){
                $h.='<section class="panel" data-event-creation-guidance><h2>Veranstaltung über Eventbrite oder Facebook anlegen</h2><p>Lege die Veranstaltung beim verbundenen Anbieter an. Der automatische Terminabgleich übernimmt sie auf die Website; der Social Hub bereitet die zugehörigen Beiträge, Stories und Reels als Entwürfe vor.</p><p>Aktive Übernahmequelle: '.Html::e(\Kulturhus\Native\Events\Workflow::SOURCES[$workflow['source']]).'. Bei einer Anlage über Eventbrite muss der Termin für den Facebook-Abgleich auch auf der verbundenen Facebook-Seite vorhanden sein.</p><div class="actions"><a class="btn btn-solid theme-primary" target="_blank" rel="noopener noreferrer" href="https://www.eventbrite.de/manage/events/create">In Eventbrite anlegen ↗</a><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener noreferrer" href="https://www.facebook.com/events/create/">In Facebook anlegen ↗</a><a class="btn btn-outline theme-secondary" data-nav href="/?view=events#event-provider-sync">Terminabgleich öffnen</a></div></section>';
            }
        }
        if ($id) {
            foreach (['edit'=>'write','publish'=>'publish'] as $capability=>$flag) {
                if (!$$flag) continue;
                try { \Kulturhus\Native\Cms\TemplatePolicy::assertCapability($this->db,$this->access,$id,$capability); }
                catch (\DomainException) { $$flag=false; }
            }
        }
        if ($id && $write && !$p['archived'] && !$p['trashed']) {
            $h .= '<p><a data-navigation-native class="btn btn-outline theme-secondary" href="'.$e(\Kulturhus\Native\Integration\Routes::endpoint('frontend-edit',['item'=>$id,'field'=>'body_html'])).'">Auf Website bearbeiten</a></p>';
        }
        if ($write) {
            $p=DocumentLinks::rejectedPage($p,$_POST,$this->csrf);
            $p=(new PageMediaPicker($this->db,$this->access))->rejectedSelection($p,$_POST,$this->csrf);
        }
        if ($id && $write && !$p['archived'] && !$p['trashed'] && ($p['template_definition']['renderer']??$p['template'])==='standard') $h.='<a class="btn btn-outline theme-secondary page-sections-jump" href="#page-content-sections">Inhaltsabschnitte</a>';
        if ($id && \Kulturhus\Native\Cms\NewsChannels::isNews($p)) $h.='<a class="btn btn-outline theme-secondary news-review-jump" href="#news-review-panel">Prüfung &amp; Freigabe</a>';
        if ($write) {
            $live=($p["slug"]!=="start" && in_array($p["template_definition"]["renderer"]??$p["template"],["standard","news","gallery"],true));
            if($live)$h.='<div class="page-live-layout">';
            $h .=
                '<div class="panel"><h2>' .
                ($section === "events"
                    ? ($id
                        ? "Veranstaltung bearbeiten"
                        : "Neue Veranstaltung")
                    : ($id
                        ? "Seite bearbeiten"
                        : "Neue Seite")) .
                "</h2>" .
                str_replace('<form method="post">','<form method="post" enctype="multipart/form-data"'.($id?'':' data-address-suggestion').'>',$this->start("page-save")) .
                Html::hidden("id", $p["id"]) .
                Html::hidden("version", $p["version"]) . ($id?'':Html::hidden('auto_address','1'));
            $model = new ContentModel($this->db, $this->access, $this->csrf);
            $choices = $model->templateChoices();
            $renderer = (new \Kulturhus\Native\Cms\DataModel(
                $this->db,
                $this->access,
            ))->template($p["template"])["renderer"];
            if ($this->workspace) {
                $definitions = new \Kulturhus\Native\Cms\DataModel(
                    $this->db,
                    $this->access,
                );
                foreach ($choices as $key => $label) {
                    if (
                        $definitions->template($key)["renderer"] !==
                        ($section === "events" ? "event" : $renderer)
                    ) {
                        unset($choices[$key]);
                    }
                }
            }
            $h .=
                '<div class="fields">' .
                Html::input(
                    "title",
                    "Titel",
                    $p["title"],
                    "text",
                    'required maxlength="180"',
                ) .
                str_replace(
                    '<select class="form-control" ',
                    '<select class="form-control" data-model-template ',
                    Html::select(
                        "template",
                        "Seitenart",
                        $choices,
                        $p["template"],
                    ),
                ) .
                '</div><details class="page-address-settings"><summary>Adresse und Menütitel</summary><p>Bei neuen Seiten wird die Adresse aus dem Titel vorgeschlagen. Bereits gespeicherte Adressen ändern sich nur, wenn du sie hier bearbeitest.</p><div class="fields">'.Html::input('slug','Adresse auf der Website',$p['slug'],'text',($id?'required ':'').'maxlength="180" data-address-target').Html::input('nav_label','Menütitel',$p['nav_label']).'</div></details><div data-model-fields>' .
                $model->fields($p["template"], $p["custom_fields"] ?? [], $id ? (new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->editorDefinitions($id,$p["template"]) : null) .
                "</div>";
            $h .=
                Html::input("excerpt", "Einleitung", $p["excerpt"]) .
                RichTextEditor::render(
                    "body_html",
                    Html::content($p),
                    "Inhalt",
                );
            $h .= PagePolicyPanel::render($p,$this->access);
            if ($renderer==='standard') $h .= DocumentLinks::fields($p);
            if ($p['slug'] === 'start') {
                $h .= HomeForm::render($p);
            }
            $h .=
                '<details><summary>Navigation, SEO und Bilder</summary><div class="fields">' .
                Html::input("seo_title", "SEO-Titel", $p["seo_title"]) .
                Html::input(
                    "seo_description",
                    "SEO-Beschreibung",
                    $p["seo_description"],
                ) .
                Html::input(
                    "nav_order",
                    "Menüreihenfolge",
                    $p["nav_order"],
                    "number",
                ) .
                '<div><label for="native-parent">Übergeordnete Seite</label>' .
                (new ReferencePicker($this->db, $this->access))->render(
                    "page",
                    "parent_id",
                    "native-parent",
                    $p["parent_id"] ?? "",
                    false,
                    "",
                    $p["id"],
                    "Oberste Ebene",
                ) .
                "</div>" .
                '</div><label class="check"><input class="check" type="checkbox" name="nav_visible"' .
                ($p["nav_visible"] ? " checked" : "") .
                '> Im Menü anzeigen</label><label class="check"><input class="check" type="checkbox" name="noindex"' .
                ($p["noindex"] ? " checked" : "") .
                '> Nicht in Suchmaschinen indexieren</label>' .
                (new PageMediaPicker($this->db,$this->access))->render($p['media_ids']) .
                '</details>';
            if (!$this->workspace || $renderer === "news") {
                $h .= Html::input(
                    "news_date",
                    "Beitragsdatum",
                    $p["news_date"] ?? "",
                    "date",
                );
            }
            if ($renderer === "news") {
                $h .= NewsChannelFields::render($p);
            }
            if (!$this->workspace || $renderer === "event") {
                $h .=
                    "<details" .
                    ($section === "events" ? " open" : "") .
                    '><summary>Veranstaltungsdaten</summary><div class="fields">' .
                    Html::input(
                        "event_start",
                        "Beginn (gewählte Zeitzone)",
                        $p["event_start"],
                        "datetime-local",
                    ) .
                    Html::input(
                        "event_location",
                        "Veranstaltungsort",
                        $p["event_location"],
                    ) .
                    "</div>" .
                    Html::input(
                        "event_timezone",
                        "Zeitzone",
                        $p["event_timezone"],
                    ) .
                    Html::input(
                        "event_end_local",
                        "Ende (gewählte Zeitzone)",
                        ($p["event_end"] ?? "") !== ""
                            ? (new \DateTimeImmutable($p["event_end"]))
                                ->setTimezone(
                                    new \DateTimeZone($p["event_timezone"]),
                                )
                                ->format("Y-m-d\TH:i")
                            : "",
                        "datetime-local",
                    ) .
                    Html::select(
                        "event_status",
                        "Status",
                        ['automatic'=>'Automatisch nach Termin']+\Kulturhus\Native\Cms\EventDetails::STATUSES,
                        \Kulturhus\Native\Cms\EventDetails::mode($p)==='automatic'&&in_array($p['event_status']??'scheduled',['scheduled','started'],true)?'automatic':($p['event_status']??'scheduled'),
                    ) .
                    Html::input(
                        "event_address",
                        "Anschrift des Veranstaltungsortes",
                        $p["event_address"] ?? "",
                        "text",
                        'maxlength="1200"',
                    ) .
                    Html::input(
                        "event_ticket_url",
                        "Ticketlink",
                        $p["event_ticket_url"] ?? "",
                        "url",
                        'maxlength="2000"',
                    ) .
                    Html::input(
                        "event_reference_url",
                        "Weiterführender Link",
                        $p["event_reference_url"] ?? "",
                        "url",
                        'maxlength="2000"',
                    ) .
                    Html::input(
                        "event_price",
                        "Eintritt in Euro (0 für freien Eintritt; leer, wenn noch offen)",
                        $p["event_price"] ?? "",
                        "text",
                        'inputmode="decimal"',
                    ) .
                    "</details>";
            }
            if($renderer==='standard')$h.=(new ContentSectionsPanel($this->db,$this->access,$this->csrf))->render($p,true);
            $h .=
                '<p class="hint" data-page-save-status role="status">'.($saveFailed?'Nicht gespeichert. Bitte die Angaben prüfen.':($renderer==='standard'?($id?'Gespeicherter Entwurf. Text, Bilder und Inhaltsabschnitte werden gemeinsam gespeichert.':'Noch nicht gespeichert. Text, Bilder und Inhaltsabschnitte werden gemeinsam gespeichert.'):($id?'Gespeicherter Entwurf. Änderungen werden gemeinsam gespeichert.':'Noch nicht gespeichert. Änderungen werden gemeinsam gespeichert.'))).'</p>'.
                '<button class="btn btn-solid theme-primary">Entwurf speichern</button></form></div>';
            if($live)$h.=LivePagePreview::render($this->db,$this->access,$this->mediaRoot,$p).'</div>';
        } else {
            if ($id && \Kulturhus\Native\Cms\NewsChannels::isNews($p)) {
                $h.='<div class="panel">'.(new NewsReadingView($this->db,$this->access,$this->mediaRoot))->render($id,$p['version']).'</div>';
            } else {
            $h .=
                '<div class="panel"><h2>' .
                $e($p["title"] ?: "Seite auswählen") .
                '</h2><div class="kh-richtext">' .
                Html::content($p) .
                '</div>'.DocumentLinks::render($p,true).(($id&&!$p['archived']&&!$p['trashed']&&($p['template_definition']['renderer']??$p['template'])==='standard')?'<p><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="/sections-preview?item='.$e($id).'">Gespeicherte Vorschau ↗</a></p>':'').'<p class="hint">Du hast Leserechte.</p></div>';
            }
        }
        if ($id) {
            if(!$write)$h .= (new ContentSectionsPanel($this->db,$this->access,$this->csrf))->render($p);
            if ($p['slug']==='start') {
                $h .= '<p><a class="btn btn-outline theme-secondary" target="_blank" rel="noopener" href="/home-preview?item='.$e($id).'">Gespeicherte Startseite privat ansehen ↗</a></p>';
            }
            $h .= (new NewsReviewPanel($this->db, $this->access, $this->csrf))->render(
                $p,
                "page",
            );
            $publicEvent = $pages->events()->publicId($id);
            $notice = $publicEvent["cancellation_notice"] ?? false;
            $isPublic=!empty($p['published_revision']);
            $changed=$isPublic&&(int)$p['version']!==(int)$p['published_revision'];
            $status=$notice?'Absagehinweis öffentlich':(!$isPublic?'Entwurf · noch nicht veröffentlicht':($changed?'Änderungen gespeichert, noch nicht veröffentlicht':'Veröffentlicht'));
            $h.='<div class="panel"><h2>Veröffentlichung</h2><p role="status">'.Html::e($status).'</p>'.($notice?'<p class="hint">Der Absagehinweis bleibt auf der Website sichtbar. „Offline nehmen“ entfernt auch diesen Hinweis.</p>':'').'<div class="actions">';
            if ($publish) {
                $approval=\Kulturhus\Native\Cms\NewsChannels::isNews($p)?(new \Kulturhus\Native\Cms\NewsPolicy($this->db,$this->access))->state($id,$p):['enabled'=>false,'valid'=>true];
                foreach (
                    [
                        "page-publish" => "Veröffentlichen",
                        "page-unpublish" => "Offline nehmen",
                    ]
                    as $op => $label
                ) {
                    if($op==='page-publish'&&$isPublic&&!$changed)continue;
                    if($op==='page-unpublish'&&!$isPublic&&!$notice)continue;
                    $h .=
                        $this->start($op) .
                        Html::hidden("id", $id) .
                        Html::hidden("version", $p["version"]) .
                        '<button class="btn btn-solid theme-secondary secondary"'.($op==='page-publish'&&$approval['enabled']&&!$approval['valid']?' disabled':'').'>' .
                        $label .
                        "</button></form>";
                }
            }
            if ($p["published_slug"] ?? "") {
                $h .=
                    '<a target="_blank" rel="noopener" href="/site/' .
                    $e($p["published_slug"]) .
                    '">Öffentliche Vorschau ↗</a>';
            } elseif ($notice) {
                $h .=
                    '<a target="_blank" rel="noopener" href="/site/' .
                    $e($publicEvent["slug"]) .
                    '">Absage ansehen ↗</a>';
            }
            $h .= "</div></div>";
            if ($write) {
                $h .= PageCopyPanel::render($p, $this->csrf, (new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->copyBases($p["id"]));
            }
            $h .= (new PageFieldtypesPanel($this->db,$this->access,$this->csrf))->render($p);
            $h.=(new FacebookLinkPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($p);
            $h.=(new TicketPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($p);
            $h .= (new PageHierarchyPanel($this->db, $this->access, $this->csrf))->render($p);
            $h .= (new PageChildrenPanel($this->db, $this->access))->render($p);
            $h .= (new PathHistoryPanel(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
            ))->render($p);
            $h.='<div class="panel">'.(new PageVersionsPanel($this->db,$this->access,$this->csrf))->render($p,$section,'page-restore').'</div>';
        }
        return $h . "</div></div>";
    }
}
