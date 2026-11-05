<?php declare(strict_types=1);
// These helpers deliberately cannot select a production database or ProcessWire identity.
require_once __DIR__.'/mysql-environment.php';
$memberTestBase=rtrim(getenv('KULTURHUS_TEST_BASE')?:'http://127.0.0.1:8091','/');
if(!in_array($memberTestBase,['http://127.0.0.1:8091','http://localhost:8091'],true))throw new RuntimeException('Standalone loopback synthetic server on port 8091 required.');
$memberTestRoot=realpath(dirname(__DIR__,3));
$memberTestDatabase=$supportMysqlDsn;
$memberTestOutput=$memberTestRoot.'/.local/member-profile-fixtures';
$memberTestSessions=$memberTestRoot.'/.local/vereinsverwaltung/sessions';
