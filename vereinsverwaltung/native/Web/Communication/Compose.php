<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Communication;
use Kulturhus\Native\Web\{
    Workspace as BaseWorkspace,
    Html,
    Editor,
    RichTextEditor,
    ImageEditor,
    VideoEditor,
};
use Kulturhus\Native\Communication\Workspace as Communication;
use Kulturhus\Native\Communication\{Subscribers, PressContacts};
use Kulturhus\Native\Cms\Media;
/** Newsletter, social and press editing; shell and other modules stay separate. */
final class Compose extends BaseWorkspace
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    public function manager(string $module, string $id,?array $composeState=null): string
    {
        if($module==='social'&&isset($composeState['reel_only']))return ReelRenderForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['reel_only']);
        if($module==='social'&&isset($composeState['video_reuse_only']))return VideoReuseForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_reuse_only']);
        if($module==='social'&&array_key_exists('video_use_window',$_GET))return (new VideoReusePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->page($id);
        if($module==='social'&&isset($composeState['video_recovery_only']))return VideoRecoveryForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_recovery_only']);
        if($module==='social'&&array_key_exists('video_recovery_window',$_GET))return (new VideoRecoveryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->page($id);
        if($module==='social'&&array_key_exists('video_storage_window',$_GET))return (new VideoStoragePanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->page($id);
        if($module==='social'&&isset($composeState['video_file_only']))return VideoRemovalForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_file_only']);
        if($module==='social'&&isset($composeState['video_archive_only']))return VideoArchiveForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_archive_only']);
        if($module==='social'&&isset($composeState['video_policy_only']))return VideoPolicyForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_policy_only']);
        if($module==='social'&&isset($composeState['video_metadata_only']))return VideoMetadataForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_metadata_only']);
        if($module==='social'&&isset($composeState['video_only']))return VideoUploadForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['video_only']);
        if($module==='social'&&isset($composeState['photo_only']))return PhotoUploadForm::page($this->db,$this->access,$this->mediaRoot,$this->csrf,$composeState['photo_only']);
        if(in_array($module,['newsletter','press','social'],true)&&($composeState['lookup_only']??false)){
            $this->access->require($module,'read');$lookup=$composeState['save_lookup'];
            return ('<section class="panel"><h1>' . Html::e($this->contentText('Gespeicherter Stand')) . '</h1><p data-save-status role="status">').Html::e($lookup['found']?$this->contentFormat('Fassung {version} gespeichert.',['version'=>$lookup['version']]):$this->contentText('Kein gespeicherter Vorgang gefunden.')).'</p>'.($lookup['found']?'<a class="btn btn-outline theme-secondary" data-save-original target="_blank" rel="noopener" href="'.Html::e($lookup['url']).('">' . Html::e($this->contentText('Gespeicherte Fassung öffnen')) . '</a>'):'').'</section>';
        }
        $w = new Communication($this->db, $this->access, $module,$this->mediaRoot);
        $e = Html::e(...);
        $rows = $module==='social'?$w->list():[];
        $defaults = [
            "newsletter" => ["edition", "newsletter-standard"],
            "social" => ["post", "social-post"],
            "press" => ["release", "press-release"],
        ];
        $media = $this->can("website", "read")
            ? (new \Kulturhus\Native\Cms\Media(
                $this->db,
                $this->access,
                $this->mediaRoot,
            ))->list()
            : [];
        $p = $id
            ? $w->get($id)
            : [
                "id" => "",
                "version" => 0,
                "title" => "",
                "summary" => "",
                "body" => "",
                "kind" => $defaults[$module][0],
                "template" => $defaults[$module][1],
                "status" => "draft",
            ];
        if($module==='social'&&$id===''&&$composeState===null&&isset($_GET['new_kind'])){
            $kind=$_GET['new_kind'];if(!is_string($kind)||!in_array($kind,['post','story','reel','carousel'],true))throw new \DomainException('Inhaltsart gehört nicht zu diesem Modul.',422);
            if($kind==='reel'&&!(new \Kulturhus\Native\Communication\Videos($this->db,$this->access,$this->mediaRoot))->installed())throw new \DomainException('Videoverarbeitung ist noch nicht eingerichtet.',422);
            $p['kind']=$kind;$p['template']='social-'.$kind;
        }
        if($composeState!==null){
            if(!in_array($module,['press','social','newsletter'],true)||($composeState['document']['id']??null)!==$id)throw new \DomainException('Redaktionsformular erneut öffnen.');
            if(in_array($module,['newsletter','press','social'],true)&&!array_key_exists('body_html',$composeState['document'])&&array_key_exists('body',$composeState['document']))unset($p['body_html']);
            $p=array_replace($p,$composeState['document']);
        }
        $storyKind=$module==='social'&&$p['kind']==='story';
        $storyArtwork=$storyKind&&$id!==''&&$this->can('social','edit');
        $status = fn(string $s): string => [
            "draft" => $this->contentText('Entwurf'),
            "review" => $this->contentText('In Prüfung'),
            "approved" => $this->contentText('Freigegeben'),
            "scheduled" => $this->contentText('Geplant'),
            "local_ready" => $this->contentText('Vorbereitet'),
        ][$s] ?? $s;
        if($module!=='social'){
            $h=(new EditionLibrary($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($module,$id).'<div class="compose-layout"><div class="compose-main">';
        }else{
        $h = ($module==='social'?'':
            '<p class="intro compose-intro">' .
            match ($module) {
                "newsletter"
                    => Html::e($this->contentText('Neuigkeiten, die verbinden. Gestalte die nächste Kulturpost.')),
                "social"
                    => Html::e($this->contentText('Vereinsleben teilen. Gestalte freie Beiträge und Geschichten oder bereite eine eigene Veranstaltung vor.')),
                default
                    => Html::e($this->contentText('Kultur ins Gespräch bringen. Eure Mitteilung an die Presse.')),
            } .
            '</p>').('<div class="compose-library"><details class="draft-library"><summary>' . Html::e($this->contentText('Entwürfe & Ausgaben')) . ' <span class="badge">') .
            count($rows) .
            '</span></summary><div class="draft-cards">';
        foreach ($rows as $r) {
            $h .=
                '<a class="item" data-nav href="/?view=' .
                $module .
                "&amp;section=compose&amp;item=" .
                $e($r["id"]) .
                ($r['kind']==='story'?'#social-image-editor':'').'"' .
                ($r["id"] === $id ? ' aria-current="page"' : "") .
                "><strong>" .
                $e($r["title"]) .
                "</strong><small>" .
                $e($status($r["status"])) .
                " · Version " .
                $e($r["version"]) .
                "</small></a>";
        }
        $h .=
            '</div></details><a class="btn btn-outline theme-secondary button secondary" data-nav href="/?view=' .
            $module .
            ('&amp;section=compose">' . Html::e($this->contentText('+ Neu anlegen')) . '</a></div><div class="compose-layout"><div class="compose-main">');
        }
        if ($module === 'social') {
            if($id)$h.=('<p><a class="btn btn-outline theme-secondary" href="#publication-planning">' . Html::e($this->contentText('Planung öffnen')) . '</a></p>');
        }
        if (in_array($module,['social','press'],true)) {
            if (
                $id &&
                ($source = (new \Kulturhus\Native\Communication\EventDrafts(
                    $this->db,
                    $this->access,
                    $module,
                ))->origin($id))
            ) {
                if($module==='press')$h.=('<details class="press-event-origin"><summary>' . Html::e($this->contentText('Veranstaltungsquelle')) . '</summary><p>').Html::e($source['snapshot']['title']).' · '.Html::e($this->contentText('Fassung')).' '.$source['revision'].'</p><p>'.Html::e($source['source_kind']==='manager'?$this->contentText('Vereinskalender'):$this->contentText('Veröffentlichtes Website-Programm')).'</p></details>';
                else $h.='<p class="hint social-source-note">'.Html::e($this->contentFormat('Aus Veranstaltung „{title}“, {source} {version}, vorbereitet. Der Entwurf behält seine Angaben, wenn sich die Veranstaltung später ändert.',['title'=>$source['snapshot']['title'],'source'=>$this->contentText($source['source_kind']==='manager'?'Kalenderfassung':'Veröffentlichte Fassung'),'version'=>$source['revision']])).'</p>';
            }
            if (
                (new \Kulturhus\Native\Communication\EventDrafts(
                    $this->db,
                    $this->access,
                    $module,
                ))->sources()
            ) {
                $h .=
                    '<p><a class="btn btn-outline theme-secondary button secondary" href="/?view='.Html::e($module).('&amp;section=events" target="_blank" rel="noopener" data-save-original data-navigation-native>' . Html::e($this->contentText('Veranstaltung auswählen')) . '</a></p>');
            }
        }
        if ($this->can($module, "edit")) {
            if($storyArtwork)$h.='<details class="story-editor-details" data-story-editor-details'.($composeState!==null?' open':'').'><summary>'.Html::e($this->contentText('Angaben zur Story')).'</summary><p>'.Html::e($this->contentText('Das fertige Story-Bild bearbeitest du im Bildstudio. Diese Angaben bleiben für Vorlagen und Planung erhalten.')).'</p>';
            $choices = [];
            foreach ($w->templateChoices() as $key => $template) {
                if (is_array($template)) {
                    $choices[$template["id"] ?? $key] =
                        $template["name"] ??
                        ($template["title"] ?? ($template["id"] ?? $key));
                }
            }
            if (!$choices) {
                $choices = [$defaults[$module][1] => "Standard"];
            }
            $saveRequests=in_array($module,['newsletter','press','social'],true)&&\Kulturhus\Native\Communication\EditionSaveRequests::installed($this->db);
            $saveRequestKey=$saveRequests?($p['save_request_key']??bin2hex(random_bytes(16))):'';
            $pdfUploads=$module==='newsletter'&&\Kulturhus\Native\Communication\DocumentAttachments::installed($this->db);
            $checkAttributes=(in_array($module,['press','social'],true)||$pdfUploads)?' formtarget="_blank" formenctype="application/x-www-form-urlencoded"':'';
            $h .=
                '<div class="panel compose-paper"'.($module==='social'?' id="social-compose"':''). '>' .
                (($module==='press'||($module==='newsletter'&&\Kulturhus\Native\Communication\DocumentAttachments::installed($this->db)))?str_replace('<form method="post">','<form method="post" enctype="multipart/form-data">',$this->start("communication-save")):$this->start("communication-save")) .
                ($saveRequests?'<div data-newsletter-save data-save-module="'.Html::e($module).'"></div>':'') .
                Html::hidden("id", $p["id"]) .
                Html::hidden("version", $p["version"]) .
                ($saveRequests?Html::hidden('save_request_key',$saveRequestKey):'') .
                ((in_array($module,['press','social'],true)||$pdfUploads)?Html::hidden('save_review_window','1'):'') .
                ($module==='press'?'<button class="btn btn-outline theme-secondary" type="submit" name="press_browse" value="search" formnovalidate hidden></button>':'') .
                '<div class="compose-heading"><div><span class="eyebrow">' .
                Html::e($this->contentText($module === 'newsletter' ? 'Kulturpost' : ($module==='social'?'Facebook & Instagram':'Redaktion'))) .
                "</span><h2>" .
                ($id
                    ? ($module === "social"
                        ? Html::e($this->contentText(match($p['kind']){'story'=>'Story bearbeiten','reel'=>'Reel bearbeiten','carousel'=>'Karussell bearbeiten',default=>'Beitrag bearbeiten'}))
                        : Html::e($this->contentText('Ausgabe bearbeiten')))
                    : Html::e($this->contentText($module==='social'?match($p['kind']){'story'=>'Neue Story anlegen','reel'=>'Neues Reel anlegen','carousel'=>'Neues Karussell anlegen',default=>'Neuen Beitrag anlegen'}:'Neuen Entwurf anlegen'))) .
                '</h2><span class="badge">' .
                $e($status($p["status"])) .
                '</span></div>'.($saveRequests?('<div class="actions"><button class="btn btn-solid theme-primary">' . Html::e($this->contentText($storyKind&&$id===''?'Story anlegen':'Entwurf speichern')) . '</button><button class="btn btn-outline theme-secondary" name="save_review" value="1" formnovalidate').$checkAttributes.('>' . Html::e($this->contentText('Gespeicherten Stand prüfen')) . '</button></div>'):('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText($storyKind&&$id===''?'Story anlegen':'Entwurf speichern')) . '</button>')).'</div><div class="compose-writing">' .
                Html::input(
                    "title",
                    $this->contentText('Titel'),
                    $p["title"],
                    "text",
                    'required placeholder="'.Html::e($this->contentText('Ein Titel, der neugierig macht …')).'"',
                ) .
                ($storyKind&&$id===''?'<details class="story-new-details"'.($composeState!==null?' open':'').'><summary>'.Html::e($this->contentText('Weitere Story-Angaben')).'</summary>':'').
                Html::input(
                    "summary",
                    $this->contentText($module === 'social' ? 'Text auf dem Bild' : 'Einleitung'),
                    $p["summary"] ?? "",
                    "text",
                    'placeholder="'.Html::e($this->contentText('Die wichtigsten Neuigkeiten in einem Satz')).'"',
                ) .
                RichTextEditor::render(
                    "body_html",
                    Html::content($p),
                    $this->contentText($storyKind?'Zusätzliche Angaben (optional)':($module === 'social' ? 'Beitragstext' : 'Text')),
                    interfaceText:$this->contentText(...),
                ) . (in_array($module,['press','social','newsletter'],true)?('<noscript><label>' . Html::e($this->contentText('Text')) . '<textarea class="form-control" name="body_html" rows="8">').Html::e(Html::content($p)).'</textarea></label></noscript>':'');
            $kinds = match ($module) {
                "social" => ["post" => $this->contentText('Beitrag'), "story" => "Story", "carousel"=>$this->contentText('Karussell')],
                "newsletter" => ["edition" => $this->contentText('Newsletter-Ausgabe')],
                default => ["release" => $this->contentText('Pressemitteilung')],
            };
            if($module==='social'&&(new \Kulturhus\Native\Communication\Videos($this->db,$this->access,$this->mediaRoot))->installed())$kinds['reel']='Reel';
            $h .=
                '<details class="compose-settings"><summary>' .
                ($module === "social"
                    ? Html::e($this->contentText('Format & Kanäle'))
                    : Html::e($this->contentText($module==='press'?$this->contentText('Vorlage'):"Format & Empfänger"))) .
                '</summary><div class="fields">' .
                ($module==='press'?Html::hidden('kind','release'):Html::select("kind", $this->contentText("Format"), $this->contentOptions($kinds), $p["kind"])) .
                Html::select("template", $this->contentText('Vorlage'), $choices, $p["template"]) .
                "</div>";
            if($module==='social')$h.=('<noscript><button class="btn btn-outline theme-secondary" name="social_format" value="1" formnovalidate>' . Html::e($this->contentText('Format übernehmen')) . '</button></noscript>');
            if ($module === "social") {
                $h .= Html::input(
                    "destination_url",
                    $this->contentText('QR-Zieladresse (leer: Vereinsdomain)'),
                    $p["destination_url"] ?? "",
                    "url",
                );
                $h .= '<div class="actions">';
                foreach (
                    ["facebook" => "Facebook", "instagram" => "Instagram"]
                    as $key => $title
                ) {
                    $h .=
                        '<label class="check"><input class="check" type="checkbox" name="channels[]" value="' .
                        $key .
                        '"' .
                        (in_array($key, $p["channels"] ?? ["facebook"], true)
                            ? " checked"
                            : "") .
                        ">" .
                        $title .
                        "</label>";
                }
                $h .= "</div>";
            }
            if ($module === "newsletter") {
                $h .= Html::select(
                    "audience",
                    "Einwilligungszweck",
                    [
                        "all" => $this->contentText('Veranstaltungen und Neuigkeiten'),
                        "events" => $this->contentText('Veranstaltungen'),
                        "news" => $this->contentText('Neuigkeiten'),
                    ],
                    $p["audience"] ?? "all",
                );
            }
            $h.='</details>';
            if($saveRequests){
                $lookup=$composeState['save_lookup']??null;
                $h.='<div class="newsletter-save-check" data-save-status role="status" aria-live="polite"'.($lookup===null?' hidden':'').'>'.($lookup===null?'':Html::e(($lookup['found']??false)?$this->contentFormat('Fassung {version} gespeichert.',['version'=>$lookup['version']]):$this->contentText('Kein gespeicherter Vorgang gefunden.'))).'</div>';
                $h.='<a class="btn btn-outline theme-secondary" data-save-original target="_blank" rel="noopener"'.(($lookup['found']??false)?' href="'.Html::e($lookup['url']).'"':' hidden').('>' . Html::e($this->contentText('Gespeicherte Fassung öffnen')) . '</a>');
            }

            if($module==='newsletter')$h.=PdfAttachments::fields($this->db,$this->access,$this->mediaRoot,$p);
            if ($module === "press") {
                $h .= PressPhotoPicker::render($this->db,$this->access,$p);
                $h .= PressRecipientPicker::render($this->db,$this->access,$composeState['picker']??['recipient_ids'=>$p['recipient_ids']??[]],$composeState!==null);
            }
            $h .= ($module==='newsletter'?'<section class="compose-blocks newsletter-direct-editor">':('<details class="compose-blocks"><summary><span>'.Html::e($this->contentText('＋ Inhaltsbausteine')).'</span><small>'.Html::e($this->contentText('Bilder, Schaltflächen & Veranstaltungen')).'</small></summary>')) .
                $this->blocks($p,$composeState,$media) . ($module==='newsletter'?'</section>':'</details>');
            if ($module === "social") {
                $design = \Kulturhus\Native\Communication\ImageDesign::options(
                    $p["image_design"] ?? [],
                );
                $h .=
                    ('<fieldset class="image-design"><legend>' . Html::e($this->contentText('Bild gestalten')) . '</legend><p>' . Html::e($this->contentText('Eigene Fotos und lokale Vorlagen. Für Posts (4:5) und Stories (9:16).')) . '</p><div class="fields">') .
                    Html::select(
                        "image_layout",
                        "Layout",
                        [
                            "classic" => $this->contentText('Vereinskarte · ursprüngliche Gestaltung'),
                            "editorial" => $this->contentText('Magazin · Foto und Text'),
                            "poster" => $this->contentText('Plakat · Typografie und Formen'),
                            "photo" => $this->contentText('Fotoposter · Text auf dunkler Fläche'),
                        ],
                        $design["layout"],
                    ) .
                    Html::select(
                        "image_palette",
                        "Farbwelt",
                        [
                            "forest" => $this->contentText('Wald · Grün und Limette'),
                            "sunset" => $this->contentText('Abend · Bordeaux und Apricot'),
                            "night" => $this->contentText('Nacht · Blau und Lavendel'),
                        ],
                        $design["palette"],
                    ) .
                    ('</div><details><summary>' . Html::e($this->contentText('Bildausschnitt verschieben')) . '</summary><div class="fields">') .
                    Html::input(
                        "image_focal_x",
                        $this->contentText('Horizontal (0 links, 100 rechts)'),
                        $design["focal_x"],
                        "range",
                        'min="0" max="100"',
                    ) .
                    Html::input(
                        "image_focal_y",
                        $this->contentText('Vertikal (0 oben, 100 unten)'),
                        $design["focal_y"],
                        "range",
                        'min="0" max="100"',
                    ) .
                    ('</div></details><p class="hint">' . Html::e($this->contentText('Gestaltung speichern, dann Bild erzeugen. Vorschau und Download zeigen die gespeicherte Bilddatei.')) . '</p></fieldset>');
                $h .=
                    "<div data-story-panel" .
                    ($p["kind"] !== "story" ? " hidden" : "") .
                    '><label class="check"><input class="check" type="checkbox" name="story_link_requested"' .
                    (!empty($p["story_link_requested"]) ? " checked" : "") .
                    ('> ' . Html::e($this->contentText('Story mit klickbarem Link manuell veröffentlichen')) . '</label><p class="hint">' . Html::e($this->contentText('Instagram unterstützt im verwendeten API-Weg keinen Link-Sticker. Datei herunterladen, Link kopieren und vor der Veröffentlichung in der App ergänzen. Ein QR-Code im Bild ist kein klickbarer Link.')) . '</p>') .
                    ($storyKind&&$id===''&&empty($p['story'])?Html::hidden('story_json','[]'):Editor::story("story_json", $p["story"] ?? [], $media, $this->can("website", "edit"),interfaceText:$this->contentText(...))) .
                    "</div>";
                $h.='<div data-carousel-panel'.($p['kind']!=='carousel'?' hidden':'').'>'.Editor::carousel('carousel_json',$p['carousel']??[],$media,$this->can('website','edit'),interfaceText:$this->contentText(...)).'</div>';
            }
            $h .= ($storyKind&&$id===''?'</details>':'')."</div></form></div>";
            if($storyArtwork)$h.='</details>';
        } elseif($module!=='social') {
            $h.=$id===''?('<div class="panel"><h2>' . Html::e($this->contentText('Ausgabe auswählen')) . '</h2></div>'):'<div class="panel edition-reading">'.\Kulturhus\Native\Communication\Content::preview($p,static fn($mediaId)=>'/edition-image?'.http_build_query(['module'=>$module,'item'=>$p['id'],'revision'=>$p['version'],'media'=>$mediaId])).PressPhotoLinks::render($this->db,$this->access,$p).($module==='newsletter'?PdfAttachments::links($p):'').'</div>';
        } else {
            $h .=
                '<div class="panel"><h2>' .
                $e($p["title"] ?: $this->contentText('Entwurf auswählen')) .
                '</h2><div class="kh-richtext">' .
                Html::content($p) .
                "</div></div>";
        }
        $automaticStory=$module==='social'&&$id&&$p['kind']==='story'&&\Kulturhus\Native\Communication\StoryAutomation::configured($this->db,$this->access,$id);
        $h .=
            '</div><aside class="compose-side" aria-label="'.Html::e($this->contentText('Ausgabe verwalten')).'">'.($module==='social'?('<div class="compose-guide"><span class="eyebrow">' . Html::e($this->contentText('So entsteht die Veröffentlichung')) . '</span><ol><li><span>1</span><div><strong>' . Html::e($this->contentText('Gestalten')) . '</strong><small>' . Html::e($this->contentText('Text, Farben und Bilder zusammenbringen.')) . '</small></div></li><li><span>2</span><div><strong>' . Html::e($this->contentText('Prüfen')) . '</strong><small>' . Html::e($this->contentText($automaticStory?'Story-Automatik übernimmt die Freigabe zum Termin.':'Vorschau ansehen und Fassung freigeben.')) . '</small></div></li><li><span>3</span><div><strong>' . Html::e($this->contentText('Planen')) . '</strong><small>' . Html::e($this->contentText('Den passenden Zeitpunkt auswählen.')) . '</small></div></li></ol></div>'):'');
        if($automaticStory)$h.='<p class="hint" data-story-automatic>'.Html::e($this->contentText('Diese Story wird zum vorgesehenen Story-Termin automatisch veröffentlicht. Eine einzelne Freigabe ist nicht nötig.')).'</p>';
        $h.=$this->can($module,'edit')&&!$storyKind?LivePreview::render($module,$p,\Kulturhus\Native\Shared\Identity::forClub($this->db,$this->access->club()),$media):'';
        if ($id) {
            if ($module === "social") {
                if($p['kind']!=='reel')$h.=(new ImageHistoryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($p);
                $h.=(new VideoHistoryPanel($this->db,$this->access,$this->mediaRoot,$this->csrf))->render($p);
            }
            if ($module === "newsletter" && $this->can("newsletter", "edit")) {
                $h .=
                    $this->start("newsletter-test") .
                    Html::hidden("id", $id) .
                    Html::input(
                        "test_email",
                        $this->contentText('Testmail an'),
                        "",
                        "email",
                        "required",
                    ) .
                    ('<p class="hint">' . Html::e($this->contentText('Die Testmail geht nur an die hier eingegebene Adresse.')) . '</p><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Testmail senden')) . '</button></form>');
            }
            $pressDeliverySummary=$module==='press'&&(int)$p['version']>0?\Kulturhus\Native\Communication\PressDeliveryCatalogue::summary($this->db,$this->access,$id,(int)$p['version']):[];
            $pressSendRestriction=$module==='press'?\Kulturhus\Native\Communication\PressDeliveryCatalogue::restriction($this->db,$this->access,$id,(int)$p['version']):'';
            $shownStatus=$status($p['status']);
            if($pressDeliverySummary){
                $shownStatus='Gestoppt';
                foreach(['uncertain','processing','pending','sent']as$deliveryState)if(isset($pressDeliverySummary[$deliveryState])){$shownStatus=\Kulturhus\Native\Communication\PressDeliveryCatalogue::STATES[$deliveryState];break;}
            }
            $h .=
                '<div class="panel'.($module==='press'?' press-send-planning':'').('"><h2 id="publication-planning" tabindex="-1">' . Html::e($this->contentText('Freigabe & Planung')) . '</h2><p><span class="badge">') .
                $e($shownStatus) .
                "</span> <small>Version " .
                $e($p["version"]) .
                "</small></p>";
            if($module==='press')$h.='<p><a class="btn btn-outline theme-primary" data-nav href="/?view=press&amp;section=planning&amp;press_item='.$id.('">' . Html::e($this->contentText('Zustellverlauf öffnen')) . '</a></p>');
            if($pressSendRestriction!=='')$h.=('<p>' . Html::e($this->contentText('Früherer Versand:')) . ' ').Html::e(\Kulturhus\Native\Communication\PressDeliveryCatalogue::STATES[$pressSendRestriction]).'</p>';
            if($pressDeliverySummary){$parts=[];foreach($pressDeliverySummary as$deliveryState=>$count)$parts[]=$count.' '.(\Kulturhus\Native\Communication\PressDeliveryCatalogue::STATES[$deliveryState]??$this->contentText('Stand prüfen'));$h.='<p>'.Html::e(implode(' · ',$parts)).'</p>';}
            if ($this->can($module, "publish")) {
                $personalAvailable=false;$existing=null;
                if($module==='social'&&$p['kind']==='post'&&(new \Kulturhus\Native\Communication\EventDrafts($this->db,$this->access))->origin($id)){
                    try{$existing=(new \Kulturhus\Native\Communication\PersonalPublication($this->db,$this->box()))->existing($this->access,$id);$personalAvailable=true;}
                    catch(\DomainException){$h.=('<p class="hint">' . Html::e($this->contentText('StadtLand.Funk nicht verbunden.')) . '</p>');}
                    if($existing)$h.='<p><a class="btn btn-outline theme-secondary" data-nav href="/?view=social&amp;section=planning&amp;queue_job='.Html::e($existing).('">' . Html::e($this->contentText('StadtLand.Funk-Verlauf öffnen')) . '</a></p>');
                    if($personalAvailable&&!$existing&&in_array($p['status'],['draft','review'],true))$h.='<p class="hint">'.Html::e($this->contentText('Dieser Veranstaltungsbeitrag kann nach der Freigabe auch für StadtLand.Funk eingeplant werden.')).'</p>';
                }
                if (!$automaticStory && in_array($p["status"], ["draft", "review"], true) && $p["kind"]!=="reel") {
                    $h .=
                        $this->start("communication-approve") .
                        Html::hidden("id", $id) .
                        Html::hidden("version", $p["version"]) .
                        ('<button class="btn btn-outline theme-secondary secondary">' . Html::e($this->contentText('Freigeben')) . '</button></form>');
                }
                if($module==='social'&&$p['kind']==='reel'&&in_array($p['status'],['draft','review'],true))$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e('/?'.http_build_query(['view'=>'social','item'=>$id,'video_page'=>1]).'#social-videos').('">' . Html::e($this->contentText('Videofassung auswählen')) . '</a>');
                if (
                    $p["status"] === "approved" && !$pressDeliverySummary && $pressSendRestriction==='' &&
                    !(
                        $module === "social" &&
                        !empty($p["story_link_requested"])
                    )
                ) {
                    $newsletterSendSummary='';$newsletterRecipients=null;
                    if($module==='newsletter'){
                        $savedEdition=$w->get($id);$audience=$savedEdition['audience']??'all';$newsletterRecipients=count((new Subscribers($this->db,$this->access))->eligible($audience));
                        $audienceLabel=['all'=>'Veranstaltungen und Neuigkeiten','events'=>'Veranstaltungen','news'=>'Neuigkeiten'][$audience];
                        $newsletterSendSummary='<div class="newsletter-send-summary" data-newsletter-send-summary><strong>'.Html::e($this->contentText('Zielgruppe')).': '.Html::e($this->contentText($audienceLabel)).'</strong><p>'.Html::e($this->contentFormat('{count} bestätigte Empfänger',['count'=>$newsletterRecipients])).'</p>'.($newsletterRecipients===0?'<p>'.Html::e($this->contentText('Für diese Zielgruppe sind noch keine bestätigten Empfänger vorhanden.')).'</p>':'').'</div>';
                    }
                    $h .=
                        "<hr>" .
                        $this->start("communication-schedule") .
                        Html::hidden("id", $id) .
                        Html::hidden("version", $module==='press'&&($_POST['operation']??null)==='communication-schedule'&&($_POST['id']??null)===$id&&is_scalar($_POST['version']??null)?$_POST['version']:$p['version']) .
                        Html::input(
                            "scheduled_at",
                            $this->contentText($module==='social'?'Veröffentlichen am (Europe/Berlin)':'Versenden am (Europe/Berlin)'),
                            $module==='press'&&($_POST['operation']??null)==='communication-schedule'&&($_POST['id']??null)===$id&&is_string($_POST['scheduled_at']??null)?$_POST['scheduled_at']:"",
                            "datetime-local",
                            "required",
                        ) .
                        $newsletterSendSummary.'<button class="btn btn-solid theme-primary"'.($newsletterRecipients===0?' disabled':'').'>'.Html::e($this->contentText($module==='social'?'Veröffentlichung planen':'Versand planen')).'</button></form>';
                    if($personalAvailable&&!$existing){
                            $retry=($_POST['operation']??null)==='stadtlandfunk-schedule'&&($_POST['id']??null)===$id;
                            $time=$retry&&is_string($_POST['city_scheduled_at']??null)?$_POST['city_scheduled_at']:'';
                            $checked=$retry&&is_scalar($_POST['version']??null)&&(int)$_POST['version']===$p['version']&&($_POST['city_confirm']??null)==='1';
                            $h.='<details class="personal-publication"'.($retry?' open':'').('><summary>' . Html::e($this->contentText('StadtLand.Funk planen')) . '</summary>').$this->start('stadtlandfunk-schedule').Html::hidden('id',$id).Html::hidden('version',$p['version']).Html::input('city_scheduled_at',$this->contentText('Veröffentlichen am (Europe/Berlin)'),$time,'datetime-local','required').'<label class="check"><input class="check" type="checkbox" name="city_confirm" value="1" required'.($checked?' checked':'').('> ' . Html::e($this->contentText('Veranstaltung auf StadtLand.Funk veröffentlichen')) . '</label><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('StadtLand.Funk einplanen')) . '</button></form></details>');
                    }
                }
            }
            if ($this->can($module, "manage")) {
                $templateRetry=($_POST['operation']??null)==='communication-template'&&($_POST['id']??null)===$id&&is_string($_POST['version']??null)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$_POST['version'])&&is_string($_POST['name']??null);
                $h .=
                    '<details'.($templateRetry?' open':'').('><summary>' . Html::e($this->contentText('Als eigene Vorlage speichern')) . '</summary>') .
                    $this->start("communication-template") .
                    Html::hidden("id", $id) .
                    Html::hidden("version",$templateRetry?$_POST['version']:$p['version']) .
                    Html::input(
                        "name",
                        $this->contentText('Vorlagenname'),
                        $templateRetry?$_POST['name']:"",
                        "text",
                        "required",
                    ) .
                    ('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Vorlage speichern')) . '</button></form></details><details><summary>' . Html::e($this->contentText('Neuen Vorschlag automatisch vorbereiten')) . '</summary><p class="hint">' . Html::e($this->contentText('Einmalig aus dieser freigegebenen Fassung. Der Vorschlag bleibt ein neuer Entwurf.')) . '</p>') .
                    $this->start("communication-automation") .
                    Html::hidden("id", $id) .
                    Html::hidden("version", $p["version"]) .
                    Html::input(
                        "scheduled_at",
                        $this->contentText('Vorschlag vorbereiten am (Europe/Berlin)'),
                        "",
                        "datetime-local",
                        "required",
                    ) .
                    ('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Vorschlag planen')) . '</button></form></details>');
            }
            $h .=
                '<a class="btn btn-outline theme-secondary button secondary compose-preview" data-manager-preview href="/preview?module=' .
                $module .
                "&amp;section=compose&amp;item=" .
                $e($id) .
                ('">' . Html::e($this->contentText('Vorschau öffnen ↗')) . '</a>');
            if($module!=='social')$h.=(new EditionLibrary($this->db,$this->access,$this->mediaRoot,$this->csrf))->history($module,$id).'</div>';
            else{ $h.=('<details><summary>' . Html::e($this->contentText('Versionen')) . '</summary><ol>');
            foreach ($w->revisions($id) as $r) {
                $h .=
                    "<li>Version " .
                    $e($r["version"] ?? ($r["revision"] ?? "")) .
                    " · " .
                    $e($r["action"] ?? "gespeichert") .
                    "</li>";
            }
            $h .= "</ol></details></div>";}
        }
        if ($module === "social" && $id) {
            $h .= Handoff::render(
                $this->db,
                $this->access,
                $this->mediaRoot,
                $this->csrf,
                $p,
            );
        }
        $studioMetadata=$module==='social'&&$id&&$this->can('social','edit') ? \Kulturhus\Native\Cms\MediaMetadata::capture($this->db,$this->access->club(),['media_ids'=>array_column($media,'id')]) : [];
        $studioMessages=$module==='social'&&$id&&$this->can('social','edit')?(new \Kulturhus\Native\Language\Catalog($this->db,$this->access))->messages():[];
        $studio =
            $module === "social" && $id && $p["kind"]!=="reel" && $this->can("social", "edit")
                ? ImageEditor::render(
                    $p,
                    \Kulturhus\Native\Shared\Identity::forClub(
                        $this->db,
                        $this->access->club(),
                    ),
                    $media,
                    (new \Kulturhus\Native\Communication\ImageHistory($this->db,$this->access))->documents($id,$p['kind']),
                    $this->csrf,
                    $this->can("website", "edit"),
                    $studioMetadata,
                    fn(string $source):string=>\Kulturhus\Native\Language\ImageStudioMessages::text($source,$studioMessages),
                    $p['kind']==='story'?(new \Kulturhus\Native\Communication\StorySeries($this->db,$this->access,$this->mediaRoot))->editor($id):null,
                )
                : "";
        $mediaWorkplace = $studio .
            ($module === 'social' && $id && $p['kind'] === 'reel' && $this->can('social', 'edit')
                ? ReelRenderForm::render($p,$this->csrf,fn(string $source):string=>\Kulturhus\Native\Language\EventPreparationMessages::text($source,$studioMessages),separate:false) : '') .
            ($module === "social" && $id && $this->can("social", "edit")
                ? VideoEditor::render($p,\Kulturhus\Native\Shared\Identity::forClub($this->db,$this->access->club()),$this->csrf,fn(string $source):string=>\Kulturhus\Native\Language\VideoStudioMessages::text($source,$studioMessages))
                : "");
        if($mediaWorkplace!==''){
            $mediaWorkplace='<section class="social-editor-media" id="social-media-workspace" data-social-media-workspace'.($storyArtwork?' data-story-artwork':'').'><h2>'.Html::e($this->contentText($storyArtwork?'Story gestalten':'2. Gestaltung')).'</h2><p class="hint">'.Html::e($this->contentText($storyArtwork?'Gestalte das Story-Bild im Hochformat. Danach die Veröffentlichung prüfen.':'Bild oder Video auswählen und die Gestaltung hier speichern.')).'</p>'.$mediaWorkplace.'</section>';
            $h=$storyArtwork?str_replace('<div class="compose-main">','<div class="compose-main">'.$mediaWorkplace,$h):str_replace('</div><aside class="compose-side"',$mediaWorkplace.'</div><aside class="compose-side"',$h);
            $workflow='<section class="panel social-editor-workflow" aria-label="'.Html::e($this->contentText('Bearbeitungsschritte')).'"><strong>'.Html::e($p['title']).'</strong><span class="badge">'.Html::e($status($p['status'])).'</span><nav aria-label="'.Html::e($this->contentText('Bearbeitungsschritte')).'"><a class="btn btn-outline theme-secondary" href="#social-compose">'.Html::e($this->contentText('1. Text & Angaben')).'</a><a class="btn btn-outline theme-secondary" href="#social-media-workspace">'.Html::e($this->contentText('2. Gestaltung')).'</a><a class="btn btn-outline theme-secondary" href="#publication-planning">'.Html::e($this->contentText('3. Veröffentlichung')).'</a></nav><p>'.Html::e($this->contentText('Text und Gestaltung haben jeweils einen eigenen Speicherknopf. Die Veröffentlichung erfolgt anschließend.')).'</p></section>';
            $saveState='<p role="status" aria-live="polite" data-social-save-state data-clean="'.Html::e($this->contentText('Keine offenen Änderungen')).'" data-pending="'.Html::e($this->contentText('Noch zu speichern: {parts}')).'" data-text="'.Html::e($this->contentText('Text und Angaben')).'" data-image="'.Html::e($this->contentText('Bild')).'" data-video="'.Html::e($this->contentText('Video')).'">'.Html::e($this->contentText('Keine offenen Änderungen')).'</p><p data-social-design-reminder hidden>'.Html::e($this->contentText('Nach Textänderungen die Gestaltung erneut speichern.')).'</p>';
            if($storyArtwork)$workflow='<section class="panel social-editor-workflow" data-story-workflow aria-label="'.Html::e($this->contentText('Story gestalten')).'"><strong>'.Html::e($p['title']).'</strong><span class="badge">'.Html::e($status($p['status'])).'</span><nav><a class="btn btn-outline theme-secondary" href="#social-image-editor">'.Html::e($this->contentText('Bildstudio')).'</a><a class="btn btn-outline theme-secondary" href="#publication-planning">'.Html::e($this->contentText('Veröffentlichung')).'</a><a class="btn btn-outline theme-secondary" href="#social-compose">'.Html::e($this->contentText('Angaben zur Story')).'</a></nav></section>';
            $workflow=str_replace('</section>',$saveState.'</section>',$workflow);
            $h=$workflow.$h;
        }
        return $h."</aside></div>";
    }

    private function blocks(array $p,?array $state,array $media):string
    {
        $newsletter=($p['kind']??'')==='edition';$brand=$newsletter?\Kulturhus\Native\Shared\MailDesign::brand(\Kulturhus\Native\Shared\Identity::forClub($this->db,$this->access->club())):[];
        $events=$newsletter&&$this->can('website','read')?\Kulturhus\Native\Communication\NewsletterEvents::catalogue($this->db,$this->access):null;
        if($state===null&&($p['id']??'')===''&&$events!==null&&!($p['blocks']??[]))$p['blocks']=array_slice($events,0,6);
        if($state===null)return Editor::blocks('blocks_json',$p['blocks']??[],$media,$this->can('website','edit'),interfaceText:$this->contentText(...),events:$events,brand:$brand);
        $raw=$state['document']['blocks_json'];
        try{return Editor::blocks('blocks_json',json_decode($raw,true,64,JSON_THROW_ON_ERROR),$media,$this->can('website','edit'),interfaceText:$this->contentText(...),events:$events,brand:$brand);}
        catch(\DomainException|\JsonException|\TypeError){return ('<label>' . Html::e($this->contentText('Inhaltsbausteine')) . '<textarea class="form-control" name="blocks_json" rows="6">').Html::e($raw).'</textarea></label>';}
    }
}
