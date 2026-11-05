<?php declare(strict_types=1);
require __DIR__.'/member-profile-environment.php';
use Kulturhus\Verein\Database;
$file=realpath($argv[1]??'');$prefix=realpath($memberTestOutput);
if(!$file||!$prefix||!str_starts_with(str_replace('\\','/',$file),rtrim(str_replace('\\','/',$prefix),'/').'/'))throw new RuntimeException('Use a generated local fixture path.');
$fixture=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
if(!str_starts_with($fixture['club'],'member-profile-'))throw new RuntimeException('Synthetic tenant required.');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($memberTestDatabase);$db->exec('START TRANSACTION READ ONLY');$out=[];
foreach(['contacts','memberships','member_families','member_family_people','manual_member_accounts','finance_entries','finance_transactions','documents','document_links'] as $table){$out[$table]=mysqlFixtureTenantRows($db,$table,$fixture['club']);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
