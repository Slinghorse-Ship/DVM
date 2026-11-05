<?php declare(strict_types=1);
use Kulturhus\Verein\WebsiteWorkspace;
$websiteDraft=null;$websiteId=input($_GET,'draft');$websitePage=input($_GET,'site_page');
if(input($_POST,'action')==='website-save'){$websiteId=input($_POST,'draft_id');$websitePage=input($_POST,'page_key');}
if($websiteId!=='')$websiteDraft=$website->draft($websiteId);elseif($websitePage!=='')$websiteDraft=$website->pageDraft($websitePage);
$websiteForm=$websiteDraft??['id'=>'','version'=>0,'kind'=>$websitePage!==''?'page':'news','page_key'=>$websitePage,'title'=>WebsiteWorkspace::PAGES[$websitePage][0]??'','summary'=>'','body'=>'','status'=>'draft'];
if(input($_POST,'action')==='website-save')foreach(['title','summary','body','status','version'] as $key)$websiteForm[$key]=input($_POST,$key,(string)$websiteForm[$key]);
$websiteReturn=$websiteForm['kind']==='page'?'pages':'news';
?>
<div class="website-editor-grid"><section class="card"><div class="section-heading"><h2><?= $websiteForm['kind']==='page'?'Seiteninhalt bearbeiten':'Beitrag bearbeiten' ?></h2><a href="<?= e(url(['view'=>'website','tab'=>$websiteReturn])) ?>">Zur Übersicht</a></div>
<?php if(!$websiteDraft&&$websiteForm['kind']==='page'): ?><p class="muted">Neuer Textentwurf. Der derzeit veröffentlichte Inhalt wurde noch nicht übernommen.</p><?php endif ?>
<form method="post"><?= csrf().hidden('action','website-save').hidden('draft_id',$websiteForm['id']).hidden('version',$websiteForm['version']).hidden('kind',$websiteForm['kind']).hidden('page_key',$websiteForm['page_key']) ?>
<label>Titel<input class="form-control" name="title" required maxlength="200" value="<?= e($websiteForm['title']) ?>" data-website-title></label>
<label>Kurzbeschreibung<textarea class="form-control" name="summary" maxlength="2000" rows="3" data-website-summary><?= e($websiteForm['summary']) ?></textarea></label>
<label>Inhalt<textarea class="form-control" name="body" maxlength="100000" rows="14" data-website-body><?= e($websiteForm['body']) ?></textarea></label>
<label>Bearbeitungsstand<select class="form-control" name="status"><?php foreach($websiteLabels as $key=>$label): ?><option value="<?= $key ?>" <?= $websiteForm['status']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<div class="website-editor-actions"><button class="btn btn-solid theme-primary" <?= $website->canWrite('pages')?'':'disabled' ?>>Entwurf speichern</button><span class="muted">Noch keine Veröffentlichung</span></div></form></section>
<section class="card website-preview"><span class="website-eyebrow">Textvorschau · Entwurf</span><h2 data-website-preview-title><?= e($websiteForm['title']) ?></h2><p class="website-preview-summary" data-website-preview-summary><?= e($websiteForm['summary']) ?></p><div class="website-preview-body" data-website-preview-body><?= e($websiteForm['body']) ?></div><p class="muted">Das endgültige Seitenlayout und Bilder kommen mit der Website-Anbindung.</p></section></div>
<?php if($websiteDraft): ?><details class="card website-history"><summary>Änderungsverlauf</summary><?php foreach($website->revisions($websiteDraft['id']) as $revision):$payload=json_decode($revision['payload'],true,512,JSON_THROW_ON_ERROR); ?><details><summary><?= e($revision['happened_at']) ?> · <?= e($websiteLabels[$payload['status']]) ?></summary><h3><?= e($payload['title']) ?></h3><p><?= e($payload['summary']) ?></p><div class="website-preview-body"><?= e($payload['body']) ?></div></details><?php endforeach ?></details><?php endif ?>
