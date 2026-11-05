<?php declare(strict_types=1);
// Render the real sidebar partial for local browser tests; no authentication or writes.
require __DIR__.'/member-profile-environment.php';
require __DIR__.'/../../src/Web/View.php';
$signedInName='Alex Muster';$production=($argv[1]??'')!=='no-logout';$view='settings';$tab='account';
require __DIR__.'/../../views/Shell/sidebar-account.php';
