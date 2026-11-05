<?php
declare(strict_types=1);

namespace Kulturhus\Native\Web\Website;

use DomainException;
use Kulturhus\Native\Cms\{NewsChannels, NewsReview, NewsPolicy, TemplatePolicy};
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Web\Html;

/** Shared by the typed article editor and the general page editor. */
final class NewsReviewPanel
{
    public function __construct(
        private readonly \PDO $db,
        private readonly Access $access,
        private readonly string $csrf,
    ) {}

    public function render(array $page, string $prefix): string
    {
        if (!NewsChannels::isNews($page) || empty($page["id"])) {
            return "";
        }
        $state = NewsReview::status($page);
        $policy = (new NewsPolicy($this->db,$this->access))->state($page['id'],$page);
        $html =
            '<section id="news-review-panel" tabindex="-1" class="panel news-review-panel" aria-label="Redaktionelle Prüfung"><h2>Redaktionelle Prüfung</h2><p data-news-review="' .
            Html::e($state) .
            '">Redaktionsstand: <strong>' .
            Html::e(NewsReview::LABELS[$state]) .
            "</strong></p>";
        if ($policy['enabled']) {
            $html .= '<p data-four-eyes="'.($policy['valid']?'approved':'required').'">'.($policy['valid']?'Vier-Augen-Freigabe liegt vor.':($policy['self']?'Wartet auf Freigabe durch eine andere Person.':'Vier-Augen-Freigabe erforderlich.')).'</p>';
        }
        if (empty($page["archived"])) {
            foreach (
                [
                    "draft" => "Zurück zum Entwurf",
                    "review" => "Zur Prüfung geben",
                    "approved" => "Redaktionell freigeben",
                ]
                as $target => $label
            ) {
                if (
                    ($target === $state && !($target === 'approved' && $policy['enabled'] && !$policy['valid'])) ||
                    ($target === 'approved' && $policy['enabled'] && $policy['self']) ||
                    !$this->can($target === "approved" ? "publish" : "edit",$page["id"])
                ) {
                    continue;
                }
                $html .=
                    '<form method="post">' .
                    Html::hidden("csrf", $this->csrf) .
                    Html::hidden("operation", $prefix . "-review") .
                    Html::hidden("id", $page["id"]) .
                    Html::hidden("version", $page["version"]) .
                    Html::hidden("review_status", $target) .
                    '<button class="btn btn-outline theme-secondary secondary">' .
                    Html::e($target === 'approved' && $state === 'approved' ? 'Erneut freigeben' : $label) .
                    "</button></form>";
            }
        }
        return $html . "</section>";
    }

    private function can(string $capability,string $id): bool
    {
        try {
            $this->access->require("website", $capability);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,$capability);
            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
