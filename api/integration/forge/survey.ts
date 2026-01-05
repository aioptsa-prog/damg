/**
 * Forge Survey Generation Endpoint
 * POST /api/integration/forge/survey
 * 
 * Generates a survey/intel report from a linked forge lead.
 * Uses existing AI service and enrichment logic.
 * 
 * SECURITY:
 * - Behind INTEGRATION_SURVEY_FROM_LEAD flag
 * - Requires authenticated user
 * - Validates lead ownership via RBAC
 * - Server-side only (OP-Target calls forge, not browser)
 * 
 * IDEMPOTENCY:
 * - Same lead won't regenerate unless force=true or TTL expired
 * - TTL default: 24 hours
 * 
 * @since Phase 3
 */

import { query } from '../../_db.js';
import { getAuthFromRequest, canAccessLead } from '../../_auth.js';
import { FLAGS } from '../../_flags.js';
import { createHmac, randomUUID } from 'crypto';

// TTL for report idempotency (24 hours)
const REPORT_TTL_HOURS = 24;

interface SurveyRequest {
  opLeadId: string;
  force?: boolean;
}

interface ForgeLead {
  id: string;
  phone: string;
  phone_norm: string;
  name: string;
  city: string;
  category?: string;
  created_at: string;
}

interface SurveyReport {
  id: string;
  lead_id: string;
  source: string;
  external_lead_id: string;
  output: any;
  suggested_message: string;
  created_at: string;
}

