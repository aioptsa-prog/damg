/**
 * Integration Worker Modules
 * Phase 6: Modular enrichment modules for lead data collection
 * 
 * Modules:
 * - maps: Google Maps data (name, category, address, phone, website, rating, etc.)
 * - website: Homepage analysis (title, meta, contacts, social links, tech hints)
 * - instagram: (beta) Instagram presence detection
 */

import fetch from 'node-fetch';

// Module timeout (ms)
const MODULE_TIMEOUT = 60000;

/**
 * Maps Module
 * Collects data from Google Maps for a business
 */
export async function mapsModule(page, lead, options = {}) {
  const result = {
    module: 'maps',
    success: false,
    data: {},
    error: null
  };

  try {
    const searchQuery = lead.name || lead.company_name || '';
    const city = lead.city || options.geo?.city || '';
    const query = `${searchQuery} ${city}`.trim();

    if (!query) {
      result.error = { code: 'no_query', message: 'No search query available' };
      return result;
    }

    // Navigate to Google Maps search
    const mapsUrl = `https://www.google.com/maps/search/${encodeURIComponent(query)}`;
    await page.goto(mapsUrl, { waitUntil: 'networkidle', timeout: MODULE_TIMEOUT });

    // Wait for results or single place
    await page.waitForTimeout(3000);

    // Check if we landed on a single place page
    const isPlacePage = await page.evaluate(() => {
      return !!document.querySelector('[data-attrid="title"]') || 
             !!document.querySelector('h1.DUwDvf') ||
             window.location.href.includes('/place/');
    });

    if (isPlacePage) {
      // Extract place data
      result.data = await extractPlaceData(page);
      result.success = true;
    } else {
      // Try to click first result
      const firstResult = await page.$('a[href*="/maps/place/"]');
      if (firstResult) {
        await firstResult.click();
        await page.waitForTimeout(3000);
        result.data = await extractPlaceData(page);
        result.success = true;
      } else {
        result.error = { code: 'not_found', message: 'No results found' };
      }
    }

  } catch (err) {
    if (err.message.includes('timeout')) {
      result.error = { code: 'timeout', message: 'Maps request timed out' };
    } else if (err.message.includes('blocked') || err.message.includes('captcha')) {
      result.error = { code: 'blocked', message: 'Request blocked by Google' };
    } else {
      result.error = { code: 'error', message: err.message };
    }
  }

  return result;
}

/**
 * Extract place data from Google Maps page
 */
async function extractPlaceData(page) {
  return await page.evaluate(() => {
    const data = {
      name: null,
      category: null,
      address: null,
      phones: [],
      website: null,
      plus_code: null,
      rating: null,
      reviews_count: null,
      opening_hours: null,
      map_url: window.location.href
    };

    // Name
    const nameEl = document.querySelector('h1.DUwDvf, h1.fontHeadlineLarge');
    if (nameEl) data.name = nameEl.textContent.trim();

    // Category
    const categoryEl = document.querySelector('button[jsaction*="category"]');
    if (categoryEl) data.category = categoryEl.textContent.trim();

    // Rating
    const ratingEl = document.querySelector('span.ceNzKf, div.F7nice span');
    if (ratingEl) {
      const ratingText = ratingEl.getAttribute('aria-label') || ratingEl.textContent;
      const ratingMatch = ratingText.match(/(\d+[.,]\d+)/);
      if (ratingMatch) data.rating = parseFloat(ratingMatch[1].replace(',', '.'));
    }

    // Reviews count
    const reviewsEl = document.querySelector('span.UY7F9, button[jsaction*="reviews"] span');
    if (reviewsEl) {
      const reviewsText = reviewsEl.textContent;
      const reviewsMatch = reviewsText.match(/(\d+[\d,]*)/);
      if (reviewsMatch) data.reviews_count = parseInt(reviewsMatch[1].replace(/,/g, ''));
    }

    // Info items (address, phone, website, hours)
    const infoButtons = document.querySelectorAll('button[data-item-id]');
    infoButtons.forEach(btn => {
      const itemId = btn.getAttribute('data-item-id');
      const text = btn.textContent.trim();
      
      if (itemId?.includes('address') || btn.querySelector('[data-tooltip="Copy address"]')) {
        data.address = text;
      } else if (itemId?.includes('phone') || btn.querySelector('[data-tooltip="Copy phone number"]')) {
        if (text && !data.phones.includes(text)) {
          data.phones.push(text);
        }
      } else if (itemId?.includes('authority')) {
        data.website = btn.querySelector('a')?.href || text;
      } else if (itemId?.includes('hours')) {
        data.opening_hours = text;
      } else if (itemId?.includes('plus_code')) {
        data.plus_code = text;
      }
    });

    // Fallback: look for links
    if (!data.website) {
      const websiteLink = document.querySelector('a[data-item-id="authority"]');
      if (websiteLink) data.website = websiteLink.href;
    }

    return data;
  });
}

