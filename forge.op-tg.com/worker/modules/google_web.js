/**
 * Google Web Module
 * Phase 7: Google Web Search with SerpAPI (primary) + Chromium (fallback)
 * 
 * Collects evidence-driven data from Google web search results.
 * Output: URLs, snippets, social candidates, official site candidates
 */

import fetch from 'node-fetch';
import crypto from 'crypto';

// Configuration from environment
const SERPAPI_KEY = process.env.SERPAPI_KEY || '';
const FALLBACK_ENABLED = process.env.GOOGLE_WEB_FALLBACK_ENABLED === '1';
const MAX_RESULTS = parseInt(process.env.GOOGLE_WEB_MAX_RESULTS || '10', 10);

/**
 * Generate query hash for caching
 */
function hashQuery(query) {
  return crypto.createHash('sha256').update(query.toLowerCase().trim()).digest('hex').slice(0, 32);
}

/**
 * Check if URL is a social media profile
 */
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
    if (match) {
      return { platform, handle: match[1] };
    }
  }
  return null;
}

/**
 * Check if URL is a business directory
 */
function isDirectoryUrl(url) {
  const directories = [
    'yelp.com', 'tripadvisor.com', 'yellowpages.com', 'foursquare.com',
    'zomato.com', 'hungerstation.com', 'talabat.com', 'carriage.com',
    'haraj.com.sa', 'opensooq.com', 'dubizzle.com', 'olx.com',
    'maroof.sa', 'saudiyellow.com', 'daleeli.com'
  ];
  return directories.some(d => url.includes(d));
}

/**
 * Check if URL might be the official website
 */
function isOfficialSiteCandidate(url, businessName) {
  // Exclude known non-official sites
  const excludePatterns = [
    /google\.com/i, /facebook\.com/i, /instagram\.com/i, /twitter\.com/i,
    /linkedin\.com/i, /youtube\.com/i, /tiktok\.com/i, /wikipedia\.org/i,
    /yelp\.com/i, /tripadvisor\.com/i, /yellowpages/i
  ];
  
  if (excludePatterns.some(p => p.test(url))) return false;
  
  // Check if domain contains business name keywords
  const domain = new URL(url).hostname.toLowerCase();
  const nameWords = businessName.toLowerCase().split(/\s+/).filter(w => w.length > 2);
  
  return nameWords.some(word => domain.includes(word));
}

/**
 * Parse SerpAPI response into normalized format
 */
function parseSerpApiResults(data, businessName) {
  const results = [];
  const socialCandidates = [];
  const officialSiteCandidates = [];
  const directories = [];
  
  const organicResults = data.organic_results || [];
  
  for (let i = 0; i < Math.min(organicResults.length, MAX_RESULTS); i++) {
    const item = organicResults[i];
    const result = {
      rank: i + 1,
      title: item.title || '',
      url: item.link || '',
      snippet: item.snippet || '',
      displayed_link: item.displayed_link || '',
    };
    results.push(result);
    
    // Detect social profiles
    const social = detectSocialPlatform(result.url);
    if (social) {
      socialCandidates.push({
        platform: social.platform,
        handle: social.handle,
        url: result.url,
        evidence_rank: result.rank,
      });
    }
    
    // Detect directories
    if (isDirectoryUrl(result.url)) {
      directories.push({
        url: result.url,
        title: result.title,
        evidence_rank: result.rank,
      });
    }
    
    // Detect official site candidates
    if (isOfficialSiteCandidate(result.url, businessName)) {
      officialSiteCandidates.push({
        url: result.url,
        title: result.title,
        domain: new URL(result.url).hostname,
        evidence_rank: result.rank,
      });
    }
  }
  
  return {
    results,
    social_candidates: socialCandidates,
    official_site_candidates: officialSiteCandidates,
    directories,
    result_count: results.length,
  };
}

/**
 * SerpAPI Provider - Primary
 */
