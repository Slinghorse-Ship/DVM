<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Documents/WorkflowPager.php';
require_once __DIR__.'/../../src/Documents/PersonalDocumentText.php';
$vvState=$voucherViewState;$vvMessages=$voucherInterfaceMessages??[];
$vvI=static fn(string $source):string=>\Kulturhus\Native\Language\EventVoucherMessages::text($source,$vvMessages);
$vvF=static function(string $source,array $values)use($vvI):string{$args=[];foreach($values as$key=>$value)$args['{'.$key.'}']=(string)$value;return strtr($vvI($source),$args);};
$vvLocale=$managerLanguageLocale??'de';
$vvMoney=static fn(int $cents):string=>\Kulturhus\Verein\Documents\PersonalDocumentText::money($cents,$vvLocale);
$vvAmount=static fn(int $value,string $unit):string=>$unit==='eur'?$vvMoney($value):$vvF('{count} Stück',['count'=>$value]);
$vvDate=static function(string $value)use($vvLocale):string{try{return(new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format(explode('-',$vvLocale,2)[0]==='en'?'Y-m-d H:i':'d.m.Y H:i');}catch(Throwable){return$value;}};
$vvQuery=['view'=>'portal','tab'=>'vouchers','voucher_scope'=>'own']+$vvState['query'];
$vvFields=['events'=>['voucher_event_query','voucher_event_page'],'cards'=>['voucher_cards_query','voucher_cards_page'],'history'=>['voucher_history_query','voucher_history_page']];
$vvSearch=static function(string $section,string $label)use($vvQuery,$vvFields,$vvI,$vvState):void{
 [$search,$page]=$vvFields[$section];?>
 <form method="get" class="personal-voucher-search" data-live-search-page="<?= e($page) ?>"><?php foreach($vvQuery as$field=>$value)if(!in_array($field,[$search,$page],true))echo hidden($field,$value);echo hidden($page,'1');?><label><?= e($vvI($label)) ?><input class="form-control" type="search" name="<?= e($search) ?>" value="<?= e($vvState['query'][$search]??'') ?>"></label><button class="btn btn-solid theme-primary dwf-button"><?= e($vvI('Suchen')) ?></button><a class="btn btn-outline theme-secondary dwf-button" href="<?= e(url(array_replace($vvQuery,[$search=>'',$page=>1]))) ?>"><?= e($vvI('Suche zurücksetzen')) ?></a></form>
<?php };
?>
<link rel="stylesheet" href="document-workflows.css?v=2"><link rel="stylesheet" href="personal-vouchers.css?v=1">
<section class="content dwf-workspace personal-voucher-workspace" data-personal-vouchers data-document-interface>
<header class="dwf-heading"><div><h1><?= e($vvI('Meine Veranstaltungsgutscheine')) ?></h1><p><?= e($vvI('Eigene Originalkarten, Guthaben und Verlauf')) ?></p></div></header>
<p><?= e($vvI('Dieser Bereich zeigt ausschließlich dein verknüpftes Profil. Allgemeine Einlöserechte erweitern diese persönliche Auswahl nicht.')) ?></p>
<?php if($vvState['access']&&($vvState['access']['manage']||$vvState['access']['helpers'])):?><p><a class="btn btn-outline theme-secondary dwf-button" href="<?= e(url(['view'=>'portal','tab'=>'vouchers','voucher_scope'=>'manage'])) ?>"><?= e($vvI('Zur Gutscheinverwaltung')) ?></a></p><?php endif ?>
<?php if($vvState['error']!==''):?><p class="dwf-alert dwf-error" role="alert"><?= e($vvI($vvState['error'])) ?></p><?php endif ?>
<?php if($vvState['event']===null):?>
<section class="dwf-card" data-personal-voucher-events><h2><?= e($vvI('Meine Veranstaltungen')) ?></h2><?php $vvSearch('events','Veranstaltungen durchsuchen'); ?>
<?php if(isset($vvState['errors']['events'])):?><p class="dwf-alert dwf-error" role="alert"><?= e($vvI($vvState['errors']['events'])) ?></p><?php endif ?>
<?= \Kulturhus\Verein\Documents\WorkflowPager::render($vvState['events'],'voucher_event_page',$vvQuery,$vvI) ?>
<?php if($vvState['events']!==null&&!$vvState['events']['rows']):?><p><?= e($vvI('Keine passenden eigenen Veranstaltungen.')) ?></p><?php endif ?>
<?php foreach($vvState['events']['rows']??[]as$vvDisplay):$vvEvent=$vvDisplay['event'];?><article class="personal-voucher-event" data-personal-voucher-event><h3><a href="<?= e(url(array_replace($vvQuery,['voucher_event'=>$vvEvent['id'],'voucher_cards_page'=>1,'voucher_history_page'=>1]))) ?>"><?= e($vvEvent['title']) ?></a></h3><p><?= e($vvDate($vvEvent['starts_at'])) ?> · <?= e($vvEvent['location']) ?></p><?php if($vvDisplay['notice']!==''):?><p role="status"><?= e($vvI($vvDisplay['notice'])) ?></p><?php endif ?></article><?php endforeach ?>
<?= \Kulturhus\Verein\Documents\WorkflowPager::render($vvState['events'],'voucher_event_page',$vvQuery,$vvI) ?></section>
<?php else:$vvDisplay=$vvState['event'];$vvEvent=$vvDisplay['event'];?>
<p><a class="btn btn-outline theme-secondary dwf-button" href="<?= e(url(array_diff_key($vvQuery,array_flip(['voucher_event','voucher_cards_page','voucher_cards_query','voucher_history_page','voucher_history_query'])))) ?>"><?= e($vvI('← Meine Veranstaltungen')) ?></a></p>
<section class="dwf-card" data-personal-voucher-source><h2><?= e($vvEvent['title']) ?></h2><p><?= e($vvDate($vvEvent['starts_at'])) ?> · <?= e($vvEvent['location']) ?></p>
<?php if($vvDisplay['notice']!==''):?><p class="dwf-alert" role="status"><?= e($vvI($vvDisplay['notice'])) ?></p><?php endif ?>
<?php if($vvState['print']['available']??false):?><p><a download class="btn btn-solid theme-primary dwf-button" href="<?= e(url(['own_voucher_pdf'=>$vvEvent['id']])) ?>"><?= e($vvI('Eigene verfügbare Gutscheine als PDF')) ?></a></p><p><?= e($vvI('Die PDF enthält alle derzeit verfügbaren eigenen Karten, nicht nur diese Seite. Höchstens 200 Karten je Druckdatei.')) ?></p><?php endif ?>
<?php if(($vvState['print']['notice']??'')!==''):?><p role="status"><?= e($vvI($vvState['print']['notice'])) ?></p><?php endif ?>
<p><?= e($vvI('Diese Ansicht löst keine Karte ein und verändert kein Guthaben.')) ?></p></section>
<section class="dwf-card" data-personal-voucher-cards><h2><?= e($vvI('Gutscheinkarten')) ?></h2><?php $vvSearch('cards','Gutscheinkarten durchsuchen'); ?>
<form method="get" class="personal-voucher-state"><?php foreach($vvQuery as$field=>$value)if(!in_array($field,['voucher_card_state','voucher_cards_page'],true))echo hidden($field,$value);echo hidden('voucher_cards_page','1');?><label><?= e($vvI('Gutscheinstand')) ?><select class="form-control" name="voucher_card_state"><?php if(!in_array($vvQuery['voucher_card_state']??'all',['all','active','redeemed','revoked'],true)):?><option value="<?= e($vvQuery['voucher_card_state']??'') ?>" selected><?= e($vvI('Gutscheinstand auswählen.')) ?></option><?php endif ?><?php foreach(['all'=>'Alle Originalkarten','active'=>'Nur verfügbar','redeemed'=>'Bereits eingelöst','revoked'=>'Gesperrt']as$vvValue=>$vvLabel):?><option value="<?= e($vvValue) ?>" <?= ($vvQuery['voucher_card_state']??'all')===$vvValue?'selected':'' ?>><?= e($vvI($vvLabel)) ?></option><?php endforeach ?></select></label><button class="btn btn-outline theme-secondary dwf-button"><?= e($vvI('Anzeigen')) ?></button></form>
<?php if(isset($vvState['errors']['cards'])):?><p class="dwf-alert dwf-error" role="alert"><?= e($vvI($vvState['errors']['cards'])) ?></p><?php endif ?>
<?php foreach($vvState['cards']['errors']??[]as$vvError):?><p class="dwf-alert dwf-error" role="alert"><?= e($vvI($vvError)) ?></p><?php endforeach ?>
<?= \Kulturhus\Verein\Documents\WorkflowPager::render($vvState['cards'],'voucher_cards_page',$vvQuery,$vvI) ?>
<?php if($vvState['cards']!==null&&!$vvState['cards']['rows']):?><p><?= e($vvI('Keine passenden eigenen Gutscheinkarten.')) ?></p><?php endif ?>
<div class="personal-voucher-grid"><?php foreach($vvState['cards']['rows']??[]as$vvCard):$vvOpen=$vvCard['display_state']==='active'&&(int)$vvCard['remaining']>0;$vvStatus=['active'=>'Verfügbar','redeemed'=>'Bereits eingelöst','revoked'=>'Gesperrt'][$vvCard['display_state']];?>
<article class="personal-voucher-card" data-personal-voucher-card data-card-identity="<?= e($vvCard['ticket_id']?:$vvCard['id']) ?>"><h3><?= e($vvCard['label']) ?></h3><p><?= e($vvCard['name']) ?></p><p><strong><?= e($vvAmount((int)$vvCard['remaining'],$vvCard['unit'])) ?></strong> · <?= e($vvI($vvStatus)) ?></p>
<?php if($vvCard['unit']==='piece'):?><p><?= e($vvF('Einzelgutschein {number} von {count}',['number'=>$vvCard['ticket_number'],'count'=>$vvCard['ticket_total']])) ?></p><?php endif ?>
<?php if($vvOpen&&$vvCard['consistent']&&$vvDisplay['available']):?><img class="personal-voucher-qr" src="<?= e(\Kulturhus\Verein\EventVoucherQr::image($vvEvent['id'],$vvCard['code'])) ?>" width="246" height="246" alt="<?= e($vvI('QR-Code für diesen ursprünglichen Gutschein')) ?>"><?php else:?><p><?= e($vvI('Für diesen Stand wird kein aktiver QR-Code angeboten.')) ?></p><?php endif ?>
<p><?= e($vvI('Ursprünglicher Code')) ?> <strong class="personal-voucher-code" data-original-voucher-code><?= e(implode('-',str_split($vvCard['code'],4))) ?></strong></p><p><?= e($vvI('Nur für diese Person und Veranstaltung gültig.')) ?></p></article><?php endforeach ?></div>
<?= \Kulturhus\Verein\Documents\WorkflowPager::render($vvState['cards'],'voucher_cards_page',$vvQuery,$vvI) ?></section>
<section class="dwf-card" data-personal-voucher-history><h2><?= e($vvI('Verlauf')) ?></h2><?php $vvSearch('history','Verlauf durchsuchen'); ?>
<?php if(isset($vvState['errors']['history'])):?><p class="dwf-alert dwf-error" role="alert"><?= e($vvI($vvState['errors']['history'])) ?></p><?php endif ?>
<?= \Kulturhus\Verein\Documents\WorkflowPager::render($vvState['history'],'voucher_history_page',$vvQuery,$vvI) ?>
<?php if($vvState['history']!==null&&!$vvState['history']['rows']):?><p><?= e($vvI('Keine passenden ursprünglichen Vorgänge.')) ?></p><?php endif ?>
<?php foreach($vvState['history']['rows']??[]as$vvMove):?><article class="personal-voucher-move" data-personal-voucher-move><h3><?= e($vvMove['label']) ?></h3><p><?= e($vvMove['name']) ?> · <?= e($vvI(['grant'=>'Zugeteilt','redeem'=>'Eingelöst','reverse'=>'Zurückgenommen','revoke'=>'Sperrung'][$vvMove['kind']])) ?> · <?= e($vvAmount((int)$vvMove['amount'],$vvMove['unit'])) ?></p><p><?= e($vvDate($vvMove['created_at'])) ?> · <?= e($vvMove['reason']) ?></p><?php if($vvMove['reversed']):?><p><?= e($vvI('Diese Einlösung wurde zurückgenommen.')) ?></p><?php endif ?></article><?php endforeach ?>
<?= \Kulturhus\Verein\Documents\WorkflowPager::render($vvState['history'],'voucher_history_page',$vvQuery,$vvI) ?></section>
<?php endif ?></section>
