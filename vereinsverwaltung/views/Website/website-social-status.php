<?php declare(strict_types=1);
$storyIssues = [];
foreach (($native['story_connection_status'] ?? []) as $state) {
    if (($state['state'] ?? '') !== 'published') $storyIssues[] = ['title'=>'Verbindung', 'id'=>0] + $state;
}
foreach (($native['rows'] ?? []) as $socialEvent) {
    if ($nativeId && $nativeId !== (int)$socialEvent['id']) continue;
    foreach (($socialEvent['story_status'] ?? []) as $state) {
        if (($state['state'] ?? '') !== 'published') $storyIssues[] = ['title'=>$socialEvent['title'], 'id'=>(int)$socialEvent['id']] + $state;
    }
}
?>
<?php if ($storyIssues): ?>
<section class="card" aria-labelledby="story-issues-heading">
<h2 id="story-issues-heading">Story-Veröffentlichung prüfen</h2>
<p>Für diese Versuche fehlt eine bestätigte Veröffentlichung. Ein späterer erfolgreicher Prüflauf allein bedeutet nicht, dass die Story veröffentlicht wurde.</p>
<ul>
<?php foreach ($storyIssues as $storyIssue): ?>
<li><strong><?= e($storyIssue['platform'].' · '.$storyIssue['title'].' · '.$storyIssue['label']) ?></strong><br>
<?= e(date('d.m.Y. H:i', (int)$storyIssue['checked_at'])) ?> · <?= e($storyIssue['state']==='failed'?'Nicht veröffentlicht':'Veröffentlichung nicht bestätigt') ?><br>
<?= e((string)($storyIssue['message'] ?? '')) ?>
<?php if (!$nativeId && $storyIssue['id']): ?> <a href="<?= e($link(['item'=>$storyIssue['id']])) ?>">Veranstaltung prüfen</a><?php endif ?>
</li>
<?php endforeach ?>
</ul>
</section>
<?php endif ?>
