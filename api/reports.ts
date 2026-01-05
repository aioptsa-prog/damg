
import { query, toCamel, toSnake } from './_db.js';
import { requireAuth, canAccessLead } from './_auth.js';
import * as crypto from 'crypto';

/**
 * Reports API with RBAC protection
 * Access is based on lead ownership
 * Also handles:
 * - AI generation via ?generate=true
 * - Enrichment via ?enrich=true (Evidence-based Pipeline v4.0)
 */

// ============================================
// Enrichment Types
// ============================================

interface FetchResult {
  url: string;
  finalUrl: string;
  status: number;
  statusText: string;
  contentType: string;
  bytes: number;
  html?: string;
  error?: string;
  fetchedAt: string;
  durationMs: number;
}

interface ParsedWebsite {
  title: string;
  metaDescription: string;
  h1: string[];
  h2: string[];
  language: string;
  direction: string;
  phones: string[];
  emails: string[];
  whatsappLinks: string[];
  socialLinks: { platform: string; url: string }[];
  forms: number;
  ctaButtons: string[];
  pricingMentions: string[];
  tracking: {
    googleAnalytics: boolean;
    googleTagManager: boolean;
    metaPixel: boolean;
    tiktokPixel: boolean;
    snapPixel: boolean;
    otherTracking: string[];
  };
  structuredData: any[];
  textExcerpt: string;
}

interface SourceEvidence {
  type: 'website' | 'instagram' | 'google_maps';
  url: string;
  fetchedAt: string;
  status: 'success' | 'blocked' | 'error' | 'timeout';
  statusCode?: number;
  finalUrl?: string;
  bytes?: number;
  parseOk: boolean;
  notes: string;
  keyFindings: string[];
  rawExcerpt?: string;
  parsed?: ParsedWebsite;
}

interface EvidenceBundle {
  sources: SourceEvidence[];
  extracted: { website: ParsedWebsite | null; social: any | null };
  diagnostics: { totalDurationMs: number; errors: string[]; warnings: string[] };
  qualityScore: number;
  fetchedAt: string;
}

// ============================================
// Website Fetcher (Server-side)
// ============================================

async function fetchWebsite(url: string, timeoutMs = 15000): Promise<FetchResult> {
  const startTime = Date.now();
  const fetchedAt = new Date().toISOString();
  
  try {
    let normalizedUrl = url.trim();
    if (!normalizedUrl.startsWith('http')) {
      normalizedUrl = 'https://' + normalizedUrl;
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    const response = await fetch(normalizedUrl, {
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'ar,en;q=0.9',
      },
      redirect: 'follow',
      signal: controller.signal,
    });

    clearTimeout(timeoutId);
    const contentType = response.headers.get('content-type') || '';
    const html = contentType.includes('text/html') ? await response.text() : '';
    
    return {
      url: normalizedUrl, finalUrl: response.url, status: response.status,
      statusText: response.statusText, contentType, bytes: html.length,
      html: html.substring(0, 500000), fetchedAt, durationMs: Date.now() - startTime,
    };
  } catch (error: any) {
    return {
      url, finalUrl: url, status: 0, statusText: 'FETCH_ERROR', contentType: '', bytes: 0,
      error: error.name === 'AbortError' ? 'TIMEOUT' : error.message,
      fetchedAt, durationMs: Date.now() - startTime,
    };
  }
}

// ============================================
// Website Parser
// ============================================

