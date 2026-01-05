import 'dotenv/config';
import fs from 'fs';
import path from 'path';
import fetch from 'node-fetch';
import { chromium } from 'playwright';
import { spawnSync } from 'child_process';
import express from 'express';
import crypto from 'crypto';
import os from 'os';

// Preload worker.env (if present) BEFORE reading process.env into locals.
// This lets users drop a worker.env file next to the executable without editing .env.
try {
  const WENV = path.join(process.cwd(), 'worker.env');
  if (fs.existsSync(WENV)) {
    const lines = fs.readFileSync(WENV, 'utf8').split(/\r?\n/);
    for (const ln of lines) {
      if (!ln || /^\s*[#;]/.test(ln)) continue;
      const idx = ln.indexOf('=');
      if (idx > 0) {
        const k = ln.slice(0, idx).trim();
        const v = ln.slice(idx + 1); // keep as-is to preserve # inside quoted strings
        if (!process.env[k]) { process.env[k] = v; }
      }
    }
  }
} catch (e) { /* ignore */ }

// ---- Config and defaults ----
const APP_VER = '1.5.3';

// Helper function (must be defined before use)
function parseBool(v) { const s = String(v ?? '').trim().toLowerCase(); return s === '1' || s === 'true' || s === 'yes' || s === 'on'; }

// Phase 7: Google Web Search config
const SERPAPI_KEY = process.env.SERPAPI_KEY || '';
const GOOGLE_WEB_FALLBACK_ENABLED = parseBool(process.env.GOOGLE_WEB_FALLBACK_ENABLED ?? '0');
const GOOGLE_WEB_MAX_RESULTS = parseInt(process.env.GOOGLE_WEB_MAX_RESULTS || '10', 10);
let BASE = String(process.env.BASE_URL || '').trim().replace(/\/+$/, '');
let BASES = (process.env.BASE_URLS || '').split(',').map(s => s.trim()).filter(Boolean).map(s => s.replace(/\/+$/, ''));
let baseIdx = 0;
function getBase() { return BASE || (BASES[baseIdx] || ''); }
function rotateBase() { if (BASES.length > 0) { baseIdx = (baseIdx + 1) % BASES.length; BASE = BASES[baseIdx]; log('Rotated BASE to', BASE); } }
let SECRET = String(process.env.INTERNAL_SECRET || '').trim();
let WORKER_SECRET = String(process.env.WORKER_SECRET || '').trim();
let WORKER_ID = process.env.WORKER_ID || '';
let PULL_SEC = parseInt(process.env.PULL_INTERVAL_SEC || '30', 10);
let MAX_PAGES = parseInt(process.env.MAX_PAGES || '5', 10);
// When true, scroll search results until the end-of-list is reached (preferred behavior)
let SCRAPE_UNTIL_END = parseBool(process.env.SCRAPE_UNTIL_END ?? '1');
let HEADLESS = String(process.env.HEADLESS || 'false').toLowerCase() === 'true';
const CHROME_EXE = process.env.CHROME_EXE || '';
const CHROME_ARGS = (process.env.CHROME_ARGS || '').trim();
const PERSIST_DIR = path.resolve(process.cwd(), process.env.PERSIST_DIR || 'profile-data');
const DEBUG_SNAP = String(process.env.DEBUG_SNAPSHOTS || '0') === '1';
let LEASE_SEC = parseInt(process.env.LEASE_SEC || '180', 10);
let REPORT_BATCH_SIZE = parseInt(process.env.REPORT_BATCH_SIZE || '10', 10);
let REPORT_EVERY_MS = parseInt(process.env.REPORT_EVERY_MS || '15000', 10);
let REPORT_FIRST_MS = parseInt(process.env.REPORT_FIRST_MS || '2000', 10);
let ITEM_DELAY_MS = parseInt(process.env.ITEM_DELAY_MS || '800', 10);
let LAST_CMD_REV = 0;
let LAST_APPLIED_CMD_REV = 0;
let LAST_METRICS_PUSH = 0;
// Resilience controls
let REPORT_FAIL_STREAK = 0;
const REPORT_FAIL_MAX = parseInt(process.env.REPORT_FAIL_MAX || '8', 10);
const JOB_MAX_MINUTES = parseInt(process.env.JOB_MAX_MINUTES || '20', 10);
// UI heartbeat interval (seconds) to emit a concise status line into logs; set to 0 to disable
const UI_HB_SEC = parseInt(process.env.WORKER_UI_HEARTBEAT_SEC || '5', 10);
// UI streaming intervals (ms)
const UI_EVENTS_MS = parseInt(process.env.WORKER_UI_EVENTS_MS || '1500', 10);
const UI_EVENTS_KEEPALIVE_MS = parseInt(process.env.WORKER_UI_EVENTS_KEEPALIVE_MS || '15000', 10);
const UI_LOGS_MS = parseInt(process.env.WORKER_UI_LOGS_MS || '1500', 10);

// ---- Logging ----
const LOG_DIR = path.resolve(process.cwd(), 'logs');
const LOG_STATE = { connected: false, lastError: '', lastJob: null, totalAdded: 0, lastReport: null, active: false, startedAt: Date.now(), paused: false, armed: true, displayName: '' };
// Runtime diagnostics (live counters)
const DIAG = {
  startTs: Date.now(),
  sseClients: 0, sseEvents: 0, sseKeepAlive: 0, lastEventAt: 0,
  logsClients: 0, logsEvents: 0, logsKeepAlive: 0, lastLogEventAt: 0
};
if (!fs.existsSync(LOG_DIR)) fs.mkdirSync(LOG_DIR, { recursive: true });
function log(...args) {
  const line = `[${new Date().toISOString()}] ${args.join(' ')}`;
  console.log(line);
  const f = path.join(LOG_DIR, `worker-${new Date().toISOString().slice(0, 10)}.log`);
  try { fs.appendFileSync(f, line + '\n'); } catch (e) { }
}
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function die(msg) { log('FATAL:', msg); process.exit(1); }

// ---- Phase 7: Google Web Search Functions ----
function hashQuery(query) {
  return crypto.createHash('sha256').update(query.toLowerCase().trim()).digest('hex').slice(0, 32);
}

function detectSocialPlatform(url) {
  const patterns = {
    instagram: /instagram\.com\/([^\/\?]+)/i,
    twitter: /(?:twitter|x)\.com\/([^\/\?]+)/i,
    facebook: /facebook\.com\/([^\/\?]+)/i,
    linkedin: /linkedin\.com\/(?:company|in)\/([^\/\?]+)/i,
    youtube: /youtube\.com\/(?:channel|c|user|@)\/([^\/\?]+)/i,
    tiktok: /tiktok\.com\/@([^\/\?]+)/i,
  };
  for (const [platform, regex] of Object.entries(patterns)) {
    const match = url.match(regex);
    if (match) return { platform, handle: match[1] };
  }
  return null;
}

function isDirectoryUrl(url) {
  const directories = ['yelp.com', 'tripadvisor.com', 'yellowpages.com', 'foursquare.com', 'zomato.com', 'hungerstation.com', 'talabat.com', 'haraj.com.sa', 'opensooq.com', 'maroof.sa', 'saudiyellow.com'];
  return directories.some(d => url.includes(d));
}

function isOfficialSiteCandidate(url, businessName) {
  const excludePatterns = [/google\.com/i, /facebook\.com/i, /instagram\.com/i, /twitter\.com/i, /linkedin\.com/i, /youtube\.com/i, /wikipedia\.org/i, /yelp\.com/i, /tripadvisor\.com/i];
  if (excludePatterns.some(p => p.test(url))) return false;
  try {
    const domain = new URL(url).hostname.toLowerCase();
    const nameWords = businessName.toLowerCase().split(/\s+/).filter(w => w.length > 2);
    return nameWords.some(word => domain.includes(word));
  } catch (e) { return false; }
}

async function runGoogleWebSearch(page, query, businessName) {
  // Check cache first
  const queryHash = hashQuery(query);
  try {
    const cacheRes = await fetch(`${getBase()}/v1/api/integration/google_web/cache.php?hash=${queryHash}`, {
      headers: { 'X-Internal-Secret': SECRET }
    });
    if (cacheRes.ok) {
      const cacheData = await cacheRes.json();
      if (cacheData.success && cacheData.data) {
        log('[GOOGLE_WEB] Cache hit for query');
        return { ...cacheData.data, from_cache: true, success: true };
      }
    }
  } catch (e) { /* ignore cache errors */ }

  // Check usage limits
  let usage = { serpapi: 0, chromium: 0, serpapi_limit: 100, chromium_limit: 100 };
  try {
    const usageRes = await fetch(`${getBase()}/v1/api/integration/google_web/usage.php`, {
      headers: { 'X-Internal-Secret': SECRET }
    });
    if (usageRes.ok) usage = await usageRes.json();
  } catch (e) { /* ignore */ }

  // Try Chromium FIRST (primary method - no API key needed!)
  if (page && usage.chromium < usage.chromium_limit) {
    log('[GOOGLE_WEB] Using Chromium (direct Google search)');
    const result = await searchWithChromium(page, query, businessName);
    if (result.success) {
      await saveGoogleWebCache(queryHash, query, 'chromium', result);
      await incrementGoogleWebUsage('chromium');
      return result;
    }
    // If Chromium failed (blocked/captcha), try SerpAPI as fallback
    log('[GOOGLE_WEB] Chromium failed:', result.error_code);
  }

  // Try SerpAPI as fallback (if available)
  if (SERPAPI_KEY && usage.serpapi < usage.serpapi_limit) {
    log('[GOOGLE_WEB] Using SerpAPI fallback');
    const result = await searchWithSerpApi(query, businessName);
    if (result.success) {
      await saveGoogleWebCache(queryHash, query, 'serpapi', result);
      await incrementGoogleWebUsage('serpapi');
      return result;
    }
    return result;
  }

  // No provider available
  log('[GOOGLE_WEB] No provider available');
  return { success: false, error_code: 'no_provider', error: 'Chromium blocked and no SERPAPI_KEY configured' };
}

async function searchWithSerpApi(query, businessName) {
  const params = new URLSearchParams({
    q: query, api_key: SERPAPI_KEY, engine: 'google', hl: 'ar', gl: 'sa', num: String(GOOGLE_WEB_MAX_RESULTS)
  });
  try {
    const response = await fetch(`https://serpapi.com/search?${params}`, { timeout: 30000 });
    if (response.status === 429) return { success: false, error_code: 'rate_limited', error: 'SerpAPI rate limit' };
    if (!response.ok) return { success: false, error_code: 'api_error', error: `SerpAPI error: ${response.status}` };
    const data = await response.json();
    if (data.error) return { success: false, error_code: 'api_error', error: data.error };
    return parseGoogleWebResults(data.organic_results || [], businessName, 'serpapi');
  } catch (err) {
    return { success: false, error_code: 'network_error', error: err.message };
  }
}

async function searchWithChromium(page, query, businessName) {
  try {
    const searchUrl = `https://www.google.com/search?q=${encodeURIComponent(query)}&hl=ar&gl=sa`;
    await page.goto(searchUrl, { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(2000);
    const pageContent = await page.content();
    if (pageContent.includes('unusual traffic') || pageContent.includes('captcha')) {
      return { success: false, error_code: 'blocked', error: 'Google blocked the request' };
    }
    const results = await page.evaluate((maxResults) => {
      const items = [];
      const resultElements = document.querySelectorAll('div.g, div[data-hveid]');
      for (let i = 0; i < Math.min(resultElements.length, maxResults); i++) {
        const el = resultElements[i];
        const linkEl = el.querySelector('a[href^="http"]');
        const titleEl = el.querySelector('h3');
        const snippetEl = el.querySelector('div[data-sncf], div.VwiC3b');
        if (linkEl && titleEl) {
          items.push({ rank: i + 1, title: titleEl.textContent || '', url: linkEl.href || '', snippet: snippetEl ? snippetEl.textContent : '' });
        }
      }
      return items;
    }, GOOGLE_WEB_MAX_RESULTS);
    if (results.length === 0) return { success: false, error_code: 'no_results', error: 'No results found' };
    return parseGoogleWebResults(results, businessName, 'chromium');
  } catch (err) {
    return { success: false, error_code: 'scrape_error', error: err.message };
  }
}

function parseGoogleWebResults(items, businessName, provider) {
  const results = [], socialCandidates = [], officialSiteCandidates = [], directories = [];
  for (let i = 0; i < items.length; i++) {
    const item = items[i];
    const result = { rank: item.rank || i + 1, title: item.title || '', url: item.link || item.url || '', snippet: item.snippet || '' };
    results.push(result);
    const social = detectSocialPlatform(result.url);
    if (social) socialCandidates.push({ platform: social.platform, handle: social.handle, url: result.url, evidence_rank: result.rank });
    if (isDirectoryUrl(result.url)) directories.push({ url: result.url, title: result.title, evidence_rank: result.rank });
    if (isOfficialSiteCandidate(result.url, businessName)) {
      try { officialSiteCandidates.push({ url: result.url, title: result.title, domain: new URL(result.url).hostname, evidence_rank: result.rank }); } catch (e) {}
    }
  }
  return { success: true, provider, results, social_candidates: socialCandidates, official_site_candidates: officialSiteCandidates, directories, result_count: results.length };
}

async function saveGoogleWebCache(hash, query, provider, data) {
  try {
    await fetch(`${getBase()}/v1/api/integration/google_web/cache.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
      body: JSON.stringify({ hash, query, provider, data })
    });
  } catch (e) { /* ignore */ }
}

async function incrementGoogleWebUsage(provider) {
  try {
    await fetch(`${getBase()}/v1/api/integration/google_web/usage.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
      body: JSON.stringify({ provider })
    });
  } catch (e) { /* ignore */ }
}

function buildAiPackFromGoogleWeb(googleWebData, existingAiPack = {}) {
  const aiPack = {
    evidence: existingAiPack.evidence || [],
    social_links: existingAiPack.social_links || {},
    official_site: existingAiPack.official_site || null,
    directories: existingAiPack.directories || [],
    confidence: existingAiPack.confidence || {},
    missing_data: existingAiPack.missing_data || [],
  };
  if (!googleWebData || !googleWebData.success) {
    aiPack.missing_data.push('google_web_failed');
    return aiPack;
  }
  for (const result of googleWebData.results || []) {
    aiPack.evidence.push({ source: 'google_web', url: result.url, title: result.title, snippet: result.snippet, rank: result.rank });
  }
  for (const social of googleWebData.social_candidates || []) {
    if (!aiPack.social_links[social.platform]) {
      aiPack.social_links[social.platform] = { url: social.url, handle: social.handle, confidence: social.evidence_rank <= 3 ? 'high' : 'medium' };
    }
  }
  if (!aiPack.official_site && googleWebData.official_site_candidates?.length > 0) {
    const best = googleWebData.official_site_candidates[0];
    aiPack.official_site = { url: best.url, domain: best.domain, confidence: best.evidence_rank <= 3 ? 'high' : 'medium' };
  }
  for (const dir of googleWebData.directories || []) {
    aiPack.directories.push({ url: dir.url, title: dir.title });
  }
  aiPack.confidence.google_web = googleWebData.result_count >= 5 ? 'high' : googleWebData.result_count >= 2 ? 'medium' : 'low';
  return aiPack;
}

// ---- HMAC signing for API requests (server accepts legacy secret header or these HMAC headers) ----
function sha256Hex(s) { return crypto.createHash('sha256').update(String(s || ''), 'utf8').digest('hex'); }
function hmacHeaders(method, pathOnly, bodyStr) {
  try {
    if (!SECRET) return {};
    const ts = Math.floor(Date.now() / 1000).toString();
    const bodySha = sha256Hex(bodyStr || '');
    const msg = `${String(method || 'GET').toUpperCase()}|${pathOnly}|${bodySha}|${ts}`;
    const sign = crypto.createHmac('sha256', SECRET).update(msg).digest('hex');
    return { 'X-Auth-Ts': ts, 'X-Auth-Sign': sign };
  } catch (_) { return {}; }
}
function authHeaders(method, pathOnly, bodyStr) {
  const base = { 'X-Worker-Id': WORKER_ID };
  if (SECRET) base['X-Internal-Secret'] = SECRET;
  if (WORKER_SECRET) base['X-Worker-Secret'] = WORKER_SECRET;
  return { ...base, ...hmacHeaders(method, pathOnly, bodyStr) };
}

// Open the local dashboard in default browser (Windows-friendly)
function openStatusPage() {
  const url = `http://127.0.0.1:${APP_PORT}/status`;
  try {
    if (process.platform === 'win32') {
      // Use rundll32 to open default browser without blocking
      spawnSync('rundll32', ['url.dll,FileProtocolHandler', url], { stdio: 'ignore', shell: true });
    } else if (process.platform === 'darwin') {
      spawnSync('open', [url], { stdio: 'ignore' });
    } else {
      spawnSync('xdg-open', [url], { stdio: 'ignore' });
    }
  } catch (e) { log('openStatusPage failed', String(e)); }
}

// ---- Lightweight persistent stats (per-day and totals) ----
const STATS_PATH = path.join(LOG_DIR, 'stats.json');
function defaultStats() { return { version: 1, createdAt: Date.now(), totalAdded: 0, jobsDoneTotal: 0, perDay: {} }; }
function loadStats() {
  try {
    if (fs.existsSync(STATS_PATH)) {
      const j = JSON.parse(fs.readFileSync(STATS_PATH, 'utf8') || '{}');
      if (!j || typeof j !== 'object') return defaultStats();
      j.perDay = j.perDay && typeof j.perDay === 'object' ? j.perDay : {};
      j.totalAdded = Number(j.totalAdded || 0);
      j.jobsDoneTotal = Number(j.jobsDoneTotal || 0);
      return j;
    }
  } catch (e) { /* ignore */ }
  return defaultStats();
}
function saveStats() { try { fs.writeFileSync(STATS_PATH, JSON.stringify(STATS)); } catch (e) { } }
function todayKey() { return new Date().toISOString().slice(0, 10); }
function ensureDay(d) { if (!STATS.perDay[d]) STATS.perDay[d] = { added: 0, jobsDone: 0 }; }
function addAdded(n) { const v = Number(n || 0); if (!v) return; STATS.totalAdded += v; const d = todayKey(); ensureDay(d); STATS.perDay[d].added += v; saveStats(); }
function addJobDone() { STATS.jobsDoneTotal += 1; const d = todayKey(); ensureDay(d); STATS.perDay[d].jobsDone += 1; saveStats(); }
const STATS = loadStats();

// ---- First-run helpers ----
function envPath() { return path.join(process.cwd(), '.env'); }
function loadEnvFile() { try { return fs.readFileSync(envPath(), 'utf8'); } catch (e) { return ''; } }
function saveEnvVars(vars) {
  const lines = loadEnvFile().split(/\r?\n/);
  const map = new Map();
  for (const ln of lines) { if (!ln.trim()) continue; const idx = ln.indexOf('='); if (idx > 0) { map.set(ln.slice(0, idx).trim(), ln.slice(1 + idx)); } }
  for (const [k, v] of Object.entries(vars)) {
    if (v === undefined || v === null) continue;
    map.set(k, String(v));
  }
  const out = Array.from(map.entries()).map(([k, v]) => `${k}=${v}`).join('\n');
  fs.writeFileSync(envPath(), out, 'utf8');
}
function randomId() { return 'wrk-' + Math.random().toString(36).slice(2, 8); }
function configMissing() {
  const missing = [];
  const hasBase = Boolean(getBase());
  const hasConfUrl = Boolean(process.env.WORKER_CONF_URL);
  if (!SECRET) missing.push('INTERNAL_SECRET');
  if (!(hasBase || hasConfUrl)) missing.push('BASE_URL or WORKER_CONF_URL');
  if (!WORKER_ID) missing.push('WORKER_ID');
  return missing;
}

// Warn if INTERNAL_SECRET likely truncated due to unquoted '#'
try {
  const rawEnv = loadEnvFile();
  if (rawEnv && /\bINTERNAL_SECRET\s*=.*#/.test(rawEnv) && !/INTERNAL_SECRET\s*=\s*"[^"]*#/.test(rawEnv)) {
    if (SECRET && SECRET.length < 16) {
      const warn = 'INTERNAL_SECRET may be truncated because of an unquoted # in .env. Wrap the value in double quotes.';
      console.warn(warn); log('WARN', warn); if (!LOG_STATE.lastError) LOG_STATE.lastError = warn;
    }
  }
} catch (_) { }

// ---- Update mechanism (optional) ----
function versionGt(a, b) {
  const pa = String(a).split('.').map(x => parseInt(x, 10) || 0);
  const pb = String(b).split('.').map(x => parseInt(x, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) { const da = pa[i] || 0, db = pb[i] || 0; if (da !== db) return da > db; }
  return false;
}
async function downloadTo(url, dest) {
  const res = await fetch(url); if (!res.ok) throw new Error('download failed ' + res.status);
  const fileStream = fs.createWriteStream(dest);
  await new Promise((resolve, reject) => { res.body.pipe(fileStream); res.body.on('error', reject); fileStream.on('finish', resolve); });
}
function sha256File(p) { const h = crypto.createHash('sha256'); const d = fs.readFileSync(p); h.update(d); return h.digest('hex'); }
async function maybeUpdate() {
  try {
    const ch = (process.env.WORKER_UPDATE_CHANNEL || '').trim();
    let url = process.env.WORKER_UPDATE_URL || (getBase() ? (getBase().replace(/\/$/, '') + '/api/latest.php') : '');
    if (url && ch) { url += (url.includes('?') ? '&' : '?') + 'channel=' + encodeURIComponent(ch); }
    if (!url) return;
    const r = await fetch(url).catch(() => null); if (!r || !r.ok) return;
    const info = await r.json().catch(() => null); if (!info || !info.version || !info.url) return;
    if (!versionGt(info.version, APP_VER)) return; // already latest
    const urlLower = String(info.url).toLowerCase();
    if (!(urlLower.endsWith('.exe'))) {
      log('update available', info.version, 'but installer EXE not provided; skipping auto-install');
      return;
    }
    // Use new branded filename for the temporary installer path; content verified by sha256 when provided
    const tmp = path.join(os.tmpdir(), 'OptForgeWorker_Setup.exe');
    await downloadTo(info.url, tmp);
    const size = fs.statSync(tmp).size;
    if (info.sha256) {
      const got = sha256File(tmp).toLowerCase();
      if (got !== String(info.sha256).toLowerCase()) { log('update sha256 mismatch expected', info.sha256, 'got', got); return; }
      log('update sha256 OK for', info.version, 'size', size);
    } else {
      log('update downloaded (no sha256 provided) version', info.version, 'size', size);
    }
    if (String(process.env.UPDATE_DRYRUN || '0') === '1') {
      log('UPDATE_DRYRUN=1 set — skipping installer execution');
      return;
    }
    const ps = 'powershell.exe';
    const args = ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(process.cwd(), 'update_worker.ps1'), '-SetupPath', tmp, '-Silent'];
    log('Invoking update installer...');
    spawnSync(ps, args, { stdio: 'inherit' });
  } catch (e) { log('maybeUpdate error', String(e)); }
}

// ---- Backend helpers ----
async function applyCentralConfig() {
  const CONF_URL = process.env.WORKER_CONF_URL || '';
  if (!CONF_URL) return;
  try {
    const url = CONF_URL + (CONF_URL.includes('?') ? '&' : '?') + 'code=' + encodeURIComponent(process.env.WORKER_CONF_CODE || '');
    const res = await fetch(url, { headers: { 'X-Worker-Id': WORKER_ID } });
    if (!res.ok) { log('central config fetch failed status', res.status); return; }
    const j = await res.json();
    if (j.base_url) { BASE = String(j.base_url).replace(/\/+$/, ''); BASES = []; baseIdx = 0; }
    if (j.display_name) { LOG_STATE.displayName = String(j.display_name); }
    if (typeof j.pull_interval_sec === 'number' && j.pull_interval_sec > 0) { PULL_SEC = j.pull_interval_sec; }
    if (typeof j.headless === 'boolean') { HEADLESS = j.headless; }
    if (typeof j.max_pages === 'number' && j.max_pages > 0) { MAX_PAGES = j.max_pages; }
    if (typeof j.until_end === 'boolean') { SCRAPE_UNTIL_END = j.until_end; }
    if (typeof j.lease_sec === 'number' && j.lease_sec > 0) { LEASE_SEC = j.lease_sec; }
    if (typeof j.report_batch_size === 'number' && j.report_batch_size > 0) { REPORT_BATCH_SIZE = j.report_batch_size; }
    if (typeof j.report_every_ms === 'number' && j.report_every_ms >= 1000) { REPORT_EVERY_MS = j.report_every_ms; }
    if (typeof j.report_first_ms === 'number' && j.report_first_ms >= 200) { REPORT_FIRST_MS = j.report_first_ms; }
    if (typeof j.item_delay_ms === 'number' && j.item_delay_ms >= 0) { ITEM_DELAY_MS = j.item_delay_ms; }
    // Persist for later use by launcher options
    if (j.chrome_exe) { process.env.CHROME_EXE = j.chrome_exe; }
    if (j.chrome_args) { process.env.CHROME_ARGS = j.chrome_args; }
    log('central config applied', JSON.stringify({ BASE, PULL_SEC, MAX_PAGES, HEADLESS, UNTIL_END: SCRAPE_UNTIL_END }));

    // Remote commands from super admin
    const rev = typeof j.command_rev === 'number' ? j.command_rev : 0;
    if (j.command && rev && rev !== LAST_CMD_REV) {
      LAST_CMD_REV = rev;
      const cmd = String(j.command).toLowerCase();
      log('central command received', cmd, 'rev', rev);
      if (cmd === 'pause') { LOG_STATE.paused = true; }
      else if (cmd === 'resume') { LOG_STATE.paused = false; await heartbeat().catch(() => { }); }
      else if (cmd === 'arm') { LOG_STATE.armed = true; }
      else if (cmd === 'disarm') { LOG_STATE.armed = false; LOG_STATE.active = false; }
      else if (cmd === 'update-now') { await maybeUpdate(); }
      else if (cmd === 'restart') {
        // Graceful self-restart: exit with code 17; launcher can relaunch
        log('Restart requested by central command');
        try { await pushMetrics(); } catch (_) { }
        setTimeout(() => process.exit(17), 250);
      }
      else if (cmd === 'heartbeat-now') { await heartbeat().catch(() => { }); }
      else { log('Unknown command from central config:', cmd); }
      // Acknowledge applying this command rev in the next metrics push
      LAST_APPLIED_CMD_REV = rev;
    }
  } catch (e) { log('central config error', String(e)); }
}
async function fetchText(url, opts = {}) {
  const res = await fetch(url, opts);
  const text = await res.text();
  return { status: res.status, ok: res.ok, text };
}
async function pullJob() {
  const url = `${getBase()}/api/pull_job.php?lease_sec=${LEASE_SEC}`;
  const headers = authHeaders('GET', '/api/pull_job.php', '');
  const { status, ok, text } = await fetchText(url, { headers });
  if (status === 401) {
    // Do not exit the process; surface as an error to trigger backoff and allow reconfiguration
    throw new Error('unauthorized: INTERNAL_SECRET mismatch');
  }
  if (status === 403) { log('Internal server disabled on backend. Sleeping...'); return { job: null }; }
  if (!ok) {
    log('pull_job non-OK', status, text.slice(0, 200).replace(/\s+/g, ' '));
    if (status === 0 || status === 502 || status === 503 || status === 504) { rotateBase(); }
    return { job: null };
  }
  try { return JSON.parse(text); }
  catch (e) { log('pull_job invalid JSON:', text.slice(0, 400).replace(/\s+/g, ' ')); return { job: null }; }
}
async function heartbeat() {
  try {
    const pathOnly = '/api/heartbeat.php';
    const info = Buffer.from(JSON.stringify({ ver: APP_VER, host: os.hostname() })).toString('utf8');
    const headers = { ...authHeaders('GET', pathOnly, ''), 'X-Worker-Info': info };
    const res = await fetchText(`${getBase()}${pathOnly}`, { headers });
    if (!res.ok) return false;
    const data = JSON.parse(res.text);
    if (data.stopped) { log('backend reports stopped — pausing'); }
    LOG_STATE.connected = true; return true;
  } catch (e) { return false; }
}
async function pushMetrics() {
  try {
    const pathOnly = '/api/worker_metrics.php';
    const day = todayKey(); const dayObj = STATS.perDay[day] || { added: 0, jobsDone: 0 };
    const uptimeSec = Math.round((Date.now() - LOG_STATE.startedAt) / 1000);
    const body = JSON.stringify({
      worker_id: WORKER_ID,
      version: APP_VER,
      host: os.hostname(),
      connected: LOG_STATE.connected,
      active: LOG_STATE.active,
      paused: LOG_STATE.paused,
      armed: LOG_STATE.armed,
      lastJob: LOG_STATE.lastJob,
      lastReport: LOG_STATE.lastReport,
      attempt_id: (LOG_STATE.lastJob && LOG_STATE.lastJob.attempt_id) ? LOG_STATE.lastJob.attempt_id : undefined,
      uptimeSec,
      todayAdded: dayObj.added || 0,
      totalAdded: STATS.totalAdded || 0,
      jobsDoneToday: dayObj.jobsDone || 0,
      jobsDoneTotal: STATS.jobsDoneTotal || 0,
      last_applied_command_rev: LAST_APPLIED_CMD_REV || undefined,
      log_tail: (() => { try { const f = path.join(LOG_DIR, `worker-${new Date().toISOString().slice(0, 10)}.log`); if (!fs.existsSync(f)) return null; const txt = fs.readFileSync(f, 'utf8'); const lines = txt.trim().split(/\r?\n/); return lines.slice(-80).join('\n'); } catch (_) { return null; } })()
    });
    const headers = { 'Content-Type': 'application/json', ...authHeaders('POST', pathOnly, body) };
    const res = await fetch(`${getBase()}${pathOnly}`, { method: 'POST', headers, body }).catch(() => null);
    if (res && res.ok) { LAST_METRICS_PUSH = Date.now(); }
  } catch (_) { }
}
async function report(job_id, items, cursor, done = false) {
  const pathOnly = '/api/report_results.php';
  const body = JSON.stringify({ job_id, items, cursor, done, extend_lease_sec: LEASE_SEC, attempt_id: (LOG_STATE.lastJob && LOG_STATE.lastJob.attempt_id) ? LOG_STATE.lastJob.attempt_id : undefined });
  const headers = { 'Content-Type': 'application/json', ...authHeaders('POST', pathOnly, body) };
  const res = await fetch(`${getBase()}${pathOnly}`, { method: 'POST', headers, body }).catch(e => ({ ok: false, status: 0, _err: String(e) }));
  if (!res || !res.ok) {
    const st = (res && typeof res.status === 'number') ? res.status : 0;
    const msg = (res && res._err) ? res._err : '';
    log('report status', st, msg ? ('err ' + msg) : '');
    // Surface error to caller to decide backoff/abort
    const err = new Error(`report_failed status=${st}`);
    err.name = 'ReportError';
    err.code = st;
    throw err;
  }
  const j = await res.json().catch(() => ({ ok: true }));
  LOG_STATE.lastReport = { t: Date.now(), added: j.added || 0, cursor };
  // Update cumulative stats
  if (typeof j.added === 'number') { addAdded(j.added); }
  return j;
}

// Check if job is still active (not paused/cancelled) - call periodically during processing
async function checkJobStatus(job_id) {
  try {
    const pathOnly = `/api/job_status.php?job_id=${job_id}`;
    const headers = authHeaders('GET', pathOnly, '');
    const res = await fetch(`${getBase()}${pathOnly}`, { headers });
    if (!res || !res.ok) return { should_stop: false }; // If API unavailable, continue working
    const data = await res.json();
    if (data.should_stop) {
      log('Job', job_id, 'status changed to', data.status, '- stopping processing');
    }
    return data;
  } catch (e) {
    log('checkJobStatus error:', String(e).slice(0, 200));
    return { should_stop: false }; // On error, continue working
  }
}

// ---- Scraping helpers ----
function uniqPhones(list) {
  const seen = new Set(); const out = [];
  for (const it of list) {
    const p = (it.phone || '').replace(/[^\d+]/g, '');
    if (!p || seen.has(p)) continue;
    seen.add(p); out.push({ ...it, phone: p });
  }
  return out;
}
function normalizeDigits(s) {
  if (!s) return s;
  const map = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };
  return s.replace(/[٠-٩]/g, ch => map[ch] || ch);
}
async function ensureSearchReady(page) {
  try { const acc = await page.$('button:has-text("أوافق"),button:has-text("قبول الكل"),button:has-text("الموافقة على الكل")'); if (acc) await acc.click({ timeout: 1500 }); } catch (e) { }
  try { const acc2 = await page.$('button:has-text("I agree"), button:has-text("Accept all")'); if (acc2) await acc2.click({ timeout: 1500 }); } catch (e) { }
  await page.click('input#searchboxinput', { timeout: 15000 }).catch(() => { });
}
async function collectFromDetail(ctx, hrefOrPage) {
  let page;
  if (typeof hrefOrPage === 'string') {
    page = await ctx.newPage();
    await page.goto(hrefOrPage.startsWith('http') ? hrefOrPage : ('https://www.google.com' + hrefOrPage), { waitUntil: 'domcontentloaded' });
  } else { page = hrefOrPage; }
  await page.waitForTimeout(1200);
  let phone = '';
  try {
    const aTel = await page.$('a[href^="tel:"]');
    if (aTel) { const href = await aTel.getAttribute('href'); if (href) phone = href.replace(/^tel:/, ''); }
    if (!phone) {
      const btn = await page.$('button[data-item-id*="phone:tel"], button[aria-label*="phone" i], button[aria-label*="الهاتف"]');
      if (btn) { const t = (await btn.getAttribute('aria-label')) || (await btn.textContent()) || ''; if (t) phone = (t.match(/[\+]?[\d\s\-\(\)]+/g) || []).join(' '); }
    }
  } catch (e) { }
  if (!phone) {
    const body = await page.textContent('body').catch(() => '');
    if (body) { const text = normalizeDigits(body); const match = text.match(/(?:\+?\d[\d\s\-\(\)]{6,}\d)/); if (match) phone = match[0]; }
  }
  let name = ''; try { const h = await page.$('h1[aria-level="1"], h1'); if (h) name = (await h.textContent() || '').trim(); } catch (e) { }
  let city = '', country = '';
  try {
    const addrBtn = await page.$('button[data-item-id*="address"]');
    const addrTextRaw = addrBtn ? (await addrBtn.textContent() || '') : '';
    const addrText = normalizeDigits(addrTextRaw);
    const parts = addrText.split(',').map(s => s.trim());
    if (parts.length >= 2) { city = parts[parts.length - 2]; country = parts[parts.length - 1]; }
  } catch (e) { }
  let rating = null;
  try { const star = await page.$('[aria-label*="نجوم"], [aria-label*="stars" i]'); if (star) { const t = await star.getAttribute('aria-label'); const m = t && t.match(/([0-9]+[\.,][0-9]|[0-9]+)/); if (m) { rating = parseFloat(m[1].replace(',', '.')); } } } catch (e) { }
  let website = null; try { const w = await page.$('a:has-text("Website"), a:has-text("الموقع الإلكتروني")'); if (w) { website = await w.getAttribute('href'); } } catch (e) { }
  let email = null; try { const mail = await page.$('a[href^="mailto:"]'); if (mail) { const href = await mail.getAttribute('href'); if (href) email = href.replace(/^mailto:/, ''); } } catch (e) { }
  let social = {}; try {
    const anchors = await page.$$('a[href^="http"]');
    for (const a of anchors) {
      const href = (await a.getAttribute('href')) || ''; const h = href.toLowerCase();
      if (h.includes('facebook.com')) social.facebook = href; else if (h.includes('instagram.com')) social.instagram = href;
      else if (h.includes('twitter.com') || h.includes('x.com')) social.twitter = href; else if (h.includes('snapchat.com')) social.snapchat = href;
      else if (h.includes('tiktok.com')) social.tiktok = href; else if (h.includes('linkedin.com')) social.linkedin = href;
    }
    if (Object.keys(social).length === 0) social = null;
  } catch (e) { }
  let types = null; try { const chips = await page.$$('[role="button"][jsaction*="pane.rating.category"], button:has-text("فئة"), button:has-text("Category")'); const vals = []; for (const c of chips) { const t = (await c.textContent())?.trim(); if (t) vals.push(t); } if (vals.length) types = vals.join(','); } catch (e) { }
  phone = normalizeDigits(phone).replace(/[^\d\+]/g, '');
  return { name, phone, city, country, rating, website, email, social, types };
}
async function findFeed(page) {
  const selectors = ['div[role="feed"]', 'div[aria-label*="Results" i]', 'div[aria-label*="نتائج"]'];
  for (const sel of selectors) { const el = await page.$(sel); if (el) return el; }
  return null;
}
async function getResultAnchors(page) {
  let anchors = await page.$$('div[role="feed"] a[href*="/maps/place/"]');
  if (anchors.length === 0) { anchors = await page.$$('a[href*="/maps/place/"]'); }
  if (anchors.length === 0) { const articles = await page.$$('div[role="feed"] [role="article"], [role="article"]'); const out = []; for (const a of articles) { const link = await a.$('a[href*="/maps/place/"]'); if (link) out.push(link); } anchors = out; }
  const filtered = [];
  for (const a of anchors) { try { const card = await a.evaluateHandle(node => node.closest('[role="article"]') || node.closest('div')); if (card) { const txt = (await card.asElement().textContent()) || ''; if (/إعلان/.test(txt)) continue; } } catch (e) { } filtered.push(a); }
  return filtered;
}
async function dumpDebug(page, prefix) {
  try {
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    const base = path.join(LOG_DIR, `${prefix}-${ts}`);
    const feed = await findFeed(page);
    if (feed) { const html = await feed.evaluate(el => el.innerHTML); fs.writeFileSync(base + "-feed.html", html, 'utf8'); }
    else { const html = await page.content(); fs.writeFileSync(base + "-page.html", html, 'utf8'); }
    await page.screenshot({ path: base + ".png", fullPage: true }).catch(() => { });
    log('Saved debug snapshot to', base);
  } catch (e) { log('dumpDebug error', String(e)); }
}

// ---- Worker main loop ----
async function run() {
  await applyCentralConfig();
  if (!getBase()) die('BASE_URL not set (env or central config).');
  const args = ['--start-maximized', '--disable-blink-features=AutomationControlled'];
  if (CHROME_ARGS) { const re = /[^\s"]+|"([^"]*)"/g; let m; while ((m = re.exec(CHROME_ARGS)) !== null) { args.push(m[1] ? m[1] : m[0]); } }
  let launchOpts = { headless: HEADLESS, args };
  if (CHROME_EXE) launchOpts.executablePath = CHROME_EXE; else { const CHANNEL = (process.env.CHANNEL || '').toLowerCase(); if (CHANNEL === 'chrome') launchOpts.channel = 'chrome'; }
  log('Starting worker', JSON.stringify({ BASE: getBase(), WORKER_ID, PULL_SEC, HEADLESS, MAX_PAGES, UNTIL_END: SCRAPE_UNTIL_END, PERSIST_DIR, CHROME_EXE, CHROME_ARGS }));
  async function newContext() {
    try { const ctx = await chromium.launchPersistentContext(PERSIST_DIR, launchOpts); await ctx.addInitScript(() => { Object.defineProperty(navigator, 'webdriver', { get: () => undefined }); Object.defineProperty(navigator, 'languages', { get: () => ['ar', 'en-US', 'en'] }); Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] }); }); return ctx; }
    catch (e) {
      const msg = String(e); log('launchPersistentContext failed:', msg.slice(0, 300));
      // If Chromium missing, try installing
      if (/browser needs to be installed|executable doesn't exist|\binstall\b/i.test(msg)) { log('Attempting to install Playwright Chromium...'); try { spawnSync('npx', ['playwright', 'install', 'chromium', '--with-deps'], { stdio: 'inherit', shell: process.platform === 'win32' }); } catch (_) { } }
      // If profile is corrupted/locked, clean and retry once
      try {
        if (fs.existsSync(PERSIST_DIR)) {
          const marker = path.join(PERSIST_DIR, 'DevToolsActivePort');
          const lock1 = path.join(PERSIST_DIR, 'SingletonLock');
          if (fs.existsSync(marker) || fs.existsSync(lock1)) {
            log('Cleaning persistent profile directory due to lock/corruption');
            try { fs.rmSync(PERSIST_DIR, { recursive: true, force: true }); } catch (er) { log('Profile cleanup failed', String(er)); }
          }
        }
      } catch (cleanErr) { log('Profile check error', String(cleanErr)); }
      try { const ctx2 = await chromium.launchPersistentContext(PERSIST_DIR, launchOpts); await ctx2.addInitScript(() => { Object.defineProperty(navigator, 'webdriver', { get: () => undefined }); Object.defineProperty(navigator, 'languages', { get: () => ['ar', 'en-US', 'en'] }); Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] }); }); return ctx2; } catch (e2) { log('Retry persistent failed, falling back to non-persistent:', String(e2).slice(0, 300)); }
      const browser = await chromium.launch(launchOpts); const ctx = await browser.newContext(); await ctx.addInitScript(() => { Object.defineProperty(navigator, 'webdriver', { get: () => undefined }); Object.defineProperty(navigator, 'languages', { get: () => ['ar', 'en-US', 'en'] }); Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] }); }); return ctx;
    }
  }
  let context = await newContext();
  await context.addInitScript(() => { Object.defineProperty(navigator, 'webdriver', { get: () => undefined }); Object.defineProperty(navigator, 'languages', { get: () => ['ar', 'en-US', 'en'] }); Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] }); });
  let page = await context.newPage(); try { await page.setViewportSize({ width: 1400, height: 900 }); } catch (e) { }
  if (!(await heartbeat())) { log('WARN heartbeat failed — check BASE_URL or INTERNAL_SECRET'); } else { log('heartbeat ok'); }
  // Push metrics periodically
  setInterval(() => { pushMetrics().catch(() => { }); }, 5000);
  // Background heartbeat to keep connectivity state accurate even when paused/disarmed
  setInterval(() => { heartbeat().catch(() => { }); }, 15000);
  
  // Phase 6: Integration job polling (runs in parallel)
  let integrationPage = null;
  async function pollIntegrationJobs() {
    try {
      const url = `${getBase()}/v1/api/integration/jobs/process.php`;
      const res = await fetch(url, {
        method: 'GET',
        headers: { 'X-Internal-Secret': SECRET, 'X-Worker-Secret': SECRET }
      });
      if (!res.ok) return null;
      const data = await res.json();
      if (!data.ok || !data.job) return null;
      return data.job;
    } catch (e) { return null; }
  }
  
  async function processIntegrationJob(job) {
    log('[INTEGRATION] Processing job', job.id, 'modules:', job.modules);
    if (!integrationPage || integrationPage.isClosed()) {
      integrationPage = await context.newPage();
    }
    const results = { modules: {}, snapshot: { lead_id: job.forgeLeadId, collected_at: new Date().toISOString(), sources: [] } };
    
    for (const moduleName of job.modules || []) {
      // Report module start
      await fetch(`${getBase()}/v1/api/integration/jobs/process.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
        body: JSON.stringify({ action: 'module_start', jobId: job.id, module: moduleName })
      }).catch(() => {});
      
      try {
        if (moduleName === 'maps') {
          const lead = job.lead || {};
          const query = `${lead.name || ''} ${lead.city || ''}`.trim();
          if (query) {
            log('[INTEGRATION] Maps search:', query);
            await integrationPage.goto(`https://www.google.com/maps/search/${encodeURIComponent(query)}`, { waitUntil: 'networkidle', timeout: 60000 });
            await integrationPage.waitForTimeout(3000);
            // Extract data
            const mapsData = await integrationPage.evaluate(() => {
              const data = { name: null, category: null, address: null, phones: [], website: null, rating: null, reviews_count: null };
              const nameEl = document.querySelector('h1.DUwDvf, h1.fontHeadlineLarge');
              if (nameEl) data.name = nameEl.textContent.trim();
              const ratingEl = document.querySelector('span.ceNzKf, div.F7nice span');
              if (ratingEl) { const m = (ratingEl.getAttribute('aria-label') || ratingEl.textContent).match(/(\d+[.,]\d+)/); if (m) data.rating = parseFloat(m[1].replace(',', '.')); }
              return data;
            });
            results.modules.maps = { success: true, data: mapsData };
            results.snapshot.maps = mapsData;
            results.snapshot.sources.push('maps');
            log('[INTEGRATION] Maps done:', mapsData.name || 'no name');
          }
        } else if (moduleName === 'website') {
          const lead = job.lead || {};
          let url = lead.website || lead.url;
          if (url) {
            if (!url.startsWith('http')) url = 'https://' + url;
            log('[INTEGRATION] Website:', url);
            await integrationPage.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
            await integrationPage.waitForTimeout(2000);
            const webData = await integrationPage.evaluate(() => {
              const data = { title: document.title, emails: [], phones: [] };
              const emailRe = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
              const emails = (document.body.innerText || '').match(emailRe) || [];
              data.emails = [...new Set(emails)].slice(0, 5);
              return data;
            });
            results.modules.website = { success: true, data: webData };
            results.snapshot.website_data = webData;
            results.snapshot.sources.push('website');
            log('[INTEGRATION] Website done:', webData.title || 'no title');
          }
        } else if (moduleName === 'google_web') {
          // Phase 7: Google Web Search module
          const lead = job.lead || {};
          const query = `${lead.name || ''} ${lead.city || ''}`.trim();
          if (query) {
            log('[INTEGRATION] Google Web search:', query);
            const googleWebResult = await runGoogleWebSearch(integrationPage, query, lead.name || query);
            if (googleWebResult.success) {
              results.modules.google_web = { success: true, data: googleWebResult };
              results.snapshot.google_web = googleWebResult;
              results.snapshot.sources.push('google_web');
              // Build AI pack from results
              results.snapshot.ai_pack = buildAiPackFromGoogleWeb(googleWebResult, results.snapshot.ai_pack || {});
              log('[INTEGRATION] Google Web done:', googleWebResult.result_count, 'results, provider:', googleWebResult.provider || 'unknown');
            } else {
              log('[INTEGRATION] Google Web skipped/failed:', googleWebResult.error_code || 'unknown');
              results.modules.google_web = { success: false, error_code: googleWebResult.error_code, error: googleWebResult.error };
              // Mark as skipped if no provider available
              if (googleWebResult.error_code === 'no_api_key' || googleWebResult.error_code === 'no_provider') {
                await fetch(`${getBase()}/v1/api/integration/jobs/process.php`, {
                  method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
                  body: JSON.stringify({ action: 'module_skipped', jobId: job.id, module: moduleName, reason: googleWebResult.error_code })
                }).catch(() => {});
                continue;
              }
            }
          }
        }
        // Report success
        await fetch(`${getBase()}/v1/api/integration/jobs/process.php`, {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
          body: JSON.stringify({ action: 'module_success', jobId: job.id, module: moduleName, output: results.modules[moduleName]?.data || {} })
        }).catch(() => {});
      } catch (err) {
        log('[INTEGRATION] Module', moduleName, 'failed:', err.message);
        results.modules[moduleName] = { success: false, error: err.message };
        await fetch(`${getBase()}/v1/api/integration/jobs/process.php`, {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
          body: JSON.stringify({ action: 'module_failed', jobId: job.id, module: moduleName, errorCode: 'error', errorMessage: err.message })
        }).catch(() => {});
      }
    }
    
    // Report job complete
    const successCount = Object.values(results.modules).filter(m => m.success).length;
    const status = successCount === job.modules.length ? 'success' : successCount > 0 ? 'partial' : 'failed';
    await fetch(`${getBase()}/v1/api/integration/jobs/process.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': SECRET },
      body: JSON.stringify({ action: 'job_complete', jobId: job.id, status, snapshot: results.snapshot })
    }).catch(() => {});
    log('[INTEGRATION] Job', job.id, 'completed with status:', status);
  }
  
  // Start integration polling loop
  (async () => {
    while (true) {
      try {
        if (!LOG_STATE.paused && LOG_STATE.armed) {
          const intJob = await pollIntegrationJobs();
          if (intJob) await processIntegrationJob(intJob);
        }
      } catch (e) { log('[INTEGRATION] Poll error:', e.message); }
      await sleep(10000); // Poll every 10 seconds
    }
  })();
  
  let failCount = 0;
  while (true) {
    try {
      if (LOG_STATE.paused || !LOG_STATE.armed) {
        LOG_STATE.active = false;
        // Keep connection state via background heartbeat; do not force connected=false here
        log(LOG_STATE.paused ? 'paused by user — sleeping' : 'disarmed — sleeping');
        await sleep(2000); continue;
      }
      const pulled = await pullJob();
      if (pulled && pulled.stopped) { log('system stopped by admin — sleeping'); await sleep(PULL_SEC * 1000); continue; }
      const { job } = pulled || {};
      if (!job) { log('idle: no jobs, sleeping', PULL_SEC, 'sec'); await sleep(PULL_SEC * 1000); continue; }
      failCount = 0; REPORT_FAIL_STREAK = 0; LOG_STATE.lastJob = job; LOG_STATE.active = true; log('Job', job.id, JSON.stringify(job));
      const jobStartAt = Date.now();
      const [lat, lng] = job.ll.split(',').map(s => s.trim()); const startUrl = `https://www.google.com/maps/@${lat},${lng},14z`;
      if (!page || page.isClosed()) { try { page = await context.newPage(); } catch (e) { } }
      try { await page.goto(startUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }); }
      catch (navErr) { log('page.goto failed, recreating context/page and retrying once:', String(navErr).slice(0, 200)); try { await context.close(); } catch (e) { } context = await newContext(); page = await context.newPage(); await page.goto(startUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }); }
      await ensureSearchReady(page); await page.fill('input#searchboxinput', job.query, { timeout: 15000 }).catch(() => { }); await page.keyboard.press('Enter'); await page.waitForSelector('[role="feed"], a[href*="/maps/place/"]', { timeout: 20000 }).catch(() => { }); await page.waitForTimeout(2000);
      let pageIndex = 0; const linkSet = new Set();
      async function reachedEndText() {
        try {
          const body = (await page.textContent('body')) || '';
          const texts = ['لقد وصلت إلى نهاية القائمة', 'You\'ve reached the end of the list', 'No more results'];
          return texts.some(t => body.includes(t));
        } catch (_) { return false; }
      }
      async function atBottom(feed) {
        try {
          if (feed) { return await feed.evaluate(el => (Math.ceil(el.scrollTop + el.clientHeight) >= el.scrollHeight - 2)); }
          return await page.evaluate(() => (Math.ceil(window.scrollY + window.innerHeight) >= document.documentElement.scrollHeight - 2));
        } catch (_) { return false; }
      }
      let tries = 0, noNewStreak = 0;
      const safetyMax = SCRAPE_UNTIL_END ? Math.max(40, Math.min(400, MAX_PAGES * 50)) : MAX_PAGES;
      while (true) {
        const feed = await findFeed(page);
        const anchors = await getResultAnchors(page);
        const before = linkSet.size;
        for (const a of anchors) { const href = await a.getAttribute('href'); if (!href) continue; const abs = href.startsWith('http') ? href : ('https://www.google.com' + href); const key = abs.split('#')[0]; if (!linkSet.has(key)) { linkSet.add(key); } }
        const added = linkSet.size - before;
        log('results on page', pageIndex + 1, anchors.length, 'unique total', linkSet.size, 'added', added);
        if (added === 0) noNewStreak++; else noNewStreak = 0;
        const endTxt = await reachedEndText();
        const bottom = await atBottom(feed);
        if (SCRAPE_UNTIL_END) {
          if (endTxt || (bottom && noNewStreak >= 2)) { log('end-of-list reached (until_end)'); break; }
          if (tries >= safetyMax) { log('safety break (max scroll cycles)'); break; }
        } else {
          if (pageIndex + 1 >= MAX_PAGES) { break; }
        }
        // Scroll further
        if (feed) { try { await feed.evaluate(el => { el.scrollBy(0, Math.max(400, el.clientHeight)); }); } catch (_) { /* ignore */ } await page.waitForTimeout(1200); }
        else { await page.mouse.wheel(0, 1500); await page.waitForTimeout(800); }
        pageIndex++; tries++;
      }
      const links = Array.from(linkSet.values()); let cursor = Number(job.last_cursor || 0); let batch = []; let lastReportAt = 0; let firstFlushed = false; const targetCount = job.target_count ? Number(job.target_count) : null; let totalAdded = Number(job.result_count || 0);
      let lastStatusCheck = 0;
      for (let i = cursor; i < links.length; i++) {
        // Check job status every 5 iterations to respect pause/cancel commands
        if (i - lastStatusCheck >= 5 || lastStatusCheck === 0) {
          const statusResp = await checkJobStatus(job.id);
          lastStatusCheck = i;
          if (statusResp.should_stop) {
            log('Job', job.id, 'was paused/cancelled - aborting processing');
            break;
          }
        }
        try { const details = await context.newPage(); await details.goto(links[i], { waitUntil: 'domcontentloaded', timeout: 60000 }); const det = await collectFromDetail(context, details); det.source_url = links[i]; await details.close().catch(() => { }); if (det.phone) batch.push(det); } catch (err) { log('detail error', String(err).slice(0, 200)); }
        const now = Date.now();
        const timeSince = lastReportAt ? (now - lastReportAt) : Infinity;
        const earlyWindow = (!firstFlushed && REPORT_FIRST_MS > 0 && now - (lastReportAt || 0) >= REPORT_FIRST_MS);
        const shouldFlush = batch.length >= REPORT_BATCH_SIZE || timeSince >= REPORT_EVERY_MS || earlyWindow;
        if (shouldFlush) {
          const payload = uniqPhones(batch);
          try {
            const resp = await report(job.id, payload, i + 1, false);
            REPORT_FAIL_STREAK = 0; // success
            batch = [];
            log('partial report payload', payload.length, 'cursor', i + 1, JSON.stringify(resp));
            if (resp && typeof resp.added === 'number') { totalAdded += resp.added; }
            if ((resp && resp.done === true) || (targetCount && totalAdded >= targetCount)) {
              log('target reached (server/est):', totalAdded, 'target=', targetCount);
              break;
            }
            lastReportAt = now; if (!firstFlushed) firstFlushed = true;
          } catch (e) {
            REPORT_FAIL_STREAK++;
            const st = (e && typeof e.code === 'number') ? e.code : 0;
            log('report flush failed streak=', REPORT_FAIL_STREAK, 'status=', st);
            // 401 -> config mismatch, pause & resync
            if (st === 401) { LOG_STATE.paused = true; await applyCentralConfig(); await heartbeat(); throw e; }
            // 404 -> API missing on server; pause to avoid spinning
            if (st === 404) { LOG_STATE.paused = true; log('report endpoint appears missing (404). Pausing worker until server is fixed.'); throw e; }
            // Backoff proportional to streak
            const backoffMs = Math.min(60000, 3000 * REPORT_FAIL_STREAK);
            await sleep(backoffMs);
            // Break current job loop so we re-pull and try later (server kept last_cursor)
            throw e;
          }
        }
        try { if (!page || page.isClosed()) throw new Error('page closed'); await page.waitForTimeout(ITEM_DELAY_MS); }
        catch (waitErr) { log('page wait error', String(waitErr).slice(0, 200), '— recreating context/page'); try { await context.close(); } catch (e) { } context = await newContext(); page = await context.newPage(); try { await page.goto(startUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }); await ensureSearchReady(page); await page.fill('input#searchboxinput', job.query, { timeout: 15000 }).catch(() => { }); await page.keyboard.press('Enter'); await page.waitForSelector('[role="feed"], a[href*="/maps/place/"]', { timeout: 20000 }).catch(() => { }); await page.waitForTimeout(800); } catch (nav2) { log('recreate nav error', String(nav2).slice(0, 200)); } }
        // Guard: abort job if it exceeds max runtime
        if (JOB_MAX_MINUTES > 0 && ((Date.now() - jobStartAt) > JOB_MAX_MINUTES * 60 * 1000)) {
          log('job runtime exceeded', JOB_MAX_MINUTES, 'minutes — aborting attempt to avoid being stuck');
          throw new Error('job_time_cap_exceeded');
        }
      }
      if (batch.length) {
        const payload = uniqPhones(batch);
        try {
          const resp = await report(job.id, payload, links.length, false);
          REPORT_FAIL_STREAK = 0;
          log('final partial payload', payload.length, JSON.stringify(resp));
          if (resp && typeof resp.added === 'number') { totalAdded += resp.added; }
          if (resp && resp.done === true) { addJobDone(); LOG_STATE.active = false; continue; }
        } catch (e) {
          REPORT_FAIL_STREAK++;
          const st = (e && typeof e.code === 'number') ? e.code : 0;
          log('final flush failed status=', st, 'streak=', REPORT_FAIL_STREAK);
          throw e;
        }
      }
      try {
        const doneResp = await report(job.id, [], links.length, true);
        REPORT_FAIL_STREAK = 0;
        log('done report', JSON.stringify(doneResp));
      } catch (e) {
        REPORT_FAIL_STREAK++;
        const st = (e && typeof e.code === 'number') ? e.code : 0;
        log('done report failed status=', st, 'streak=', REPORT_FAIL_STREAK);
        throw e;
      }
      addJobDone(); LOG_STATE.active = false;
    } catch (err) {
      LOG_STATE.lastError = String(err);
      log('Loop error', (err && err.stack) ? err.stack : String(err));
      // Escalate on repeated report failures: restart context first, then process, to self-heal
      try { await context.close(); } catch (_) { }
      try { context = await newContext(); page = await context.newPage(); } catch (_) { }
      if (REPORT_FAIL_STREAK >= REPORT_FAIL_MAX) {
        log('report failure streak exceeded max (', REPORT_FAIL_MAX, ') — restarting process for self-heal');
        setTimeout(() => process.exit(17), 100);
        await sleep(2000);
      }
      failCount = Math.min(failCount + 1, 10);
      const backoff = Math.min(PULL_SEC * 1000 * Math.pow(1.5, failCount), 120000);
      log('backing off for', Math.round(backoff / 1000), 'sec (failCount=', failCount, ')');
      await sleep(backoff);
    }
  }
}

