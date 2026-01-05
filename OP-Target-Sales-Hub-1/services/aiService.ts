import { GoogleGenAI, Type } from "@google/genai";
import { db } from "./db";
import { Lead, Report, EvidencePack } from '../types';
import { logger } from '../utils/logger';
import { rateLimitService, RateLimitAction } from "./rateLimitService";
import { authService } from "./authService";
import { SECTOR_TEMPLATES } from "../constants";

export const REPORT_SCHEMA = {
  type: Type.OBJECT,
  properties: {
    company: {
      type: Type.OBJECT,
      properties: {
        name: { type: Type.STRING },
        activity: { type: Type.STRING },
        city: { type: Type.STRING },
        website: { type: Type.STRING },
      }
    },
    sector: {
      type: Type.OBJECT,
      properties: {
        primary: { type: Type.STRING },
        confidence: { type: Type.NUMBER },
        matched_signals: { type: Type.ARRAY, items: { type: Type.STRING } },
      }
    },
    evidence_summary: {
      type: Type.OBJECT,
      properties: {
        sources_used: { type: Type.ARRAY, items: { type: Type.STRING } },
        key_findings: {
          type: Type.ARRAY,
          items: {
            type: Type.OBJECT,
            properties: {
              finding: { type: Type.STRING },
              evidence_url: { type: Type.STRING, nullable: true },
              confidence: { type: Type.STRING }
            }
          }
        },
        tech_hints: { type: Type.ARRAY, items: { type: Type.STRING } },
        contacts_found: {
          type: Type.OBJECT,
          properties: {
            phone: { type: Type.STRING, nullable: true },
            whatsapp: { type: Type.STRING, nullable: true },
            email: { type: Type.STRING, nullable: true }
          }
        }
      }
    },
    snapshot: {
      type: Type.OBJECT,
      properties: {
        summary: { type: Type.STRING },
        market_fit: { type: Type.STRING },
        signals: { type: Type.ARRAY, items: { type: Type.STRING } },
      },
    },
    website_audit: {
      type: Type.OBJECT,
      properties: {
        strengths: { type: Type.ARRAY, items: { type: Type.STRING } },
        issues: {
          type: Type.ARRAY,
          items: {
            type: Type.OBJECT,
            properties: {
              issue: { type: Type.STRING },
              impact: { type: Type.STRING },
              quick_fix: { type: Type.STRING },
              evidence_url: { type: Type.STRING, nullable: true }
            }
          }
        },
        cta_quality: { type: Type.STRING },
        tracking_gap: { type: Type.STRING }
      }
    },
    social_audit: {
      type: Type.OBJECT,
      properties: {
        presence: {
          type: Type.ARRAY,
          items: {
            type: Type.OBJECT,
            properties: {
              platform: { type: Type.STRING },
              link: { type: Type.STRING },
              status: { type: Type.STRING }
            }
          }
        },
        content_gaps: { type: Type.ARRAY, items: { type: Type.STRING } },
        quick_content_ideas: { type: Type.ARRAY, items: { type: Type.STRING } }
      }
    },
    pain_points: {
      type: Type.ARRAY,
      items: {
        type: Type.OBJECT,
        properties: {
          title: { type: Type.STRING },
          why_it_matters: { type: Type.STRING },
          evidence_level: { type: Type.STRING },
        },
      },
    },
    quick_wins: {
      type: Type.ARRAY,
      items: {
        type: Type.OBJECT,
        properties: {
          title: { type: Type.STRING },
          action: { type: Type.STRING },
          expected_impact: { type: Type.STRING },
          evidence_url: { type: Type.STRING, nullable: true }
        },
      },
    },
    recommended_services: {
      type: Type.ARRAY,
      items: {
        type: Type.OBJECT,
        properties: {
          tier: { type: Type.STRING, description: 'tier1|tier2|tier3' },
          service: { type: Type.STRING },
          why: { type: Type.STRING },
          offer: { type: Type.STRING },
          next_step: { type: Type.STRING },
          confidence: { type: Type.NUMBER },
          package_suggestion: {
            type: Type.OBJECT,
            properties: {
              package_name: { type: Type.STRING },
              scope: { type: Type.STRING },
              duration: { type: Type.STRING },
              price_range: { type: Type.STRING },
              notes: { type: Type.STRING, nullable: true }
            },
            nullable: true
          },
        },
      },
    },
    talk_track: {
      type: Type.OBJECT,
      properties: {
        opening: { type: Type.STRING },
        qualifying_questions: { type: Type.ARRAY, items: { type: Type.STRING } },
        pitch: { type: Type.STRING },
        objection_handlers: {
          type: Type.ARRAY,
          items: {
            type: Type.OBJECT,
            properties: {
              objection: { type: Type.STRING },
              answer: { type: Type.STRING },
            },
          },
        },
        closing: { type: Type.STRING },
        whatsapp_messages: {
          type: Type.ARRAY,
          items: {
            type: Type.OBJECT,
            properties: {
              variant: { type: Type.STRING },
              text: { type: Type.STRING }
            }
          }
        },
      },
    },
    follow_up_plan: {
      type: Type.ARRAY,
      items: {
        type: Type.OBJECT,
        properties: {
          day: { type: Type.NUMBER },
          action: { type: Type.STRING },
          channel: { type: Type.STRING },
          goal: { type: Type.STRING },
        },
      },
    },
    assumptions: { type: Type.ARRAY, items: { type: Type.STRING } },
    data_gaps: { type: Type.ARRAY, items: { type: Type.STRING } },
    compliance_notes: { type: Type.ARRAY, items: { type: Type.STRING } },
  },
  required: ['company', 'sector', 'evidence_summary', 'snapshot', 'website_audit', 'social_audit', 'pain_points', 'recommended_services', 'talk_track', 'follow_up_plan']
};