function parseWebsite(html: string): ParsedWebsite {
  const extractAllTags = (tag: string): string[] => {
    const regex = new RegExp(`<${tag}[^>]*>([^<]*)</${tag}>`, 'gi');
    const matches: string[] = [];
    let match;
    while ((match = regex.exec(html)) !== null) {
      const text = match[1].trim();
      if (text && text.length > 2 && text.length < 200) matches.push(text);
    }
    return matches.slice(0, 10);
  };

  const getMetaContent = (name: string): string => {
    const patterns = [
      new RegExp(`<meta[^>]*name=["']${name}["'][^>]*content=["']([^"']*)["']`, 'i'),
      new RegExp(`<meta[^>]*property=["']${name}["'][^>]*content=["']([^"']*)["']`, 'i'),
    ];
    for (const pattern of patterns) {
      const match = pattern.exec(html);
      if (match) return match[1];
    }
    return '';
  };

  const titleMatch = /<title[^>]*>([^<]*)<\/title>/i.exec(html);
  const phoneRegex = /(?:\+966|00966|0)?5[0-9]{8}|(?:\+966|00966|0)?1[0-9]{7}/g;
  const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
  const whatsappRegex = /(?:wa\.me|api\.whatsapp\.com)[^\s"'<>]*/gi;

  const socialPatterns = [
    { platform: 'instagram', regex: /instagram\.com\/[a-zA-Z0-9_.]+/gi },
    { platform: 'twitter', regex: /(?:twitter|x)\.com\/[a-zA-Z0-9_]+/gi },
    { platform: 'facebook', regex: /facebook\.com\/[a-zA-Z0-9.]+/gi },
    { platform: 'linkedin', regex: /linkedin\.com\/(?:company|in)\/[a-zA-Z0-9-]+/gi },
    { platform: 'tiktok', regex: /tiktok\.com\/@[a-zA-Z0-9_.]+/gi },
  ];

  const socialLinks: { platform: string; url: string }[] = [];
  for (const { platform, regex } of socialPatterns) {
    const matches = html.match(regex) || [];
    for (const match of matches.slice(0, 2)) {
      socialLinks.push({ platform, url: match });
    }
  }

  const ctaPatterns = [
    /(?:احجز|اطلب|تواصل|اشترك|سجل|ابدأ|اتصل)[^<]{0,30}/gi,
    /(?:book|order|contact|subscribe|register|start)[^<]{0,30}/gi,
  ];
  const ctaButtons: string[] = [];
  for (const pattern of ctaPatterns) {
    ctaButtons.push(...(html.match(pattern) || []).slice(0, 5));
  }

  const tracking = {
    googleAnalytics: /(?:google-analytics\.com|gtag|UA-|G-[A-Z0-9]+)/i.test(html),
    googleTagManager: /googletagmanager\.com|GTM-/i.test(html),
    metaPixel: /(?:facebook\.com\/tr|fbq\(|connect\.facebook\.net)/i.test(html),
    tiktokPixel: /analytics\.tiktok\.com|ttq\./i.test(html),
    snapPixel: /sc-static\.net\/scevent|snaptr\(/i.test(html),
    otherTracking: [] as string[],
  };
  if (/hotjar\.com/i.test(html)) tracking.otherTracking.push('Hotjar');
  if (/clarity\.ms/i.test(html)) tracking.otherTracking.push('Microsoft Clarity');

  const textContent = html.replace(/<script[\s\S]*?<\/script>/gi, '').replace(/<style[\s\S]*?<\/style>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  const langMatch = /<html[^>]*lang=["']([^"']*)["']/i.exec(html);

  return {
    title: titleMatch ? titleMatch[1].trim() : '',
    metaDescription: getMetaContent('description') || getMetaContent('og:description'),
    h1: extractAllTags('h1'), h2: extractAllTags('h2'),
    language: langMatch ? langMatch[1] : 'ar', direction: 'rtl',
    phones: [...new Set((html.match(phoneRegex) || []))].slice(0, 5),
    emails: [...new Set((html.match(emailRegex) || []))].filter(e => !e.includes('example')).slice(0, 5),
    whatsappLinks: [...new Set((html.match(whatsappRegex) || []))].slice(0, 3),
    socialLinks, forms: (html.match(/<form/gi) || []).length,
    ctaButtons: [...new Set(ctaButtons)].slice(0, 10),
    pricingMentions: [...new Set((html.match(/(?:ريال|SAR|SR)\s*[\d,]+|[\d,]+\s*(?:ريال|SAR|SR)/gi) || []))].slice(0, 5),
    tracking, structuredData: [],
    textExcerpt: textContent.substring(0, 1000),
  };
}

// ============================================
// Instagram Fetcher (Limited)
// ============================================

async function fetchInstagram(url: string): Promise<SourceEvidence> {
  const fetchedAt = new Date().toISOString();
  const evidence: SourceEvidence = {
    type: 'instagram', url, fetchedAt, status: 'blocked', parseOk: false, notes: '', keyFindings: [],
  };

  const handleMatch = url.match(/instagram\.com\/([a-zA-Z0-9_.]+)/i);
  const handle = handleMatch ? handleMatch[1] : '';
  if (!handle || handle === 'p' || handle === 'reel') {
    evidence.notes = 'INVALID_URL: الرابط لا يحتوي على اسم حساب صالح';
    return evidence;
  }

  try {
    const controller = new AbortController();
    setTimeout(() => controller.abort(), 10000);
    const response = await fetch(`https://www.instagram.com/${handle}/`, {
      headers: { 'User-Agent': 'Mozilla/5.0' }, signal: controller.signal,
    });

    if (response.status === 200) {
      const html = await response.text();
      const ogTitle = html.match(/<meta[^>]*property=["']og:title["'][^>]*content=["']([^"']*)["']/i);
      const ogDesc = html.match(/<meta[^>]*property=["']og:description["'][^>]*content=["']([^"']*)["']/i);
      if (ogTitle || ogDesc) {
        evidence.status = 'success'; evidence.parseOk = true; evidence.statusCode = 200;
        if (ogTitle) evidence.keyFindings.push(`اسم الحساب: ${ogTitle[1]}`);
        if (ogDesc) evidence.keyFindings.push(`الوصف: ${ogDesc[1].substring(0, 200)}`);
        evidence.notes = 'تم استخراج بيانات OpenGraph';
      } else {
        evidence.notes = 'SOCIAL_ACCESS_BLOCKED: يُنصح بتكامل Meta Graph API';
        evidence.keyFindings.push(`@${handle} - يتطلب تكامل API`);
      }
    } else {
      evidence.statusCode = response.status;
      evidence.notes = response.status === 404 ? 'ACCOUNT_NOT_FOUND' : `BLOCKED (${response.status})`;
    }
  } catch (error: any) {
    evidence.status = error.name === 'AbortError' ? 'timeout' : 'error';
    evidence.notes = error.name === 'AbortError' ? 'TIMEOUT' : error.message;
  }
  return evidence;
}

// ============================================
// Build Evidence Bundle
// ============================================

async function buildEvidenceBundle(website?: string, instagram?: string, maps?: string): Promise<EvidenceBundle> {
  const startTime = Date.now();
  const bundle: EvidenceBundle = {
    sources: [], extracted: { website: null, social: null },
    diagnostics: { totalDurationMs: 0, errors: [], warnings: [] },
    qualityScore: 0, fetchedAt: new Date().toISOString(),
  };

  if (website?.trim()) {
    console.log(`[Enrichment] Fetching homepage: ${website}`);
    const fetchResult = await fetchWebsite(website);
    const evidence: SourceEvidence = {
      type: 'website', url: website, fetchedAt: fetchResult.fetchedAt,
      status: fetchResult.status >= 200 && fetchResult.status < 400 ? 'success' : fetchResult.error === 'TIMEOUT' ? 'timeout' : 'error',
      statusCode: fetchResult.status, finalUrl: fetchResult.finalUrl, bytes: fetchResult.bytes,
      parseOk: false, notes: '', keyFindings: [],
    };

    // Combined content from multiple pages
    let combinedContent = '';
    let allPhones: string[] = [];
    let allEmails: string[] = [];
    let allServices: string[] = [];

    if (fetchResult.html && fetchResult.status >= 200 && fetchResult.status < 400) {
      const parsed = parseWebsite(fetchResult.html);
      evidence.parseOk = true; evidence.parsed = parsed; bundle.extracted.website = parsed;
      combinedContent += parsed.textExcerpt || '';
      allPhones.push(...(parsed.phones || []));
      allEmails.push(...(parsed.emails || []));

      if (parsed.title) evidence.keyFindings.push(`عنوان: ${parsed.title}`);
      if (parsed.phones.length) evidence.keyFindings.push(`هاتف: ${parsed.phones.join(', ')}`);
      if (parsed.emails.length) evidence.keyFindings.push(`بريد: ${parsed.emails.join(', ')}`);
      
      const trackingFound: string[] = [];
      if (parsed.tracking.googleAnalytics) trackingFound.push('GA');
      if (parsed.tracking.googleTagManager) trackingFound.push('GTM');
      if (parsed.tracking.metaPixel) trackingFound.push('Meta Pixel');
      evidence.keyFindings.push(trackingFound.length ? `تتبع: ${trackingFound.join(', ')}` : 'لا يوجد تتبع');
      
      if (!trackingFound.length) bundle.diagnostics.warnings.push('TRACKING_NOT_FOUND');
      evidence.rawExcerpt = parsed.textExcerpt.substring(0, 500);
      evidence.notes = `${fetchResult.bytes} bytes in ${fetchResult.durationMs}ms`;

      // Fetch additional pages (about, services, contact) in parallel
      const baseUrl = new URL(fetchResult.finalUrl || website).origin;
      const additionalPaths = [
        '/about', '/about-us', '/من-نحن', '/عن-الشركة',
        '/services', '/خدماتنا', '/الخدمات',
        '/contact', '/contact-us', '/اتصل-بنا', '/تواصل-معنا'
      ];

      console.log(`[Enrichment] Fetching additional pages from: ${baseUrl}`);
      const additionalFetches = additionalPaths.slice(0, 6).map(async (path) => {
        try {
          const pageUrl = `${baseUrl}${path}`;
          const pageResult = await fetchWebsite(pageUrl);
          if (pageResult.html && pageResult.status >= 200 && pageResult.status < 400) {
            const pageParsed = parseWebsite(pageResult.html);
            return { path, parsed: pageParsed, success: true };
          }
          return { path, success: false };
        } catch {
          return { path, success: false };
        }
      });

      const additionalResults = await Promise.allSettled(additionalFetches);
      let pagesFound = 0;
      
      for (const result of additionalResults) {
        if (result.status === 'fulfilled' && result.value.success && result.value.parsed) {
          pagesFound++;
          const pageParsed = result.value.parsed;
          combinedContent += '\n' + (pageParsed.textExcerpt || '');
          allPhones.push(...(pageParsed.phones || []));
          allEmails.push(...(pageParsed.emails || []));
          
          // Extract service names from H1/H2
          allServices.push(...(pageParsed.h1 || []), ...(pageParsed.h2 || []));
        }
      }

      if (pagesFound > 0) {
        evidence.keyFindings.push(`صفحات إضافية: ${pagesFound} صفحة`);
        // Deduplicate and update parsed data
        evidence.parsed!.phones = [...new Set(allPhones)].slice(0, 10);
        evidence.parsed!.emails = [...new Set(allEmails)].slice(0, 10);
        evidence.parsed!.textExcerpt = combinedContent.substring(0, 2000);
        
        // Add discovered services
        const uniqueServices = [...new Set(allServices)].filter(s => s.length > 3 && s.length < 100);
        if (uniqueServices.length > 0) {
          evidence.keyFindings.push(`خدمات مكتشفة: ${uniqueServices.slice(0, 5).join(', ')}`);
        }
      }
    } else {
      evidence.notes = fetchResult.error || `HTTP ${fetchResult.status}`;
      bundle.diagnostics.errors.push(`WEBSITE_FETCH_FAILED: ${evidence.notes}`);
    }
    bundle.sources.push(evidence);
  }

  if (instagram?.includes('instagram')) {
    const igEvidence = await fetchInstagram(instagram);
    bundle.sources.push(igEvidence);
    if (igEvidence.status === 'blocked') bundle.diagnostics.warnings.push('INSTAGRAM_BLOCKED');
  }

  if (maps?.includes('maps')) {
    bundle.sources.push({
      type: 'google_maps', url: maps, fetchedAt: new Date().toISOString(),
      status: 'blocked', parseOk: false, notes: 'MAPS_API_REQUIRED',
      keyFindings: ['يتطلب Google Places API'],
    });
    bundle.diagnostics.warnings.push('MAPS_API_REQUIRED');
  }

  let score = 0;
  for (const source of bundle.sources) {
    if (source.status === 'success') score += 30;
    if (source.parseOk) score += 20;
    score += Math.min(source.keyFindings.length * 5, 25);
  }
  bundle.qualityScore = Math.min(score, 100);
  bundle.diagnostics.totalDurationMs = Date.now() - startTime;
  return bundle;
}

// ============================================
// Enrichment Handler
// ============================================

async function handleEnrichment(req: any, res: any) {
  try {
    const { website, instagram, maps } = req.body;
    if (!website && !instagram && !maps) {
      return res.status(400).json({ error: 'BAD_REQUEST', message: 'يجب توفير رابط واحد على الأقل' });
    }
    console.log('[Enrichment API] Starting...');
    const evidence = await buildEvidenceBundle(website, instagram, maps);
    console.log(`[Enrichment API] Done in ${evidence.diagnostics.totalDurationMs}ms, quality: ${evidence.qualityScore}%`);
    return res.status(200).json(evidence);
  } catch (error: any) {
    console.error('[Enrichment API] Error:', error);
    return res.status(500).json({ error: 'ENRICHMENT_ERROR', message: error.message });
  }
}

// ============================================
// Google Search via Forge Worker (Real Search with Chromium)
// ============================================

async function searchGoogleViaForge(companyName: string, city?: string): Promise<any> {
  console.log('[Google Search] Searching via Forge for:', companyName, city);
  
  const FORGE_API_BASE = process.env.FORGE_API_BASE_URL || 'http://localhost:8081';
  const INTEGRATION_SECRET = process.env.INTEGRATION_SHARED_SECRET || '';
  
  try {
    // Create a google_web enrichment job in Forge
    const searchQuery = city ? `${companyName} ${city}` : companyName;
    
    const jobResponse = await fetch(`${FORGE_API_BASE}/v1/api/integration/jobs.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Internal-Secret': INTEGRATION_SECRET
      },
      body: JSON.stringify({
        phone: 'search-' + Date.now(),
        name: companyName,
        city: city || '',
        modules: ['google_web'],
        priority: 10
      })
    });

    if (!jobResponse.ok) {
      console.log('[Google Search] Failed to create job:', jobResponse.status);
      return { results: [], searchedAt: new Date().toISOString() };
    }

    const jobData = await jobResponse.json();
    const jobId = jobData.job_id;
    console.log('[Google Search] Created job:', jobId);

    // Wait for job to complete (max 30 seconds)
    let attempts = 0;
    const maxAttempts = 15;
    let snapshot = null;

    while (attempts < maxAttempts) {
      await new Promise(r => setTimeout(r, 2000));
      attempts++;

      const statusResponse = await fetch(`${FORGE_API_BASE}/v1/api/integration/jobs.php?id=${jobId}`, {
        headers: { 'X-Internal-Secret': INTEGRATION_SECRET }
      });

      if (statusResponse.ok) {
        const statusData = await statusResponse.json();
        if (statusData.status === 'done' || statusData.status === 'failed') {
          // Get snapshot
          const snapshotResponse = await fetch(`${FORGE_API_BASE}/v1/api/integration/snapshots.php?job_id=${jobId}`, {
            headers: { 'X-Internal-Secret': INTEGRATION_SECRET }
          });
          if (snapshotResponse.ok) {
            snapshot = await snapshotResponse.json();
          }
          break;
        }
      }
    }

    if (!snapshot || !snapshot.google_web) {
      console.log('[Google Search] No results from Forge');
      return { results: [], searchedAt: new Date().toISOString() };
    }

    const googleWeb = snapshot.google_web;
    const results = googleWeb.raw_results || [];
    
    console.log('[Google Search] Found', results.length, 'results via Forge');
    
    return {
      results: results.map((r: any, i: number) => ({
        url: r.link || r.url,
        title: r.title,
        snippet: r.snippet,
        position: i + 1
      })),
      searchedAt: new Date().toISOString(),
      officialSite: googleWeb.official_site,
      socialLinks: googleWeb.social_links,
      directories: googleWeb.directories
    };

  } catch (error: any) {
    console.error('[Google Search] Error:', error.message);
    return { results: [], searchedAt: new Date().toISOString() };
  }
}

async function handleAIGeneration(req: any, res: any) {
  try {
    const { prompt, useSearch, companyName, city } = req.body;

    // If company name provided, do real Google search via Forge Worker
    let googleSearchResults: any = null;
    if (companyName && useSearch !== false) {
      googleSearchResults = await searchGoogleViaForge(companyName, city);
      console.log('[AI Generation] Google search completed:', {
        resultsCount: googleSearchResults.results?.length || 0,
        officialSite: googleSearchResults.officialSite,
        socialLinks: googleSearchResults.socialLinks?.length || 0
      });
    }

    // Get AI settings from database
    const settingsResult = await query('SELECT value FROM settings WHERE key = $1', ['ai_settings']);
    const settings = settingsResult.rows[0]?.value || {};

    const activeProvider = settings.activeProvider || 'gemini';
    const isGemini = activeProvider === 'gemini';
    
    const apiKey = isGemini ? settings.geminiApiKey : settings.openaiApiKey;
    const activeModel = isGemini ? (settings.geminiModel || 'gemini-2.0-flash') : (settings.openaiModel || 'gpt-4o');
    const activeTemp = settings.temperature ?? 0.7;
    const sysInstruction = settings.systemInstruction || 'أنت خبير استراتيجي لشركة تسويق سعودية.';

    if (!apiKey) {
      return res.status(400).json({ 
        error: 'AI_CONFIG_ERROR', 
        message: 'يرجى ضبط مفتاح الـ API للمزود المختار في الإعدادات.' 
      });
    }

    const startTime = Date.now();
    let text = '';
    let inputTokens = 0;
    let outputTokens = 0;

    if (isGemini) {
      const geminiUrl = `https://generativelanguage.googleapis.com/v1beta/models/${activeModel}:generateContent?key=${apiKey}`;
      
      const geminiBody: any = {
        contents: [{ parts: [{ text: prompt }] }],
        generationConfig: {
          temperature: activeTemp,
          responseMimeType: "application/json"
        },
        systemInstruction: { parts: [{ text: sysInstruction }] }
      };

      if (useSearch) {
        // FIXED: Use correct Gemini REST API format for Google Search grounding
        geminiBody.tools = [{ google_search: {} }];
      }

      const geminiResponse = await fetch(geminiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(geminiBody)
      });

      if (!geminiResponse.ok) {
        const errorData = await geminiResponse.json();
        throw new Error(`Gemini API Error: ${errorData.error?.message || geminiResponse.statusText}`);
      }

      const geminiData = await geminiResponse.json();
      
      // Extract grounding metadata for citations if available
      const groundingMetadata = geminiData.candidates?.[0]?.groundingMetadata;
      const searchEntryPoint = groundingMetadata?.searchEntryPoint?.renderedContent;
      const groundingChunks = groundingMetadata?.groundingChunks || [];
      const webSearchQueries = groundingMetadata?.webSearchQueries || [];
      
      // Log grounding info for debugging
      if (groundingMetadata) {
        console.log('[Gemini] Grounding metadata found:', {
          hasSearchEntryPoint: !!searchEntryPoint,
          chunksCount: groundingChunks.length,
          queries: webSearchQueries
        });
      }
      
      text = geminiData.candidates?.[0]?.content?.parts?.[0]?.text || '{}';
      inputTokens = geminiData.usageMetadata?.promptTokenCount || Math.round(prompt.length / 3);
      outputTokens = geminiData.usageMetadata?.candidatesTokenCount || Math.round(text.length / 3);
    } else {
      // Enhance prompt with Google search results if available
      let enhancedPrompt = prompt;
      if (googleSearchResults && (googleSearchResults.results?.length > 0 || googleSearchResults.officialSite)) {
        const searchContext = `
=== نتائج البحث الفعلي من Google (عبر Forge Worker) ===
تم البحث في: ${googleSearchResults.searchedAt}

${googleSearchResults.officialSite ? `
✅ الموقع الرسمي المكتشف: ${googleSearchResults.officialSite}
` : '⚠️ لم يتم العثور على موقع رسمي واضح'}

${googleSearchResults.socialLinks?.length > 0 ? `
✅ حسابات السوشيال ميديا المكتشفة:
${googleSearchResults.socialLinks.map((s: any) => `- ${s.platform}: ${s.url}`).join('\n')}
` : ''}

${googleSearchResults.directories?.length > 0 ? `
📍 الأدلة والمنصات:
${googleSearchResults.directories.slice(0, 3).map((d: any) => `- ${d}`).join('\n')}
` : ''}

${googleSearchResults.results?.length > 0 ? `
نتائج البحث الرئيسية:
${googleSearchResults.results.slice(0, 5).map((r: any, i: number) => 
  `${i + 1}. ${r.title}\n   الرابط: ${r.url}\n   ${r.snippet || ''}`
).join('\n\n')}
` : ''}

=== تعليمات مهمة ===
استخدم نتائج البحث أعلاه لتحديد:
1. الموقع الإلكتروني الرسمي للشركة (إن وجد)
2. حسابات السوشيال ميديا
3. معلومات التواصل
4. طبيعة نشاط الشركة

`;
        enhancedPrompt = searchContext + prompt;
        console.log('[OpenAI] Enhanced prompt with Forge search results');
      }

      const openaiResponse = await fetch('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${apiKey}`
        },
        body: JSON.stringify({
          model: activeModel,
          messages: [
            { role: 'system', content: sysInstruction },
            { role: 'user', content: enhancedPrompt + "\n\nIMPORTANT: Return ONLY valid JSON matching the requested schema." }
          ],
          response_format: { type: "json_object" },
          temperature: activeTemp
        })
      });

      if (!openaiResponse.ok) {
        const errorData = await openaiResponse.json();
        throw new Error(`OpenAI API Error: ${errorData.error?.message || openaiResponse.statusText}`);
      }

      const openaiData = await openaiResponse.json();
      text = openaiData.choices[0].message.content;
      inputTokens = openaiData.usage?.prompt_tokens || 0;
      outputTokens = openaiData.usage?.completion_tokens || 0;
    }

    const latencyMs = Date.now() - startTime;
    const cost = isGemini 
      ? (inputTokens * 0.00000015) + (outputTokens * 0.00000060)
      : (inputTokens * 0.000005) + (outputTokens * 0.000015);

    // Log usage
    await query(
      'INSERT INTO usage_logs (id, model, provider, latency_ms, input_tokens, output_tokens, cost, status, created_at) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())',
      [crypto.randomUUID(), activeModel, activeProvider, latencyMs, inputTokens, outputTokens, cost, 'success']
    );

    let parsed;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }

    return res.status(200).json({
      data: parsed,
      usage: { model: activeModel, provider: activeProvider, latencyMs, inputTokens, outputTokens, cost }
    });

  } catch (error: any) {
    console.error('AI Generation Error:', error);
    return res.status(500).json({ error: 'AI_ERROR', message: error.message });
  }
}

export default async function handler(req: any, res: any) {
  // Require authentication
  const user = requireAuth(req, res);
  if (!user) return;

  const { method, query: queryParams } = req;

  try {
    // AI Generation endpoint: POST /api/reports?generate=true
    if (method === 'POST' && queryParams.generate === 'true') {
      return handleAIGeneration(req, res);
    }

    // Enrichment endpoint: POST /api/reports?enrich=true
    if (method === 'POST' && queryParams.enrich === 'true') {
      return handleEnrichment(req, res);
    }

    switch (method) {
      case 'GET':
        const { leadId } = queryParams;

        if (!leadId) {
          return res.status(400).json({ error: 'Bad Request', message: 'Lead ID is required' });
        }

        // Check access to the lead
        const hasAccess = await canAccessLead(user, leadId);
        if (!hasAccess) {
          return res.status(403).json({ error: 'Forbidden', message: 'ليس لديك صلاحية لعرض تقارير هذا العميل' });
        }

        const reportsRes = await query(
          'SELECT * FROM reports WHERE lead_id = $1 ORDER BY version_number DESC',
          [leadId]
        );
        return res.status(200).json(toCamel(reportsRes.rows));

      case 'POST':
        const reportData = toSnake(req.body);

        if (!reportData.lead_id) {
          return res.status(400).json({ error: 'Bad Request', message: 'Lead ID is required' });
        }

        // Check access to the lead
        const canPost = await canAccessLead(user, reportData.lead_id);
        if (!canPost) {
          return res.status(403).json({ error: 'Forbidden', message: 'ليس لديك صلاحية لإضافة تقرير لهذا العميل' });
        }

        // Remove user_id from report data (it's not in the Report schema)
        delete reportData.user_id;

        const columns = Object.keys(reportData).join(', ');
        const values = Object.values(reportData);
        const placeholders = values.map((_, i) => `$${i + 1}`).join(', ');

        const insertQuery = `INSERT INTO reports (${columns}) VALUES (${placeholders}) RETURNING *`;
        const saveRes = await query(insertQuery, values);

        // Add activity record
        await query(
          'INSERT INTO activities (id, lead_id, user_id, type, payload, created_at) VALUES ($1, $2, $3, $4, $5, NOW())',
          [crypto.randomUUID(), reportData.lead_id, user.id, 'report_generated', JSON.stringify({ version: reportData.version_number })]
        );

        return res.status(200).json(toCamel(saveRes.rows[0]));

      default:
        res.setHeader('Allow', ['GET', 'POST']);
        res.status(405).end();
    }
  } catch (error: any) {
    console.error('API Reports Error:', error);
    res.status(500).json({ message: error.message });
  }
}