process.on('uncaughtException', (e) => { LOG_STATE.lastError = 'uncaught: ' + String(e); log('uncaughtException', String(e.stack || e)); });
process.on('unhandledRejection', (e) => { LOG_STATE.lastError = 'unhandledRejection: ' + String(e); log('unhandledRejection', String(e.stack || e)); });

// ---- Single HTTP server (first-run UI + status) ----
const APP_PORT = parseInt(process.env.WORKER_UI_PORT || '4499', 10);
const app = express();
app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.get('/', (req, res) => { if (configMissing().length) { return res.redirect('/setup'); } res.redirect('/status'); });
app.get('/setup', (req, res) => {
  // Always show the setup form (even if already configured) to allow quick edits
  const missing = configMissing();
  const guessBase = getBase() || process.env.WORKER_CONF_URL ? '' : (process.env.BASE_URL || 'http://127.0.0.1:8080');
  res.send(`<!doctype html><meta charset="utf-8"><title>تهيئة أول مرة</title>
  <style>body{font-family:system-ui;direction:rtl;background:#0b1220;color:#eef3fb;padding:24px} .card{background:#0e1a2d;border:1px solid #11233d;border-radius:12px;padding:16px;max-width:740px} label{display:block;margin:10px 0 4px} input{width:100%;padding:10px;border-radius:8px;border:1px solid #2a3b57;background:#091222;color:#e6eefc} .row{display:flex;gap:12px} .row>div{flex:1} button{background:#2563eb;color:#fff;padding:10px 14px;border:0;border-radius:8px;cursor:pointer;margin-top:14px} .muted{color:#9fb3d1;font-size:.9em}</style>
  <h2>إعداد العامل</h2>
  ${missing.length ? `<div class=muted>حقول ناقصة: ${missing.join(', ')}</div>` : `<div class=muted>الإعداد مكتمل، يمكنك تعديل القيم وحفظها.</div>`}
  <div class=card>
  <form method="post" action="/setup">
    <label>رابط السيرفر (BASE_URL) — مثال: http://localhost/LeadsMembershipPRO</label>
    <input name="BASE_URL" placeholder="${guessBase}" value="${getBase() || guessBase}">
    <div class=row>
      <div>
        <label>الرمز السري الداخلي (INTERNAL_SECRET)</label>
  <input name="INTERNAL_SECRET" placeholder="ضع نفس الرمز من الإعدادات في لوحة الإدارة" value="${SECRET || ''}">
      </div>
      <div>
        <label>معرّف العامل (WORKER_ID)</label>
  <input name="WORKER_ID" value="${WORKER_ID || randomId()}">
      </div>
    </div>
    <div class=row>
      <div>
        <label>فاصل السحب بالثواني (PULL_INTERVAL_SEC)</label>
  <input name="PULL_INTERVAL_SEC" value="${PULL_SEC}">
      </div>
      <div>
        <label>تشغيل بدون واجهة (HEADLESS: true/false)</label>
  <input name="HEADLESS" value="${HEADLESS}">
      </div>
    </div>
    <details style="margin-top:10px"><summary>إعداد مركزي (اختياري)</summary>
      <label>رابط إعداد العامل (WORKER_CONF_URL) — مثال: http://localhost/LeadsMembershipPRO/api/worker_config.php</label>
  <input name="WORKER_CONF_URL" value="${process.env.WORKER_CONF_URL || ''}">
      <label>رمز الإعداد (WORKER_CONF_CODE)</label>
  <input name="WORKER_CONF_CODE" value="${process.env.WORKER_CONF_CODE || ''}">
    </details>
    <button type="submit">حفظ وبدء التشغيل</button>
    <div class=muted>يمكنك دائمًا زيارة <b>/status</b> و<b>/logs</b> للمراقبة.</div>
  </form>
  </div>`);
});
app.post('/setup', async (req, res) => {
  try {
    const body = req.body || {};
    const toSave = {
      BASE_URL: (body.BASE_URL || '').trim(),
      INTERNAL_SECRET: (body.INTERNAL_SECRET || '').trim(),
      WORKER_ID: (body.WORKER_ID || '').trim() || randomId(),
      PULL_INTERVAL_SEC: (body.PULL_INTERVAL_SEC || '').trim() || String(PULL_SEC),
      HEADLESS: String(body.HEADLESS || HEADLESS),
    };
    if ((body.WORKER_CONF_URL || '').trim()) toSave.WORKER_CONF_URL = (body.WORKER_CONF_URL || '').trim();
    if ((body.WORKER_CONF_CODE || '').trim()) toSave.WORKER_CONF_CODE = (body.WORKER_CONF_CODE || '').trim();
    saveEnvVars(toSave);
    SECRET = toSave.INTERNAL_SECRET || SECRET;
    WORKER_ID = toSave.WORKER_ID || WORKER_ID || randomId();
    PULL_SEC = parseInt(toSave.PULL_INTERVAL_SEC || String(PULL_SEC), 10);
    HEADLESS = String(toSave.HEADLESS || HEADLESS).toLowerCase() === 'true';
    if (toSave.BASE_URL) { BASE = String(toSave.BASE_URL).replace(/\/+$/, ''); BASES = []; baseIdx = 0; }
    if (toSave.WORKER_CONF_URL) { process.env.WORKER_CONF_URL = toSave.WORKER_CONF_URL; }
    if (toSave.WORKER_CONF_CODE) { process.env.WORKER_CONF_CODE = toSave.WORKER_CONF_CODE; }
    const missingAfter = configMissing();
    if (missingAfter.length) { return res.status(400).send('إعداد ناقص: ' + missingAfter.join(', ')); }
    res.send('<meta charset="utf-8"><div style="font-family:system-ui;direction:rtl;padding:20px;background:#0b1220;color:#eaf1ff">✅ تم الحفظ. سيبدأ العامل الآن. <a style="color:#93c5fd" href="/status">عرض الحالة</a></div>');
    if (!RUNNING) { RUNNING = true; run().catch(e => { log('run() error after setup', String(e.stack || e)); RUNNING = false; }); }
  } catch (e) { res.status(500).send('خطأ: ' + String(e)); }
});
// Live mini-dashboard with real-time updates (SSE with polling fallback)
app.get('/status', (req, res) => {
  const missing = configMissing().length ? true : false;
  const brand = 'OPT';
  res.setHeader('Cache-Control', 'no-store');
  // Build initial payload (SSR fallback)
  const day = todayKey(); const dayObj = STATS.perDay[day] || { added: 0, jobsDone: 0 };
  const uptimeSec = Math.round((Date.now() - LOG_STATE.startedAt) / 1000);
  const payload = {
    connected: LOG_STATE.connected,
    active: LOG_STATE.active,
    paused: LOG_STATE.paused,
    armed: LOG_STATE.armed,
    lastJob: LOG_STATE.lastJob,
    lastReport: LOG_STATE.lastReport,
    lastError: LOG_STATE.lastError,
    workerId: WORKER_ID,
    displayName: LOG_STATE.displayName || '',
    base: getBase(),
    uptimeSec,
    todayAdded: dayObj.added || 0,
    totalAdded: STATS.totalAdded || 0,
    jobsDoneToday: dayObj.jobsDone || 0,
    jobsDoneTotal: STATS.jobsDoneTotal || 0
  };
  function fmtUptime(ss) { let s = Number(ss || 0); const d = Math.floor(s / 86400); s -= d * 86400; const h = Math.floor(s / 3600); s -= h * 3600; const m = Math.floor(s / 60); s -= m * 60; const parts = []; if (d) parts.push(d + 'ي'); if (h) parts.push(h + 'س'); if (m) parts.push(m + 'د'); parts.push(Math.floor(s) + 'ث'); return parts.join(' '); }
  const connTxt = payload.connected ? 'متصل' : 'غير متصل';
  const connDot = missing ? 'warn' : (payload.connected ? 'ok' : 'bad');
  const runState = payload.paused ? 'موقوف مؤقتاً' : (payload.active ? 'ينفذ' : (payload.connected ? 'خامل' : '—'));
  const jobTxt = (payload.active && payload.lastJob) ? ('#' + payload.lastJob.id + ' — ' + (payload.lastJob.query || '') + ' @ ' + (payload.lastJob.ll || '')) : (payload.connected ? 'بانتظار مهمة...' : '—');
  const lastRep = payload.lastReport ? (new Date(payload.lastReport.t).toLocaleTimeString('ar') + ' • +' + (payload.lastReport.added || 0) + ' • ' + (payload.lastReport.cursor || 0)) : '—';
  const armedTxt = payload.armed !== false ? 'مُفعّل' : 'مُعطّل';
  res.send(`<!doctype html><meta charset="utf-8"><title>${brand} • لوحة التحكم الكاملة</title>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-bxK2n2t0m2wFf8Yw2t3q7wG8mS9v7yTz2k3a1uF+o0q8r1Yk+P0c4q3o0j1gK9J0D2C0E6pGf6GqQ+WkZ1r0kQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root{ --bg:#0b1220; --card:#0e1a2d; --muted:#9fb3d1; --fg:#eef3fb; --ok:#10b981; --bad:#ef4444; --warn:#f59e0b; --link:#93c5fd; --primary:#3b82f6; --primary-glow:rgba(59,130,246,0.35) }
    body{font-family:'Cairo',system-ui, -apple-system, Segoe UI, Roboto, sans-serif;direction:rtl;background:var(--bg);color:var(--fg);margin:0;padding:24px}
    .wrap{max-width:1080px;margin:0 auto}
    .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
    .links a{color:var(--link);margin-inline-start:12px;text-decoration:none}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
    .card{background:var(--card);border:1px solid #11233d;border-radius:12px;padding:16px}
    .k{color:var(--muted);font-size:.95em}
    .v{font-weight:700}
    .dot{display:inline-block;width:11px;height:11px;border-radius:50%;margin-inline-start:8px}
    @keyframes pulseOk{0%{box-shadow:0 0 0 0 rgba(16,185,129,.6)}70%{box-shadow:0 0 0 10px rgba(16,185,129,0)}100%{box-shadow:0 0 0 0 rgba(16,185,129,0)}}
    @keyframes pulseBad{0%{box-shadow:0 0 0 0 rgba(239,68,68,.6)}70%{box-shadow:0 0 0 10px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}
    @keyframes pulseWarn{0%{box-shadow:0 0 0 0 rgba(245,158,11,.6)}70%{box-shadow:0 0 0 10px rgba(245,158,11,0)}100%{box-shadow:0 0 0 0 rgba(245,158,11,0)}}
    .dot.ok{background:var(--ok);animation:pulseOk 1.8s infinite}
    .dot.bad{background:var(--bad);animation:pulseBad 2.2s infinite}
    .dot.warn{background:var(--warn);animation:pulseWarn 2s infinite}
    pre{white-space:pre-wrap;word-break:break-word;max-height:220px;overflow:auto;background:#0a1629;padding:10px;border-radius:8px;border:1px solid #122643}
  .controls{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  .btn{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:10px 14px;cursor:pointer;font-weight:700;display:inline-flex;align-items:center;gap:8px;position:relative;overflow:hidden;transition:transform .08s ease-in-out}
  .btn i{font-size:14px}
  .btn:active{transform:scale(0.98)}
  .btn::after{content:"";position:absolute;inset:auto;left:50%;top:50%;width:0;height:0;border-radius:999px;transform:translate(-50%,-50%);background:var(--primary-glow);opacity:.6;transition:width .35s,height .35s,opacity .6s}
  .btn:active::after{width:220px;height:220px;opacity:0}
    .pill{border-radius:999px;padding:3px 10px;font-size:12px;border:1px solid #1e3357;background:#12213a;color:#9fb3d1}
    .pill.ok{background:rgba(16,185,129,.15);color:#b9f5db;border-color:rgba(16,185,129,.35)}
    .pill.off{background:#1a2436;color:#99a7bd;border-color:#243651}
    .logs{margin-top:12px;background:#0a1629;border:1px solid #122643;border-radius:10px;padding:10px;height:220px;overflow:auto;font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#cbe5ff}
    .muted{color:var(--muted);font-size:12px}
  </style>
  <div class=wrap>
    <div class=header>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px"><i class="fas fa-robot" style="color:#60a5fa"></i> ${brand} — لوحة التحكم ${missing ? '(ينتظر الإعداد)' : ''}
        <span class="muted" id=connLabel>${payload.connected ? '• حالة الاتصال: متصل' : '• حالة الاتصال: غير متصل'}</span>
        <span id=connDot class="dot ${connDot}" title="مؤشر الاتصال"></span>
        <span class="muted" id=lastRef title="آخر تحديث">${new Date().toLocaleTimeString('ar')}</span>
      </h2>
      <div class=links>
        <a href="/metrics" target="_blank"><i class="fas fa-chart-line"></i> القياسات</a>
        <a href="/self-test" target="_blank"><i class="fas fa-stethoscope"></i> فحص</a>
        <a href="/setup" target="_blank"><i class="fas fa-cog"></i> الإعداد</a>
      </div>
    </div>
    <div class=grid>
      <div class=card>
        <div class=k>الاتصال</div>
        <div class=v id=connTxt>${connTxt}</div>
  <div class=k>المعرف</div>
  <div class=v id=wid>${payload.workerId || '—'} ${payload.displayName ? `<span class=muted style="font-size:12px">(${payload.displayName})</span>` : ''}</div>
        <div class=k>الخادم</div>
        <div class=v id=base>${payload.base || '—'}</div>
        <div class=k>عمر التشغيل</div>
        <div class=v id=uptime>${fmtUptime(payload.uptimeSec || 0)}</div>
      </div>
      <div class=card>
        <div class=k>النتائج اليوم</div>
        <div class=v id=todayAdded>${payload.todayAdded || 0}</div>
        <div class=k>النتائج الكلية</div>
        <div class=v id=totalAdded>${payload.totalAdded || 0}</div>
      </div>
      <div class=card>
        <div class=k>عمليات مُنجزة اليوم</div>
        <div class=v id=jobsToday>${payload.jobsDoneToday || 0}</div>
        <div class=k>عمليات مُنجزة إجمالًا</div>
        <div class=v id=jobsTotal>${payload.jobsDoneTotal || 0}</div>
      </div>
      <div class=card>
        <div style="display:flex;align-items:center;gap:8px;justify-content:space-between">
          <div class=k>الحالة الجارية</div>
          <span id=armedBadge class="pill ${payload.armed !== false ? 'ok' : 'off'}">${armedTxt}</span>
        </div>
        <div class=v id=runState>${runState}</div>
        <div class=k>المهمة الحالية</div>
        <div class=v id=job>${jobTxt}</div>
        <div class=k>آخر تقرير</div>
        <div class=v id=lastReport>${lastRep}</div>
        <div class=controls>
          <button class="btn" id=btnTogglePause><i class="fas fa-pause"></i><span>إيقاف مؤقت</span></button>
          <button class="btn" id=btnToggleArmed style="background:#059669"><i class="fas fa-shield-alt"></i><span>تفعيل</span></button>
          <button class="btn" id=btnReconnect style="background:#475569"><i class="fas fa-plug"></i><span>إعادة اتصال</span></button>
          <button class="btn" id=btnSync style="background:#1d4ed8"><i class="fas fa-cloud-download-alt"></i><span>مزامنة</span></button>
          <button class="btn" id=btnUpdate style="background:#0ea5e9"><i class="fas fa-download"></i><span>تحديث</span></button>
          <button class="btn" id=btnRestart style="background:#7c3aed"><i class="fas fa-rotate"></i><span>إعادة تشغيل</span></button>
          <button class="btn" id=btnFront style="background:#374151"><i class="fas fa-window-restore"></i><span>أمامية</span></button>
          <button class="btn" id=btnClose style="background:#ef4444"><i class="fas fa-xmark"></i><span>إغلاق المصغّر</span></button>
        </div>
      </div>
    </div>
    <div class=card>
      <div class=k>آخر خطأ</div>
      <pre id=err>${payload.lastError ? String(payload.lastError).slice(0, 300) : '—'}</pre>
      <div class=logs id=liveLogs>—</div>
    </div>
  </div>
  <!-- Notifications Container -->
  <div id="notifications" style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999"></div>
  <!-- Secret Update Modal -->
  <div id="secretModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998;align-items:center;justify-content:center">
    <div style="background:var(--card);border:1px solid #122643;border-radius:14px;padding:18px;min-width:320px;max-width:90%">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:8px;color:#f59e0b"><i class="fas fa-key"></i><b>تحديث الرمز السري</b></div>
        <button class="btn" style="background:#ef4444" onclick="closeSecretModal()"><i class="fas fa-xmark"></i></button>
      </div>
      <div style="display:grid;gap:10px">
        <label style="font-size:13px;color:var(--muted)">الرمز السري الجديد</label>
        <input id="newSecret" type="password" style="padding:10px;border-radius:10px;background:#0a1629;border:1px solid #122643;color:#e5e7eb" placeholder="أدخل الرمز السري">
        <label style="font-size:13px;color:var(--muted)">تأكيد الرمز السري</label>
        <input id="confirmSecret" type="password" style="padding:10px;border-radius:10px;background:#0a1629;border:1px solid #122643;color:#e5e7eb" placeholder="أعد إدخال الرمز">
        <small style="color:var(--muted)">تأكد من تطابق الرمز مع إعدادات الخادم.</small>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
        <button class="btn" style="background:#6b7280" onclick="closeSecretModal()">إلغاء</button>
        <button class="btn" style="background:#3b82f6" onclick="updateSecret()"><i class="fas fa-save"></i> حفظ وإعادة التشغيل</button>
      </div>
    </div>
  </div>
  <noscript><div style="color:#fbbf24;padding:10px">المتصفح يمنع سكربتات جافاسكربت؛ الصفحة تعمل بوضع عرض فقط. فعّل JavaScript لعرض الحالة مباشرة.</div></noscript>
  <script>window.__statusSSR=${JSON.stringify(payload)};</script>
  <script src="/status.js?v=${Date.now()}" defer></script>
  <script>(function(){
    // Notifications
    function notify(msg, type){
      try{
        var box = document.getElementById('notifications'); if(!box) return;
        var div = document.createElement('div');
        var bg = type==='error'? '#ef4444' : type==='warn'? '#f59e0b' : type==='success'? '#10b981' : '#3b82f6';
        div.style.cssText = 'background:'+bg+';color:white;padding:10px 14px;margin:6px;border-radius:10px;box-shadow:0 10px 20px rgba(0,0,0,.2);display:flex;align-items:center;gap:8px;min-width:260px;';
        div.innerHTML = '<i class="fas '+ (type==='error'?'fa-circle-exclamation': type==='warn'?'fa-triangle-exclamation': type==='success'?'fa-check-circle':'fa-info-circle') +'"></i><span>'+ (msg||'') +'</span>';
        box.appendChild(div);
        setTimeout(function(){ div.style.opacity='0'; div.style.transition='opacity .5s'; setTimeout(function(){ div.remove(); }, 500); }, 2500);
      }catch(_){ }
    }
    // Modal helpers
    window.openSecretModal = function(){ var m=document.getElementById('secretModal'); if(m){ m.style.display='flex'; } };
    window.closeSecretModal = function(){ var m=document.getElementById('secretModal'); if(m){ m.style.display='none'; } };
    window.updateSecret = function(){
      try{
        var s = document.getElementById('newSecret').value.trim();
        var c = document.getElementById('confirmSecret').value.trim();
        if(!s){ notify('الرجاء إدخال الرمز السري','warn'); return; }
        if(s!==c){ notify('الرمزان غير متطابقين','warn'); return; }
        fetch('/update-secret', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ secret: s }) })
          .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
          .then(function(j){ if(j && j.ok){ notify('تم حفظ الرمز، سيتم إعادة التشغيل','success'); setTimeout(function(){ fetch('/control',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'restart'})}); }, 600); } else { notify('تعذر حفظ الرمز','error'); } });
      }catch(e){ notify('خطأ: '+ (e && e.message? e.message:String(e)),'error'); }
    };
    // Fallback bootstrap: إذا لم يحمّل status.js خلال ثانيتين نبدأ استطلاعاً خفيفاً
    var bootOk=false; function tick(){ var e=document.getElementById('lastRef'); if(e){ e.textContent=new Date().toLocaleTimeString('ar'); } }
    setInterval(tick, 1000);
    function applyMini(m){ try{
      var ct=document.getElementById('connTxt'); if(ct) ct.textContent = m.connected? 'متصل':'غير متصل';
      var cl=document.getElementById('connLabel'); if(cl) cl.textContent = m.connected? '• حالة الاتصال: متصل' : '• حالة الاتصال: غير متصل';
      var cd=document.getElementById('connDot'); if(cd) cd.className='dot ' + (m.connected? 'ok':'bad');
      var wid=document.getElementById('wid'); if(wid) wid.textContent = m.workerId||'—';
      var base=document.getElementById('base'); if(base) base.textContent = m.base||'—';
      var up=document.getElementById('uptime'); if(up){ var s=Number(m.uptimeSec||0); var d=Math.floor(s/86400); s-=d*86400; var h=Math.floor(s/3600); s-=h*3600; var mm=Math.floor(s/60); s-=mm*60; var parts=[]; if(d) parts.push(d+'ي'); if(h) parts.push(h+'س'); if(mm) parts.push(mm+'د'); parts.push(Math.floor(s)+'ث'); up.textContent=parts.join(' ');} 
      var rs=document.getElementById('runState'); if(rs) rs.textContent = m.paused? 'موقوف مؤقتاً' : (m.active? 'ينفذ' : (m.connected? 'خامل':'—'));
      var jb=document.getElementById('job'); if(jb) jb.textContent = (m.active&&m.lastJob)? ('#'+m.lastJob.id+' — '+(m.lastJob.query||'')+' @ '+(m.lastJob.ll||'')) : (m.connected? 'بانتظار مهمة...' : '—');
      var lr=document.getElementById('lastReport'); if(lr) lr.textContent = m.lastReport? (new Date(m.lastReport.t).toLocaleTimeString('ar') + ' • +' + (m.lastReport.added||0) + ' • ' + (m.lastReport.cursor||0)) : '—';
      var ab=document.getElementById('armedBadge'); if(ab){ ab.textContent = (m.armed!==false)? 'مُفعّل' : 'مُعطّل'; ab.className='pill ' + ((m.armed!==false)? 'ok':'off'); }
      var ta=document.getElementById('todayAdded'); if(ta) ta.textContent = m.todayAdded ?? 0;
      var to=document.getElementById('totalAdded'); if(to) to.textContent = m.totalAdded ?? 0;
      var jt=document.getElementById('jobsToday'); if(jt) jt.textContent = m.jobsDoneToday ?? 0;
      var jtt=document.getElementById('jobsTotal'); if(jtt) jtt.textContent = m.jobsDoneTotal ?? 0;
      var er=document.getElementById('err'); if(er && m.lastError) er.textContent = String(m.lastError).slice(0,300);
      // Auth error onboarding: open secret modal automatically on 401/unauthorized
      try{ var le = (m.lastError||'').toString(); if(/401|unauthorized|unauthor/i.test(le)){ notify('فشل المصادقة: حدّث الرمز السري','error'); openSecretModal(); } }catch(_){ }
    }catch(_){} }
    function poll(){ fetch('/metrics').then(function(r){return r.json();}).then(function(m){ applyMini(m); setTimeout(poll, 3000); }).catch(function(){ setTimeout(poll, 5000); }); }
    setTimeout(function(){ bootOk = !!(window.__statusBoot); if(!bootOk){ poll(); } }, 2000);
  })();</script>`);
});

