/**
 * Research API - البحث الشامل عن شركة
 * 
 * POST /api/research
 * يبحث عن الشركة ويجمع كل المعلومات المتاحة
 */

import { requireAuth } from './_auth.js';
import { query } from './_db.js';
import * as crypto from 'crypto';

interface ResearchRequest {
  companyName: string;
  city?: string;
  activity?: string;
}

interface DiscoveredLink {
  url: string;
  type: string;
  confidence: number;
  source: string;
}

// ============================================
// Google Search (Server-side with Puppeteer-like fetch)
// ============================================

async function searchGoogle(searchQuery: string): Promise<any[]> {
  console.log('[Research API] Searching Google for:', searchQuery);
  
  try {
    // Use Google Custom Search API if available, otherwise scrape
    const GOOGLE_API_KEY = process.env.GOOGLE_API_KEY;
    const GOOGLE_CX = process.env.GOOGLE_SEARCH_CX;
    
    if (GOOGLE_API_KEY && GOOGLE_CX) {
      const url = `https://www.googleapis.com/customsearch/v1?key=${GOOGLE_API_KEY}&cx=${GOOGLE_CX}&q=${encodeURIComponent(searchQuery)}&num=10`;
      const response = await fetch(url);
      if (response.ok) {
        const data = await response.json();
        return (data.items || []).map((item: any) => ({
          title: item.title,
          url: item.link,
          snippet: item.snippet
        }));
      }
    }
    
    // Fallback: Direct Google search (may be blocked)
    const googleUrl = `https://www.google.com/search?q=${encodeURIComponent(searchQuery)}&hl=ar&gl=sa&num=10`;
    const response = await fetch(googleUrl, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'text/html',
        'Accept-Language': 'ar,en;q=0.9'
      }
    });
    
    if (!response.ok) {
      console.log('[Research API] Google search blocked:', response.status);
      return [];
    }
    
    const html = await response.text();
    
    // Extract results from HTML
    const results: any[] = [];
    const urlRegex = /href="\/url\?q=([^&"]+)/g;
    const titleRegex = /<h3[^>]*>([^<]+)<\/h3>/g;
    
    let urlMatch;
    while ((urlMatch = urlRegex.exec(html)) !== null) {
      const url = decodeURIComponent(urlMatch[1]);
      if (url.startsWith('http') && !url.includes('google.com')) {
        results.push({ url, title: '', snippet: '' });
      }
    }
    
    return results.slice(0, 10);
    
  } catch (error: any) {
    console.error('[Research API] Google search error:', error.message);
    return [];
  }
}

// ============================================
// Extract Links from Search Results
// ============================================