export async function searchWithSerpApi(query, options = {}) {
  if (!SERPAPI_KEY) {
    return { success: false, error_code: 'no_api_key', error: 'SERPAPI_KEY not configured' };
  }
  
  const params = new URLSearchParams({
    q: query,
    api_key: SERPAPI_KEY,
    engine: 'google',
    hl: options.hl || 'ar',
    gl: options.gl || 'sa',
    num: String(MAX_RESULTS),
  });
  
  try {
    const response = await fetch(`https://serpapi.com/search?${params}`, {
      timeout: 30000,
    });
    
    if (response.status === 429) {
      return { success: false, error_code: 'rate_limited', error: 'SerpAPI rate limit exceeded' };
    }
    
    if (!response.ok) {
      return { success: false, error_code: 'api_error', error: `SerpAPI error: ${response.status}` };
    }
    
    const data = await response.json();
    
    if (data.error) {
      return { success: false, error_code: 'api_error', error: data.error };
    }
    
    const businessName = options.businessName || query.split(' ')[0];
    const parsed = parseSerpApiResults(data, businessName);
    
    return {
      success: true,
      provider: 'serpapi',
      query,
      ...parsed,
      search_metadata: {
        total_results: data.search_information?.total_results || 0,
        time_taken: data.search_information?.time_taken_displayed || null,
      },
    };
  } catch (err) {
    return { success: false, error_code: 'network_error', error: err.message };
  }
}

/**
 * Chromium Fallback Provider
 * Only used when GOOGLE_WEB_FALLBACK_ENABLED=1 and within daily cap
 */
