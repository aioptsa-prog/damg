/**
 * Lead Enrichment Engine
 * 
 * نظام بحث ذكي متعدد الطبقات للبحث عن عميل واحد محدد وإثراء بياناته.
 * يستغل البنية التحتية للمتصفح (Puppeteer) دون استخدام منظومة Worker الداخلية.
 */

import puppeteer from 'puppeteer';
import { KeywordEngine } from './keyword_engine.js';
import { WebsiteSearchLayer } from './layers/website.js';
import { SocialMediaSearchLayer } from './layers/social.js';
import { MapsSearchLayer } from './layers/maps.js';
import { Verifier } from './verification.js';
import { Aggregator } from './aggregator.js';

export class LeadEnricher {
  constructor(options = {}) {
    this.options = {
      headless: options.headless ?? true,
      timeout: options.timeout ?? 30000,
      delayBetweenSearches: options.delayBetweenSearches ?? 2000,
      userAgent: options.userAgent ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      ...options
    };
    
    this.browser = null;
    this.keywordEngine = new KeywordEngine();
    this.verifier = new Verifier();
    this.aggregator = new Aggregator();
  }

  /**
   * تهيئة المتصفح
   */
  async init() {
    if (this.browser) return;
    
    this.browser = await puppeteer.launch({
      headless: this.options.headless,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--disable-gpu',
        '--lang=ar,en'
      ]
    });
    
    console.log('[LeadEnricher] Browser initialized');
  }

  /**
   * إغلاق المتصفح
   */
  async close() {
    if (this.browser) {
      await this.browser.close();
      this.browser = null;
      console.log('[LeadEnricher] Browser closed');
    }
  }

  /**
   * إنشاء صفحة جديدة مع الإعدادات
   */
  async createPage() {
    const page = await this.browser.newPage();
    
    await page.setUserAgent(this.options.userAgent);
    await page.setViewport({ width: 1366, height: 768 });
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'ar,en;q=0.9' });
    
    // تجاهل الصور والخطوط لتسريع التحميل
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      const resourceType = req.resourceType();
      if (['image', 'font', 'media'].includes(resourceType)) {
        req.abort();
      } else {
        req.continue();
      }
    });
    
    return page;
  }

  /**
   * تأخير بين العمليات
   */
  async delay(ms = this.options.delayBetweenSearches) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * البحث الرئيسي - إثراء بيانات العميل
   * @param {Object} lead - بيانات العميل
   * @returns {Object} - البيانات المُثراة
   */
  async enrich(lead) {
    const startTime = Date.now();
    console.log(`[LeadEnricher] Starting enrichment for: ${lead.name}`);
    
    await this.init();
    
    const results = {
      original: lead,
      enriched: {
        website: null,
        socialMedia: {},
        maps: null,
        contact: null
      },
      searchesPerformed: 0,
      errors: []
    };

    try {
      // توليد الكلمات المفتاحية
      const queries = this.keywordEngine.generate(lead);
      console.log(`[LeadEnricher] Generated ${queries.length} search queries`);

      // === Layer 1: Website Search ===
      try {
        console.log('[LeadEnricher] Layer 1: Website Search');
        const websiteLayer = new WebsiteSearchLayer(this);
        const websiteResults = await websiteLayer.search(lead, queries.filter(q => q.purpose === 'website'));
        results.enriched.website = this.verifier.pickBest(websiteResults, lead);
        results.searchesPerformed++;
        await this.delay();
      } catch (err) {
        console.error('[LeadEnricher] Website search error:', err.message);
        results.errors.push({ layer: 'website', error: err.message });
      }

      // === Layer 2: Social Media Search ===
      try {
        console.log('[LeadEnricher] Layer 2: Social Media Search');
        const socialLayer = new SocialMediaSearchLayer(this);
        const socialResults = await socialLayer.search(lead, queries.filter(q => q.purpose === 'social'));
        results.enriched.socialMedia = this.aggregator.groupSocialResults(socialResults);
        results.searchesPerformed++;
        await this.delay();
      } catch (err) {
        console.error('[LeadEnricher] Social media search error:', err.message);
        results.errors.push({ layer: 'social', error: err.message });
      }

      // === Layer 3: Maps Search (مرة واحدة) ===
      try {
        console.log('[LeadEnricher] Layer 3: Maps Search');
        const mapsLayer = new MapsSearchLayer(this);
        const mapsResult = await mapsLayer.search(lead);
        results.enriched.maps = mapsResult;
        results.searchesPerformed++;
      } catch (err) {
        console.error('[LeadEnricher] Maps search error:', err.message);
        results.errors.push({ layer: 'maps', error: err.message });
      }

    } catch (err) {
      console.error('[LeadEnricher] Fatal error:', err);
      results.errors.push({ layer: 'fatal', error: err.message });
    }

    // حساب الملخص
    const duration = Date.now() - startTime;
    results.summary = this.aggregator.summarize(results, duration);
    
    console.log(`[LeadEnricher] Enrichment completed in ${duration}ms`);
    console.log(`[LeadEnricher] Total confidence: ${results.summary.totalConfidence}`);
    
    return results;
  }
}

export default LeadEnricher;
