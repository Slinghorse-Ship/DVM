<?php declare(strict_types=1);
// Only saved original values and references. Current document names never replace old evidence.
require_once dirname(__DIR__,3).'/src/Finance/Accounting/FinancialReserveReview.php';
try{$reserveYearReview=\Kulturhus\Verein\FinancialReserveReview::fromIntake($reserveRecord);}
catch(DomainException){$reserveYearReview=null;}
$reserveRecorded=(new DateTimeImmutable($reserveRecord['recorded_at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s T');
?>
<section data-reserve-source-original class="card">
<h3><?= e($bt('Gespeicherter Quellenstand')) ?></h3>
<p><?= e($reserveRecorded) ?></p>
<p><?= e($bt('Diese Quellenaufnahme bestätigt keine steuerliche Zulässigkeit und keine XML-Freigabe.')) ?></p>
<dl>
<dt><?= e($bt('Ursprünglicher Rücklagenbildner (Einzelunternehmer)')) ?></dt><dd><?= e($reserveRecord['original_taxpayer']) ?></dd>
<dt><?= e($bt('Originales Konto')) ?></dt><dd><?= e($reserveRecord['skr_code'].' · '.$reserveRecord['account']) ?></dd>
<dt><?= e($bt('Ursprüngliches Gewinnjahr')) ?></dt><dd><?= e((string)$reserveRecord['original']['sale_year']) ?></dd>
<?php foreach(['held_since'=>'Ursprünglicher Beginn der Betriebszugehörigkeit','sold_on'=>'Originalverkauf','acquired_on'=>'Ersatzkauf im Abschlussjahr']as$key=>$label): ?><dt><?= e($bt($label)) ?></dt><dd><?= e($reserveRecord['original'][$key]) ?></dd><?php endforeach ?>
<dt><?= e($bt('Steuerlicher Einbringungsstichtag')) ?></dt><dd><?= e($reserveRecord['transferred_on']) ?></dd>
<?php foreach($reserveLabels as$key=>$label): ?><dt><?= e($bt($label)) ?></dt><dd><?= e($money($reserveRecord['original'][$key.'_cents'])) ?></dd><?php endforeach ?>
<dt><?= e($bt('Übernommener ursprünglicher Rücklagenbetrag')) ?></dt><dd><?= e($money($reserveRecord['transferred_reserve_cents'])) ?></dd>
<dt><?= e($bt('Frist für Ersatzanteile')) ?></dt><dd><?= e($reserveRecord['calculation']['original_share_reinvestment_deadline']) ?></dd>
<?php if($reserveYearReview): ?>
<dt><?= e($bt('Frist für Ersatzgebäude')) ?></dt><dd><?= e($reserveYearReview['calendar']['building_reinvestment_deadline']) ?></dd>
<dt><?= e($bt('Verbleibende Rücklage spätestens auflösen zum')) ?></dt><dd><?= e($reserveYearReview['calendar']['mandatory_release_on']) ?></dd>
<?php endif ?>
<dt><?= e($bt('Ursprünglich beabsichtigte Ersatzbasis')) ?></dt><dd><?= e($money($reserveRecord['calculation']['tax_basis_cents'])) ?></dd>
<dt><?= e($bt('Rechnerischer Rest bei beabsichtigter Verwendung')) ?></dt><dd><?= e($money($reserveRecord['calculation']['reserve_closing_cents'])) ?></dd>
</dl>
<?php if($reserveYearReview): ?>
<p data-reserve-purchase-scope="<?= e($reserveYearReview['intended_purchase_scope']) ?>"><?= e($bt($reserveYearReview['intended_purchase_scope']==='excluded_corporate_purchase_after_transfer'
    ?'Für den erfassten Neukauf nach dem Einbringungsstichtag darf die GmbH die persönliche Rücklage nicht nach § 6b Absatz 10 auf die Ersatzanteile übertragen. Daraus werden keine Steuerbuchungen abgeleitet.'
    :'Ersatzkauf und steuerlicher Einbringungsstichtag liegen am selben Tag. Die tatsächliche Reihenfolge ist gesondert zu prüfen; daraus werden keine Steuerbuchungen abgeleitet.')) ?></p>
<?php if($reserveYearReview['intended_purchase_scope']==='excluded_corporate_purchase_after_transfer'): ?>
<dl data-reserve-excluded-purchase-values>
<dt><?= e($bt('Erfasste Anschaffungskosten ohne diesen Abzug, vor weiteren Wertänderungen')) ?></dt><dd><?= e($money($reserveYearReview['purchase_cost_without_intended_reduction_cents'])) ?></dd>
<dt><?= e($bt('Hier nicht anwendbarer beabsichtigter Abzug')) ?></dt><dd><?= e($money($reserveYearReview['excluded_intended_reduction_cents'])) ?></dd>
</dl>
<?php endif ?>
<p data-reserve-review-note><?= e($bt('Die ursprünglichen Rechenansätze sind keine bestätigten Steuer- oder Jahresendwerte. Weitere Jahresbewegungen und ein möglicher Gewinnzuschlag müssen gesondert geprüft werden.')) ?></p>
<details data-reserve-year-review><summary><?= e($bt('Fristen der Folgejahre')) ?></summary>
<p><?= e($bt('Die Fristen beziehen sich auf die Ursprungsrücklage.')) ?></p>
<ul><?php foreach($reserveYearReview['calendar_rows']as$reserveCalendar):
    $reserveYearLabel=match(true){
        $reserveCalendar['year']===$reserveCalendar['sale_year']=>'Bildungsjahr',
        $reserveCalendar['year']>(int)substr($reserveCalendar['mandatory_release_on'],0,4)=>'Auflösungsfrist abgelaufen',
        $reserveCalendar['mandatory_release_due']=>'Letztes Jahr für Ersatzgebäude; Rest zum Jahresende auflösen',
        !$reserveCalendar['share_reinvestment_in_year']=>'Anteilsfrist abgelaufen; Gebäudefrist läuft',
        $reserveCalendar['share_years_remaining']===0=>'Letztes Jahr für Ersatzanteile',
        default=>'Anteils- und Gebäudefrist laufen',
    }; ?>
<li><strong><?= (int)$reserveCalendar['year'] ?></strong> · <?= e($bt($reserveYearLabel)) ?></li>
<?php endforeach ?></ul>
</details>
<?php else: ?><p role="alert" data-reserve-review-unavailable><?= e($bt('Fristen können mit diesen Angaben nicht geprüft werden.')) ?></p><?php endif ?>
<details><summary><?= e($bt('Bei der Aufnahme geprüfte Voraussetzungen')) ?></summary><dl>
<?php foreach($reserveChecks as$key=>$label): ?><dt><?= e($bt($label)) ?></dt><dd><?= e($bt(($reserveRecord['original'][$key]??$reserveRecord['confirmations'][$key]??false)===true?'Bei Aufnahme bestätigt':'Nicht bestätigt')) ?></dd><?php endforeach ?></dl></details>
<details><summary><?= e($bt('Gebundene Belegfassungen')) ?></summary>
<?php foreach(['original'=>'Originalbildung und Verkauf','transfer'=>'Buchwerteinbringung','continuity'=>'Rücklagenfortführung und Restbetrag','election'=>'Ersatzkauf und beabsichtigte Wahl']as$key=>$label):$reserveReference=$reserveRecord['documents'][$key]; ?>
<article><h4><?= e($bt($label)) ?></h4><p><?= e($bt('Belegfassung')) ?> <?= (int)$reserveReference['revision'] ?></p><p><code><?= e($reserveReference['sha256']) ?></code></p><a class="btn btn-outline" href="<?= e(url(['document_file'=>$reserveReference['document_id'],'revision'=>(string)$reserveReference['revision']])) ?>"><?= e($bt('Diese ursprüngliche Belegfassung lesen')) ?></a></article>
<?php endforeach ?></details>
</section>