export async function searchWithChromium(page, query, options = {}) {
  if (!FALLBACK_ENABLED) {
    return { success: false, error_code: 'fallback_disabled', error: 'Chromium fallback is disabled' };
  }
  
  try {
    const searchUrl = `https://www.google.com/search?q=${encodeURIComponent(query)}&hl=ar&gl=sa`;
    
    await page.goto(searchUrl, { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(2000);
    
    // Check for captcha/blocked
    const pageContent = await page.content();
    if (pageContent.includes('unusual traffic') || pageContent.includes('captcha') || pageContent.includes('blocked')) {
      return { success: false, error_code: 'blocked', error: 'Google blocked the request (captcha/unusual traffic)' };
    }
    
    // Extract search results
    const results = await page.evaluate((maxResults) => {
      const items = [];
      const resultElements = document.querySelectorAll('div.g, div[data-hveid]');
      
      for (let i = 0; i < Math.min(resultElements.length, maxResults); i++) {
        const el = resultElements[i];
        const linkEl = el.querySelector('a[href^="http"]');
        const titleEl = el.querySelector('h3');
        const snippetEl = el.querySelector('div[data-sncf], div.VwiC3b, span.aCOpRe');
        
        if (linkEl && titleEl) {
          items.push({
            rank: i + 1,
            title: titleEl.textContent || '',
            url: linkEl.href || '',
            snippet: snippetEl ? snippetEl.textContent : '',
          });
        }
      }
      
      return items;
    }, MAX_RESULTS);
    
    if (results.length === 0) {
      return { success: false, error_code: 'no_results', error: 'No results found or page structure changed' };
    }
    
    // Process results same as SerpAPI
    const businessName = options.businessName || query.split(' ')[0];
    const socialCandidates = [];
    const officialSiteCandidates = [];
    const directories = [];
    
    for (const result of results) {
      const social = detectSocialPlatform(result.url);
      if (social) {
        socialCandidates.push({
          platform: social.platform,
          handle: social.handle,
          url: result.url,
          evidence_rank: result.rank,
        });
      }
      
      if (isDirectoryUrl(result.url)) {
        directories.push({
          url: result.url,
          title: result.title,
          evidence_rank: result.rank,
        });
      }
      
      if (isOfficialSiteCandidate(result.url, businessName)) {
        officialSiteCandidates.push({
          url: result.url,
          title: result.title,
          domain: new URL(result.url).hostname,
          evidence_rank: result.rank,
        });
      }
    }
    
    return {
      success: true,
      provider: 'chromium',
      query,
      results,
      social_candidates: socialCandidates,
      official_site_candidates: officialSiteCandidates,
      directories,
      result_count: results.length,
    };
  } catch (err) {
    return { success: false, error_code: 'scrape_error', error: err.message };
  }
}

/**
 * Main Google Web Search Handler
 * Tries SerpAPI first, falls back to Chromium if enabled
 */
export async function googleWebSearch(query, options = {}) {
  const { page, baseUrl, secret, forceProvider } = options;
  
  // Check cache first
  const queryHash = hashQuery(query);
  const cacheResult = await checkCache(baseUrl, secret, queryHash);
  if (cacheResult && cacheResult.success) {
    return { ...cacheResult.data, from_cache: true };
  }
  
  // Check daily usage
  const usage = await checkUsage(baseUrl, secret);
  
  // Try SerpAPI first
  if (forceProvider !== 'chromium' && SERPAPI_KEY) {
    if (usage.serpapi < (usage.serpapi_limit || 100)) {
      const result = await searchWithSerpApi(query, options);
      
      if (result.success) {
        await saveCache(baseUrl, secret, queryHash, query, 'serpapi', result);
        await incrementUsage(baseUrl, secret, 'serpapi');
        return result;
      }
      
      // If rate limited, try fallback
      if (result.error_code !== 'rate_limited') {
        return result;
      }
    }
  }
  
  // Try Chromium fallback
  if (FALLBACK_ENABLED && page && usage.chromium < (usage.chromium_limit || 10)) {
    const result = await searchWithChromium(page, query, options);
    
    if (result.success) {
      await saveCache(baseUrl, secret, queryHash, query, 'chromium', result);
      await incrementUsage(baseUrl, secret, 'chromium');
    }
    
    return result;
  }
  
  // No provider available
  if (!SERPAPI_KEY && !FALLBACK_ENABLED) {
    return { success: false, error_code: 'no_provider', error: 'No search provider configured (SERPAPI_KEY missing, fallback disabled)' };
  }
  
  return { success: false, error_code: 'caps_exceeded', error: 'Daily usage caps exceeded for all providers' };
}

/**
 * Cache helpers - communicate with forge API
 */
async function checkCache(baseUrl, secret, queryHash) {
  try {
    const res = await fetch(`${baseUrl}/v1/api/integration/google_web/cache.php?hash=${queryHash}`, {
      headers: { 'X-Internal-Secret': secret },
    });
    if (!res.ok) return null;
    return await res.json();
  } catch (e) { return null; }
}

async function saveCache(baseUrl, secret, queryHash, query, provider, data) {
  try {
    await fetch(`${baseUrl}/v1/api/integration/google_web/cache.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': secret },
      body: JSON.stringify({ hash: queryHash, query, provider, data }),
    });
  } catch (e) { /* ignore */ }
}

async function checkUsage(baseUrl, secret) {
  try {
    const res = await fetch(`${baseUrl}/v1/api/integration/google_web/usage.php`, {
      headers: { 'X-Internal-Secret': secret },
    });
    if (!res.ok) return { serpapi: 0, chromium: 0 };
    return await res.json();
  } catch (e) { return { serpapi: 0, chromium: 0 }; }
}

async function incrementUsage(baseUrl, secret, provider) {
  try {
    await fetch(`${baseUrl}/v1/api/integration/google_web/usage.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': secret },
      body: JSON.stringify({ provider }),
    });
  } catch (e) { /* ignore */ }
}

/**
 * Build AI Pack from google_web results
 * Deterministic extraction of evidence for AI consumption
 */
export function buildAiPack(googleWebData, existingSnapshot = {}) {
  const aiPack = existingSnapshot.ai_pack || {
    evidence: [],
    social_links: {},
    official_site: null,
    directories: [],
    confidence: {},
    missing_data: [],
  };
  
  if (!googleWebData || !googleWebData.success) {
    aiPack.missing_data.push('google_web_failed');
    return aiPack;
  }
  
  // Add evidence from search results
  for (const result of googleWebData.results || []) {
    aiPack.evidence.push({
      source: 'google_web',
      url: result.url,
      title: result.title,
      snippet: result.snippet,
      rank: result.rank,
    });
  }
  
  // Add social links
  for (const social of googleWebData.social_candidates || []) {
    if (!aiPack.social_links[social.platform]) {
      aiPack.social_links[social.platform] = {
        url: social.url,
        handle: social.handle,
        confidence: social.evidence_rank <= 3 ? 'high' : 'medium',
      };
    }
  }
  
  // Add official site candidate
  if (!aiPack.official_site && googleWebData.official_site_candidates?.length > 0) {
    const best = googleWebData.official_site_candidates[0];
    aiPack.official_site = {
      url: best.url,
      domain: best.domain,
      confidence: best.evidence_rank <= 3 ? 'high' : 'medium',
    };
  }
  
  // Add directories
  for (const dir of googleWebData.directories || []) {
    aiPack.directories.push({
      url: dir.url,
      title: dir.title,
    });
  }
  
  // Update confidence
  aiPack.confidence.google_web = googleWebData.result_count >= 5 ? 'high' : 
                                  googleWebData.result_count >= 2 ? 'medium' : 'low';
  
  return aiPack;
}

export default {
  googleWebSearch,
  searchWithSerpApi,
  searchWithChromium,
  buildAiPack,
  hashQuery,
};