export default async function handler(req: any, res: any) {
  // === Feature Flag Check ===
  if (!FLAGS.SURVEY_FROM_LEAD) {
    return res.status(404).json({ ok: false, error: 'Not found' });
  }

  // === Only POST allowed ===
  if (req.method !== 'POST') {
    res.setHeader('Allow', ['POST']);
    return res.status(405).json({ ok: false, error: 'Method not allowed' });
  }

  // === Verify current user ===
  const auth = await getAuthFromRequest(req);
  if (!auth) {
    return res.status(401).json({ ok: false, error: 'Unauthorized' });
  }

  // === Parse request body ===
  let body: SurveyRequest;
  try {
    body = typeof req.body === 'string' ? JSON.parse(req.body) : req.body;
  } catch {
    return res.status(400).json({ ok: false, error: 'Invalid JSON' });
  }

  const { opLeadId, force = false } = body;

  if (!opLeadId) {
    return res.status(400).json({ ok: false, error: 'Missing opLeadId' });
  }

  // === Check lead access ===
  const hasAccess = await canAccessLead(auth, opLeadId);
  if (!hasAccess) {
    return res.status(403).json({ ok: false, error: 'Access denied to this lead' });
  }

  try {
    // === Get link from lead_external_links ===
    const linkResult = await query(
      `SELECT external_lead_id, external_phone, external_name, external_city
       FROM lead_external_links 
       WHERE op_target_lead_id = $1 AND external_system = 'forge' AND link_status = 'active'`,
      [opLeadId]
    );

    if (linkResult.rows.length === 0) {
      return res.status(404).json({ 
        ok: false, 
        error: 'Lead not linked to forge',
        hint: 'Use /api/integration/forge/link to link this lead first'
      });
    }

    const link = linkResult.rows[0];
    const forgeLeadId = link.external_lead_id;

    // === Check idempotency (existing valid report) ===
    if (!force) {
      const existingReport = await query(
        `SELECT id, output, suggested_message, created_at, ttl_expires_at
         FROM reports 
         WHERE lead_id = $1 
           AND source = 'forge' 
           AND external_lead_id = $2
           AND (ttl_expires_at IS NULL OR ttl_expires_at > NOW())
         ORDER BY created_at DESC
         LIMIT 1`,
        [opLeadId, forgeLeadId]
      );

      if (existingReport.rows.length > 0) {
        const report = existingReport.rows[0];
        console.log(`[INTEGRATION] forge/survey: Returning cached report ${report.id} for lead ${opLeadId}`);
        return res.status(200).json({
          ok: true,
          cached: true,
          report: {
            id: report.id,
            output: report.output,
            suggested_message: report.suggested_message,
            created_at: report.created_at,
            ttl_expires_at: report.ttl_expires_at,
          },
        });
      }
    }

    // === Get forge token ===
    const forgeToken = await getForgeToken(auth);
    if (!forgeToken) {
      return res.status(502).json({ 
        ok: false, 
        error: 'Failed to obtain forge token',
        hint: 'Check INTEGRATION_SHARED_SECRET and forge connectivity'
      });
    }

    // === Fetch forge lead data ===
    const forgeLead = await fetchForgeLead(forgeToken, forgeLeadId);
    if (!forgeLead) {
      return res.status(502).json({ 
        ok: false, 
        error: 'Failed to fetch forge lead',
        hint: 'Forge lead may have been deleted'
      });
    }

    // === Phase 6: Fetch snapshot if available ===
    let snapshot = null;
    if (FLAGS.WORKER_ENRICH) {
      snapshot = await fetchSnapshot(forgeToken, forgeLeadId);
      if (snapshot) {
        console.log(`[INTEGRATION] forge/survey: Using snapshot for lead ${opLeadId}`);
      }
    }

    // === Generate survey using AI ===
    const surveyResult = await generateSurvey(forgeLead, link, snapshot);
    if (!surveyResult.ok) {
      return res.status(500).json({ 
        ok: false, 
        error: 'Survey generation failed',
        details: surveyResult.error
      });
    }

    // === Save report ===
    const reportId = randomUUID();
    const ttlExpiresAt = new Date(Date.now() + REPORT_TTL_HOURS * 60 * 60 * 1000).toISOString();
    
    await query(
      `INSERT INTO reports 
       (id, lead_id, version_number, provider, model, prompt_version, output, 
        source, external_lead_id, external_system, suggested_message, forge_snapshot, ttl_expires_at, created_at)
       VALUES ($1, $2, 1, $3, $4, 'forge-survey-v1', $5, 
               'forge', $6, 'forge', $7, $8, $9, NOW())`,
      [
        reportId, 
        opLeadId, 
        surveyResult.provider,
        surveyResult.model,
        JSON.stringify(surveyResult.output),
        forgeLeadId,
        surveyResult.suggestedMessage,
        JSON.stringify(forgeLead),
        ttlExpiresAt
      ]
    );

    console.log(`[INTEGRATION] forge/survey: Generated report ${reportId} for lead ${opLeadId} from forge:${forgeLeadId}`);

    return res.status(201).json({
      ok: true,
      cached: false,
      report: {
        id: reportId,
        output: surveyResult.output,
        suggested_message: surveyResult.suggestedMessage,
        created_at: new Date().toISOString(),
        ttl_expires_at: ttlExpiresAt,
        usage: surveyResult.usage,
      },
    });

  } catch (error: any) {
    console.error('[INTEGRATION] forge/survey error:', error);
    return res.status(500).json({ ok: false, error: 'Internal error', details: error.message });
  }
}

/**
 * Get forge integration token via the forge-token endpoint logic
 */
