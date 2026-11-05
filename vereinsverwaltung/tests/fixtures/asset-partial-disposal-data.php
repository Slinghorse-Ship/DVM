<?php declare(strict_types=1);
require_once __DIR__.'/../../src/Finance/Assets/AssetPartialDisposal.php';
use Kulturhus\Verein\AssetPartialDisposal;

function partialDisposalFixture(array $changes=[]):array
{
    $plan=array_replace(['basis_cents'=>12000,'residual_cents'=>0,'acquired_on'=>'2024-01-15','months'=>24,
        'sphere'=>'4','account'=>'06300','reason'=>'Synthetic original invoice and reviewed useful life.','method'=>'linear_monthly'],$changes);
    $proof=['schema'=>1,'kind'=>'partial_scrap','date'=>'2025-06-15','reason'=>'Synthetic irreparable documented component; no compensation.',
        'document_id'=>str_repeat('d',32),'revision'=>1,'sha256'=>str_repeat('e',64),'plan_sha256'=>AssetPartialDisposal::hash($plan),
        'allocation'=>['basis'=>'documented_component_cost','cost_cents'=>4000,'residual_cents'=>0,
            'document_id'=>str_repeat('a',32),'revision'=>2,'sha256'=>str_repeat('b',64),
            'reason'=>'Synthetic invoice separately proves component cost and common use/life.','same_use_and_life'=>true,'confirmed'=>true],
        'no_proceeds'=>true,'complete'=>false];
    return ['plan'=>$plan,'proof'=>$proof,'year'=>2025,'tax_review'=>['months'=>48,'reason'=>'Synthetic independently reviewed tax life.','confirmed'=>true]];
}