// Externalized JS for /status to avoid inline-script blockers and surface errors
app.get('/status.js', (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  res.type('application/javascript').send(`(function(){
    try{ window.__statusBoot = true; }catch(_){}
    var $ = function(id){ return document.getElementById(id); };
    function fmtUptime(s){ s=Number(s||0); var d=Math.floor(s/86400); s-=d*86400; var h=Math.floor(s/3600); s-=h*3600; var m=Math.floor(s/60); s-=m*60; var parts=[]; if(d) parts.push(d+'ي'); if(h) parts.push(h+'س'); if(m) parts.push(m+'د'); parts.push(Math.floor(s)+'ث'); return parts.join(' '); }
    var lastMetrics = null;
    function notify(msg, type){
      try{
        var box = document.getElementById('notifications'); if(!box) return;
        var div = document.createElement('div');
        var bg = type==='error'? '#ef4444' : type==='warn'? '#f59e0b' : type==='success'? '#10b981' : '#3b82f6';
        div.style.cssText = 'background:'+bg+';color:white;padding:10px 14px;margin:6px;border-radius:10px;box-shadow:0 10px 20px rgba(0,0,0,.2);display:flex;align-items:center;gap:8px;min-width:260px;';
        div.innerHTML = '<span>'+ (msg||'') +'</span>';
        box.appendChild(div);
        setTimeout(function(){ div.style.opacity='0'; div.style.transition='opacity .5s'; setTimeout(function(){ div.remove(); }, 500); }, 2500);
      }catch(_){ }
    }
    function openSecretModal(){ var m=document.getElementById('secretModal'); if(m){ m.style.display='flex'; } }
    function apply(m){
      try{
  // Worker ID + optional display name
  var widEl = $('wid'); if(widEl){ widEl.textContent = (m.workerId || '—') + (m.displayName? (' ('+m.displayName+')') : ''); }
        $('base').textContent = m.base || '—';
        $('uptime').textContent = fmtUptime(m.uptimeSec||0);
        $('todayAdded').textContent = (m.todayAdded!=null? m.todayAdded:0);
        $('totalAdded').textContent = (m.totalAdded!=null? m.totalAdded:0);
        $('jobsToday').textContent = (m.jobsDoneToday!=null? m.jobsDoneToday:0);
        $('jobsTotal').textContent = (m.jobsDoneTotal!=null? m.jobsDoneTotal:0);
        var connOk = !!m.connected;
        var isActive = !!m.active;
        var isPaused = !!m.paused;
        var isArmed = (m.armed !== false);
        $('connTxt').textContent = connOk? 'متصل' : 'غير متصل';
        var connLabel = $('connLabel'); if(connLabel) connLabel.textContent = connOk? '• حالة الاتصال: متصل' : '• حالة الاتصال: غير متصل';
        var connDot = $('connDot'); if(connDot) connDot.className = 'dot ' + (connOk? 'ok':'bad');
        var lastRef = $('lastRef'); if(lastRef) lastRef.textContent = new Date().toLocaleTimeString('ar');
        $('runState').textContent = isPaused? 'موقوف مؤقتاً' : (isActive? 'ينفذ' : (connOk? 'خامل':'—'));
        var j = (isActive && m.lastJob)? ('#'+m.lastJob.id+' — '+(m.lastJob.query||'')+' @ '+(m.lastJob.ll||'')) : (connOk? 'بانتظار مهمة...' : '—');
        $('job').textContent = j;
        var lr = m.lastReport? (new Date(m.lastReport.t).toLocaleTimeString('ar') + ' • +' + (m.lastReport.added||0) + ' • ' + (m.lastReport.cursor||0)) : '—';
        $('lastReport').textContent = lr;
  $('err').textContent = (m.lastError||'—');
  try{ var le = (m.lastError||'').toString(); if(/401|unauthorized|unauthor/i.test(le)){ notify('فشل المصادقة: حدّث الرمز السري','error'); openSecretModal(); } }catch(_){ }
        var badge = $('armedBadge');
        if(badge){ badge.textContent = isArmed ? 'مُفعّل' : 'مُعطّل'; badge.className = 'pill ' + (isArmed ? 'ok' : 'off'); }
        var btnTogglePause = $('btnTogglePause'); if(btnTogglePause){ btnTogglePause.textContent = isPaused ? 'استئناف' : 'إيقاف مؤقت'; btnTogglePause.dataset.state = isPaused ? 'resume' : 'pause'; }
        var btnToggleArmed = $('btnToggleArmed'); if(btnToggleArmed){ btnToggleArmed.textContent = isArmed ? 'تعطيل' : 'تفعيل'; btnToggleArmed.style.background = isArmed ? '#6b7280' : '#059669'; }
        lastMetrics = m;
      }catch(e){ var ebox=$('err'); if(ebox){ ebox.textContent = 'JS apply() error: '+ (e && e.message ? e.message : String(e)); } }
    }
    function poll(){ fetch('/metrics').then(function(r){return r.json();}).then(apply).catch(function(){}); }
    // Show last refresh and locally tick uptime every second so the UI looks alive
    setInterval(function(){
      var e=$('lastRef'); if(e){ e.textContent = new Date().toLocaleTimeString('ar'); }
      // locally tick uptime from the last received metrics without extra network
      try{
        if(lastMetrics){ lastMetrics.uptimeSec = Number(lastMetrics.uptimeSec||0) + 1; var up=$('uptime'); if(up){ up.textContent = fmtUptime(lastMetrics.uptimeSec); } }
      }catch(_){ }
    }, 1000);
    // Wire controls and streams after DOM is ready
    function boot(){
      try{
        // Seed with SSR payload if present so the UI shows immediate values
        try{ if(window.__statusSSR){ apply(window.__statusSSR); } }catch(_){ }
        var es = null; var startedPolling=false;
        function startPolling(){ if(startedPolling) return; startedPolling=true; setInterval(poll, 3000); }
        try{
          es = new EventSource('/events');
          es.onmessage = function(ev){ try{ var m = JSON.parse(ev.data); apply(m); }catch(_){ } };
          es.onerror = function(){ try{ es.close(); }catch(_){} startPolling(); };
        }catch(_){ startPolling(); }
        poll();
        function setMsg(msg){ try{ var box=$('err'); if(box){ box.textContent = (typeof msg==='string')? msg : JSON.stringify(msg); } }catch(_){ } }
        function call(action){
          return fetch('/control', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action: action }) })
            .then(function(r){ return r.json().catch(function(){ return { ok:false, httpStatus:r.status }; }); })
            .then(function(j){ setMsg({action:action, result:j}); return j; })
            .catch(function(e){ setMsg('control error: '+ (e&&e.message? e.message : String(e))); return { ok:false, error:String(e) }; });
        }
        function bind(id, actionOrFn){ var btn=$(id); if(!btn) return; btn.onclick=function(){ try{ btn.disabled=true; var act = (typeof actionOrFn==='function')? actionOrFn() : actionOrFn; Promise.resolve(act).then(function(a){ return (typeof a==='string')? call(a): a; }).then(function(){ poll(); setTimeout(function(){ btn.disabled=false; }, 600); }).catch(function(){ btn.disabled=false; }); }catch(_){ btn.disabled=false; } } };
        bind('btnTogglePause', function(){ var b=$('btnTogglePause'); return (b && b.dataset.state==='resume')? 'resume':'pause'; });
        bind('btnToggleArmed', function(){ var t=$('btnToggleArmed'); var txt=t? t.textContent.trim():''; return (txt==='تعطيل')? 'disarm':'arm'; });
        bind('btnReconnect','reconnect');
        bind('btnSync','sync-config');
        bind('btnUpdate','update-now');
        bind('btnRestart','restart');
        bind('btnFront','bring-to-front');
        bind('btnClose','ui-close');
        var logBox = $('liveLogs');
        try{
          var esLogs = new EventSource('/logs_sse');
          esLogs.onmessage = function(ev){ try{ var j = JSON.parse(ev.data); if(j && j.chunk){ var lines = (j.chunk||'').split(/\r?\n/); var prev = logBox && logBox.textContent ? logBox.textContent.split(/\r?\n/) : []; var merged = prev.concat(lines).slice(-24); if(logBox){ logBox.textContent = merged.join('\n'); logBox.scrollTop = logBox.scrollHeight; } } }catch(_){ } };
          esLogs.onerror = function(){ try{ esLogs.close(); }catch(_){} setInterval(function(){ fetch('/logs_short').then(function(r){return r.text();}).then(function(t){ var lines=(t||'').trim().split(/\r?\n/); var tail=lines.slice(-18); if(logBox){ logBox.textContent=tail.join('\n'); logBox.scrollTop=logBox.scrollHeight; } }).catch(function(){}); }, 5000); };
        }catch(_){ setInterval(function(){ fetch('/logs_short').then(function(r){return r.text();}).then(function(t){ var lines=(t||'').trim().split(/\r?\n/); var tail=lines.slice(-18); if(logBox){ logBox.textContent=tail.join('\n'); logBox.scrollTop=logBox.scrollHeight; } }).catch(function(){}); }, 5000); }
      }catch(e){ var ebox=$('err'); if(ebox){ ebox.textContent = 'JS init error: '+ (e && e.message ? e.message : String(e)); } }
    }
    if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
    // Global error banner so the user sees why the page might be static
    window.addEventListener('error', function(e){ try{ var box=$('err'); if(box){ box.textContent = 'JS error: ' + (e && e.message? e.message : String(e)); } }catch(_){} });
  })();`);
});
app.get('/logs', (req, res) => {
  try { const f = path.join(LOG_DIR, `worker-${new Date().toISOString().slice(0, 10)}.log`); if (!fs.existsSync(f)) return res.type('text/plain').send('no log'); const txt = fs.readFileSync(f, 'utf8'); const lines = txt.trim().split(/\r?\n/); res.type('text/plain').send(lines.slice(-200).join('\n')); }
  catch (e) { res.type('text/plain').send('error'); }
});
// Short logs for UI polling
app.get('/logs_short', (req, res) => {
  try { const f = path.join(LOG_DIR, `worker-${new Date().toISOString().slice(0, 10)}.log`); if (!fs.existsSync(f)) return res.type('text/plain').send('no log'); const txt = fs.readFileSync(f, 'utf8'); const lines = txt.trim().split(/\r?\n/); res.type('text/plain').send(lines.slice(-24).join('\n')); }
  catch (e) { res.type('text/plain').send('error'); }
});
// Live logs via SSE (tail updates without refresh)
app.get('/logs_sse', (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  DIAG.logsClients++;
  const logFile = path.join(LOG_DIR, `worker-${new Date().toISOString().slice(0, 10)}.log`);
  let lastSize = 0;
  let lastSentAt = Date.now();
  const send = (chunk) => { res.write('data: ' + JSON.stringify({ t: Date.now(), chunk }) + '\n\n'); lastSentAt = Date.now(); };
  const tailN = (txt, n) => { const lines = (txt || '').split(/\r?\n/); return lines.slice(-n).join('\n'); };
  const initial = () => {
    try {
      if (fs.existsSync(logFile)) {
        const txt = fs.readFileSync(logFile, 'utf8');
        send(tailN(txt, 24));
        lastSize = fs.statSync(logFile).size;
      } else { send(''); }
    } catch (_) { /* ignore */ }
  };
  const tick = () => {
    try {
      if (!fs.existsSync(logFile)) {
        // keep-alive heartbeat without disk read
        if (Date.now() - lastSentAt > UI_EVENTS_KEEPALIVE_MS) send('');
        return;
      }
      const sz = fs.statSync(logFile).size;
      if (sz > lastSize) {
        const txt = fs.readFileSync(logFile, 'utf8');
        DIAG.logsEvents++; DIAG.lastLogEventAt = Date.now();
        send(tailN(txt, 24));
        lastSize = sz;
      } else {
        // lightweight keep-alive: do not re-read the file when unchanged
        if (Date.now() - lastSentAt > UI_EVENTS_KEEPALIVE_MS) { DIAG.logsKeepAlive++; send(''); }
      }
    } catch (_) { /* ignore read errors */ }
  };
  const id = setInterval(tick, Math.max(500, UI_LOGS_MS));
  req.on('close', () => { clearInterval(id); DIAG.logsClients = Math.max(0, DIAG.logsClients - 1); });
  initial();
});
app.get('/metrics', (req, res) => {
  const day = todayKey(); const dayObj = STATS.perDay[day] || { added: 0, jobsDone: 0 };
  const uptimeSec = Math.round((Date.now() - LOG_STATE.startedAt) / 1000);
  res.json({
    ok: true,
    connected: LOG_STATE.connected,
    active: LOG_STATE.active,
    paused: LOG_STATE.paused,
    armed: LOG_STATE.armed,
    lastJob: LOG_STATE.lastJob,
    lastReport: LOG_STATE.lastReport,
    lastError: LOG_STATE.lastError,
    workerId: WORKER_ID,
    displayName: LOG_STATE.displayName || '',
    base: getBase(),
    uptimeSec,
    todayAdded: dayObj.added || 0,
    totalAdded: STATS.totalAdded || 0,
    jobsDoneToday: dayObj.jobsDone || 0,
    jobsDoneTotal: STATS.jobsDoneTotal || 0
  });
});