async function getForgeToken(auth: { id: string; role: string }): Promise<string | null> {
  const secret = process.env.INTEGRATION_SHARED_SECRET;
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  if (!secret) {
    console.error('[INTEGRATION] forge/survey: INTEGRATION_SHARED_SECRET not configured');
    return null;
  }

  const now = Math.floor(Date.now() / 1000);
  const assertion = {
    issuer: 'op-target',
    sub: auth.id,
    role: auth.role,
    iat: now,
    exp: now + 300,
    nonce: randomUUID(),
  };

  const canonical = {
    exp: assertion.exp,
    iat: assertion.iat,
    issuer: assertion.issuer,
    nonce: assertion.nonce,
    role: assertion.role,
    sub: assertion.sub,
  };
  const sig = createHmac('sha256', secret).update(JSON.stringify(canonical)).digest('hex');

  try {
    const response = await fetch(`${forgeBaseUrl}/v1/api/integration/exchange.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...assertion, sig }),
    });

    const data = await response.json();
    if (!data.ok || !data.token) {
      console.error('[INTEGRATION] forge/survey: Token exchange failed:', data.error);
      return null;
    }

    return data.token;
  } catch (error) {
    console.error('[INTEGRATION] forge/survey: Token exchange error:', error);
    return null;
  }
}

/**
 * Fetch lead data from forge using integration token
 */
async function fetchForgeLead(token: string, leadId: string): Promise<ForgeLead | null> {
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  try {
    const response = await fetch(`${forgeBaseUrl}/v1/api/integration/lead.php?id=${leadId}`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });

    const data = await response.json();
    if (!data.ok || !data.lead) {
      console.error('[INTEGRATION] forge/survey: Fetch lead failed:', data.error);
      return null;
    }

    return data.lead;
  } catch (error) {
    console.error('[INTEGRATION] forge/survey: Fetch lead error:', error);
    return null;
  }
}

/**
 * Fetch snapshot from forge using integration token
 * Phase 6: Worker enrichment snapshot
 */
async function fetchSnapshot(token: string, forgeLeadId: string): Promise<any | null> {
  const forgeBaseUrl = process.env.FORGE_API_BASE_URL || 'http://localhost:8080';

  try {
    const response = await fetch(
      `${forgeBaseUrl}/v1/api/integration/leads/snapshot.php?forgeLeadId=${forgeLeadId}`,
      { headers: { 'Authorization': `Bearer ${token}` } }
    );

    if (!response.ok) {
      if (response.status === 404) {
        return null; // No snapshot yet
      }
      console.error('[INTEGRATION] forge/survey: Fetch snapshot failed:', response.status);
      return null;
    }

    const data = await response.json();
    return data.ok ? data.snapshot : null;
  } catch (error) {
    console.error('[INTEGRATION] forge/survey: Fetch snapshot error:', error);
    return null;
  }
}

/**
 * Generate survey report using AI service
 * Phase 6: Now accepts optional snapshot for enriched data
 */
async function generateSurvey(forgeLead: ForgeLead, linkData: any, snapshot?: any): Promise<{
  ok: boolean;
  output?: any;
  suggestedMessage?: string;
  provider?: string;
  model?: string;
  usage?: any;
  error?: string;
}> {
  try {
    // Get AI settings
    const settingsResult = await query('SELECT value FROM settings WHERE key = $1', ['ai_settings']);
    const settings = settingsResult.rows[0]?.value || {};

    const activeProvider = settings.activeProvider || 'gemini';
    const isGemini = activeProvider === 'gemini';
    const apiKey = isGemini ? settings.geminiApiKey : settings.openaiApiKey;
    const activeModel = isGemini ? (settings.geminiModel || 'gemini-2.0-flash') : (settings.openaiModel || 'gpt-4o');
    const activeTemp = settings.temperature ?? 0.7;

    if (!apiKey) {
      return { ok: false, error: 'AI API key not configured' };
    }

    // Build prompt (use snapshot if available for richer data)
    const prompt = buildSurveyPrompt(forgeLead, linkData, snapshot);
    const sysInstruction = `أنت خبير تسويق سعودي متخصص في تحليل العملاء المحتملين وإنشاء رسائل تواصل احترافية.
مهمتك: تحليل بيانات العميل المحتمل وإنشاء:
1. تقرير تحليلي مختصر
2. رسالة واتساب مقترحة للتواصل الأول

الرد يجب أن يكون JSON صالح فقط.`;

    const startTime = Date.now();
    let text = '';
    let inputTokens = 0;
    let outputTokens = 0;

    if (isGemini) {
      const geminiUrl = `https://generativelanguage.googleapis.com/v1beta/models/${activeModel}:generateContent?key=${apiKey}`;
      
      const response = await fetch(geminiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          contents: [{ parts: [{ text: prompt }] }],
          generationConfig: { temperature: activeTemp, responseMimeType: "application/json" },
          systemInstruction: { parts: [{ text: sysInstruction }] }
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        return { ok: false, error: `Gemini API Error: ${errorData.error?.message}` };
      }

      const data = await response.json();
      text = data.candidates?.[0]?.content?.parts?.[0]?.text || '{}';
      inputTokens = data.usageMetadata?.promptTokenCount || 0;
      outputTokens = data.usageMetadata?.candidatesTokenCount || 0;
    } else {
      const response = await fetch('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${apiKey}`
        },
        body: JSON.stringify({
          model: activeModel,
          messages: [
            { role: 'system', content: sysInstruction },
            { role: 'user', content: prompt }
          ],
          response_format: { type: "json_object" },
          temperature: activeTemp
        })
      });

      if (!response.ok) {
        const errorData = await response.json();
        return { ok: false, error: `OpenAI API Error: ${errorData.error?.message}` };
      }

      const data = await response.json();
      text = data.choices[0].message.content;
      inputTokens = data.usage?.prompt_tokens || 0;
      outputTokens = data.usage?.completion_tokens || 0;
    }

    const latencyMs = Date.now() - startTime;
    const cost = isGemini 
      ? (inputTokens * 0.00000015) + (outputTokens * 0.00000060)
      : (inputTokens * 0.000005) + (outputTokens * 0.000015);

    // Log usage
    await query(
      'INSERT INTO usage_logs (id, model, provider, latency_ms, input_tokens, output_tokens, cost, status, created_at) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())',
      [randomUUID(), activeModel, activeProvider, latencyMs, inputTokens, outputTokens, cost, 'success']
    );

    // Parse response
    let parsed;
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw: text };
    }

    return {
      ok: true,
      output: parsed.analysis || parsed,
      suggestedMessage: parsed.suggested_message || parsed.suggestedMessage || '',
      provider: activeProvider,
      model: activeModel,
      usage: { latencyMs, inputTokens, outputTokens, cost }
    };

  } catch (error: any) {
    console.error('[INTEGRATION] forge/survey: AI generation error:', error);
    return { ok: false, error: error.message };
  }
}

