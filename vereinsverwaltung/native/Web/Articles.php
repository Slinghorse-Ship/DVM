<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Cms\{Articles as ArticleStore, Conflict, Media, TemplatePolicy};
use Kulturhus\Native\Shared\Access;
use PDO;

/** News editor and public view. The caller supplies the site shell and routes. */
final class Articles
{
    use \Kulturhus\Native\Web\ContentInterfaceText;
    private array $listFilters=[];
    private readonly ArticleStore $articles;
    private readonly Media $media;
    private readonly ContentModel $model;
    private readonly PublicationPlan $planning;
    private readonly Website\PageChildrenPanel $children;
    private readonly Website\PageHierarchyPanel $hierarchy;
    private readonly Website\PageVersionsPanel $versions;

    public function __construct(private readonly PDO $db, private readonly Access $access, private readonly string $root, private readonly string $csrf = '')
    {
        $this->articles = new ArticleStore($db, $access);
        $this->versions = new Website\PageVersionsPanel($db,$access,$csrf);
        $this->children = new Website\PageChildrenPanel($db, $access);
        $this->hierarchy = new Website\PageHierarchyPanel($db, $access, $csrf);
        $this->media = new Media($db, $access, $root);
        $this->model = new ContentModel($db, $access, $csrf);
        $this->planning = new PublicationPlan($db,$access,$csrf);
    }

    public function action(array $post, array $files = []): string
    {
        if ($this->csrf === '' || !is_string($post['csrf'] ?? null) || !hash_equals($this->csrf, $post['csrf'])) throw new DomainException('Formular abgelaufen. Bitte Seite neu laden.');
        $op = $this->string($post, 'operation');
        if (!in_array($op, ['article-save', 'article-publish', 'article-unpublish', 'article-restore', 'article-upload','article-archive','article-unarchive','article-plan','article-cancel-plan','article-review'], true)) throw new DomainException('Unbekannte Beitragsaktion.');
        $publishing=in_array($op, ['article-publish', 'article-unpublish','article-archive','article-plan','article-cancel-plan'], true)
            || ($op==='article-review' && $this->string($post,'review_status')==='approved');
        $this->access->require('website', $publishing ? 'publish' : 'edit');
        $id = $this->string($post, 'id');
        $version = filter_var($post['version'] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version === null || $version < 0) throw new InvalidArgumentException('Gültigen Versionsstand angeben.');
        if($id!=='')TemplatePolicy::assertCapability($this->db,$this->access,$id,$publishing?'publish':'edit');
        if ($op === 'article-save') {
            $slug = $this->string($post, 'slug');
            if (!str_starts_with($slug, 'aktuelles/')) $slug = 'aktuelles/' . $slug;
            $input = ['id' => $id, 'title' => $this->string($post, 'title'), 'slug' => $slug, 'news_date' => $this->string($post, 'news_date'), 'excerpt' => $this->string($post, 'excerpt'), 'body_html' => $this->string($post, 'body_html'), 'body' => '', 'seo_title' => $this->string($post, 'seo_title'), 'seo_description' => $this->string($post, 'seo_description')];
            $images = $post['media_ids'] ?? [];
            if (!is_array($images) || !array_is_list($images) || count($images) > 100) throw new InvalidArgumentException('Bilder als Liste angeben.');
            foreach ($images as $image) if (!is_string($image)) throw new InvalidArgumentException('Ungültige Bildauswahl.');
            $cover = $this->string($post, 'cover_id');
            if ($cover !== '') array_unshift($images, $cover);
            $input['media_ids'] = array_values(array_unique($images));
            $values = $post['custom_fields'] ?? [];
            if (!is_array($values)) throw new InvalidArgumentException('Ungültige Zusatzfelder.');
            $input['custom_fields'] = $this->model->values('news', $values,$id!==''?$this->articles->get($id)['field_definitions']??null:null);
            $channels = \Kulturhus\Native\Web\Website\NewsChannelFields::fromPost($post);
            if ($channels !== null) $input['news_channels'] = $channels;
            return $this->articles->save($input, $version)['id'];
        }
        $item = $this->articles->get($id);
        if ($item['version'] !== $version) throw new Conflict('Beitrag wurde inzwischen geändert. Bitte neu laden.');
        if(in_array($op,['article-plan','article-cancel-plan'],true)){$this->planning->action($post,$item,$op==='article-cancel-plan');return $id;}
        if ($op === 'article-review') $this->articles->review($id, $this->string($post, 'review_status'), $version);
        elseif ($op === 'article-publish') $this->articles->publish($id, $version);
        elseif ($op === 'article-unpublish') $this->articles->unpublish($id, $version);
        elseif ($op === 'article-archive') $this->articles->archive($id, $version);
        elseif ($op === 'article-unarchive') $this->articles->unarchive($id, $version);
        elseif ($op === 'article-restore') {
            $revision = filter_var($post['revision'] ?? null, FILTER_VALIDATE_INT);
            if (!$revision || $revision < 1) throw new InvalidArgumentException('Gültige Version wählen.');
            $this->articles->restore($id, $revision, $version);
        } else {
            if (count($item['media_ids']) >= 100) throw new DomainException('Ein Beitrag kann höchstens 100 Bilder enthalten.');
            $file = $files['image'] ?? [];
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name'] ?? null)) throw new DomainException('Bild konnte nicht hochgeladen werden.');
            $alt = $this->string($post, 'alt');
            $this->media->upload($file['tmp_name'], $file['name'], $alt, null, function(array $asset)use($item,$version):void{
                $item['media_ids'][] = $asset['id'];
                $item['gallery_items'][] = ['id' => $asset['id'], 'caption' => $asset['alt'], 'tags' => []];
                $this->articles->save($item, $version);
            });
        }
        return $id;
    }