function extractLinksFromResults(results: any[], companyName: string): DiscoveredLink[] {
  const discovered: DiscoveredLink[] = [];
  const normalizedName = companyName.toLowerCase().replace(/\s+/g, '');
  
  const socialPatterns: Record<string, RegExp> = {
    twitter: /(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/i,
    instagram: /instagram\.com\/([a-zA-Z0-9_.]+)/i,
    linkedin: /linkedin\.com\/(?:company|in)\/([a-zA-Z0-9-]+)/i,
    facebook: /facebook\.com\/([a-zA-Z0-9.]+)/i,
    tiktok: /tiktok\.com\/@([a-zA-Z0-9_.]+)/i,
    snapchat: /snapchat\.com\/add\/([a-zA-Z0-9_.-]+)/i,
    youtube: /youtube\.com\/(?:c\/|channel\/|@)([a-zA-Z0-9_-]+)/i
  };
  
  // Aggregator domains to skip
  const aggregators = [
    'yelp.com', 'tripadvisor.com', 'foursquare.com', 'zomato.com',
    'hungerstation.com', 'talabat.com', 'yellowpages', 'daleeli.com'
  ];
  
  for (const result of results) {
    const url = result.url?.toLowerCase() || '';
    
    // Skip aggregators
    if (aggregators.some(a => url.includes(a))) continue;
    
    // Check for social media
    for (const [platform, pattern] of Object.entries(socialPatterns)) {
      if (pattern.test(url)) {
        const match = url.match(pattern);
        if (match) {
          discovered.push({
            url: result.url,
            type: platform,
            confidence: calculateLinkConfidence(match[1], normalizedName),
            source: 'google_search'
          });
        }
        break;
      }
    }
    
    // Check for potential website (not social, not aggregator)
    const isSocial = Object.values(socialPatterns).some(p => p.test(url));
    if (!isSocial && !url.includes('google.com') && !url.includes('maps.google')) {
      // Check if URL might be the company website
      const domain = extractDomain(url);
      if (domain) {
        const domainConfidence = calculateDomainConfidence(domain, normalizedName, result.title || '');
        if (domainConfidence > 0.3) {
          discovered.push({
            url: result.url,
            type: 'website',
            confidence: domainConfidence,
            source: 'google_search'
          });
        }
      }
    }
  }
  
  return discovered;
}

function extractDomain(url: string): string {
  try {
    return new URL(url).hostname.replace('www.', '');
  } catch {
    return '';
  }
}

function calculateLinkConfidence(handle: string, normalizedName: string): number {
  const normalizedHandle = handle.toLowerCase().replace(/[_.-]/g, '');
  
  if (normalizedHandle === normalizedName) return 0.9;
  if (normalizedHandle.includes(normalizedName) || normalizedName.includes(normalizedHandle)) return 0.7;
  
  // Levenshtein-like similarity
  const longer = normalizedHandle.length > normalizedName.length ? normalizedHandle : normalizedName;
  const shorter = normalizedHandle.length > normalizedName.length ? normalizedName : normalizedHandle;
  
  if (longer.length === 0) return 0;
  const matchCount = shorter.split('').filter((c, i) => longer[i] === c).length;
  return matchCount / longer.length * 0.5;
}

function calculateDomainConfidence(domain: string, normalizedName: string, title: string): number {
  let score = 0;
  const normalizedDomain = domain.toLowerCase().replace(/[.-]/g, '');
  const normalizedTitle = title.toLowerCase();
  
  // Domain contains company name
  if (normalizedDomain.includes(normalizedName.substring(0, 5))) score += 0.3;
  
  // Title contains company name
  if (normalizedTitle.includes(normalizedName.substring(0, 5))) score += 0.3;
  
  // Saudi domain
  if (domain.endsWith('.sa') || domain.includes('.com.sa')) score += 0.2;
  
  // Not a generic domain
  const genericDomains = ['wordpress', 'blogspot', 'wix', 'squarespace'];
  if (!genericDomains.some(g => domain.includes(g))) score += 0.1;
  
  return Math.min(score, 1);
}

// ============================================
// Website Fetcher
// ============================================

async function fetchWebsite(url: string): Promise<any> {
  try {
    let normalizedUrl = url.trim();
    if (!normalizedUrl.startsWith('http')) {
      normalizedUrl = 'https://' + normalizedUrl;
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);

    const response = await fetch(normalizedUrl, {
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html',
        'Accept-Language': 'ar,en;q=0.9',
      },
      redirect: 'follow',
      signal: controller.signal,
    });

    clearTimeout(timeoutId);
    
    if (!response.ok) return null;
    
    const html = await response.text();
    return parseWebsite(html, response.url);
    
  } catch (error: any) {
    console.error('[Research API] Website fetch error:', error.message);
    return null;
  }
}

