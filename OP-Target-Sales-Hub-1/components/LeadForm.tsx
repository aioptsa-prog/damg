
import React, { useState } from 'react';
import { Sparkles, Building2, FileText, MapPin, Globe, AlertCircle, Loader2, Zap, ChevronDown, MessageSquare, Search, CheckCircle2, ExternalLink } from 'lucide-react';
import { aiService } from '../services/aiService';
import { sectorService } from '../services/sectorService';
import { enrichmentService } from '../services/enrichmentService';
import { authService } from '../services/authService';
import { db } from '../services/db';
import { SECTOR_TEMPLATES } from '../constants';
import { LeadStatus, SectorSlug, Lead, Report, Task } from '../types';
import { LoadingOverlay, ErrorState } from './UI';
import { normalizeEvidence, NormalizedEvidencePack } from '../domain/normalizeReport';
import { asArray } from '../utils/safeData';
import { logger } from '../utils/logger';

interface LeadFormProps {
  onSuccess: (lead: Lead, report: Report) => void;
}

const LeadForm: React.FC<LeadFormProps> = ({ onSuccess }) => {
  const [loading, setLoading] = useState(false);
  const [enriching, setEnriching] = useState(false);
  const [researching, setResearching] = useState(false);
  const [researchResult, setResearchResult] = useState<any>(null);
  const [evidence, setEvidence] = useState<NormalizedEvidencePack | null>(null);
  const [error, setError] = useState<{title: string, msg: string} | null>(null);
  const [attemptedSubmit, setAttemptedSubmit] = useState(false);
  const user = authService.getCurrentUser();

  const [formData, setFormData] = useState({
    companyName: '',
    activity: '',
    city: 'الرياض',
    website: '',
    instagram: '',
    maps: '',
    notes: '',
    sector: 'other' as SectorSlug
  });

  const errors = {
    companyName: attemptedSubmit && !formData.companyName.trim(),
    activity: attemptedSubmit && !formData.activity.trim(),
  };

  const handleTextChange = (field: 'companyName' | 'activity', val: string) => {
    const updated = { ...formData, [field]: val };
    const detection = sectorService.detectSector(updated.companyName + " " + updated.activity);
    setFormData({ ...updated, sector: detection.slug });
  };

  const runResearch = async () => {
    if (!formData.website && !formData.instagram && !formData.maps) return;
    setEnriching(true);
    try {
      const data = await enrichmentService.enrichLead(formData.website, formData.instagram, formData.maps);
      setEvidence(normalizeEvidence(data));
    } catch (e) {
      console.error('Enrichment failed', e);
    } finally {
      setEnriching(false);
    }
  };

  // البحث التلقائي عن الشركة
  const runAutoResearch = async () => {
    if (!formData.companyName.trim()) return;
    
    setResearching(true);
    setResearchResult(null);
    
    try {
      logger.debug('[LeadForm] Starting auto research for:', formData.companyName);
      
      const response = await fetch('/api/research', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          companyName: formData.companyName,
          city: formData.city,
          activity: formData.activity
        })
      });

      if (!response.ok) {
        throw new Error('فشل البحث');
      }

      const result = await response.json();
      logger.debug('[LeadForm] Research result:', result);
      setResearchResult(result);

      // تحديث الحقول تلقائياً بالنتائج
      const updates: any = {};
      
      if (result.discovered?.website?.url && !formData.website) {
        updates.website = result.discovered.website.url;
      }
      
      if (result.discovered?.socialMedia?.length > 0) {
        const instagram = result.discovered.socialMedia.find((s: any) => s.type === 'instagram');
        if (instagram && !formData.instagram) {
          updates.instagram = instagram.url;
        }
      }
      
      if (result.discovered?.maps?.url && !formData.maps) {
        updates.maps = result.discovered.maps.url;
      }

      if (Object.keys(updates).length > 0) {
        setFormData(prev => ({ ...prev, ...updates }));
      }

      // إذا وجدنا بيانات، نحولها لـ evidence
      if (result.extracted?.website || result.extracted?.maps) {
        const evidenceBundle = convertResearchToEvidence(result);
        setEvidence(normalizeEvidence(evidenceBundle));
      }

    } catch (e: any) {
      logger.error('[LeadForm] Auto research failed:', e);
    } finally {
      setResearching(false);
    }
  };

  // تحويل نتائج البحث لصيغة Evidence
  const convertResearchToEvidence = (result: any): any => {
    const sources: any[] = [];
    
    if (result.extracted?.website) {
      sources.push({
        type: 'website',
        url: result.discovered?.website?.url,
        status: 'success',
        parseOk: true,
        parsed: result.extracted.website,
        keyFindings: [
          result.extracted.website.title && `عنوان: ${result.extracted.website.title}`,
          result.extracted.website.phones?.length > 0 && `هاتف: ${result.extracted.website.phones.join(', ')}`,
          result.extracted.website.emails?.length > 0 && `بريد: ${result.extracted.website.emails.join(', ')}`,
        ].filter(Boolean)
      });
    }
    
    if (result.extracted?.maps) {
      sources.push({
        type: 'google_maps',
        url: result.discovered?.maps?.url,
        status: 'success',
        parseOk: true,
        keyFindings: [
          `الاسم: ${result.extracted.maps.name}`,
          `العنوان: ${result.extracted.maps.address}`,
          result.extracted.maps.phone && `الهاتف: ${result.extracted.maps.phone}`,
          result.extracted.maps.rating && `التقييم: ${result.extracted.maps.rating}`,
        ].filter(Boolean)
      });
    }

    return {
      sources,
      extracted: result.extracted,
      diagnostics: {
        totalDurationMs: result.summary?.duration || 0,
        errors: result.summary?.errors || [],
        warnings: []
      },
      qualityScore: Math.round((result.summary?.totalConfidence || 0) * 100),
      _raw_bundle: {
        sources,
        extracted: result.extracted,
        diagnostics: { totalDurationMs: result.summary?.duration || 0, errors: [], warnings: [] },
        qualityScore: Math.round((result.summary?.totalConfidence || 0) * 100)
      }
    };
  };

  const startAnalysis = async () => {
    setAttemptedSubmit(true);
    if (!formData.companyName.trim() || !formData.activity.trim() || !user) return;

    setError(null);
    setLoading(true);

    try {
      // Step 1: Ensure research is done - ALWAYS call enrichment if we have links
      let currentEvidence = evidence;
      let enrichmentFailed = false;
      let enrichmentError = '';
      
      if (!currentEvidence && (formData.website || formData.instagram || formData.maps)) {
        logger.debug('[LeadForm] Running enrichment for:', { website: formData.website, instagram: formData.instagram });
        try {
          const rawEvidence = await enrichmentService.enrichLead(formData.website, formData.instagram, formData.maps);
          currentEvidence = rawEvidence;
          
          // Check if enrichment actually collected useful data
          const bundle = (rawEvidence as any)?._raw_bundle;
          const qualityScore = bundle?.qualityScore || 0;
          const hasSuccessfulSource = bundle?.sources?.some((s: any) => s.status === 'success');
          
          if (!hasSuccessfulSource || qualityScore < 10) {
            enrichmentFailed = true;
            const errors = bundle?.diagnostics?.errors || [];
            const warnings = bundle?.diagnostics?.warnings || [];
            enrichmentError = errors[0] || warnings[0] || 'لم نتمكن من الوصول للموقع';
          }
          
          logger.debug('[LeadForm] Enrichment result:', { 
            hasRawBundle: !!bundle,
            sourcesCount: bundle?.sources?.length || 0,
            qualityScore,
            hasSuccessfulSource
          });
        } catch (e: any) {
          enrichmentFailed = true;
          enrichmentError = e.message || 'فشل في جمع الأدلة';
        }
      }

      // P0-C: Stop AI if no evidence was collected
      if (enrichmentFailed && (formData.website || formData.instagram)) {
        setError({
          title: 'فشل جمع الأدلة',
          msg: `${enrichmentError}\n\nالأسباب المحتملة:\n• الموقع محمي بـ robots.txt أو Cloudflare\n• رابط غير صالح أو الموقع غير متاح\n• انتهت مهلة الاتصال\n\nيمكنك المتابعة بدون أدلة أو تصحيح الرابط.`
        });
        setLoading(false);
        return;
      }

      // Step 2: Generate Report with AI - pass evidence with _raw_bundle
      logger.debug('[LeadForm] Passing to AI:', { hasEvidence: !!currentEvidence, hasRawBundle: !!(currentEvidence as any)?._raw_bundle });
      const { data: reportOutput, usage } = await aiService.generateReport(formData, currentEvidence as any);

      const leadId = Math.random().toString(36).substr(2, 9);
      const newLead: Lead = {
        ...formData,
        id: leadId,
        status: LeadStatus.NEW,
        ownerUserId: user.id,
        teamId: user.teamId,
        createdAt: new Date().toISOString(),
        createdBy: user.name,
        customFields: [],
        attachments: [],
        sector: reportOutput.sector,
        enrichment_signals: currentEvidence || undefined
      };

      await db.saveLead(newLead);
      
      const newReport: Report = { 
        id: Math.random().toString(36).substr(2, 9), 
        leadId: leadId, 
        versionNumber: 1, 
        provider: 'gemini',
        model: 'gemini-3-flash-preview',
        promptVersion: '3.0.0 (Research Pack)',
        output: reportOutput, 
        usage: {
          inputTokens: usage.inputTokens,
          outputTokens: usage.outputTokens,
          cost: usage.cost,
          latencyMs: usage.latencyMs
        },
        createdAt: new Date().toISOString() 
      };
      await db.saveReport(newReport, user.id);

      // Defensive check for follow_up_plan array
      const followUpPlan = Array.isArray(reportOutput.follow_up_plan) ? reportOutput.follow_up_plan : [];
      if (followUpPlan.length > 0) {
        try {
          const tasks: Task[] = followUpPlan.map((step: any) => ({
            id: Math.random().toString(36).substr(2, 9),
            leadId,
            assignedToUserId: user.id,
            dayNumber: step.day || 1,
            channel: step.channel || '',
            goal: step.goal || '',
            action: step.action || '',
            status: 'OPEN',
            dueDate: new Date(Date.now() + (step.day || 1) * 86400000).toISOString()
          }));
          await db.saveTasks(tasks);
        } catch (taskErr) {
          console.warn('[LeadForm] Failed to save tasks, continuing:', taskErr);
        }
      }

      onSuccess(newLead, newReport);
    } catch (err: any) {
      setError({
        title: "فشل في تحليل البيانات",
        msg: "تعذر الاتصال بخادم الذكاء الاصطناعي أو فشل البحث الفعلي. يرجى مراجعة الإنترنت أو الـ API Key."
      });
    } finally {
      setLoading(false);
    }
  };

  if (loading) return <LoadingOverlay message="جاري إجراء البحث الفعلي عن العميل وتوليد التقرير الاستراتيجي..." />;
  if (error) return <ErrorState title={error.title} message={error.msg} onRetry={startAnalysis} />;

  return (
    <div className="max-w-4xl mx-auto pb-12 animate-in fade-in duration-500">
      <div className="bg-white rounded-[2.5rem] shadow-xl border border-slate-100 overflow-hidden">
        <div className="bg-slate-900 p-10 flex flex-row-reverse items-center gap-6">
          <div className="bg-primary p-4 rounded-3xl shadow-xl shadow-primary/20 text-white"><Sparkles size={32} /></div>
          <div className="text-right">
            <h2 className="text-2xl font-black text-white">تحليل عميل استراتيجي (بحث فعلي)</h2>
            <p className="text-slate-400 font-medium">يقوم النظام بمسح الموقع والسوشيال لبناء تقرير مبني على أدلة حقيقية</p>
          </div>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); startAnalysis(); }} className="p-12 space-y-8 text-right">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div className="space-y-3">
              <label className={`text-xs font-black uppercase tracking-widest flex flex-row-reverse items-center gap-2 ${errors.companyName ? 'text-red-600' : 'text-slate-500'}`}>
                <Building2 size={14} /> اسم الشركة <span className="text-red-500">*</span>
              </label>
              <input 
                className={`w-full px-7 py-5 bg-slate-50 border-2 rounded-[1.5rem] text-sm font-bold outline-none text-right transition-all ${errors.companyName ? 'border-red-500 bg-red-50' : 'border-transparent focus:border-primary'}`} 
                placeholder="مثال: شركة سماء العقارية" 
                value={formData.companyName} 
                onChange={e => handleTextChange('companyName', e.target.value)} 
              />
            </div>

            <div className="space-y-3">
              <label className={`text-xs font-black uppercase tracking-widest flex flex-row-reverse items-center gap-2 ${errors.activity ? 'text-red-600' : 'text-slate-500'}`}>
                <FileText size={14} /> النشاط التفصيلي <span className="text-red-500">*</span>
              </label>
              <input 
                className={`w-full px-7 py-5 bg-slate-50 border-2 rounded-[1.5rem] text-sm font-bold outline-none text-right transition-all ${errors.activity ? 'border-red-500 bg-red-50' : 'border-transparent focus:border-primary'}`} 
                placeholder="مثال: تطوير العقارات السكنية والتجارية" 
                value={formData.activity} 
                onChange={e => handleTextChange('activity', e.target.value)} 
              />
            </div>

            <div className="space-y-3">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest flex flex-row-reverse items-center gap-2">
                <Zap size={14} /> القطاع المستهدف
              </label>
              <div className="relative">
                <select 
                  className="w-full px-7 py-5 bg-primary/5 border-2 border-primary/20 rounded-[1.5rem] text-sm font-black text-primary appearance-none text-right outline-none cursor-pointer pr-14"
                  value={formData.sector}
                  onChange={e => setFormData({...formData, sector: e.target.value as SectorSlug})}
                >
                  {SECTOR_TEMPLATES.map(s => (
                    <option key={s.slug} value={s.slug}>{s.name}</option>
                  ))}
                  <option value="other">قطاع عام / آخر</option>
                </select>
                <ChevronDown size={20} className="absolute right-6 top-1/2 -translate-y-1/2 text-primary pointer-events-none" />
              </div>
            </div>

            <div className="space-y-3">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest flex flex-row-reverse items-center gap-2"><MapPin size={14} /> المدينة</label>
              <input className="w-full px-7 py-5 bg-slate-50 border-none rounded-[1.5rem] text-sm font-bold text-right" value={formData.city} onChange={e => setFormData({...formData, city: e.target.value})} />
            </div>
          </div>

          {/* زر البحث التلقائي */}
          <div className="pt-6 border-t border-slate-50">
            <button
              type="button"
              onClick={runAutoResearch}
              disabled={!formData.companyName.trim() || researching}
              className="w-full bg-gradient-to-r from-indigo-500 to-purple-500 hover:from-indigo-600 hover:to-purple-600 text-white font-black py-5 rounded-[1.5rem] shadow-xl flex items-center justify-center gap-4 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {researching ? (
                <>
                  <Loader2 size={24} className="animate-spin" />
                  جاري البحث التلقائي عن الشركة...
                </>
              ) : (
                <>
                  <Search size={24} />
                  بحث تلقائي عن "{formData.companyName || 'الشركة'}"
                </>
              )}
            </button>
            <p className="text-[10px] text-slate-400 text-center mt-2">
              يبحث النظام تلقائياً عن الموقع الإلكتروني وحسابات التواصل ومعلومات الخريطة
            </p>
          </div>

          {/* نتائج البحث التلقائي */}
          {researchResult && (
            <div className="p-6 bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-[2rem] animate-in fade-in slide-in-from-top-2">
              <div className="flex flex-row-reverse items-center justify-between mb-4">
                <h5 className="text-sm font-black text-indigo-800 flex items-center gap-2">
                  <CheckCircle2 size={18} className="text-green-500" />
                  نتائج البحث التلقائي
                </h5>
                <span className="text-xs font-bold text-indigo-600 bg-indigo-100 px-3 py-1 rounded-full">
                  ثقة: {Math.round((researchResult.summary?.totalConfidence || 0) * 100)}%
                </span>
              </div>
              
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {/* الموقع الإلكتروني */}
                <div className="bg-white rounded-xl p-4 border border-indigo-100">
                  <div className="flex items-center justify-end gap-2 text-indigo-600 font-black text-xs mb-2">
                    <Globe size={14} /> الموقع الإلكتروني
                  </div>
                  {researchResult.discovered?.website ? (
                    <a href={researchResult.discovered.website.url} target="_blank" rel="noopener" className="text-xs text-blue-600 hover:underline break-all flex items-center gap-1">
                      <ExternalLink size={10} />
                      {researchResult.discovered.website.url}
                    </a>
                  ) : (
                    <span className="text-xs text-slate-400">لم يُعثر عليه</span>
                  )}
                </div>

                {/* Google Maps */}
                <div className="bg-white rounded-xl p-4 border border-indigo-100">
                  <div className="flex items-center justify-end gap-2 text-indigo-600 font-black text-xs mb-2">
                    <MapPin size={14} /> الموقع الجغرافي
                  </div>
                  {researchResult.extracted?.maps ? (
                    <div className="text-xs text-slate-700">
                      <p className="font-bold">{researchResult.extracted.maps.name}</p>
                      <p className="text-slate-500 mt-1">{researchResult.extracted.maps.address}</p>
                      {researchResult.extracted.maps.rating && (
                        <p className="text-amber-500 mt-1">⭐ {researchResult.extracted.maps.rating}</p>
                      )}
                    </div>
                  ) : (
                    <span className="text-xs text-slate-400">لم يُعثر عليه</span>
                  )}
                </div>

                {/* السوشيال ميديا */}
                <div className="bg-white rounded-xl p-4 border border-indigo-100">
                  <div className="flex items-center justify-end gap-2 text-indigo-600 font-black text-xs mb-2">
                    <MessageSquare size={14} /> التواصل الاجتماعي
                  </div>
                  {researchResult.discovered?.socialMedia?.length > 0 ? (
                    <div className="space-y-1">
                      {researchResult.discovered.socialMedia.slice(0, 3).map((s: any, i: number) => (
                        <a key={i} href={s.url} target="_blank" rel="noopener" className="block text-xs text-blue-600 hover:underline">
                          {s.type}: {s.url.split('/').pop()}
                        </a>
                      ))}
                    </div>
                  ) : (
                    <span className="text-xs text-slate-400">لم يُعثر عليه</span>
                  )}
                </div>
              </div>

              {researchResult.summary?.sourcesFound?.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2 justify-end">
                  {researchResult.summary.sourcesFound.map((s: string, i: number) => (
                    <span key={i} className="px-2 py-1 bg-green-100 text-green-700 rounded text-[9px] font-bold uppercase">
                      ✓ {s}
                    </span>
                  ))}
                </div>
              )}
            </div>
          )}

          <div className="space-y-6 pt-6 border-t border-slate-50">
            <h4 className="font-black text-slate-800 text-sm">روابط البحث الفعلي (Enrichment Hub) - أو أدخلها يدوياً</h4>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center justify-end gap-1">الموقع الإلكتروني <Globe size={10} /></label>
                <input 
                  className="w-full px-6 py-4 bg-slate-50 rounded-2xl text-xs font-bold text-left outline-none focus:ring-2 focus:ring-primary/20" 
                  dir="ltr" placeholder="https://domain.com" 
                  value={formData.website} 
                  onChange={e => setFormData({...formData, website: e.target.value})}
                  onBlur={runResearch}
                />
              </div>
              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center justify-end gap-1">انستجرام <MessageSquare size={10} /></label>
                <input 
                  className="w-full px-6 py-4 bg-slate-50 rounded-2xl text-xs font-bold text-left outline-none focus:ring-2 focus:ring-primary/20" 
                  dir="ltr" placeholder="@handle" 
                  value={formData.instagram} 
                  onChange={e => setFormData({...formData, instagram: e.target.value})}
                  onBlur={runResearch}
                />
              </div>
              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center justify-end gap-1">خرائط جوجل <MapPin size={10} /></label>
                <input 
                  className="w-full px-6 py-4 bg-slate-50 rounded-2xl text-xs font-bold text-left outline-none focus:ring-2 focus:ring-primary/20" 
                  dir="ltr" placeholder="Maps URL" 
                  value={formData.maps} 
                  onChange={e => setFormData({...formData, maps: e.target.value})}
                />
              </div>
            </div>
            
            {enriching && (
              <div className="p-4 bg-primary/5 border border-primary/10 rounded-2xl flex items-center justify-center gap-3 animate-pulse">
                <Loader2 size={16} className="animate-spin text-primary" />
                <span className="text-xs font-black text-primary">جاري البحث في المصادر الحقيقية وجمع الأدلة...</span>
              </div>
            )}

            {evidence && (
              <div className="p-6 bg-slate-50 border border-slate-100 rounded-[2rem] animate-in fade-in slide-in-from-top-2">
                <div className="flex flex-row-reverse items-center justify-between mb-4">
                  <h5 className="text-xs font-black text-slate-800">حزمة الأدلة المجمّعة (Evidence Summary)</h5>
                  <div className="flex gap-2">
                    {asArray(evidence.sources_used).map(s => <span key={s} className="px-3 py-1 bg-white border border-slate-200 rounded-lg text-[9px] font-black uppercase">{s}</span>)}
                  </div>
                </div>
                <div className="space-y-3">
                  {asArray(evidence.key_findings).map((f, i) => (
                    <div key={i} className="flex flex-row-reverse items-start gap-3 bg-white p-3 rounded-xl border border-slate-200/50">
                      <Zap size={14} className="text-amber-500 shrink-0 mt-0.5" />
                      <p className="text-[11px] text-slate-600 font-bold">{f.finding}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>

          <div className="space-y-3 pt-6 border-t border-slate-50">
            <label className="text-xs font-black text-slate-500 uppercase tracking-widest">ملاحظات بيعية إضافية</label>
            <textarea className="w-full px-7 py-5 bg-slate-50 border-none rounded-[1.5rem] text-sm font-bold min-h-[120px] text-right" placeholder="أدخل أي ملاحظات من لقاءات سابقة أو أهداف محددة للمندوب..." value={formData.notes} onChange={e => setFormData({...formData, notes: e.target.value})} />
          </div>

          <button type="submit" className="w-full bg-primary hover:bg-primary/90 text-white font-black py-6 rounded-[2rem] shadow-2xl flex items-center justify-center gap-4 transition-all active:scale-95 group">
            <Sparkles size={28} className="group-hover:rotate-12 transition-transform" /> 
            توليد التقرير الاستراتيجي المخصص
          </button>
        </form>
      </div>
    </div>
  );
};

export default LeadForm;
