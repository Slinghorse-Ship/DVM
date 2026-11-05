<?php declare(strict_types=1);
namespace Kulturhus\Native\Web;

use Kulturhus\Verein\{MembershipApplications,PublicMembershipForm,ContributionRates};

/** Website presentation of the manager's actual intake, with the original manager workflow. */
final class Membership
{
    public function __construct(private MembershipApplications $intake, private array $identity = []) {}

    public function render(array $post, string $csrf, bool $submitted, bool $local = true): string
    {
        $membershipState = PublicMembershipForm::handle($this->intake, $post, $csrf, $submitted, $local);
        ob_start();
        try { require dirname(__DIR__, 2).'/views/People/Members/application-fields.php'; $form = (string) ob_get_contents(); }
        finally { ob_end_clean(); }
        $e = Html::e(...);
        $clubName = trim((string)($this->identity['name'] ?? '')) ?: 'unseren Verein';
        $single = ContributionRates::label($membershipState['rates']['single']['amount_cents']);
        $family = ContributionRates::label($membershipState['rates']['family']['amount_cents']);
        return '<main id="main" class="membership-page"><header class="membership-hero"><p class="eyebrow">Gemeinsam aktiv werden</p><h1>Mitglied werden</h1><p class="lede">Mit deiner Mitgliedschaft unterstützt du '.$e($clubName).'. Bring deine Ideen ein und gestalte unsere Gemeinschaft mit.</p><a class="btn btn-solid theme-primary button member-jump" href="#mitgliedsantrag">Mitgliedsantrag ausfüllen ↓</a></header>'
            .'<section class="member-fees" aria-label="Mitgliedsbeiträge"><article class="member-fee"><span>Einzelmitgliedschaft</span><strong>'.$e($single).'</strong><small>pro Jahr</small></article><article class="member-fee"><span>Familienmitgliedschaft</span><strong>'.$e($family).'</strong><small>pro Jahr für die gesamte Familie</small></article></section>'
            .'<section class="section"><div class="section-heading"><p class="eyebrow">Gemeinsam möglich machen</p><h2>Warum Mitglied werden?</h2></div><div class="member-benefits"><article class="member-benefit"><b>1</b><h2>Vereinsarbeit stärken</h2><p>Du unterstützt unsere Angebote und gemeinsamen Aktivitäten.</p></article><article class="member-benefit"><b>2</b><h2>Mitgestalten</h2><p>Als Mitglied kannst du Ideen einbringen und das Vereinsleben aktiv mitprägen.</p></article><article class="member-benefit"><b>3</b><h2>Gemeinschaft erleben</h2><p>Du wirst Teil unserer Gemeinschaft und lernst andere Mitglieder kennen.</p></article></div></section>'
            .'<section class="section section--soft"><div class="section-heading"><p class="eyebrow">Einfach und transparent</p><h2>So läuft der Beitritt</h2></div><div class="member-steps"><article class="member-step"><h3>Antrag ausfüllen</h3><p>Fülle den vereinseigenen Mitgliedsantrag direkt auf dieser Seite aus.</p></article><article class="member-step"><h3>Per E-Mail bestätigen</h3><p>Öffne die Bestätigungsmail und bestätige deinen Antrag über den Link. Erst danach wird er dem Vorstand vorgelegt.</p></article><article class="member-step"><h3>Prüfung durch den Vorstand</h3><p>Der Vorstand prüft den Antrag. Die Mitgliedschaft beginnt nach der Bestätigung gemäß Satzung.</p></article></div></section>'
            .'<section class="section member-application"><div class="section-heading"><p class="eyebrow">Dabei sein</p><h2>Dein Antrag. Unser nächstes Kapitel.</h2></div><div id="mitgliedsantrag">'.$form.'</div><p class="member-legal">Hinweise zur Datenverarbeitung findest du in unserer <a href="/datenschutz/">Datenschutzerklärung</a>.</p></section></main>';
    }
}