// Runtime diagnostics for deep analysis
app.get('/diag', (req, res) => {
  res.json({
    ok: true,
    ts: Date.now(),
    appVer: APP_VER,
    base: getBase(),
    workerId: WORKER_ID,
    sse: { clients: DIAG.sseClients, events: DIAG.sseEvents, keepAlive: DIAG.sseKeepAlive, lastEventAt: DIAG.lastEventAt },
    logs: { clients: DIAG.logsClients, events: DIAG.logsEvents, keepAlive: DIAG.logsKeepAlive, lastEventAt: DIAG.lastLogEventAt },
    state: { connected: LOG_STATE.connected, active: LOG_STATE.active, paused: LOG_STATE.paused, armed: LOG_STATE.armed }
  });
});

// Server-Sent Events stream for live UI updates
app.get('/events', (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  DIAG.sseClients++;
  let lastSerialized = '';
  let lastSentAt = Date.now();
  const compute = () => {
    const day = todayKey(); const dayObj = STATS.perDay[day] || { added: 0, jobsDone: 0 };
    const uptimeSec = Math.round((Date.now() - LOG_STATE.startedAt) / 1000);
    return {
      ok: true,
      connected: LOG_STATE.connected, active: LOG_STATE.active,
      paused: LOG_STATE.paused, armed: LOG_STATE.armed,
      lastJob: LOG_STATE.lastJob, lastReport: LOG_STATE.lastReport, lastError: LOG_STATE.lastError,
      workerId: WORKER_ID, displayName: LOG_STATE.displayName || '', base: getBase(), uptimeSec,
      todayAdded: dayObj.added || 0, totalAdded: STATS.totalAdded || 0,
      jobsDoneToday: dayObj.jobsDone || 0, jobsDoneTotal: STATS.jobsDoneTotal || 0
    };
  };
  const sendIfChanged = () => {
    try {
      const payload = compute();
      // round uptime to reduce churn
      payload.uptimeSec = Math.floor(payload.uptimeSec / 2) * 2;
      const ser = JSON.stringify(payload);
      if (ser !== lastSerialized) {
        res.write('data: ' + ser + '\n\n');
        lastSerialized = ser; lastSentAt = Date.now(); DIAG.sseEvents++; DIAG.lastEventAt = Date.now();
      } else if (Date.now() - lastSentAt > UI_EVENTS_KEEPALIVE_MS) {
        // keep-alive without payload to keep the connection warm
        res.write(': keep-alive\n\n');
        lastSentAt = Date.now(); DIAG.sseKeepAlive++;
      }
    } catch (_) { /* ignore */ }
  };
  const id = setInterval(sendIfChanged, Math.max(500, UI_EVENTS_MS));
  req.on('close', () => { clearInterval(id); DIAG.sseClients = Math.max(0, DIAG.sseClients - 1); });
  // first payload immediately
  try { const first = compute(); res.write('data: ' + JSON.stringify(first) + '\n\n'); lastSerialized = JSON.stringify({ ...first, uptimeSec: Math.floor(first.uptimeSec / 2) * 2 }); lastSentAt = Date.now(); DIAG.sseEvents++; DIAG.lastEventAt = Date.now(); } catch (_) { }
});