/**
 * Build the survey prompt from forge lead data
 * Phase 6: Now uses snapshot for enriched data when available
 * Phase 7: Uses ai_pack exclusively for evidence-driven analysis (no external browsing)
 */
function buildSurveyPrompt(lead: ForgeLead, linkData: any, snapshot?: any): string {
  // Build enriched data section from snapshot if available
  let enrichedSection = '';
  let evidenceSection = '';
  
  if (snapshot) {
    const parts: string[] = [];
    
    // Phase 7: Use ai_pack if available (evidence-driven)
    if (snapshot.ai_pack) {
      const aiPack = snapshot.ai_pack;
      
      // Official site
      if (aiPack.official_site) {
        parts.push(`- **الموقع الرسمي المحتمل**: ${aiPack.official_site.url} (ثقة: ${aiPack.official_site.confidence})`);
      }
      
      // Social links
      if (aiPack.social_links && Object.keys(aiPack.social_links).length > 0) {
        for (const [platform, data] of Object.entries(aiPack.social_links as Record<string, any>)) {
          parts.push(`- **${platform}**: ${data.url} (@${data.handle}, ثقة: ${data.confidence})`);
        }
      }
      
      // Directories
      if (aiPack.directories?.length > 0) {
        parts.push(`- **الأدلة التجارية**: ${aiPack.directories.map((d: any) => d.title || d.url).join(', ')}`);
      }
      
      // Evidence URLs (for AI context)
      if (aiPack.evidence?.length > 0) {
        const evidenceParts = aiPack.evidence.slice(0, 5).map((e: any) => 
          `  - [${e.rank}] ${e.title}: ${e.snippet?.slice(0, 100) || 'بدون وصف'}...`
        );
        evidenceSection = `\n## أدلة من بحث Google\n\n${evidenceParts.join('\n')}\n`;
      }
      
      // Confidence levels
      if (aiPack.confidence) {
        const confParts = Object.entries(aiPack.confidence)
          .map(([k, v]) => `${k}: ${v}`)
          .join(', ');
        parts.push(`- **مستوى الثقة**: ${confParts}`);
      }
      
      // Missing data warnings
      if (aiPack.missing_data?.length > 0) {
        parts.push(`- **تحذير**: بيانات ناقصة: ${aiPack.missing_data.join(', ')}`);
      }
    }
    
    // Maps data (fallback if no ai_pack)
    if (snapshot.maps && !snapshot.ai_pack) {
      const m = snapshot.maps;
      if (m.name) parts.push(`- **اسم المنشأة (Maps)**: ${m.name}`);
      if (m.category) parts.push(`- **التصنيف (Maps)**: ${m.category}`);
      if (m.address) parts.push(`- **العنوان**: ${m.address}`);
      if (m.rating) parts.push(`- **التقييم**: ${m.rating} (${m.reviews_count || 0} تقييم)`);
      if (m.phones?.length) parts.push(`- **أرقام الهاتف**: ${m.phones.join(', ')}`);
      if (m.website) parts.push(`- **الموقع الإلكتروني**: ${m.website}`);
      if (m.opening_hours) parts.push(`- **ساعات العمل**: ${m.opening_hours}`);
    }
    
    // Website data (fallback if no ai_pack)
    if (snapshot.website_data && !snapshot.ai_pack) {
      const w = snapshot.website_data;
      if (w.title) parts.push(`- **عنوان الموقع**: ${w.title}`);
      if (w.description) parts.push(`- **وصف الموقع**: ${w.description}`);
      if (w.emails?.length) parts.push(`- **البريد الإلكتروني**: ${w.emails.join(', ')}`);
      if (w.tech_hints?.length) parts.push(`- **التقنيات المستخدمة**: ${w.tech_hints.join(', ')}`);
      if (w.social_links && Object.keys(w.social_links).length > 0) {
        parts.push(`- **حسابات التواصل**: ${Object.keys(w.social_links).join(', ')}`);
      }
    }
    
    // Instagram
    if (snapshot.instagram?.exists) {
      parts.push(`- **انستقرام**: موجود (${snapshot.instagram.url})`);
    }
    
    if (parts.length > 0) {
      enrichedSection = `\n## بيانات مُثرية من Worker\n\n${parts.join('\n')}\n`;
    }
  }

  // Build missing data section
  let missingSection = '';
  const missing: string[] = [];
  if (!lead.name && !snapshot?.maps?.name) missing.push('اسم المنشأة');
  if (!lead.phone && !snapshot?.phones?.length) missing.push('رقم الهاتف');
  if (!snapshot?.maps?.website && !snapshot?.website_data) missing.push('الموقع الإلكتروني');
  if (!snapshot?.maps?.rating) missing.push('التقييم على Maps');
  
  if (missing.length > 0) {
    missingSection = `\n## بيانات غير متوفرة\n\n${missing.map(m => `- ${m}`).join('\n')}\n\n**ملاحظة**: أي معلومة غير متوفرة يجب ذكرها كـ "غير مؤكد" مع اقتراح طريقة للتحقق.\n`;
  }

  return `
## بيانات العميل المحتمل (الأساسية)

- **الاسم**: ${lead.name || 'غير متوفر'}
- **الهاتف**: ${lead.phone || 'غير متوفر'}
- **المدينة**: ${lead.city || 'غير متوفر'}
- **التصنيف**: ${lead.category || 'غير محدد'}
- **تاريخ الإضافة**: ${lead.created_at || 'غير متوفر'}
${enrichedSection}${evidenceSection}${missingSection}
## المطلوب

أنشئ JSON يحتوي على:

1. **analysis**: تحليل مختصر يشمل:
   - summary: ملخص عن العميل (2-3 جمل)
   - potential: تقييم الإمكانية (high/medium/low)
   - recommended_approach: نهج التواصل المقترح
   - key_points: نقاط مهمة للتواصل (array)
   - confidence: مستوى الثقة في التحليل (high/medium/low)
   - missing_data: قائمة البيانات الناقصة وكيفية التحقق منها (array)

2. **suggested_message**: رسالة واتساب مقترحة للتواصل الأول:
   - احترافية ومختصرة (أقل من 200 حرف)
   - تذكر اسم الشركة إن وجد
   - تقدم قيمة واضحة
   - تنتهي بسؤال مفتوح
   - بصيغة سعودية رسمية

3. **objections**: اعتراضات محتملة وردود مقترحة (array of {objection, response})

## مثال الإخراج المطلوب:

{
  "analysis": {
    "summary": "عميل محتمل في قطاع المطاعم بالرياض...",
    "potential": "high",
    "recommended_approach": "التواصل المباشر عبر واتساب",
    "key_points": ["نقطة 1", "نقطة 2"],
    "confidence": "medium",
    "missing_data": [{"field": "الميزانية", "how_to_verify": "السؤال المباشر"}]
  },
  "suggested_message": "السلام عليكم، أنا من شركة...",
  "objections": [{"objection": "السعر مرتفع", "response": "نقدم خطط مرنة..."}]
}
`;
}
