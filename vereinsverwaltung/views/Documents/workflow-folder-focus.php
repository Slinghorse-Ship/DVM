<?php declare(strict_types=1);
$wfSelectedFolder=$wfPrepared['selected_folder'];
if($wfMode!=='documents'||$wfDoc!==null||$wfNewDocument||$wfSelectedFolder===null)return;
$wfFolderEditPost=$wfPosted('document-workflow-folder-save',$wfSelectedFolder['id']);
?>
<section class="dwf-card dwf-folder-focus">
<div class="dwf-section-heading"><h2><?= $wfEsc($wfSelectedFolder['name']) ?></h2><?php if($wfWrite&&!$wfPrepared['folder_edit']):?><a class="dwf-button dwf-secondary" href="<?= $wfEsc($wfUrl(['wf_folder'=>$wfSelectedFolder['id'],'wf_folder_edit'=>1])) ?>"><?= $wfEsc($wfI('Ordner bearbeiten')) ?></a><?php endif ?></div>
<?php if($wfFolderTree->ambiguous($wfSelectedFolder['id'])):?><p class="dwf-help"><?= $wfEsc($wfI('Ordnerkennung')) ?>: <code class="dwf-folder-reference"><?= $wfEsc($wfSelectedFolder['id']) ?></code></p><?php endif ?>
<details class="dwf-folder-path"><summary><?= $wfEsc($wfI('Vollständigen Ordnerpfad anzeigen')) ?></summary>
<?php if($wfPrepared['folder_path']['issue']!==''):?><p class="dwf-alert"><?= $wfEsc($wfI('Der ursprüngliche Ordnerpfad ist nicht vollständig. Eine bewusste neue Zuordnung kann ihn korrigieren.')) ?></p><?php endif ?>
<ol><?php foreach($wfPrepared['folder_path']['rows']as$wfAncestor):?><li><a href="<?= $wfEsc($wfUrl(['wf_folder'=>$wfAncestor['id'],'wf_page'=>1])) ?>"><?= $wfEsc($wfAncestor['name']) ?></a></li><?php endforeach ?></ol>
</details>
<?php if($wfPrepared['folder_edit']&&$wfWrite):?>
<form method="post" class="dwf-folder-edit" data-dwf-edit-form action="<?= $wfEsc($wfUrl(['wf_folder'=>$wfSelectedFolder['id'],'wf_folder_edit'=>1])) ?>">
<?php $wfForm('document-workflow-folder-save',$wfSelectedFolder['id'],(int)$wfSelectedFolder['version']); ?>
<p><?= $wfEsc($wfI('Du bearbeitest nur den Arbeitsordner. Zugeordnete Dokumentfassungen und ihre ursprünglichen Inhalte bleiben erhalten.')) ?></p>
<label><?= $wfEsc($wfI('Name')) ?><textarea class="form-control" name="wf_name" rows="2" required><?= $wfEsc($wfFolderEditPost?$wfText($_POST,'wf_name'):$wfSelectedFolder['name']) ?></textarea><small><?= $wfEsc($wfF('Bis zu {count} Zeichen.',['count'=>240])) ?></small></label>
<label><?= $wfEsc($wfI('Übergeordneter Ordner')) ?><select class="form-control" name="wf_parent"><?php $wfFolderOptions($wfFolderEditPost?$wfText($_POST,'wf_parent'):(string)$wfSelectedFolder['parent_id'],$wfSelectedFolder['id']); ?></select></label>
<div class="dwf-actions"><button class="btn btn-solid theme-primary dwf-button"><?= $wfEsc($wfI('Ordner speichern')) ?></button><a href="<?= $wfEsc($wfUrl(['wf_folder'=>$wfSelectedFolder['id']])) ?>"><?= $wfEsc($wfI('Aktuellen Stand öffnen')) ?></a></div>
<?= $wfHidden('wf_folder_complete','1') ?>
</form>
<?php endif ?>
</section>
