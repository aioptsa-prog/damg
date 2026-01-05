
import React, { useState } from 'react';
import { Sparkles, Building2, FileText, MapPin, Globe, AlertCircle, Loader2, Zap, ChevronDown, MessageSquare } from 'lucide-react';
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

          <div className="space-y-6 pt-6 border-t border-slate-50">
            <h4 className="font-black text-slate-800 text-sm">روابط البحث الفعلي (Enrichment Hub)</h4>
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