    public function manager(string $id = '', ?array $filters = null): string
    {
        $filters ??= $_GET;
        $this->listFilters=\Kulturhus\Native\Cms\ArticleCatalogue::filters($filters);
        $edit = $this->can('edit');
        $e = Html::e(...);
        $h = ('<div class="news-manager"><div class="news-manager-heading"><p class="intro">' . Html::e($this->contentText('Geschichten, Rückblicke und Neuigkeiten aus deinem Verein. Entwürfe werden erst nach Freigabe öffentlich.')) . '</p>') . ($edit ? '<a class="btn btn-solid theme-primary button" data-nav href="'.Html::e($this->listUrl('new')).('">' . Html::e($this->contentText('+ Neuer Beitrag')) . '</a>') : '') . '</div>';
        if ($id === '') {
            $query=$this->string($filters,'q');$status=$this->string($filters,'status')?:'active';$year=$this->string($filters,'year');
            if($year!==''&&!preg_match('/^(?:19|20|21)[0-9]{2}$/D',$year))throw new InvalidArgumentException('Ungültiges Beitragsjahr.');
            $result=(new \Kulturhus\Native\Cms\ArticleCatalogue($this->db,$this->access))->listing($filters);
            $items=$result['items'];$filters=$result['filters'];$this->listFilters=$filters;$query=$filters['q'];$status=$filters['status'];$year=$filters['year'];
            $states=['active'=>$this->contentText('Aktive Beiträge'),'draft'=>$this->contentText('Entwürfe'),'review'=>$this->contentText('Zur Prüfung'),'approved'=>$this->contentText('Freigegeben'),'published'=>$this->contentText('Veröffentlicht'),'archive'=>'Archiv','all'=>$this->contentText('Alle Beiträge')];
            $h.='<nav class="content-status-tabs" aria-label="'.Html::e($this->contentText('Beitragsstatus')).'">';
            foreach(['active','draft','review','approved','archive'] as $state)$h.='<a data-nav href="'.$e('/?'.http_build_query(['view'=>'news','status'=>$state,'q'=>$query,'year'=>$year])).'"'.($status===$state?' aria-current="page"':'').'>'.Html::e($this->contentText($states[$state])).'</a>';
            $h.='</nav><form method="get" action="/" class="content-list-filters">'.Html::hidden('view','news').Html::input('q',$this->contentText('Beiträge suchen'),$query,'search','maxlength="120"').Html::select('status',$this->contentText('Status'),$this->contentOptions($states),$status).Html::input('year',$this->contentText('Jahr'),$year,'number','min="1900" max="2199"').('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Suchen')) . '</button><a data-nav href="/?view=news">' . Html::e($this->contentText('Zurücksetzen')) . '</a></form><p class="content-list-count">').$result['total'].(' ' . Html::e($this->contentText('Beiträge')) . '</p><div class="content-table-wrap"><table class="table content-management-table"><thead><tr><th scope="col">' . Html::e($this->contentText('Titel')) . '</th><th scope="col">' . Html::e($this->contentText('Datum')) . '</th><th scope="col">' . Html::e($this->contentText('Status')) . '</th><th scope="col">' . Html::e($this->contentText('Aktionen')) . '</th></tr></thead><tbody>');
            foreach ($items as $item) {
                $rowEdit=$this->can('edit',$item['id']);
                $h.='<tr data-news-row="'.$e($item['id']).('"><td data-label="' . Html::e($this->contentText('Titel')) . '"><a data-nav href="').$e($this->listUrl($item['id'])).'">'.$e($item['title']).'</a><small>/'.$e($item['slug']).('/</small></td><td data-label="' . Html::e($this->contentText('Datum')) . '">').$this->time($item['news_date']).('</td><td data-label="' . Html::e($this->contentText('Status')) . '"><span class="badge">').$this->status($item).'</span>'.$this->planning->summary($item).('</td><td data-label="' . Html::e($this->contentText('Aktionen')) . '"><div class="content-row-actions"><a data-nav href="').$e($this->listUrl($item['id'])).'">'.($rowEdit?Html::e($this->contentText('Bearbeiten')):Html::e($this->contentText('Ansehen'))).'</a>';
                if($item['published_slug'] && $this->articles->publicArticle($item['published_slug']))$h.='<a href="/site/'.$e($item['published_slug']).('" target="_blank" rel="noopener">' . Html::e($this->contentText('Website ↗')) . '</a>');
                if($item['archived']&&$rowEdit)$h.=$this->form('article-unarchive',$item).('<button class="btn btn-outline theme-secondary secondary">' . Html::e($this->contentText('Zurückholen')) . '</button></form>');
                elseif(!$item['archived']&&$this->can('publish',$item['id']))$h.=$this->form('article-archive',$item).'<button class="btn btn-outline theme-secondary secondary">'.($item['published_revision']?'Archivieren &amp; offline nehmen':'Archivieren').'</button></form>';
                $h.='</div></td></tr>';
            }
            if(!$items)$h.=('<tr><td colspan="4">' . Html::e($this->contentText('Keine Beiträge für diese Auswahl.')) . '</td></tr>');
            return $h . '</tbody></table></div>'.$this->pagination($result).('<p class="help">' . Html::e($this->contentText('Archivieren nimmt den Beitrag von der Website. Zurückholen legt ihn wieder als Entwurf zur Bearbeitung bereit.')) . '</p></div>');
        }
        $new = $id === 'new';
        if ($new && !$edit) throw new DomainException('Keine Berechtigung zum Anlegen von Beiträgen.');
        $item = $new ? ['id' => '', 'version' => 0, 'title' => '', 'slug' => '', 'news_date' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'), 'excerpt' => '', 'body' => '', 'media_ids' => [], 'seo_title' => '', 'seo_description' => '', 'custom_fields' => [], 'published_revision' => null] : $this->articles->get($id);
        if(!$new)$edit=$this->can('edit',$id);
        $h .= '<a class="back-link" data-nav href="'.$e($this->listUrl()).('">' . Html::e($this->contentText('← Alle Beiträge')) . '</a>').(!$new?('<a class="btn btn-outline theme-secondary news-review-jump" href="#news-review-panel">' . Html::e($this->contentText('Prüfung & Freigabe')) . '</a>'):'').'<div class="news-editor-layout"><div class="panel"><h2>' . ($new ? Html::e($this->contentText('Neuer Beitrag')) : ($edit ? Html::e($this->contentText('Beitrag bearbeiten')) : Html::e($this->contentText('Beitrag ansehen')))) . '</h2>';
        if ($edit) {
            $media = $this->media->list();
            $choices = ['' => $this->contentText('Ohne Titelbild')];
            foreach ($media as $m) $choices[$m['id']] = $m['alt'] ?: $m['original_name'];
            $h .= $this->form('article-save', $item) . '<div class="fields">' . Html::input('title', $this->contentText('Titel'), $item['title'], 'text', 'required maxlength="200"') . Html::input('slug', $this->contentText('Adresse unter /aktuelles/'), preg_replace('~^aktuelles/~', '', $item['slug']), 'text', 'required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="170"') . Html::input('news_date', $this->contentText('Beitragsdatum'), $item['news_date'], 'date', 'required') . ('</div><p class="help">' . Html::e($this->contentText('Das Beitragsdatum bestimmt die Reihenfolge. Veröffentlicht wird erst über die Freigabe.')) . '</p>') . Html::input('excerpt', $this->contentText('Kurztext für die Übersicht'), $item['excerpt'], 'text', 'maxlength="1200"') . RichTextEditor::render('body_html', Html::content($item), $this->contentText('Beitragstext'), interfaceText:$this->contentText(...)) . $this->model->fields('news', $item['custom_fields'] ?? [],$new ? null : (new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->editorDefinitions($item['id'],'news'));
            $h .= \Kulturhus\Native\Web\Website\NewsChannelFields::render($item);
            $h .= ('<details><summary>' . Html::e($this->contentText('Bilder und Suchmaschinen')) . '</summary>') . Html::select('cover_id', 'Titelbild', $choices, $item['media_ids'][0] ?? '') . '<div class="media-picker">';
            foreach ($media as $m) $h .= '<label><img src="/media-preview/' . $e($m['id']) . '/thumb" alt="' . $e($m['alt']) . '" loading="lazy"><span><input class="check" type="checkbox" name="media_ids[]" value="' . $e($m['id']) . '"' . (in_array($m['id'], $item['media_ids'], true) ? ' checked' : '') . '> ' . $e($m['alt'] ?: $m['original_name']) . '</span></label>';
            $h .= '</div><div class="fields">' . Html::input('seo_title', 'SEO-Titel', $item['seo_title'], 'text', 'maxlength="200"') . Html::input('seo_description', 'SEO-Beschreibung', $item['seo_description'], 'text', 'maxlength="320"') . ('</div></details><button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Entwurf speichern')) . '</button></form>');
        } else {
            $h .= (new Website\NewsReadingView($this->db,$this->access,$this->root))->render($item['id'],$item['version']);
        }
        $h .= '</div><aside class="news-editor-side">';
        if($edit)$h.=Website\LivePagePreview::render($this->db,$this->access,$this->root,array_replace(['template'=>'news'],$item));
        $h .= ('<section class="panel"><h2>' . Html::e($this->contentText('Veröffentlichung')) . '</h2><p class="badge">') . $this->status($item) . '</p><p>Version ' . $e($item['version']) . '</p>';
        if (!$new) {
            if (!empty($item['archived'])) {
                if($edit)$h.=$this->form('article-unarchive',$item).('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Aus Archiv zurückholen')) . '</button></form>');
            } elseif ($this->can('publish',$id)) {
                $approval=(new \Kulturhus\Native\Cms\NewsPolicy($this->db,$this->access))->state($item['id'],$item);
                $h .= $this->form('article-publish', $item) . '<button class="btn btn-solid theme-primary"'.($approval['enabled']&&!$approval['valid']?' disabled':'').('>' . Html::e($this->contentText('Beitrag veröffentlichen')) . '</button></form>');
                if ($item['published_revision']) $h .= $this->form('article-unpublish', $item) . ('<button class="btn btn-outline theme-secondary secondary">' . Html::e($this->contentText('Beitrag offline nehmen')) . '</button></form>');
                $h.=$this->form('article-archive',$item).'<button class="btn btn-outline theme-secondary secondary">'.($item['published_revision']?'Archivieren &amp; offline nehmen':Html::e($this->contentText('Beitrag archivieren'))).'</button></form>';
            }
            if (($item['published_slug'] ?? '') && $this->articles->publicArticle($item['published_slug'])) $h .= '<p><a target="_blank" rel="noopener" href="/site/' . $e($item['published_slug']) . ('">' . Html::e($this->contentText('Veröffentlichten Beitrag ansehen ↗')) . '</a></p>');
            $h .= ('<p class="help">' . Html::e($this->contentText('Speichern verändert den Entwurf. Die bisherige Veröffentlichung bleibt bis zur nächsten Freigabe sichtbar.')) . '</p>');
        } else $h .= ('<p>' . Html::e($this->contentText('Zuerst den Entwurf speichern, danach veröffentlichen.')) . '</p>');
        $h .= '</section>';
        if (!$new) $h .= (new \Kulturhus\Native\Web\Website\NewsReviewPanel($this->db, $this->access, $this->csrf))->render($item, 'article');
        if(!$new)$h.=$this->planning->render($item,'article');
        if (!$new && $edit) $h .= ('<section class="panel"><h2>' . Html::e($this->contentText('Bild hinzufügen')) . '</h2>') . $this->form('article-upload', $item, true) . Html::input('alt', $this->contentText('Bildbeschreibung (optional)'), '', 'text', 'maxlength="500"') . ('<label>' . Html::e($this->contentText('Bilddatei')) . '<input class="form-control" type="file" name="image" accept="').\Kulturhus\Native\Cms\PhotoInput::accept().('" required></label><p class="help">' . Html::e($this->contentText('Fotos und Kameraoriginale · bis 256 MiB. Das Bild wird optimiert und zunächst nur dem Entwurf hinzugefügt.')) . '</p><button class="btn btn-outline theme-secondary secondary">' . Html::e($this->contentText('Bild hochladen')) . '</button></form></section>');
        if (!$new) $h .= ('<section class="panel"><h2>' . Html::e($this->contentText('Versionen')) . '</h2>') . $this->versions->render($item,'news',$edit?'article-restore':'',$this->contentText('Als Entwurf wiederherstellen')) . '</section>';
        if (!$new && $this->can('edit')) $h .= \Kulturhus\Native\Web\Website\PageCopyPanel::render($item, $this->csrf,(new \Kulturhus\Native\Cms\Pages($this->db,$this->access))->copyBases($item['id']));
        if (!$new) $h .= (new \Kulturhus\Native\Web\Website\PageFieldtypesPanel($this->db,$this->access,$this->csrf))->render($item);
        if (!$new && $edit) $h .= $this->hierarchy->render($item);
        if (!$new) $h .= $this->children->render($item);
        return $h . '</aside></div></div>';
    }

    /** Site already renders the article title/excerpt; this supplies date, image, body and links. */
    public function publicContent(?array $article = null, int $page = 1, ?PublishedLanguage $language=null, ?\Closure $pageLink=null,?string $listingPath=null): string
    {
        $this->access->require('website', 'read');
        $listPath=$listingPath??'/site/'.($language?->path('aktuelles')??'aktuelles');
        $pager=$pageLink??static fn(int $n):string=>$listPath.($n>1?'?page='.$n:'');
        if ($article !== null) {
            // Resolve the current published snapshot, never trust a draft DTO passed by a caller.
            $item = $this->articles->publicArticle((string) ($article['slug'] ?? ''));
            if($item!==null&&$language!==null)$item=$language->page($item);
            if (!$item) return '<p>Dieser Beitrag ist nicht veröffentlicht.</p>';
            $h = '<article class="news-article">' . $this->image($item, true, 'large') . '<div class="prose article-body kh-richtext">' . Html::content($item) . '</div>';
            foreach (array_slice($item['media_ids'], 1) as $id) {
                $copy = $item;
                $copy['media_ids'] = [$id];
                $h .= $this->image($copy, true, 'large');
            }
            return $h . '<p class="news-back"><a href="'.Html::e($listPath).'">← Alle Beiträge</a></p></article>';
        }
        if ($page < 1 || $page > 83334) throw new InvalidArgumentException('Ungültige Beitragsseite.');
        $items = $this->articles->publicList(12, ($page - 1) * 12);
        if($language!==null)$items=array_values(array_filter(array_map($language->page(...),$items)));
        $count = $this->articles->publicCount();
        if (!$items) return '<div class="news-empty"><p>Hier erscheinen bald Geschichten und Neuigkeiten aus deinem Verein.</p>' . ($page > 1 ? '<a href="'.Html::e($pager(1)).'">Zur ersten Seite</a>' : '') . '</div>';
        $h = '<div class="news-grid cards cards--news">';
        foreach ($items as $item) $h .= $this->card($item);
        $h .= '</div>';
        if ($count > 12) {
            $h .= '<nav class="news-pagination" aria-label="Beitragsseiten">';
            if ($page > 1) $h .= '<a href="'.Html::e($pager($page - 1)) . '">← Neuere Beiträge</a>';
            $h .= '<span>Seite ' . $page . ' von ' . (int) ceil($count / 12) . '</span>';
            if ($page * 12 < $count) $h .= '<a href="'.Html::e($pager($page + 1)) . '">Ältere Beiträge →</a>';
            $h .= '</nav>';
        }
        return $h;
    }

    public function latest(int $limit = 3, string $heading = "Was uns bewegt", ?PublishedLanguage $language=null): string
    {
        $items = $this->articles->publicList($limit);
        if($language!==null)$items=array_values(array_filter(array_map($language->page(...),$items)));
        $h = '<section class="section section--news" aria-labelledby="news-latest-title"><div class="section-heading"><div><p class="eyebrow">02 · Aktuelles</p><h2 id="news-latest-title">' . Html::e($heading) . '</h2></div><a class="text-link" href="/site/'.Html::e($language?->path('aktuelles')??'aktuelles').'">Alle Beiträge →</a></div>';
        if (!$items) $h .= '<p>Neue Geschichten aus deinem Verein erscheinen hier nach ihrer Veröffentlichung.</p>';
        else { $h .= '<div class="cards">'; foreach ($items as $item) $h .= $this->card($item, false); $h .= '</div>'; }
        return $h . '</section>';
    }

    private function card(array $item, bool $showImage = true): string
    {
        $e = Html::e(...);
        $href = '/site/' . $e(PublishedLanguage::pagePath($item));
        return '<article class="news-card card">' . ($showImage && $item['media_ids'] ? '<a class="news-card-picture" href="' . $href . '" tabindex="-1" aria-hidden="true">' . $this->image($item, true, 'medium') . '</a>' : '') . '<div class="news-card-copy"><p class="news-date">' . $this->time($item['news_date']) . '</p><h2><a href="' . $href . '">' . $e($item['title']) . '</a></h2><p>' . $e($item['excerpt']) . '</p><a class="news-readmore card__more text-link" href="' . $href . '" aria-label="' . $e($item['title']) . ' weiterlesen">Weiterlesen <span aria-hidden="true">→</span></a></div></article>';
    }

    private function image(array $item, bool $public, string $size): string
    {
        $id = $item['media_ids'][0] ?? null;
        if (!$id) return '';
        $media = $public ? $this->media->publicVariant($id, $size, $item) : $this->media->variant($id, $size);
        if (!$media) return '';
        $caption = $media['alt'];
        foreach ($item['gallery_items'] ?? [] as $entry) if ($entry['id'] === $id && $entry['caption'] !== '') $caption = $entry['caption'];
        return '<figure class="news-picture"><img src="/' . ($public ? 'media' : 'media-preview') . '/' . Html::e($id) . '/' . $size . '" alt="' . Html::e($caption) . '" width="' . Html::e($media['width']) . '" height="' . Html::e($media['height']) . '" loading="lazy" decoding="async">' . ($size === 'large' ? '<figcaption>' . Html::e($caption) . MediaCredit::render($media) . '</figcaption>' : '') . '</figure>';
    }

    private function status(array $item): string
    {
        if(!empty($item['archived']))return Html::e($this->contentText('Archiviert'));
        $review=$this->contentText(\Kulturhus\Native\Cms\NewsReview::LABELS[\Kulturhus\Native\Cms\NewsReview::status($item)]);
        if(empty($item['published_revision']))return Html::e($review).' / offline';
        $channels=[];
        foreach(['website'=>'Website','rss'=>'RSS-Feed']as$channel=>$label)if($this->articles->publicArticle($item['published_slug'],$channel))$channels[]=$label;
        return Html::e($channels?$this->contentFormat('Veröffentlicht · {channels}',['channels'=>implode(', ',$channels)]):$this->contentText('Freigegeben · kein öffentlicher Kanal'))
            .(!empty($item['has_unpublished_changes'])?' · '.Html::e($this->contentFormat('neuer Entwurf ({review})',['review'=>$review])):'');
    }

    private function listUrl(string $id=''):string
    {
        return '/?'.http_build_query(['view'=>'news']+($id!==''?['item'=>$id]:[])+$this->listFilters);
    }

    private function pagination(array $result):string
    {
        $page=$result['page'];$last=$result['last'];$filters=$result['filters'];
        $url=static fn(int $n):string=>'/?'.http_build_query(['view'=>'news']+array_replace($filters,['page'=>$n]));
        $h=('<nav class="news-pagination" aria-label="'.Html::e($this->contentText('Beitragsseiten')).'"><p>' . Html::e($this->contentText('Seite')) . ' ').$page.' '.Html::e($this->contentText('von')).' '.$last.'</p>';
        if($page>1)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url($page-1)).('">' . Html::e($this->contentText('Zurück')) . '</a>');
        if($page<$last)$h.='<a class="btn btn-outline theme-secondary" data-nav href="'.Html::e($url($page+1)).('">' . Html::e($this->contentText('Weiter')) . '</a>');
        $h.='<form method="get" action="/">'.Html::hidden('view','news').Html::hidden('q',$filters['q']).Html::hidden('status',$filters['status']).Html::hidden('year',$filters['year']).Html::input('page',$this->contentText('Seite öffnen'),(string)$page,'number','min="1" max="'.$last.'" required').('<button class="btn btn-solid theme-primary">' . Html::e($this->contentText('Öffnen')) . '</button></form></nav>');
        return $h;
    }

    private function time(string $date): string { return '<time datetime="' . Html::e($date) . '">' . Html::e($this->date($date)) . '</time>'; }
    private function date(string $date): string { return strlen($date) === 10 ? substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.' . substr($date, 0, 4) : $date; }
    private function can(string $cap, ?string $id=null): bool { try { $this->access->require('website', $cap); if($id!==null)TemplatePolicy::assertCapability($this->db,$this->access,$id,$cap); return true; } catch (DomainException) { return false; } }
    private function form(string $op, array $item, bool $upload = false): string { return '<form method="post"' . ($upload ? ' enctype="multipart/form-data"' : '') . '>' . Html::hidden('csrf', $this->csrf) . Html::hidden('operation', $op) . Html::hidden('id', $item['id']) . Html::hidden('version', $item['version']); }
    private function string(array $post, string $key): string { $value = $post[$key] ?? ''; if (!is_string($value)) throw new InvalidArgumentException('Ungültiger Formularwert: ' . $key); return $value; }
}
