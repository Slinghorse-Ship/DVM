<?php declare(strict_types=1);
require_once __DIR__.'/../../../src/Finance/Accounting/FinanceNavigation.php';
require_once __DIR__.'/../../../native/Language/ManagerLabels.php';
$financeNavRoute=['view'=>$view,'tab'=>$tab,'section'=>input($_GET,'section')];
$financeNavActive=\Kulturhus\Verein\FinanceNavigation::active($financeNavRoute);
$financeNavGroups=isset($workspaceAccess)?\Kulturhus\Verein\FinanceNavigation::visible($workspaceAccess):\Kulturhus\Verein\FinanceNavigation::groups();
$financeNavGroups=\Kulturhus\Native\Language\ManagerLabels::financeGroups($financeNavGroups,$managerLanguageMessages??[]);
$financeNavText=static fn(string $kind):string=>\Kulturhus\Native\Language\ManagerLabels::financeText($kind,$managerLanguageMessages??[]);
$financeNavChildren=[];
?>
<nav class="module-tabs finance-main-nav nav" aria-label="<?= e($financeNavText('areas')) ?>">
<?php foreach($financeNavGroups as $group):if($financeNavActive===$group['id'])$financeNavChildren=$group['children']; ?>
<a class="nav-link <?= $financeNavActive===$group['id']?'active':'' ?>" href="<?= e(url($group['route'])) ?>" <?= $financeNavActive===$group['id']?'aria-current="true"':'' ?>><?= e($group['label']) ?></a>
<?php endforeach ?></nav>
<?php if(count($financeNavChildren)>1):
$financeNavTab=$tab?:($view==='donations'?'money':($view==='finance'?'bank':''));
$financeNavIsCurrent=static fn(array $route):bool=>($view===$route['view']||($view==='finance'&&$route['view']==='donations'))&&$financeNavTab===$route['tab']&&(!isset($route['section'])||input($_GET,'section')===$route['section']);
$financeNavCurrentLabel='';foreach($financeNavChildren as $item)if($financeNavIsCurrent($item['route'])){$financeNavCurrentLabel=$item['label'];break;}
?>
<details class="finance-area-menu">
<summary><span><?= e($financeNavCurrentLabel) ?></span><span class="finance-area-menu-hint"><?= e($financeNavText('choose_page')) ?></span></summary>
<nav class="module-tabs finance-sub-nav nav" aria-label="<?= e($financeNavText('pages')) ?>">
<?php foreach($financeNavChildren as $item):$r=$item['route'];$current=$financeNavIsCurrent($r); ?>
<a class="nav-link <?= $current?'active':'' ?>" href="<?= e(url($r)) ?>" <?= $current?'aria-current="page"':'' ?>><?= e($item['label']) ?></a>
<?php endforeach ?></nav>
</details>
<?php endif ?>

<p><a class="btn btn-outline theme-secondary button secondary" href="?view=finance&amp;tab=help" <?= $view==='finance'&&$tab==='help'?'aria-current="page"':'' ?>><?= e($financeNavText('help')) ?></a></p>
<?php $financePaymentDialog=($view??'')==='finance'&&($tab??'')==='bank'&&input($_GET,'fints')==='1'&&input($_GET,'payment_order')!=='';$financeProcessTab=(string)($tab??input($_GET,'tab'));$financeInboxReview=$financeProcessTab==='inbox'&&input($_GET,'inbox_doc',input($_POST,'doc',input($_POST,'inbox_doc')))!=='';if(($view??'')==='finance'&&in_array($financeProcessTab,['inbox','receipts','contributions'],true)&&!$financePaymentDialog&&!$financeInboxReview):require __DIR__.'/finance-process.php';endif; ?>