/**
 * Website Module
 * Analyzes business website homepage
 */
export async function websiteModule(page, lead, options = {}) {
  const result = {
    module: 'website',
    success: false,
    data: {},
    error: null
  };

  try {
    let websiteUrl = lead.website || lead.url;
    
    if (!websiteUrl) {
      result.error = { code: 'no_url', message: 'No website URL available' };
      return result;
    }

    // Ensure URL has protocol
    if (!websiteUrl.startsWith('http')) {
      websiteUrl = 'https://' + websiteUrl;
    }

    // Navigate to website
    await page.goto(websiteUrl, { 
      waitUntil: 'domcontentloaded', 
      timeout: MODULE_TIMEOUT 
    });

    // Wait a bit for dynamic content
    await page.waitForTimeout(2000);

    // Extract website data
    result.data = await page.evaluate(() => {
      const data = {
        title: document.title || null,
        description: null,
        emails: [],
        phones: [],
        social_links: {},
        tech_hints: [],
        structured_data: null
      };

      // Meta description
      const metaDesc = document.querySelector('meta[name="description"]');
      if (metaDesc) data.description = metaDesc.getAttribute('content');

      // Find emails
      const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
      const bodyText = document.body.innerText || '';
      const emails = bodyText.match(emailRegex) || [];
      data.emails = [...new Set(emails)].slice(0, 5);

      // Find phones (Saudi format)
      const phoneRegex = /(?:\+966|966|05|5)\d{8,9}/g;
      const phones = bodyText.match(phoneRegex) || [];
      data.phones = [...new Set(phones)].slice(0, 5);

      // Social links
      const socialPatterns = {
        facebook: /facebook\.com/i,
        twitter: /twitter\.com|x\.com/i,
        instagram: /instagram\.com/i,
        linkedin: /linkedin\.com/i,
        youtube: /youtube\.com/i,
        tiktok: /tiktok\.com/i,
        snapchat: /snapchat\.com/i
      };

      document.querySelectorAll('a[href]').forEach(link => {
        const href = link.href;
        for (const [platform, pattern] of Object.entries(socialPatterns)) {
          if (pattern.test(href) && !data.social_links[platform]) {
            data.social_links[platform] = href;
          }
        }
      });

      // Tech hints
      const techIndicators = {
        'WordPress': () => !!document.querySelector('meta[name="generator"][content*="WordPress"]'),
        'Shopify': () => window.Shopify !== undefined,
        'WooCommerce': () => !!document.querySelector('.woocommerce'),
        'React': () => !!document.querySelector('[data-reactroot]'),
        'Vue': () => !!document.querySelector('[data-v-]'),
        'Bootstrap': () => !!document.querySelector('[class*="col-md-"]'),
        'jQuery': () => typeof window.jQuery !== 'undefined'
      };

      for (const [tech, check] of Object.entries(techIndicators)) {
        try {
          if (check()) data.tech_hints.push(tech);
        } catch (e) {}
      }

      // Structured data (JSON-LD)
      const jsonLd = document.querySelector('script[type="application/ld+json"]');
      if (jsonLd) {
        try {
          data.structured_data = JSON.parse(jsonLd.textContent);
        } catch (e) {}
      }

      return data;
    });

    result.success = true;

  } catch (err) {
    if (err.message.includes('timeout')) {
      result.error = { code: 'timeout', message: 'Website request timed out' };
    } else if (err.message.includes('net::ERR_')) {
      result.error = { code: 'unreachable', message: 'Website unreachable' };
    } else {
      result.error = { code: 'error', message: err.message };
    }
  }

  return result;
}