// Ultra-compact widget: fixed-size live dashboard with a "balcony" header (logo + connection light)
app.get('/mini', (req, res) => {
  const project = 'Leads Membership PRO';
  const titleTag = '[LMPro Mini]';
  const title = `${titleTag} ${project} • وحدة التشغيل المصغّرة`;
  res.send(`<!doctype html><meta charset="utf-8"><title>${title}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>
  <style>
    :root{ --bg:#0b1220; --card:#0e1a2d; --muted:#9fb3d1; --fg:#eef3fb; --ok:#10b981; --bad:#ef4444; --warn:#f59e0b; --active:#22d3ee; --link:#93c5fd }
    html,body{height:100%;margin:0;background:var(--bg);color:var(--fg);font-family:system-ui;overflow:hidden}
    .frame{width:520px;height:380px;box-sizing:border-box;margin:0 auto;display:flex;flex-direction:column}
    .balcony{position:sticky;top:0;display:flex;align-items:center;gap:12px;background:linear-gradient(180deg,#0e1730,transparent);padding:12px 14px}
    .logo{display:flex;align-items:center;gap:8px}
    .logo svg{width:22px;height:22px}
    .title{font-weight:800;font-size:15px}
    .dot{width:10px;height:10px;border-radius:50%}
    .ok{background:var(--ok);box-shadow:0 0 10px var(--ok)}
    .bad{background:var(--bad);box-shadow:0 0 10px var(--bad)}
    .warn{background:var(--warn);box-shadow:0 0 10px var(--warn)}
    .active{background:var(--active);box-shadow:0 0 10px var(--active)}
    .pill{border-radius:999px;padding:3px 10px;font-size:12px;border:1px solid #1e3357;background:#12213a;color:#9fb3d1}
    .pill.ok{background:rgba(16,185,129,.15);color:#b9f5db;border-color:rgba(16,185,129,.35)}
    .pill.off{background:#1a2436;color:#99a7bd;border-color:#243651}
    .card{background:var(--card);border:1px solid #11233d;border-radius:12px;padding:14px;margin:10px 14px 0 14px}
    .row{display:flex;justify-content:space-between;gap:10px}
    .k{color:var(--muted);font-size:.95em}
    .v{font-weight:700;font-size:1.02em}
    .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
    .mini{font-size:14px;line-height:1.5}
    .job{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .footer{margin:10px 14px;color:var(--muted);font-size:12px;display:flex;justify-content:space-between}
    button{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:8px 12px;cursor:pointer;font-weight:600}
    .btns{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .logs{margin:10px 14px;background:#0a1629;border:1px solid #122643;border-radius:10px;padding:10px;height:92px;overflow:auto;font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#cbe5ff}
  </style>
  <div class=frame>
    <div class=balcony>
      <div class=logo>
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 12l4-4 4 4-4 4-4-4Z" fill="#93c5fd"/><path d="M12 12l4-4 4 4-4 4-4-4Z" fill="#38bdf8"/></svg>
        <div class=title>${project} • العامل</div>
      </div>
      <div style="margin-inline-start:auto;display:flex;align-items:center;gap:8px">
        <span id=stateTxt class=mini>—</span>
        <span id=stateDot class="dot warn"></span>
        <span id=armedBadge class="pill off">—</span>
      </div>
    </div>
    <div class="card mini">
      <div class=row>
        <div><div class=k>المعرف</div><div class=v id=wid>—</div></div>
        <div><div class=k>الخادم</div><div class=v id=base>—</div></div>
        <div><div class=k>المدة</div><div class=v id=uptime>—</div></div>
      </div>
    </div>
    <div class="card mini">
      <div class=grid>
        <div><div class=k>اليوم</div><div class=v id=todayAdded>0</div></div>
        <div><div class=k>الإجمالي</div><div class=v id=totalAdded>0</div></div>
        <div><div class=k>منجزة اليوم</div><div class=v id=jobsToday>0</div></div>
      </div>
    </div>
    <div class="card mini">
      <div class=k>المهمة</div>
      <div class="v job" id=job>—</div>
      <div class=row style="margin-top:6px">
        <div><div class=k>آخر تقرير</div><div class=v id=lastReport>—</div></div>
        <div><div class=k>الحالة</div><div class=v id=runState>—</div></div>
      </div>
    </div>
    <div class=footer>
      <div class=btns>
        <button id=btnTogglePause>إيقاف مؤقت</button>
        <button id=btnToggleArmed style="background:#059669">تفعيل</button>
        <button id=btnReconnect style="background:#4b5563">إعادة اتصال</button>
        <button id=btnSync style="background:#1d4ed8">مزامنة</button>
        <button id=btnUpdate style="background:#0ea5e9">تحديث</button>
        <button id=btnRestart style="background:#7c3aed">إعادة تشغيل</button>
        <button id=btnFront style="background:#475569">أمامية</button>
        <button id=btnClose style="background:#ef4444">إغلاق</button>
      </div>
      <div><a href="/status" target="_blank" style="color:#93c5fd;text-decoration:none">عرض كامل</a> • نسخة ${APP_VER}</div>
    </div>
  <div class="logs" id="liveLogs">—</div>
  <div class="mini" style="margin:6px 14px;color:#fbbf24" id="lastErrBox"></div>
  </div>
  <script>
    const $ = (id)=>document.getElementById(id);
    function fmtUptime(s){ const d=Math.floor(s/86400); s-=d*86400; const h=Math.floor(s/3600); s-=h*3600; const m=Math.floor(s/60); s-=m*60; const parts=[]; if(d) parts.push(d+'ي'); if(h) parts.push(h+'س'); if(m) parts.push(m+'د'); parts.push(Math.floor(s)+'ث'); return parts.join(' '); }
  function apply(m){
      $('wid').textContent = m.workerId || '—';
      $('base').textContent = m.base || '—';
      $('uptime').textContent = fmtUptime(m.uptimeSec||0);
      $('todayAdded').textContent = m.todayAdded ?? 0;
      $('totalAdded').textContent = m.totalAdded ?? 0;
      $('jobsToday').textContent = m.jobsDoneToday ?? 0;
      const connOk = !!m.connected;
      const isActive = !!m.active;
      const isPaused = !!m.paused;
      const isArmed = m.armed !== false; // default to armed when missing
      $('runState').textContent = isPaused? 'موقوف مؤقتاً' : (isActive? 'ينفذ' : (connOk? 'خامل':'—'));
      const j = isActive && m.lastJob? ('#'+m.lastJob.id+' — '+(m.lastJob.query||'')+' @ '+(m.lastJob.ll||'')) : '—';
      $('job').textContent = j;
      const lr = m.lastReport? (new Date(m.lastReport.t).toLocaleTimeString('ar') + ' • +' + (m.lastReport.added||0) + ' • ' + (m.lastReport.cursor||0)) : '—';
      $('lastReport').textContent = lr;
  const dot = $('stateDot');
      dot.className = 'dot ' + (isPaused? 'warn' : (isActive? 'active' : (connOk? 'ok' : 'bad')));
      $('stateTxt').textContent = isPaused? 'موقوف مؤقتاً' : (isActive? 'متصل • يعمل' : (connOk? 'متصل' : 'غير متصل'));
  const le = (m.lastError||'').toString();
  const box = $('lastErrBox');
  box.textContent = le ? ('آخر خطأ: ' + le.slice(0,300)) : '';
      const badge = $('armedBadge');
      badge.textContent = isArmed ? 'مُفعّل' : 'مُعطّل';
      badge.className = 'pill ' + (isArmed ? 'ok' : 'off');
      // Toggle buttons visibility/labels
      const btnTogglePause = $('btnTogglePause');
      btnTogglePause.textContent = isPaused ? 'استئناف' : 'إيقاف مؤقت';
      btnTogglePause.dataset.state = isPaused ? 'resume' : 'pause';
      const btnToggleArmed = $('btnToggleArmed');
      btnToggleArmed.textContent = isArmed ? 'تعطيل' : 'تفعيل';
      btnToggleArmed.style.background = isArmed ? '#6b7280' : '#059669';
    }
  function poll(){ fetch('/metrics').then(r=>r.json()).then(apply).catch(()=>{}); }
    (function init(){
      try{ const es = new EventSource('/events'); es.onmessage = (ev)=>{ try{ apply(JSON.parse(ev.data)); }catch{} }; es.onerror = ()=>{ es.close(); setInterval(poll, 3000); }; }
      catch(e){ setInterval(poll, 3000); }
      poll();
  // keep title stable for external topmost scripts/guard matching
  document.title = '${title}';
      // Controls
      function call(action){ return fetch('/control', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action }) }).then(r=>r.json()); }
      $('btnTogglePause').onclick = ()=>{ const m = $('btnTogglePause').dataset.state==='resume'?'resume':'pause'; call(m).then(poll); };
      $('btnToggleArmed').onclick = ()=>{ const txt = $('btnToggleArmed').textContent.trim(); const cmd = (txt==='تعطيل')?'disarm':'arm'; call(cmd).then(poll); };
      $('btnReconnect').onclick = ()=>{ call('reconnect').then(poll); };
      $('btnSync').onclick = ()=>{ call('sync-config').then(poll); };
      $('btnUpdate').onclick = ()=>{ call('update-now'); };
      $('btnRestart').onclick = ()=>{ call('restart'); };
      $('btnFront').onclick = ()=>{ call('bring-to-front'); };
      $('btnClose').onclick = ()=>{ call('ui-close'); };
      // Live logs panel: poll /logs every 5s and show last ~12 lines
    const logBox = $('liveLogs');
    try{ const esLogs = new EventSource('/logs_sse'); esLogs.onmessage = (ev)=>{ try{ const j=JSON.parse(ev.data); if(j&&j.chunk){ const lines=(j.chunk||'').split(/\r?\n/); const prev=logBox.textContent? logBox.textContent.split(/\r?\n/):[]; const merged=prev.concat(lines).slice(-12); logBox.textContent=merged.join('\n'); logBox.scrollTop=logBox.scrollHeight; } }catch(_){} }; esLogs.onerror=()=>{ esLogs.close(); setInterval(()=>{ fetch('/logs_short').then(r=>r.text()).then(t=>{ const lines=(t||'').trim().split(/\r?\n/); const tail=lines.slice(-12); logBox.textContent=tail.join('\n'); logBox.scrollTop=logBox.scrollHeight; }).catch(()=>{}); }, 5000); }; }
    catch(_){ setInterval(()=>{ fetch('/logs_short').then(r=>r.text()).then(t=>{ const lines=(t||'').trim().split(/\r?\n/); const tail=lines.slice(-12); logBox.textContent=tail.join('\n'); logBox.scrollTop=logBox.scrollHeight; }).catch(()=>{}); }, 5000); }
    })();
  </script>`);
});