class AIService {
  private async getProviderInstance() {
    const settings = await db.getAISettings();
    const isGemini = settings.activeProvider === 'gemini';
    const apiKey = isGemini 
      ? (settings.geminiApiKey || process.env.API_KEY) 
      : settings.openaiApiKey;

    if (!apiKey) {
      throw new Error("AI_CONFIG_ERROR: يرجى ضبط مفتاح الـ API للمزود المختار في الإعدادات.");
    }

    return {
      apiKey,
      model: isGemini ? settings.geminiModel : settings.openaiModel,
      provider: settings.activeProvider
    };
  }

  async generateWithRepair(prompt: string, schema: any, maxRetries = 2, useSearch = false, companyName?: string, city?: string): Promise<{ data: any; usage: any }> {
    const user = authService.getCurrentUser();
    if (user) {
      const check = rateLimitService.check(RateLimitAction.GENERATE_REPORT, user.id);
      if (!check.allowed) {
        throw new Error(`AI_RATE_LIMIT: تم تجاوز حد الاستخدام اليومي. يرجى المحاولة غداً.`);
      }
    }

    let lastOutput = '';

    for (let i = 0; i <= maxRetries; i++) {
      try {
        const currentPrompt = i === 0 
          ? prompt 
          : `${prompt}\n\n[REPAIR ATTEMPT ${i}]\nPrevious Output was invalid JSON.\nRaw output received: ${lastOutput}\nFIX: Provide ONLY a valid JSON object strictly matching the schema.`;

        // Use backend API to keep API keys secure
        const response = await fetch('/api/reports?generate=true', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({
            prompt: currentPrompt,
            useSearch,
            companyName,
            city
          })
        });

        if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.message || `API Error: ${response.statusText}`);
        }