/**
 * Instagram Module (Beta)
 * Detects Instagram presence - minimal scraping to avoid blocks
 */
export async function instagramModule(page, lead, options = {}) {
  const result = {
    module: 'instagram',
    success: false,
    data: {},
    error: null
  };

  try {
    // Try to find Instagram from lead data or website social links
    let instagramUrl = lead.instagram_url || lead.social_links?.instagram;
    
    if (!instagramUrl) {
      // Try to construct from business name
      const businessName = (lead.name || lead.company_name || '').toLowerCase()
        .replace(/[^a-z0-9]/g, '')
        .slice(0, 30);
      
      if (businessName) {
        instagramUrl = `https://www.instagram.com/${businessName}/`;
      }
    }

    if (!instagramUrl) {
      result.error = { code: 'no_url', message: 'No Instagram URL available' };
      return result;
    }

    // Just check if profile exists (minimal request)
    const response = await fetch(instagramUrl, {
      method: 'HEAD',
      timeout: 10000,
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
      }
    });

    if (response.ok) {
      result.data = {
        url: instagramUrl,
        exists: true,
        status: response.status
      };
      result.success = true;
    } else if (response.status === 404) {
      result.data = {
        url: instagramUrl,
        exists: false,
        status: 404
      };
      result.success = true;
    } else {
      result.error = { code: 'blocked', message: `Instagram returned ${response.status}` };
    }

  } catch (err) {
    result.error = { code: 'error', message: err.message };
  }

  return result;
}

/**
 * Run all requested modules and merge results
 */
export async function runModules(page, lead, modules, options = {}) {
  const results = {
    modules: {},
    snapshot: {
      lead_id: lead.id,
      collected_at: new Date().toISOString(),
      sources: []
    },
    success: false,
    partial: false
  };

  const moduleHandlers = {
    maps: mapsModule,
    website: websiteModule,
    instagram: instagramModule
  };

  let successCount = 0;
  let failCount = 0;

  for (const moduleName of modules) {
    const handler = moduleHandlers[moduleName];
    if (!handler) {
      results.modules[moduleName] = { 
        success: false, 
        error: { code: 'unknown_module', message: `Unknown module: ${moduleName}` }
      };
      failCount++;
      continue;
    }

    try {
      const moduleResult = await handler(page, lead, options);
      results.modules[moduleName] = moduleResult;

      if (moduleResult.success) {
        successCount++;
        results.snapshot.sources.push(moduleName);
        
        // Merge data into snapshot
        if (moduleName === 'maps') {
          results.snapshot.maps = moduleResult.data;
          // Promote key fields
          if (moduleResult.data.name) results.snapshot.name = moduleResult.data.name;
          if (moduleResult.data.phones?.length) results.snapshot.phones = moduleResult.data.phones;
          if (moduleResult.data.website) results.snapshot.website = moduleResult.data.website;
          if (moduleResult.data.address) results.snapshot.address = moduleResult.data.address;
          if (moduleResult.data.category) results.snapshot.category = moduleResult.data.category;
        } else if (moduleName === 'website') {
          results.snapshot.website_data = moduleResult.data;
          // Merge contacts
          if (moduleResult.data.emails?.length) {
            results.snapshot.emails = moduleResult.data.emails;
          }
          if (moduleResult.data.phones?.length) {
            results.snapshot.phones = [...(results.snapshot.phones || []), ...moduleResult.data.phones];
            results.snapshot.phones = [...new Set(results.snapshot.phones)];
          }
          if (moduleResult.data.social_links) {
            results.snapshot.social_links = moduleResult.data.social_links;
          }
        } else if (moduleName === 'instagram') {
          results.snapshot.instagram = moduleResult.data;
        }
      } else {
        failCount++;
      }
    } catch (err) {
      results.modules[moduleName] = {
        success: false,
        error: { code: 'exception', message: err.message }
      };
      failCount++;
    }
  }

  // Determine overall status
  if (successCount === modules.length) {
    results.success = true;
  } else if (successCount > 0) {
    results.partial = true;
  }

  return results;
}