// Quick self-test: DNS/SSL/Secret/Firewall checks
app.get('/self-test', async (req, res) => {
  const results = { ok: false, base: getBase(), steps: [] };
  try {
    const base = getBase();
    if (!base) { results.steps.push({ name: 'BASE_URL', ok: false, note: 'BASE_URL is empty' }); return res.json(results); }
    results.steps.push({ name: 'BASE_URL', ok: true, note: base });
    // DNS & SSL (by trying to fetch heartbeat)
    let url = base.replace(/\/$/, '') + '/api/heartbeat.php';
    let r1 = await fetch(url, { headers: { 'X-Internal-Secret': SECRET, 'X-Worker-Id': WORKER_ID }, method: 'GET' }).catch(e => ({ error: String(e) }));
    if (r1 && r1.status) {
      results.steps.push({ name: 'HTTP Reachability', ok: (r1.status === 200 || r1.status === 403 || r1.status === 401), note: 'status=' + (r1.status) });
      if (r1.status === 401) results.steps.push({ name: 'Secret Match', ok: false, note: 'INTERNAL_SECRET mismatch (401). تحقق أن السر في الجهاز يطابق الإعداد في اللوحة.' });
      else results.steps.push({ name: 'Secret Match', ok: (r1.status === 200 || r1.status === 403), note: r1.status === 200 ? 'OK' : 'Internal disabled (403)' });
    } else {
      results.steps.push({ name: 'HTTP Reachability', ok: false, note: String(r1 && r1.error || 'network error') });
    }
    // Probe report_results endpoint existence (GET should not be 404 if file is deployed)
    try {
      const rr = await fetch(base.replace(/\/$/, '') + '/api/report_results.php', { headers: { 'X-Internal-Secret': SECRET, 'X-Worker-Id': WORKER_ID }, method: 'GET' });
      const ok = (rr.status !== 404);
      results.steps.push({ name: 'report_results.php present', ok, note: 'status=' + rr.status });
    } catch (e) { results.steps.push({ name: 'report_results.php present', ok: false, note: String(e) }); }
    results.ok = results.steps.every(s => s.ok !== false);
  } catch (e) { results.steps.push({ name: 'self-test', ok: false, note: String(e) }); }
  res.json(results);
});

