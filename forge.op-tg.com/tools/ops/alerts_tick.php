<?php
// CLI script: scans health and emits alerts via webhook/email or stdout
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$now = time();
$win = (int)get_setting('workers_online_window_sec','90'); if($win<15) $win=90;
$offlineCut = date('Y-m-d H:i:s', $now - $win);
$alertWebhook = trim((string)get_setting('alert_webhook_url',''));
$alertEmail   = trim((string)get_setting('alert_email',''));
// Slack App Token (v2) support
$slackToken   = trim((string)get_setting('alert_slack_token',''));
$slackChannel = trim((string)get_setting('alert_slack_channel',''));

$alerts = [];
// Offline workers
try{
  $st = $pdo->prepare("SELECT worker_id,last_seen FROM internal_workers WHERE last_seen < ? ORDER BY last_seen ASC");
  $st->execute([$offlineCut]); $rows = $st->fetchAll();
  if($rows){ $ids = array_map(fn($r)=>($r['worker_id']??''), $rows); $alerts[] = 'Offline workers: '.implode(',', array_slice($ids,0,10)).(count($ids)>10?'...':''); }
}catch(Throwable $e){}
// DLQ items
try{
  $c = (int)$pdo->query("SELECT COUNT(*) c FROM dead_letter_jobs")->fetch()['c'];
  if($c>0){ $alerts[] = 'DLQ has '.$c.' items'; }
}catch(Throwable $e){}
// Stuck jobs (processing but lease expired)
try{
  $st = $pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < datetime('now')");
  $c = (int)($st->fetch()['c'] ?? 0); if($c>0){ $alerts[] = 'Stuck jobs: '.$c; }
}catch(Throwable $e){}

if(!$alerts){ echo json_encode(['ok'=>true,'alerts'=>[]])."\n"; exit(0); }
$ts = gmdate('c');
$payload = [ 'ok'=>false, 'alerts'=>$alerts, 'ts'=>$ts ];
$summaryText = "OptForge Alerts\n" . implode("\n", array_map(fn($a)=>"- ".$a, $alerts));

// Webhook JSON
if($alertWebhook){
  try{
    $ch = curl_init($alertWebhook);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $lower = strtolower($alertWebhook);
    $body = null; $hdr = ['Content-Type: application/json'];
    if(strpos($lower, 'hooks.slack.com') !== false){
      // Slack incoming webhook expects { text: "..." }
      $body = json_encode([ 'text' => $summaryText ]);
    } else if(strpos($lower, 'discord.com/api/webhooks') !== false){
      // Discord webhook expects { content: "..." }
      $body = json_encode([ 'content' => $summaryText ]);
    } else if(strpos($lower, 'office.com/webhook') !== false || strpos($lower, 'office.com/webhookb2') !== false){
      // Microsoft Teams (Office 365 Connector) simple MessageCard
      $body = json_encode([
        '@type' => 'MessageCard', '@context' => 'https://schema.org/extensions',
        'summary' => 'OptForge Alerts', 'themeColor' => 'E81123',
        'title' => 'OptForge Alerts', 'text' => implode("\n\n", $alerts)
      ]);
    } else {
      // Generic JSON receiver
      $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  }catch(Throwable $e){ /* ignore */ }
}
// Email
if($alertEmail && function_exists('mail')){
  @mail($alertEmail, '[OptForge] Alerts', implode("\n", $alerts));
}
// stdout for logs/crons
echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";

// Slack API (chat.postMessage) if configured
if($slackToken !== '' && $slackChannel !== ''){
  try{
    $ch2 = curl_init('https://slack.com/api/chat.postMessage');
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $slackToken,
    ]);
    $text = $summaryText;
    $body = json_encode(['channel'=>$slackChannel,'text'=>$text], JSON_UNESCAPED_UNICODE);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $body);
    $resp2 = curl_exec($ch2);
    curl_close($ch2);
  }catch(Throwable $e){ /* ignore */ }
}
