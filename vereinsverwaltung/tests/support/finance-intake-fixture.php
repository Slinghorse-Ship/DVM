<?php declare(strict_types=1);
require __DIR__.'/member-profile-environment.php';
use Kulturhus\Verein\{Database,Documents};
$file=realpath($argv[1]??'');$prefix=realpath($memberTestOutput);
if(!$file||!$prefix||!str_starts_with(str_replace('\\','/',$file),rtrim(str_replace('\\','/',$prefix),'/').'/'))throw new RuntimeException('Generated local fixture required.');
$fixture=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);if(!str_starts_with($fixture['club'],'member-profile-'))throw new RuntimeException('Synthetic tenant required.');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($memberTestDatabase);$docs=new Documents($db,$fixture['actor']);
for($i=1;$i<=27;$i++)$docs->upload('synthetic-'.sprintf('%02d',$i).'.xml','<Invoice xmlns="urn:synthetic:test"/>',['title'=>'Synthetic Rechnung '.sprintf('%02d',$i),'kind'=>'receipt','scope'=>'finance','document_date'=>($i===27?'2025':'2026').'-08-01','links'=>[]],bin2hex(random_bytes(16)));
echo "27 synthetic invoice originals prepared, no financial entries.\n";
