<?php declare(strict_types=1);
/** Kept command path delegates to the native empty-install entrypoint. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/distribution/install-native.php';
