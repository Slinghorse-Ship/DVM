<?php declare(strict_types=1);
$tutorials=json_decode((string)file_get_contents(__DIR__.'/../../resources/handbook-tutorials.json'),true,512,JSON_THROW_ON_ERROR);
$mediaUrl=static fn(string $name):string=>'?view=handbook&handbook_media='.rawurlencode($name);
?>
<details class="handbook-video-library" id="hilfe-videos"><summary>Videoanleitungen · <?= count($tutorials) ?> Themen</summary>
<p>Gesprochene Schrittanleitungen im Whiteboard-Stil mit echten Programmausschnitten und ausschließlich erfundenen Beispielen. Die Videos erklären die Bedienung; sie führen keine Aktion in deinem Konto aus. Starte mit ▶. Pausiere zum Mitmachen. Über CC lassen sich deutsche Untertitel einblenden, über das Vollbildsymbol wird die Aufnahme größer.</p>
<p>Die Stimme ist computergesprochen. Der vollständige Text steht unter jedem Video. Auf einem kleinen Smartphone hilft das Vollbild im Querformat; einzelne Felder kannst du außerdem in der Bildanleitung vergrößern. Für weitere Einzelaufgaben folge dem Link zum Kapitel.</p>
<?php foreach($tutorials as $tutorial): ?>
<details class="handbook-video-card" id="video-<?= e($tutorial['id']) ?>"><summary><?= e($tutorial['title']) ?> <span><?= (int)floor($tutorial['duration']/60) ?>:<?= str_pad((string)((int)$tutorial['duration']%60),2,'0',STR_PAD_LEFT) ?> Minuten</span></summary>
<p><strong>Menüweg:</strong> <?= e($tutorial['path']) ?> · <a href="#kapitel-<?= (int)$tutorial['chapter'] ?>">Ausführliche Schrittanleitung in Kapitel <?= (int)$tutorial['chapter'] ?></a></p>
<video controls preload="none" playsinline poster="<?= e($mediaUrl($tutorial['poster'])) ?>" aria-label="<?= e($tutorial['title']) ?>">
<source src="<?= e($mediaUrl($tutorial['file'])) ?>" type="video/mp4">
<track kind="subtitles" src="<?= e($mediaUrl($tutorial['subtitles'])) ?>" srclang="de" label="Deutsch" default>
Dein Browser kann dieses Video nicht abspielen. <a href="<?= e($mediaUrl($tutorial['file'])) ?>">Video öffnen</a> oder den Text darunter lesen.
</video>
<ol class="handbook-video-chapters" aria-label="Videokapitel">
<?php foreach($tutorial['scenes'] as $scene): ?><li><button type="button" class="btn btn-outline theme-secondary secondary" data-video-seek="<?= e((string)$scene['start']) ?>"><?= (int)floor($scene['start']/60) ?>:<?= str_pad((string)((int)$scene['start']%60),2,'0',STR_PAD_LEFT) ?> · <?= e($scene['title']) ?></button></li><?php endforeach ?>
</ol>
<details class="handbook-transcript"><summary>Gesamten Videotext lesen</summary><?php foreach($tutorial['scenes'] as $scene): ?><h3><?= e($scene['title']) ?></h3><p><?= e($scene['text']) ?></p><?php endforeach ?></details>
</details>
<?php endforeach ?></details>