let RUNNING = false;
app.listen(APP_PORT, () => {
  const missing = configMissing();
  if (missing.length) { log('First-run: open http://127.0.0.1:' + APP_PORT + '/setup to complete setup. Missing=' + missing.join(', ')); }
  else { if (!RUNNING) { RUNNING = true; run().catch(e => { log('run() error', String(e.stack || e)); RUNNING = false; }); } }
  // Self-update check (once at startup, then every 12 hours)
  maybeUpdate().catch(() => { });
  setInterval(() => { maybeUpdate().catch(() => { }); }, 12 * 60 * 60 * 1000);
  // Ensure the information dashboard is visible for operators by default
  if (String(process.env.WORKER_AUTO_OPEN_STATUS || '1') === '1') {
    setTimeout(() => openStatusPage(), 400);
  }
  // Emit a periodic UI heartbeat into the log so the /status page always shows live activity
  if (UI_HB_SEC > 0) {
    setInterval(() => {
      try {
        const conn = LOG_STATE.paused ? 'موقوف مؤقتاً' : (LOG_STATE.connected ? 'متصل' : 'غير متصل');
        const run = LOG_STATE.paused ? 'موقوف مؤقتاً' : (LOG_STATE.active ? 'ينفذ' : 'بانتظار مهمة');
        const base = getBase() || '-';
        log(`ui heartbeat • ${conn} • ${run} • wid=${WORKER_ID} • base=${base}`);
      } catch (_) { /* ignore */ }
    }, Math.max(1000, UI_HB_SEC * 1000));
  }
});

