<?php
// Validate taxonomy JSON and write a report to docs/validation/taxonomy_validation.txt
require_once __DIR__ . '/../bootstrap.php';

$src = __DIR__ . '/../docs/taxonomy_seed.json';
if(!is_file($src)){
  fwrite(STDERR, "Seed file not found: $src\n");
  exit(2);
}
$raw = file_get_contents($src);
$data = json_decode($raw, true);
$status = (json_last_error() === JSON_ERROR_NONE) ? 'VALID' : ('INVALID: '.json_last_error_msg());
if(!is_array($data)){
  $status = 'INVALID: not an object';
}

function walk_stats($node, &$count, &$maxDepth, $depth, &$slugs, &$top){
  $count++;
  $slug = $node['slug'] ?? null;
  if($slug){ $slugs[$slug] = ($slugs[$slug] ?? 0) + 1; }
  if($depth > $maxDepth) $maxDepth = $depth;
  if($depth === 1){ $top[] = $node['slug'] ?? ($node['name_en'] ?? ''); }
  if(!empty($node['children'])){
    foreach($node['children'] as $ch){ walk_stats($ch, $count, $maxDepth, $depth+1, $slugs, $top); }
  }
}

$count=0; $maxDepth=0; $slugs=[]; $top=[];
if(is_array($data)){
  walk_stats($data, $count, $maxDepth, 0, $slugs, $top);
}
$uniqSlugs = 0; foreach($slugs as $k=>$v){ if($v === 1) $uniqSlugs++; }
$top = array_values(array_unique(array_filter($top)));
$topCount = count($top);

$repDir = __DIR__ . '/../docs/validation';
if(!is_dir($repDir)) @mkdir($repDir, 0777, true);
$out = $repDir . '/taxonomy_validation.txt';
$lines = [];
$lines[] = 'Taxonomy Validation Report';
$lines[] = 'Generated at: '.date('Y-m-d H:i:s');
$lines[] = 'JSON status: '.$status;
$lines[] = 'Total nodes: '.$count;
$lines[] = 'Max depth: '.$maxDepth;
$lines[] = 'Unique slugs: '.$uniqSlugs.' / total slugs: '.count($slugs);
$lines[] = 'Top-level domains (count='.$topCount.'):'.PHP_EOL.' - '.implode(PHP_EOL.' - ', array_slice($top,0,50));
file_put_contents($out, implode(PHP_EOL, $lines));

echo "Report written to $out\n";
