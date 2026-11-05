<?php declare(strict_types=1);
// Shared guard for the synthetic fixture generator and query-only reader.
require_once __DIR__.'/mysql-environment.php';
$inventoryTestBase=rtrim(getenv('KULTURHUS_TEST_BASE')?:'http://127.0.0.1:8091','/');
if(!in_array($inventoryTestBase,['http://127.0.0.1:8091','http://localhost:8091'],true))throw new RuntimeException('This fixture requires the standalone loopback-only synthetic server on port 8091.');
$inventoryTestRoot=realpath(dirname(__DIR__,3));
$inventoryTestDatabase=$supportMysqlDsn;
$inventoryTestOutput=$inventoryTestRoot.'/.local/inventory-fields-fixtures';
$inventoryTestSessions=$inventoryTestRoot.'/.local/vereinsverwaltung/sessions';