        const result = await response.json();
        return { data: result.data, usage: result.usage };
      } catch (err: any) {
        lastOutput = err.message || '';
        if (i === maxRetries) {
          throw new Error(`AI_FAILURE: ${err.message}`);
        }
      }
    }
    throw new Error("AI_UNREACHABLE");
  }

  async generateReport(lead: Partial<Lead>, evidence?: EvidencePack, isUpdate = false, previousReport?: any): Promise<{ data: any; usage: any }> {
    const services = db.getServices();
    const packages = db.getPackages();
    
    const sectorSlug = typeof lead.sector === 'string' 
      ? (lead.sector as string) 
      : (lead.sector as any)?.primary || 'other';

    // Build evidence-based context
    const rawBundle = evidence?._raw_bundle;
    logger.debug('[AI Service] Evidence received:', {
      hasEvidence: !!evidence,
      hasRawBundle: !!rawBundle,
      sourcesCount: rawBundle?.sources?.length || 0,
      qualityScore: rawBundle?.qualityScore || 0
    });
    
    const websiteEvidence = rawBundle?.sources?.find((s: any) => s.type === 'website');
    const parsedWebsite = websiteEvidence?.parsed;
    
    logger.debug('[AI Service] Website evidence:', {
      hasWebsiteEvidence: !!websiteEvidence,
      status: websiteEvidence?.status,
      hasParsed: !!parsedWebsite,
      title: parsedWebsite?.title?.substring(0, 50)
    });
    
    // Build tracking analysis
    let trackingAnalysis = 'لم يتم فحص الموقع';
    let ctaAnalysis = 'لم يتم فحص الموقع';
    
    if (parsedWebsite) {
      const tracking = parsedWebsite.tracking;
      const trackingFound: string[] = [];
      if (tracking?.googleAnalytics) trackingFound.push('Google Analytics');
      if (tracking?.googleTagManager) trackingFound.push('Google Tag Manager');
      if (tracking?.metaPixel) trackingFound.push('Meta Pixel');
      if (tracking?.tiktokPixel) trackingFound.push('TikTok Pixel');
      if (tracking?.snapPixel) trackingFound.push('Snapchat Pixel');
      if (tracking?.otherTracking?.length) trackingFound.push(...tracking.otherTracking);
      
      trackingAnalysis = trackingFound.length > 0 
        ? `تم اكتشاف: ${trackingFound.join(', ')}`
        : 'لا توجد أدوات تتبع مثبتة (GA/GTM/Pixel) - فرصة كبيرة للتحسين';
      
      // CTA Analysis
      const ctaScore = (parsedWebsite.forms > 0 ? 25 : 0) + 
                       (parsedWebsite.whatsappLinks?.length > 0 ? 25 : 0) +
                       (parsedWebsite.ctaButtons?.length > 0 ? 25 : 0) +
                       (parsedWebsite.phones?.length > 0 ? 25 : 0);
      
      ctaAnalysis = `نقاط CTA: ${ctaScore}/100 - ` +
        `نماذج: ${parsedWebsite.forms || 0}, ` +
        `واتساب: ${parsedWebsite.whatsappLinks?.length || 0}, ` +
        `أزرار CTA: ${parsedWebsite.ctaButtons?.length || 0}`;
    }

    const context = `
أنت خبير استراتيجي لشركة OP Target للتسويق. مهمتك توليد تقرير استراتيجي مبني على أدلة فعلية.

=== قواعد صارمة ===
1. كل معلومة يجب أن تكون مستندة على الأدلة المقدمة أدناه
2. ممنوع اختراع معلومات لم ترد في الأدلة
3. إذا لم تتوفر معلومة، اكتب "غير متاح - السبب: [السبب التقني]"
4. استخدم اللغة العربية بلهجة مهنية سعودية

=== الخدمات المتاحة للبيع ===
${JSON.stringify(services)}

=== الباقات ===
${JSON.stringify(packages)}

=== الأدلة الفعلية المجمعة من الموقع ===
${websiteEvidence ? `
- حالة الجلب: ${websiteEvidence.status} (${websiteEvidence.statusCode || 'N/A'})
- الرابط النهائي: ${websiteEvidence.finalUrl || 'N/A'}
- حجم الصفحة: ${websiteEvidence.bytes || 0} بايت
- عنوان الموقع: ${parsedWebsite?.title || 'غير متاح'}
- الوصف: ${parsedWebsite?.metaDescription || 'غير متاح'}
- العناوين الرئيسية (H1): ${parsedWebsite?.h1?.join(' | ') || 'غير متاح'}
- أرقام الهاتف المكتشفة: ${parsedWebsite?.phones?.join(', ') || 'لم يتم العثور على أرقام'}
- البريد الإلكتروني: ${parsedWebsite?.emails?.join(', ') || 'لم يتم العثور على بريد'}
- روابط واتساب: ${parsedWebsite?.whatsappLinks?.length || 0} رابط
- روابط التواصل الاجتماعي: ${parsedWebsite?.socialLinks?.map((s: any) => s.platform).join(', ') || 'لم يتم العثور'}
- نماذج الاتصال: ${parsedWebsite?.forms || 0} نموذج
- أزرار CTA: ${parsedWebsite?.ctaButtons?.slice(0, 5).join(', ') || 'لم يتم العثور'}

=== تحليل التتبع (Tracking) ===
${trackingAnalysis}

=== تحليل CTA ===
${ctaAnalysis}

=== مقتطف من محتوى الموقع ===
${parsedWebsite?.textExcerpt?.substring(0, 800) || 'لم يتم استخراج محتوى'}
` : `
- لم يتم جلب الموقع: ${evidence?.fetch_status?.find(f => f.source === 'website')?.notes || 'لم يتم توفير رابط'}
`}

=== أدلة التواصل الاجتماعي ===
${rawBundle?.sources?.filter((s: any) => s.type !== 'website').map((s: any) => `
- ${s.type}: ${s.status} - ${s.notes || 'N/A'}
- النتائج: ${s.keyFindings?.join(', ') || 'لا توجد نتائج'}
`).join('\n') || 'لم يتم توفير روابط تواصل اجتماعي'}

=== التشخيصات ===
- جودة الأدلة: ${rawBundle?.qualityScore || 0}%
- التحذيرات: ${rawBundle?.diagnostics?.warnings?.join(', ') || 'لا توجد'}
- الأخطاء: ${rawBundle?.diagnostics?.errors?.join(', ') || 'لا توجد'}

${isUpdate ? `=== التقرير السابق للتحديث ===\n${JSON.stringify(previousReport?.output || {})}` : ''}
    `;

    const userPrompt = `
ولّد تقرير استراتيجي شامل للعميل:
- اسم الشركة: ${lead.companyName}
- النشاط: ${lead.activity}
- المدينة: ${lead.city || 'غير محدد'}
- الموقع: ${lead.website || 'غير متوفر'}

=== قواعد إلزامية لملء التقرير ===
⚠️ ممنوع ترك أي حقل فارغاً - كل حقل يجب أن يحتوي على محتوى مفيد
⚠️ إذا لم تتوفر معلومة محددة، اكتب تحليلاً عاماً مبنياً على نوع النشاط والقطاع
⚠️ كل قائمة (array) يجب أن تحتوي على 3 عناصر على الأقل

المطلوب بالتفصيل:
1. evidence_summary: 
   - key_findings: 3-5 نتائج رئيسية (إذا لم تتوفر أدلة، استنتج من نوع النشاط)
   - tech_hints: 3+ تلميحات تقنية
   - sources_used: المصادر المستخدمة

2. website_audit:
   - tracking_gap: "${trackingAnalysis}"
   - cta_quality: "${ctaAnalysis}"
   - issues: 3+ مشاكل مع الأثر والحل السريع لكل منها

3. social_audit:
   - presence: قائمة المنصات مع حالة كل منها
   - content_gaps: 3+ فجوات محتوى
   - quick_content_ideas: 3+ أفكار محتوى سريعة

4. snapshot:
   - summary: ملخص استراتيجي شامل (50+ كلمة)
   - market_fit: تحليل ملاءمة السوق

5. pain_points: 3+ نقاط ألم مع الأثر والحل

6. recommended_services: 3+ خدمات مقترحة مع:
   - tier: tier1/tier2/tier3
   - service: اسم الخدمة
   - why: سبب التوصية
   - package_suggestion: اقتراح باقة مع السعر والنطاق

7. talk_track:
   - opening: جملة افتتاحية قوية
   - pitch: عرض بيعي مقنع
   - objection_handlers: 3+ اعتراضات مع ردود

8. follow_up_plan: 4 خطوات متابعة (أيام 1, 3, 5, 7)

تذكر: التقرير الفارغ أو الناقص = فشل. املأ كل حقل بمحتوى مفيد.
    `;

    // Pass company name and city for Google search
    return this.generateWithRepair(`${context}\n\n${userPrompt}`, REPORT_SCHEMA, 2, true, (lead as any).name, (lead as any).city);
  }

  async generateSurvey(lead: Lead, dataGaps: string[]): Promise<{ data: any; usage: any }> {
    const SURVEY_SCHEMA = {
      type: Type.OBJECT,
      properties: {
        title: { type: Type.STRING },
        questions: {
          type: Type.ARRAY,
          items: {
            type: Type.OBJECT,
            properties: {
              id: { type: Type.STRING },
              type: { type: Type.STRING },
              group: { type: Type.STRING },
              question: { type: Type.STRING },
              options: { type: Type.ARRAY, items: { type: Type.STRING } },
              why_this_matters: { type: Type.STRING },
            },
            required: ['id', 'type', 'group', 'question', 'why_this_matters']
          }
        },
        call_script_questions: { type: Type.ARRAY, items: { type: Type.STRING } }
      },
      required: ['title', 'questions']
    };

    const prompt = `
      أنت أخصائي تأهيل عملاء لشركة OP Target. 
      بناءً على الفجوات المعلوماتية: ${dataGaps.join(', ')}، وقطاع العميل: ${lead.activity}.
      ولد استبيان ذكي من 10 أسئلة حاسمة لتأهيل العميل وفهم قدراته المالية واتخاذ القرار.
    `;

    return this.generateWithRepair(prompt, SURVEY_SCHEMA);
  }

  async extractLeadUpdates(answers: Record<string, any>): Promise<Partial<Lead>> {
    const SCHEMA = {
      type: Type.OBJECT,
      properties: {
        decisionMakerName: { type: Type.STRING, nullable: true },
        decisionMakerRole: { type: Type.STRING, nullable: true },
        budgetRange: { type: Type.STRING, nullable: true },
        goalPrimary: { type: Type.STRING, nullable: true },
        phone: { type: Type.STRING, nullable: true },
        contactEmail: { type: Type.STRING, nullable: true },
        notes: { type: Type.STRING, nullable: true }
      }
    };

    const prompt = `استخرج تحديثات الـ CRM من إجابات الاستبيان التالية: ${JSON.stringify(answers)}`;

    try {
      const { data } = await this.generateWithRepair(prompt, SCHEMA);
      return data;
    } catch (e) {
      return {};
    }
  }
}

export const aiService = new AIService();
