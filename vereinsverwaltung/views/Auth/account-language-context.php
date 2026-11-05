<?php declare(strict_types=1);
// Fixed account labels only. Shared partials also work in an ordinary German context.
require_once __DIR__.'/../../native/Language/AccountMessages.php';
$accountLanguageMessages??=$managerLanguageMessages??\Kulturhus\Native\Language\AccountMessages::messages('de');
$accountText??=static fn(string $source):string=>$accountLanguageMessages[\Kulturhus\Native\Language\AccountMessages::key($source)]??$source;
