<?php declare(strict_types=1); ?>
<section class="card" id="newsletter-revisions"><h2>Versionsverlauf</h2>
<?php if(empty($native['revisions'])): ?><p>Mit dem nächsten Speichern wird die bisherige Fassung gesichert.</p>
<?php else: ?><p>Frühere Fassungen ansehen oder als Entwurf wiederherstellen. Dabei wird keine Mail versendet.</p>
<?php foreach($native['revisions'] as $revision):$kind=['saved'=>'Gespeichert','automatic'=>'Von der Automatik angelegt','before_save'=>'Vor dem Speichern','deleted'=>'Gelöscht','before_restore'=>'Vor der Wiederherstellung','restored'=>'Wiederhergestellt','backup'=>'Aus Sicherung wiedergefunden','baseline'=>'Bisheriger Stand'][$revision['kind']]??'Gesichert'; ?>
<details><summary><?= e($revision['saved_at'].' · '.$kind.' · '.$revision['actor_name']) ?></summary>
<?php if($revision['source_reference']!==''): ?><p><?= e($revision['source_reference']) ?></p><?php endif ?>
<h3><?= e($revision['subject']) ?></h3><div class="native-pre"><?= e(html_entity_decode(strip_tags(str_replace(['</p>','<br>','<br />'],["\n\n","\n","\n"],$revision['body'])),ENT_QUOTES|ENT_HTML5,'UTF-8')) ?></div>
<?php if(in_array($item['status'],['draft','deleted'],true)):$simple('newsletter/restore','Diese Fassung wiederherstellen',['id'=>$item['id'],'revision'=>$revision['id']],$item['version']);endif ?>
</details><?php endforeach;endif ?></section>