function parseWebsite(html: string, finalUrl: string): any {
  const titleMatch = /<title[^>]*>([^<]*)<\/title>/i.exec(html);
  const descMatch = /<meta[^>]*name=["']description["'][^>]*content=["']([^"']*)["']/i.exec(html);
  
  const phoneRegex = /(?:\+966|00966|0)?5[0-9]{8}|(?:\+966|00966|0)?1[0-9]{7}/g;
  const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
  const whatsappRegex = /(?:wa\.me|api\.whatsapp\.com)[^\s"'<>]*/gi;
  
  const socialPatterns = [
    { platform: 'instagram', regex: /instagram\.com\/[a-zA-Z0-9_.]+/gi },
    { platform: 'twitter', regex: /(?:twitter|x)\.com\/[a-zA-Z0-9_]+/gi },
    { platform: 'facebook', regex: /facebook\.com\/[a-zA-Z0-9.]+/gi },
    { platform: 'linkedin', regex: /linkedin\.com\/(?:company|in)\/[a-zA-Z0-9-]+/gi },
    { platform: 'tiktok', regex: /tiktok\.com\/@[a-zA-Z0-9_.]+/gi },
    { platform: 'snapchat', regex: /snapchat\.com\/add\/[a-zA-Z0-9_.-]+/gi },
  ];

  const socialLinks: { platform: string; url: string }[] = [];
  for (const { platform, regex } of socialPatterns) {
    const matches = html.match(regex) || [];
    for (const match of matches.slice(0, 2)) {
      socialLinks.push({ platform, url: 'https://' + match });
    }
  }

  const tracking = {
    googleAnalytics: /(?:google-analytics\.com|gtag|UA-|G-[A-Z0-9]+)/i.test(html),
    googleTagManager: /googletagmanager\.com|GTM-/i.test(html),
    metaPixel: /(?:facebook\.com\/tr|fbq\(|connect\.facebook\.net)/i.test(html),
    tiktokPixel: /analytics\.tiktok\.com|ttq\./i.test(html),
    snapPixel: /sc-static\.net\/scevent|snaptr\(/i.test(html),
  };

  const textContent = html
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  return {
    url: finalUrl,
    title: titleMatch ? titleMatch[1].trim() : '',
    description: descMatch ? descMatch[1].trim() : '',
    phones: [...new Set((html.match(phoneRegex) || []))].slice(0, 5),
    emails: [...new Set((html.match(emailRegex) || []))].filter(e => !e.includes('example')).slice(0, 5),
    whatsappLinks: [...new Set((html.match(whatsappRegex) || []))].slice(0, 3),
    socialLinks,
    tracking,
    forms: (html.match(/<form/gi) || []).length,
    ctaButtons: [],
    textExcerpt: textContent.substring(0, 1000)
  };
}

// ============================================
// Call Forge Lead Enricher
// ============================================

async function callForgeEnricher(companyName: string, city?: string): Promise<any> {
  const FORGE_URL = process.env.FORGE_API_BASE_URL || 'http://localhost:8081';
  
  try {
    console.log('[Research API] Calling Forge Lead Enricher...');
    
    const response = await fetch(`${FORGE_URL}/v1/api/leads/enrich.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        lead: { name: companyName, city: city || '' }
      })
    });

    if (!response.ok) {
      console.log('[Research API] Forge enricher failed:', response.status);
      return null;
    }

    const data = await response.json();
    return data.ok ? data : null;
    
  } catch (error: any) {
    console.error('[Research API] Forge enricher error:', error.message);
    return null;
  }
}

// ============================================
// Main Research Handler
// ============================================

async function handleResearch(req: any, res: any) {
  const startTime = Date.now();
  const { companyName, city, activity } = req.body as ResearchRequest;

  if (!companyName?.trim()) {
    return res.status(400).json({ error: 'BAD_REQUEST', message: 'اسم الشركة مطلوب' });
  }

  console.log('[Research API] Starting research for:', companyName, city);

  const result = {
    input: { companyName, city, activity },
    discovered: {
      website: null as DiscoveredLink | null,
      socialMedia: [] as DiscoveredLink[],
      maps: null as any
    },
    extracted: {
      website: null as any,
      maps: null as any
    },
    summary: {
      totalConfidence: 0,
      sourcesFound: [] as string[],
      duration: 0,
      errors: [] as string[]
    }
  };

  try {
    // Step 1: Try Forge Lead Enricher first (has Puppeteer for real search)
    const forgeResult = await callForgeEnricher(companyName, city);
    
    if (forgeResult?.enriched) {
      // Extract Maps data
      if (forgeResult.enriched.maps) {
        result.extracted.maps = forgeResult.enriched.maps;
        result.discovered.maps = {
          url: `https://maps.google.com/?q=${encodeURIComponent(forgeResult.enriched.maps.address || companyName)}`,
          type: 'maps',
          confidence: forgeResult.enriched.maps.confidence || 0.5,
          source: 'forge_enricher'
        };
        result.summary.sourcesFound.push('google_maps');
      }

      // Extract Website
      if (forgeResult.enriched.website) {
        result.discovered.website = {
          url: forgeResult.enriched.website.url,
          type: 'website',
          confidence: forgeResult.enriched.website.confidence || 0.5,
          source: 'forge_enricher'
        };
        result.summary.sourcesFound.push('website_discovered');
      }

      // Extract Social Media
      if (forgeResult.enriched.socialMedia) {
        for (const [platform, data] of Object.entries(forgeResult.enriched.socialMedia)) {
          if (data && (data as any).url) {
            result.discovered.socialMedia.push({
              url: (data as any).url,
              type: platform,
              confidence: (data as any).confidence || 0.5,
              source: 'forge_enricher'
            });
            result.summary.sourcesFound.push(platform);
          }
        }
      }
    }

    // Step 2: Google Search as fallback/supplement
    if (!result.discovered.website || result.discovered.socialMedia.length < 2) {
      const searchQuery = city ? `${companyName} ${city}` : companyName;
      const searchResults = await searchGoogle(searchQuery);
      
      if (searchResults.length > 0) {
        const links = extractLinksFromResults(searchResults, companyName);
        
        // Add website if not found
        if (!result.discovered.website) {
          const websiteLink = links.find(l => l.type === 'website');
          if (websiteLink) {
            result.discovered.website = websiteLink;
            result.summary.sourcesFound.push('website_google');
          }
        }
        
        // Add social media
        for (const link of links.filter(l => l.type !== 'website')) {
          if (!result.discovered.socialMedia.some(s => s.type === link.type)) {
            result.discovered.socialMedia.push(link);
            if (!result.summary.sourcesFound.includes(link.type)) {
              result.summary.sourcesFound.push(link.type);
            }
          }
        }
      }
    }

    // Step 3: Fetch website details if we have a URL
    const websiteUrl = result.discovered.website?.url || result.extracted.maps?.website;
    if (websiteUrl) {
      const websiteData = await fetchWebsite(websiteUrl);
      if (websiteData) {
        result.extracted.website = websiteData;
        
        // Extract additional social links from website
        for (const social of websiteData.socialLinks || []) {
          if (!result.discovered.socialMedia.some(s => s.type === social.platform)) {
            result.discovered.socialMedia.push({
              url: social.url,
              type: social.platform,
              confidence: 0.8,
              source: 'website_scrape'
            });
            if (!result.summary.sourcesFound.includes(social.platform)) {
              result.summary.sourcesFound.push(social.platform);
            }
          }
        }
        
        if (!result.summary.sourcesFound.includes('website_data')) {
          result.summary.sourcesFound.push('website_data');
        }
      }
    }

    // Calculate total confidence
    let totalScore = 0;
    let weights = 0;
    
    if (result.discovered.website) {
      totalScore += result.discovered.website.confidence * 0.3;
      weights += 0.3;
    }
    if (result.discovered.maps) {
      totalScore += result.discovered.maps.confidence * 0.35;
      weights += 0.35;
    }
    if (result.discovered.socialMedia.length > 0) {
      const avgSocial = result.discovered.socialMedia.reduce((s, l) => s + l.confidence, 0) / result.discovered.socialMedia.length;
      totalScore += avgSocial * 0.2;
      weights += 0.2;
    }
    if (result.extracted.website) {
      totalScore += 0.15;
      weights += 0.15;
    }
    
    result.summary.totalConfidence = weights > 0 ? Math.round((totalScore / weights) * 100) / 100 : 0;

  } catch (error: any) {
    console.error('[Research API] Error:', error);
    result.summary.errors.push(error.message);
  }

  result.summary.duration = Date.now() - startTime;
  console.log('[Research API] Completed in', result.summary.duration, 'ms');
  console.log('[Research API] Sources found:', result.summary.sourcesFound);

  return res.status(200).json(result);
}

// ============================================
// Export Handler
// ============================================

export default async function handler(req: any, res: any) {
  const user = requireAuth(req, res);
  if (!user) return;

  if (req.method !== 'POST') {
    res.setHeader('Allow', ['POST']);
    return res.status(405).json({ error: 'Method not allowed' });
  }

  return handleResearch(req, res);
}
