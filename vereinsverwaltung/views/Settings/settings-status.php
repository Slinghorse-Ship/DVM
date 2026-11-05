<?php declare(strict_types=1);
$statusCatalog=isset($nativeAccess)?new \Kulturhus\Native\Language\Catalog($db,$nativeAccess):null;
$statusMessages=$statusCatalog?->messages()??[];$statusLocale=$statusCatalog?->locale()??'de';
$statusText=static fn(string $source):string=>\Kulturhus\Native\Language\StatusMessages::text($source,$statusMessages);
try{echo \Kulturhus\Native\Status\View::render(\Kulturhus\Native\Status\Factory::environment($db,(string)$_SESSION['actor'])->monitor(),(string)$_SESSION['csrf'],$_GET,$statusMessages,locale:$statusLocale);}
catch(\Throwable $failure){http_response_code($failure instanceof \PDOException||str_starts_with($failure->getMessage(),'Privater Schlüssel')||str_starts_with($failure->getMessage(),'Private Konfiguration')?503:422);echo '<section class="content"><h1>'.e($statusText('Systemstatus')).'</h1><p role="alert">'.e($statusText('Statusansicht nicht verfügbar. Bitte neu laden.')).'</p><a class="btn btn-outline theme-secondary" href="?view=settings&amp;tab=status">'.e($statusText('Neu laden')).'</a></section>';}
