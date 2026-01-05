<?php
require_once __DIR__.'/providers.php';

/*
  classify_lead($lead): returns ['category_id'=>int|null,'score'=>float,'tags'=>[], 'matched'=>[rule...]]
  - Uses keywords list (category_keywords), Google types (gmap_types), and heuristics on name.
*/
function classify_lead(array $lead){
  $pdo=db();
  if(get_setting('classify_enabled','1')!=='1'){
    return ['category_id'=>null,'score'=>0.0,'tags'=>[],'matched'=>[]];
  }
  $threshold = (float)get_setting('classify_threshold','1.0');
  // Normalize fields for matching
  $ctx=[
    'name'=>mb_strtolower((string)($lead['name'] ?? '')),
    'types'=>mb_strtolower((string)($lead['gmap_types'] ?? '')),
    'website'=>mb_strtolower((string)($lead['website'] ?? '')),
    'email'=>mb_strtolower((string)($lead['email'] ?? '')),
    'source_url'=>mb_strtolower((string)($lead['source_url'] ?? '')),
    'city'=>mb_strtolower((string)($lead['city'] ?? '')),
    'country'=>mb_strtolower((string)($lead['country'] ?? '')),
    'phone'=>preg_replace('/\D+/','',(string)($lead['phone'] ?? '')),
  ];

  // Load weight settings
  $w_kw_name = (float)get_setting('classify_w_kw_name','2.0');
  $w_kw_types = (float)get_setting('classify_w_kw_types','1.5');
  $w_name = (float)get_setting('classify_w_name','1.0');
  $w_types = (float)get_setting('classify_w_types','1.0');
  $w_website = (float)get_setting('classify_w_website','1.0');
  $w_email = (float)get_setting('classify_w_email','1.0');
  $w_source_url = (float)get_setting('classify_w_source_url','1.0');
  $w_city = (float)get_setting('classify_w_city','1.0');
  $w_country = (float)get_setting('classify_w_country','1.0');
  $w_phone = (float)get_setting('classify_w_phone','1.0');

  // Load categories, keywords, and rules
  $cats = $pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
  $kwRows = $pdo->query("SELECT category_id cid, keyword kw FROM category_keywords")->fetchAll(PDO::FETCH_ASSOC);
  $rules = $pdo->query("SELECT * FROM category_rules WHERE enabled=1")->fetchAll(PDO::FETCH_ASSOC);

  $scores=[]; $matches=[];
  foreach($cats as $cid=>$cname){ $scores[(int)$cid]=0.0; $matches[(int)$cid]=[]; }

  // Keyword heuristic (legacy): boosts name and types
  foreach($kwRows as $r){
    $cid=(int)$r['cid']; $kw=mb_strtolower($r['kw'] ?? ''); if($kw==='') continue;
    if($ctx['name']!=='' && mb_strpos($ctx['name'],$kw)!==false){ $scores[$cid]+=$w_kw_name; $matches[$cid][]= ['kind'=>'kw-name','kw'=>$kw,'w'=>$w_kw_name]; }
    if($ctx['types']!=='' && mb_strpos($ctx['types'],$kw)!==false){ $scores[$cid]+=$w_kw_types; $matches[$cid][]= ['kind'=>'kw-types','kw'=>$kw,'w'=>$w_kw_types]; }
  }

  // Advanced rules
  foreach($rules as $rule){
    $cid=(int)$rule['category_id']; if(!isset($scores[$cid])) continue;
    $target=$rule['target']; $mode=$rule['match_mode'] ?: 'contains'; $pattern=trim((string)$rule['pattern']); $w=(float)$rule['weight'];
    $val=$ctx[$target] ?? '';
    if($val==='') continue;
    $hit=false;
    if($mode==='contains'){
      $hit = mb_strpos($val, mb_strtolower($pattern)) !== false;
    } elseif($mode==='exact'){
      $hit = $val === mb_strtolower($pattern);
    } elseif($mode==='regex'){
      // Ensure valid regex delimiters
      $re = $pattern;
      // If pattern has no delimiters, wrap with /.../i
      if(@preg_match($pattern,'')===false){ $re = '/'.str_replace('/','\/',$pattern).'/iu'; }
      $hit = @preg_match($re, $val) === 1;
    }
    if($hit){
      // Apply global per-target multiplier while preserving rule's own weight
      $mul = 1.0;
      if($target==='name') $mul=$w_name; else if($target==='types') $mul=$w_types; else if($target==='website') $mul=$w_website; else if($target==='email') $mul=$w_email; else if($target==='source_url') $mul=$w_source_url; else if($target==='city') $mul=$w_city; else if($target==='country') $mul=$w_country; else if($target==='phone') $mul=$w_phone;
      $delta = $w * $mul;
      $scores[$cid]+=$delta; $matches[$cid][]= ['kind'=>'rule','target'=>$target,'mode'=>$mode,'p'=>$pattern,'w'=>$delta];
    }
  }

  // Pick best above threshold
  $bestId=null; $bestScore=0.0; $bestMatches=[];
  foreach($scores as $cid=>$sc){ if($sc>$bestScore){ $bestScore=$sc; $bestId=$cid; $bestMatches=$matches[$cid]; } }
  if($bestScore < $threshold){ return ['category_id'=>null,'score'=>$bestScore,'tags'=>[],'matched'=>[]]; }
  return ['category_id'=>$bestId,'score'=>$bestScore,'tags'=>[],'matched'=>$bestMatches];
}
?>
