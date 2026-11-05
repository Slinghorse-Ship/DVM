<?php declare(strict_types=1);
$priceActor=$settings->actor();
if($priceActor['members_access']==='none')return;
$priceListError='';
try{$pricingPage=(new \Kulturhus\Verein\CatalogAssignments($db,(string)$_SESSION['actor']))->page($tab,$_GET);}
catch(DomainException $failure){$priceListError=$failure->getMessage();$term=input($_GET,'pricing_q');$pricingPage=['rows'=>[],'total'=>0,'pages'=>1,'page'=>1,'q'=>mb_check_encoding($term,'UTF-8')?mb_substr($term,0,150):''];}
$priceQuery=['view'=>'settings','tab'=>$tab,'pricing_q'=>$pricingPage['q'],'pricing_page'=>$pricingPage['page']];
$priceId=input($_GET,'pricing_assignment');$priceDate=input($_GET,'pricing_date',date('Y-m-d'));$priceMode=input($_GET,'pricing_mode','full');$priceDay=input($_GET,'pricing_day');$quote=null;$priceError='';
if($priceId!=='')try{$quote=(new \Kulturhus\Verein\CatalogPricing($db,(string)$_SESSION['actor']))->quote($priceId,$priceDate,$priceMode,$priceDay?:null);if(($quote['kind']==='service')!==($tab==='services'))throw new DomainException('Tarif im passenden Katalog auswählen.');}catch(DomainException $failure){$quote=null;$priceError=$failure->getMessage();}
?>
<link rel="stylesheet" href="catalog-pricing.css">
<section class="card catalog-pricing" id="catalog-pricing">
<h2<?= $priceId!==''?' tabindex="-1" autofocus':'' ?>>Tarifzuweisungen &amp; Preis</h2>
<?php if($canWrite): ?><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'settings','tab'=>$tab,'billing'=>1])) ?>">Tarife abrechnen</a> <a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'settings','tab'=>$tab,'automation'=>1])) ?>">Abrechnungsautomatik</a><?php endif ?>
<details class="catalog-assignment-picker"<?= $priceId===''?' open':'' ?>><summary>Zuweisungen · <?= $pricingPage['total'] ?></summary>
<form method="get" class="settings-search" action="#catalog-pricing"><?= hidden('view','settings').hidden('tab',$tab) ?><label>Kontakt oder Tarif<input class="form-control" name="pricing_q" value="<?= e($pricingPage['q']) ?>" maxlength="150"></label><button class="btn btn-outline theme-secondary">Suchen</button></form>
<?php if($priceListError!==''): ?><p role="alert"><?= e($priceListError) ?></p><?php endif ?>
<div class="catalog-assignment-list">
<?php foreach($pricingPage['rows']as$x): ?>
<article class="catalog-assignment" id="catalog-assignment-<?= e($x['id']) ?>"><div><strong><?= e($x['name']) ?></strong><span><?= e($x['catalog_name']) ?></span><small><?= e(dateDe($x['starts_on']).' – '.($x['ends_on']?dateDe($x['ends_on']):'offen')) ?></small></div>
<div class="settings-actions"><?php if($x['active']&&$canWrite): ?><a class="btn btn-outline theme-secondary" href="<?= e(url(['view'=>'settings','tab'=>$tab,'assignment_prices'=>1,'price_assignment'=>$x['id']])) ?>">Persönlicher Preis</a><?php endif ?><?php if($x['active']): ?><a class="btn btn-outline theme-secondary" href="<?= e(url($priceQuery+['pricing_assignment'=>$x['id'],'pricing_date'=>$priceDate,'pricing_mode'=>'full']).'#catalog-pricing') ?>">Preis ermitteln</a><?php else: ?><span>Tarif inaktiv</span><?php endif ?>
<?php if($canWrite&&$priceActor['members_access']==='write'&&!$x['ends_on']):$endingDraft=input($_POST,'action')==='settings-end-assignment'&&input($_POST,'assignment_id')===$x['id']; ?>
<details<?= $endingDraft?' open':'' ?>><summary>Zuweisung beenden</summary><form method="post" class="settings-actions"><?= csrf().hidden('action','settings-end-assignment').hidden('assignment_id',$x['id']).hidden('version',$endingDraft?input($_POST,'version'):$x['version']) ?><label>Beenden am<input class="form-control" name="end" type="date" required min="<?= e($x['starts_on']) ?>" value="<?= e($endingDraft?input($_POST,'end'):date('Y-m-d')) ?>"></label><button class="btn btn-outline theme-secondary">Beenden</button></form></details>
<?php endif ?></div></article>
<?php endforeach;if(!$pricingPage['total']): ?><p>Keine passenden Tarifzuweisungen.</p><?php endif ?>
</div>
<?php if($pricingPage['pages']>1): ?><nav class="settings-actions" aria-label="Tarifzuweisungsseiten"><?php foreach([$pricingPage['page']-1=>'Vorherige',$pricingPage['page']+1=>'Nächste']as$number=>$label)if($number>=1&&$number<=$pricingPage['pages']): ?><a class="btn btn-outline theme-secondary" href="<?= e(url(array_replace($priceQuery,['pricing_page'=>$number])).'#catalog-pricing') ?>"><?= e($label) ?></a><?php endif ?><span>Seite <?= $pricingPage['page'] ?> von <?= $pricingPage['pages'] ?></span></nav><form method="get" class="settings-actions" action="#catalog-pricing"><?= hidden('view','settings').hidden('tab',$tab).hidden('pricing_q',$pricingPage['q']) ?><label>Zuweisungsseite<input class="form-control" name="pricing_page" type="number" min="1" max="<?= $pricingPage['pages'] ?>" value="<?= $pricingPage['page'] ?>" inputmode="numeric" required></label><button class="btn btn-outline theme-secondary">Seite öffnen</button></form><?php endif ?>
</details>
<?php if($priceId!==''): ?>
<div class="catalog-price-detail"><form method="get" class="form-grid" action="#catalog-pricing"><?= hidden('view','settings').hidden('tab',$tab).hidden('pricing_assignment',$priceId).hidden('pricing_q',$pricingPage['q']).hidden('pricing_page',$pricingPage['page']) ?>
<label>Abrechnungsdatum<input class="form-control" type="date" name="pricing_date" value="<?= e($priceDate) ?>" required></label>
<label>Preis-Stichtag (optional)<input class="form-control" type="date" name="pricing_day" value="<?= e($priceDay) ?>"></label>
<label>Betrag<select class="form-control" name="pricing_mode"><option value="full"<?= $priceMode==='full'?' selected':'' ?>>Voller Tarifbetrag</option><option value="days"<?= $priceMode==='days'?' selected':'' ?><?= $quote&&!$quote['can_prorate']?' disabled':'' ?>>Anteilig nach zugewiesenen Tagen</option></select></label><button class="btn btn-solid theme-primary">Preis berechnen</button></form>
<?php if($priceError!==''): ?><p role="alert"><?= e($priceError) ?></p><?php endif ?>
<?php if($quote): ?><div class="catalog-price-result" data-catalog-price><strong><?= e(euro($quote['amount_cents'])) ?> brutto</strong><span><?= e($quote['contact_name'].' · Aktueller Tarif: '.$quote['name']) ?></span><small><?= e(dateDe($quote['period_start']).' – '.dateDe($quote['period_end'])) ?></small><small><?= e('Preis-Stichtag '.dateDe($quote['price_day']).($quote['pricing']==='fixed'?'':' · '.$quote['years'].' vollendete '.($quote['pricing']==='age'?'Lebensjahre':'Mitgliedsjahre'))) ?></small><?php if($quote['mode']==='days'): ?><small><?= $quote['active_days'] ?> von <?= $quote['period_days'] ?> Tagen · <?= e(euro($quote['full_cents'])) ?> voller Tarifbetrag</small><?php endif ?>
<?php if(isset($quote['assignment_price'])&&$quote['assignment_price']['mode']!=='none'): ?><span><?= $quote['assignment_price']['mode']==='fixed'?'Persönlicher Festpreis':e(\Kulturhus\Verein\AssignmentPrices::value($quote['assignment_price']).' % Rabatt') ?></span><?php endif ?>
<?php if($canWrite&&$quote['amount_cents']>0): ?><a class="btn btn-solid theme-primary" data-catalog-template href="<?= e(url(['view'=>'finance','tab'=>'receipts','new_entry'=>1,'kind'=>$quote['kind']==='service'?'income':$quote['kind'],'catalog_assignment'=>$quote['assignment_id'],'catalog_date'=>$quote['day'],'catalog_day'=>$quote['price_day'],'catalog_mode'=>$quote['mode'],'catalog_fingerprint'=>$quote['fingerprint']])) ?>">Als Belegvorlage verwenden</a><?php endif ?></div><?php endif ?>
</div><?php endif ?>
</section>