// ---- Control endpoints (pause/resume/reconnect) ----
app.post('/control', async (req, res) => {
  try {
    const body = req.body || {};
    const action = (body.action || req.query.action || '').toString();
    if (action === 'pause') {
      LOG_STATE.paused = true; // keep connected as-is; a background heartbeat will maintain it
      try { await heartbeat(); } catch (_) { }
      return res.json({ ok: true, paused: true, connected: LOG_STATE.connected });
    }
    if (action === 'resume' || action === 'reconnect') {
      LOG_STATE.paused = false; const ok = await heartbeat(); return res.json({ ok: true, paused: false, connected: ok });
    }
    if (action === 'arm') {
      LOG_STATE.armed = true; return res.json({ ok: true, armed: true });
    }
    if (action === 'disarm') {
      LOG_STATE.armed = false; LOG_STATE.active = false; return res.json({ ok: true, armed: false });
    }
    if (action === 'ui-close') {
      try {
        const ac = new AbortController(); const t = setTimeout(() => ac.abort(), 1500);
        await fetch('http://127.0.0.1:47771/exit', { signal: ac.signal }).catch(() => { }); clearTimeout(t);
      } catch (_) { }
      return res.json({ ok: true });
    }
    if (action === 'bring-to-front') {
      try {
        const ac = new AbortController(); const t = setTimeout(() => ac.abort(), 1500);
        await fetch('http://127.0.0.1:47771/focus', { signal: ac.signal }).catch(() => { }); clearTimeout(t);
      } catch (_) { }
      return res.json({ ok: true });
    }
    if (action === 'heartbeat-now') {
      const ok = await heartbeat(); return res.json({ ok: true, connected: ok });
    }
    if (action === 'update-now') {
      await maybeUpdate(); return res.json({ ok: true });
    }
    if (action === 'restart') {
      res.json({ ok: true });
      setTimeout(() => process.exit(17), 150);
      return;
    }
    if (action === 'sync-config') {
      await applyCentralConfig(); return res.json({ ok: true });
    }
    return res.status(400).json({ ok: false, error: 'unknown action' });
  } catch (e) { return res.status(500).json({ ok: false, error: String(e) }); }
});

// Update INTERNAL_SECRET from the UI (saves to .env and memory)
app.post('/update-secret', (req, res) => {
  try {
    const body = req.body || {};
    const secret = (body.secret || '').toString().trim();
    if (!secret) { return res.status(400).json({ ok: false, error: 'Secret is required' }); }
    SECRET = secret; process.env.INTERNAL_SECRET = secret;
    try { saveEnvVars({ INTERNAL_SECRET: secret }); } catch (_) { /* ignore file write errors */ }
    // Clear last error so the UI returns to normal
    LOG_STATE.lastError = '';
    res.json({ ok: true });
  } catch (e) { res.status(500).json({ ok: false, error: String(e) }); }
});

