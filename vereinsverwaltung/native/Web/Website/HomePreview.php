<?php declare(strict_types=1);
namespace Kulturhus\Native\Web\Website;
use Kulturhus\Native\Cms\{Pages, Media};
use Kulturhus\Native\Shared\{Access, Identity};
use Kulturhus\Native\Web\{Html, Articles, PublicLayout};
use PDO;
use DomainException;

/** Uncached private draft; ancillary events/news/navigation remain actually published originals. */
final class HomePreview
{
    public function __construct(
        private PDO $db,
        private Access $access,
        private string $mediaRoot,
        private string $managerUrl = "/?view=website",
        private ?\Closure $mediaUrl = null,
    ) {}
    public function render(string $id): string
    {
        $this->access->require("website", "read");
        if (!preg_match("/\A[a-f0-9]{32}\z/D", $id)) {
            throw new DomainException("Startseite nicht verfügbar.");
        }
        $pages = new Pages($this->db, $this->access);
        try {
            $page = $pages->get($id);
        } catch (\RuntimeException $e) {
            throw new DomainException("Startseite nicht verfügbar.", 0, $e);
        }
        if ($page["slug"] !== "start") {
            throw new DomainException(
                "Startseitenvorschau nur für die Startseite verfügbar.",
            );
        }
        $media = new Media($this->db, $this->access, $this->mediaRoot);
        $identity = Identity::forClub($this->db, $this->access->club());
        $identity['website_design']=\Kulturhus\Native\Cms\WebsiteDesign::forClub($this->db,$this->access->club())['template'];
        $body =
            '<div class="prose kh-richtext">' .
            Html::content($page) .
            "</div>" .
            (new ContentSectionsView($media))->render($page,true,$this->mediaUrl) .
            (new PublishedFields($pages, $media))->render($page);
        $main =
            '<aside class="section" role="status"><strong>Private Startseitenvorschau · Version ' .
            Html::e($page["version"]) .
            "</strong><p>Dieser gespeicherte Entwurf ist noch keine Veröffentlichung. Veranstaltungen, Beiträge und Menü stammen aus den veröffentlichten Fassungen.</p></aside>" .
            (new HomePage(
                $pages,
                $media,
                new Articles($this->db, $this->access, $this->mediaRoot),
                $identity,
            ))->render($page, $body);
        return PublicLayout::render(
            $page["seo_title"] ?: $page["title"],
            $page["seo_description"] ?: $page["excerpt"],
            $main,
            $pages->navigation(),
            "start",
            $identity["origin"],
            $this->managerUrl,
            $identity,
            true,
            $pages->footerNavigation(),
        );
    }
}
