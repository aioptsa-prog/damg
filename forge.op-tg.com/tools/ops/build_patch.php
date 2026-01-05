<?php
// tools/ops/build_patch.php
// Build a zip patch from a list of relative file paths.
// Usage: php tools/ops/build_patch.php --list releases/patch_YYYYMMDD_packlist.txt --out releases/patch_YYYYMMDD.zip

require_once __DIR__ . '/../../bootstrap.php';

function arg_val(array $argv, string $name, $default=null){
  foreach($argv as $i=>$a){ if($a===$name && isset($argv[$i+1])) return $argv[$i+1]; if(str_starts_with((string)$a, $name.'=')) return substr($a, strlen($name)+1); }
  return $default;
}

$listFile = arg_val($argv ?? [], '--list', PROJ_ROOT.'/releases/patch_'.date('Ymd').'_packlist.txt');
$outZip = arg_val($argv ?? [], '--out', PROJ_ROOT.'/releases/patch_'.date('Ymd_His').'.zip');

if(!is_file($listFile)){
  fwrite(STDERR, "Packlist not found: $listFile\n");
  exit(2);
}

$paths = array_values(array_filter(array_map('trim', file($listFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [])));
if(empty($paths)){
  fwrite(STDERR, "Packlist is empty: $listFile\n");
  exit(3);
}

if(!class_exists(ZipArchive::class)){
  fwrite(STDERR, "ZipArchive missing. Enable zip extension or use 'tools/ops/build_site_bundle.php'.\n");
  exit(4);
}

@mkdir(dirname($outZip), 0777, true);
$zip = new ZipArchive();
if($zip->open($outZip, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){
  fwrite(STDERR, "Failed to open zip: $outZip\n");
  exit(5);
}

$added = [];
$root = rtrim(str_replace(['\\','/'], DIRECTORY_SEPARATOR, PROJ_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

// Normalize a path to zip local name (forward slashes, relative to root)
function to_local_name(string $absPath, string $root): string {
  $abs = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $absPath);
  // Ensure realpath when possible (keeps case consistent on Windows)
  $real = realpath($abs);
  if($real) $abs = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $real);
  // Strip root prefix
  if(str_starts_with($abs, $root)){
    $rel = substr($abs, strlen($root));
  } else {
    // Fallback: try to find the longest common prefix
    $rel = ltrim($abs, DIRECTORY_SEPARATOR);
  }
  // Convert to forward slashes for cross-platform zip extraction
  $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
  // Remove any leading './'
  $rel = ltrim($rel, './');
  return $rel;
}

foreach($paths as $rel){
  $abs = $root . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
  if(!file_exists($abs)){
    fwrite(STDERR, "WARN missing: $rel\n");
    continue;
  }
  if(is_dir($abs)){
    // Add directory recursively
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
      $f = (string)$file;
      if(is_dir($f)) continue; // skip directories; only add files
      $localName = to_local_name($f, $root);
      $zip->addFile($f, $localName);
      $added[] = $localName;
    }
  } else {
    $localName = to_local_name($abs, $root);
    $zip->addFile($abs, $localName);
    $added[] = $localName;
  }
}
$zip->close();

echo "PATCH ZIP: $outZip\n";
echo "FILES: ".count($added)."\n";
exit(0);
