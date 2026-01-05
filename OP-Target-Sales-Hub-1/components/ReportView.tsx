
import React, { useState, useEffect, useMemo } from 'react';
import { 
  FileText, 
  MessageSquare, 
  Target, 
  PhoneCall, 
  Copy,
  AlertTriangle,
  Printer,
  Zap,
  FileSpreadsheet,
  Loader2,
  Square,
  CheckSquare,
  Eye,
  X,
  ShieldCheck,
  TrendingUp,
  CheckCircle2,
  ChevronDown,
  Globe,
  Instagram,
  Search,
  Check
} from 'lucide-react';
import { Lead, Report, UserRole, Task } from '../types';
import { SECTOR_TEMPLATES } from '../constants';
import WhatsAppModal from './WhatsAppModal';
import { db } from '../services/db';
import { exportService } from '../services/exportService';
import { normalizeReport, NormalizedReportModel } from '../domain/normalizeReport';
import { asArray } from '../utils/safeData';

interface ReportViewProps {
  lead: Lead;
  report: Report;
  userRole?: UserRole;
}

const ReportView: React.FC<ReportViewProps> = ({ lead, report: initialReport, userRole }) => {
  const [report, setReport] = useState<Report>(initialReport);
  const [allReports, setAllReports] = useState<Report[]>([]);
  const [showVersionMenu, setShowVersionMenu] = useState(false);
  const [isExporting, setIsExporting] = useState<'none' | 'sheets' | 'pdf'>('none');
  const [leadTasks, setLeadTasks] = useState<Task[]>([]);
  const [isWAModalOpen, setIsWAModalOpen] = useState(false);
  const [showJson, setShowJson] = useState(false);
  
  // CRITICAL: Normalize report data ONCE - guarantees all arrays are arrays
  // Handle case where report.output might be undefined, null, or malformed
  const model: NormalizedReportModel = useMemo(() => {
    const rawOutput = report?.output ?? {};
    return normalizeReport(rawOutput);
  }, [report?.output]);
  
  // Derived values from normalized model - ALL GUARANTEED SAFE
  const sectorName = SECTOR_TEMPLATES.find(s => s.slug === model.sector.primary)?.name || 'عام';

  useEffect(() => {
    setLeadTasks(db.getTasks(lead.id) || []);
    setAllReports(db.getReportsByLeadId(lead.id) || []);
    setReport(initialReport);
  }, [initialReport, lead.id]);

  const toggleTask = (day: number) => {
    const task = asArray(leadTasks).find(t => t.dayNumber === day);
    if (task) {
      const nextStatus = task.status === 'DONE' ? 'OPEN' : 'DONE';
      db.updateTaskStatus(task.id, nextStatus as any, 'u1');
      setLeadTasks(db.getTasks(lead.id));
    }
  };

  const copyToClipboard = (text: string, label: string) => {
    navigator.clipboard.writeText(text);
    alert(`تم نسخ ${label} بنجاح!`);
  };

  return (
    <div className="max-w-6xl mx-auto pb-20 animate-in fade-in duration-500 text-right rtl">
      <WhatsAppModal 
        isOpen={isWAModalOpen} 
        onClose={() => setIsWAModalOpen(false)} 
        onSend={() => {}} 
        initialPhone={lead.phone}
        message={model.talk_track.whatsapp_messages[0]?.text || ''}
        leadId={lead.id}
      />

      {showJson && (
        <div className="fixed inset-0 z-[200] bg-slate-900/80 backdrop-blur-md flex items-center justify-center p-6">
          <div className="bg-slate-900 border border-slate-700 w-full max-w-4xl rounded-3xl overflow-hidden flex flex-col max-h-[80vh]">
            <div className="p-6 border-b border-slate-700 flex justify-between items-center text-white">
              <button onClick={() => setShowJson(false)} className="p-2 hover:bg-white/10 rounded-full transition-colors"><X size={20}/></button>
              <h3 className="font-black">JSON Structure (v3.0)</h3>
            </div>
            <div className="p-6 overflow-auto bg-black/50 font-mono text-green-400 text-xs text-left" dir="ltr">
              <pre>{JSON.stringify(report, null, 2)}</pre>
            </div>
          </div>
        </div>
      )}

      {/* Action Bar */}
      <div className="flex flex-wrap items-center justify-between mb-8 gap-4 sticky top-0 bg-[#f8fafc]/90 backdrop-blur-md py-4 z-10 border-b border-slate-200 print:hidden">
        <div className="flex flex-row-reverse items-center gap-4">
          <div className="flex flex-col items-end">
            <div className="flex flex-row-reverse items-center gap-3">
              <h2 className="text-2xl font-black text-slate-900">{lead.companyName}</h2>
              <div className="relative">
                <button 
                  onClick={() => setShowVersionMenu(!showVersionMenu)}
                  className="bg-primary text-white px-3 py-1 rounded-xl text-[10px] font-black shadow-lg shadow-primary/20 flex items-center gap-2"
                >
                  V{report.versionNumber}
                  <ChevronDown size={12} className={showVersionMenu ? 'rotate-180' : ''} />
                </button>
                {showVersionMenu && allReports.length > 0 && (
                  <div className="absolute top-full mt-2 right-0 bg-white border border-slate-200 rounded-2xl shadow-2xl py-2 min-w-[200px] z-50">
                    {allReports.map(r => (
                      <button 
                        key={r.id}
                        onClick={() => { setReport(r); setShowVersionMenu(false); }}
                        className={`w-full px-4 py-3 text-right text-xs font-bold hover:bg-slate-50 flex items-center justify-between ${r.id === report.id ? 'text-primary bg-primary/5' : 'text-slate-600'}`}
                      >
                        V{r.versionNumber} • {new Date(r.createdAt).toLocaleDateString('ar-SA')}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
            <p className="text-xs text-slate-500 font-bold tracking-tight">القطاع: {sectorName} • {model.sector.confidence}% دقة</p>
          </div>
        </div>
        <div className="flex flex-row-reverse items-center gap-3">
          {userRole === UserRole.SUPER_ADMIN && (
            <button onClick={() => setShowJson(true)} className="bg-slate-100 text-slate-500 p-2.5 rounded-2xl hover:bg-slate-200 transition-all shadow-sm"><Eye size={20} /></button>
          )}
          <button onClick={() => exportService.toSheets(lead, report)} className="bg-white border border-slate-200 hover:border-green-500 text-slate-700 px-4 py-2.5 rounded-2xl font-black text-xs flex flex-row-reverse items-center gap-2 shadow-sm transition-all">
            <FileSpreadsheet className="text-green-600" size={18} /> جداول جوجل
          </button>
          <button onClick={() => window.print()} className="bg-slate-900 text-white px-5 py-2.5 rounded-2xl font-black text-xs flex flex-row-reverse items-center gap-2 shadow-xl">
            <Printer size={18} /> طباعة PDF
          </button>
          <button onClick={() => setIsWAModalOpen(true)} className="bg-green-600 text-white px-6 py-2.5 rounded-2xl font-black text-xs flex flex-row-reverse items-center gap-2 shadow-xl shadow-green-600/20">
            <MessageSquare size={18} /> إرسال واتساب
          </button>
        </div>
      </div>

      <div className="space-y-8">
        {/* Research Pack / Evidence Summary */}
        <section className="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
          <div className="flex flex-row-reverse items-center gap-3 mb-8 text-primary">
            <div className="bg-primary/10 p-3 rounded-2xl"><Search size={28} /></div>
            <div className="text-right">
              <h3 className="text-2xl font-black">حزمة الأدلة والبحث الفعلي</h3>
              <p className="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Research Engine v3.0 Evidence Summary</p>
            </div>
          </div>
          
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-10">
            <div className="lg:col-span-2 space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {asArray(model.evidence_summary.key_findings).map((f, i) => (
                  <div key={i} className="p-5 bg-slate-50 border border-slate-100 rounded-[1.5rem] flex flex-row-reverse gap-4">
                    <div className="bg-white p-2 rounded-xl shadow-sm text-amber-500 shrink-0"><Zap size={18} /></div>
                    <div className="text-right">
                      <p className="text-xs font-bold text-slate-700 leading-relaxed">{f.finding}</p>
                      {f.evidence_url && <a href={f.evidence_url} target="_blank" className="text-[9px] text-primary hover:underline mt-2 inline-block font-black">{f.evidence_url}</a>}
                    </div>
                  </div>
                ))}
              </div>
            </div>
            
            <div className="space-y-6">
               <div className="bg-slate-900 text-white p-8 rounded-[2rem] shadow-xl">
                  <h4 className="text-xs font-black uppercase tracking-[0.2em] mb-4 text-primary">بصمات تقنية (Tech Hints)</h4>
                  <div className="flex flex-wrap gap-2 justify-end">
                    {asArray(model.evidence_summary.tech_hints).map((h: string) => (
                      <span key={h} className="px-3 py-1 bg-white/10 rounded-lg text-[10px] font-black">{h}</span>
                    ))}
                  </div>
               </div>
               <div className="bg-slate-50 border border-slate-100 p-8 rounded-[2rem]">
                  <h4 className="text-xs font-black uppercase tracking-[0.2em] mb-4 text-slate-400">بيانات تم استخراجها</h4>
                  <div className="space-y-3">
                    {model.evidence_summary.contacts_found.phone && <div className="text-xs font-bold text-slate-600 flex justify-between"><span>{model.evidence_summary.contacts_found.phone}</span> :هاتف</div>}
                    {model.evidence_summary.contacts_found.whatsapp && <div className="text-xs font-bold text-slate-600 flex justify-between"><span>{model.evidence_summary.contacts_found.whatsapp}</span> :واتساب</div>}
                    {model.evidence_summary.contacts_found.email && <div className="text-xs font-bold text-slate-600 flex justify-between"><span>{model.evidence_summary.contacts_found.email}</span> :ايميل</div>}
                  </div>
               </div>
            </div>
          </div>
        </section>

        {/* Website & Social Audit */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
           <section className="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
             <div className="flex flex-row-reverse items-center gap-3 mb-8 text-primary">
                <Globe size={24} />
                <h4 className="text-xl font-black">تدقيق الموقع الإلكتروني</h4>
             </div>
             <div className="space-y-6">
                <div className="grid grid-cols-1 gap-3">
                  {asArray(model.website_audit.issues).map((issue, i) => (
                    <div key={i} className="p-4 bg-red-50/50 border border-red-100 rounded-2xl flex flex-row-reverse gap-3">
                       <AlertTriangle size={18} className="text-red-500 shrink-0 mt-1" />
                       <div className="text-right">
                          <p className="text-xs font-black text-slate-800">{issue.issue}</p>
                          <p className="text-[10px] text-red-600 font-bold mt-1">الأثر: {issue.impact}</p>
                          <div className="mt-3 bg-white p-2 rounded-lg text-[9px] font-black text-primary border border-primary/5 italic">الحل السريع: {issue.quick_fix}</div>
                       </div>
                    </div>
                  ))}
                </div>
                <div className="flex flex-row-reverse items-center justify-between pt-6 border-t border-slate-50">
                   <div className="text-right">
                      <div className="text-[9px] font-black text-slate-400 uppercase tracking-widest">جودة التحويل (CTA)</div>
                      <div className="text-sm font-black text-slate-800">{model.website_audit.cta_quality}</div>
                   </div>
                   <div className="text-right">
                      <div className="text-[9px] font-black text-slate-400 uppercase tracking-widest">فجوة التتبع (Tracking)</div>
                      <div className="text-sm font-black text-slate-800">{model.website_audit.tracking_gap}</div>
                   </div>
                </div>
             </div>
           </section>

           <section className="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
             <div className="flex flex-row-reverse items-center gap-3 mb-8 text-pink-500">
                <Instagram size={24} />
                <h4 className="text-xl font-black">تدقيق منصات التواصل</h4>
             </div>
             <div className="space-y-6">
                <div className="flex flex-wrap gap-2 justify-end mb-6">
                  {asArray(model.social_audit.presence).map((p, i) => (
                    <div key={i} className={`px-4 py-2 rounded-xl text-[10px] font-black border flex items-center gap-2 ${p.status === 'ok' ? 'bg-green-50 text-green-600 border-green-100' : 'bg-slate-50 text-slate-400 border-slate-200'}`}>
                      {p.status === 'ok' && <Check size={12} />} {p.platform}
                    </div>
                  ))}
                </div>
                <div>
                   <h5 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 text-right">فجوات المحتوى المرصودة</h5>
                   <ul className="space-y-2">
                     {asArray(model.social_audit.content_gaps).map((gap: string, i: number) => (
                       <li key={i} className="text-xs font-bold text-slate-600 flex flex-row-reverse items-center gap-2">
                         <div className="w-1.5 h-1.5 bg-pink-400 rounded-full"></div> {gap}
                       </li>
                     ))}
                   </ul>
                </div>
                <div className="pt-6 border-t border-slate-50">
                   <h5 className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 text-right">أفكار سريعة للمحتوى</h5>
                   <div className="grid grid-cols-1 gap-2">
                     {asArray(model.social_audit.quick_content_ideas).map((idea: string, i: number) => (
                       <div key={i} className="bg-slate-50 p-3 rounded-xl text-[11px] font-black text-slate-800 text-right italic border-r-4 border-pink-500/20">"{idea}"</div>
                     ))}
                   </div>
                </div>
             </div>
           </section>
        </div>

        {/* Strategic Snapshot */}
        <section className="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
          <div className="flex flex-row-reverse items-center gap-3 mb-8 text-primary">
            <Target size={28} />
            <h3 className="text-2xl font-black">الرؤية الاستراتيجية والفرص</h3>
          </div>
          <div className="space-y-6">
            <p className="text-xl leading-relaxed text-slate-700 font-bold">{model.snapshot.summary}</p>
            <div className="bg-slate-50/50 p-8 rounded-[2rem] border border-slate-100">
               <div className="text-[10px] text-slate-400 font-black uppercase tracking-widest mb-2">تحليل الملاءمة للسوق (Market Fit)</div>
               <p className="text-slate-900 font-black text-lg leading-snug">{model.snapshot.market_fit}</p>
            </div>
          </div>
        </section>

        {/* Tiers & Recommended Services */}
        <section className="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
           <div className="flex flex-row-reverse items-center gap-3 mb-12 text-primary">
              <TrendingUp size={28} />
              <h3 className="text-2xl font-black">الخدمات والباقات المرشحة (Tiers)</h3>
           </div>
           <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
              {asArray(model.recommended_services).map((svc, i) => (
                <div key={i} className="bg-slate-50 border border-slate-100 p-8 rounded-[2.5rem] flex flex-col justify-between group hover:border-primary transition-all relative">
                  <div className={`absolute top-6 left-6 px-3 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest border ${
                    svc.tier === 'tier1' ? 'bg-green-100 text-green-700 border-green-200' :
                    svc.tier === 'tier2' ? 'bg-blue-100 text-blue-700 border-blue-200' :
                    'bg-purple-100 text-purple-700 border-purple-200'
                  }`}>
                    {svc.tier === 'tier1' ? 'Quick Wins' : svc.tier === 'tier2' ? 'Growth System' : 'Strategic Engine'}
                  </div>
                  <div>
                    <h4 className="font-black text-xl text-slate-900 mb-6 mt-4">{svc.service}</h4>
                    <p className="text-xs text-slate-600 font-bold leading-relaxed mb-6">{svc.why}</p>
                  </div>
                  {svc.package_suggestion && (
                    <div className="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm mt-auto">
                      <div className="font-black text-slate-800 text-sm mb-2">{svc.package_suggestion.package_name}</div>
                      <div className="text-lg font-black text-primary mb-4">{svc.package_suggestion.price_range} <span className="text-[10px] text-slate-400 font-bold">ريال</span></div>
                      <div className="space-y-1">
                        {asArray(svc.package_suggestion?.scope).map((line: string, idx: number) => (
                          <div key={idx} className="flex flex-row-reverse items-center gap-2 text-[10px] text-slate-500 font-bold">
                            <CheckCircle2 size={10} className="text-green-500" /> {line}
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              ))}
           </div>
        </section>

        {/* Talk Track Section */}
        <section className="bg-slate-900 text-white p-12 rounded-[3.5rem] shadow-2xl">
           <div className="grid grid-cols-1 lg:grid-cols-2 gap-20">
              <div className="space-y-10">
                 <div className="flex flex-row-reverse items-center gap-4">
                    <div className="bg-primary p-4 rounded-3xl shadow-xl"><PhoneCall size={32} /></div>
                    <div className="text-right">
                       <h3 className="text-3xl font-black">سكربت المبيعات</h3>
                       <p className="text-slate-400 font-bold">كيف تدير المحادثة بذكاء؟</p>
                    </div>
                 </div>
                 <div className="space-y-8">
                    <div>
                       <div className="text-[10px] font-black text-primary uppercase tracking-[0.3em] mb-4 text-right">الافتتاحية (The Hook)</div>
                       <p className="text-xl font-black leading-relaxed italic border-r-4 border-primary pr-6">"{model.talk_track.opening}"</p>
                    </div>
                    <div>
                       <div className="text-[10px] font-black text-primary uppercase tracking-[0.3em] mb-4 text-right">العرض البيعي (The Pitch)</div>
                       <p className="text-sm font-medium text-slate-300 leading-relaxed text-right">{model.talk_track.pitch}</p>
                    </div>
                 </div>
              </div>

              <div className="space-y-10">
                 <div>
                    <div className="text-[10px] font-black text-primary uppercase tracking-[0.3em] mb-6 text-right">معالجة الاعتراضات</div>
                    <div className="grid grid-cols-1 gap-4">
                       {asArray(model.talk_track.objection_handlers).map((obj, i) => (
                         <div key={i} className="bg-white/5 border border-white/10 p-6 rounded-[2rem] text-right">
                            <div className="text-xs font-black text-red-400 mb-2 flex flex-row-reverse items-center gap-2"><AlertTriangle size={14} /> {obj.objection}</div>
                            <p className="text-xs text-slate-300 font-bold">{obj.answer}</p>
                         </div>
                       ))}
                    </div>
                 </div>
              </div>
           </div>
        </section>

        {/* Follow-up Checklist */}
        <section className="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
           <div className="flex flex-row-reverse items-center gap-3 mb-10 text-primary">
              <CheckCircle2 size={28} />
              <h3 className="text-2xl font-black">خطة المتابعة والمهام المجدولة</h3>
           </div>
           <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {asArray(model.follow_up_plan).map((step) => {
                 const task = asArray(leadTasks).find(t => t.dayNumber === step.day);
                 const isDone = task?.status === 'DONE';
                 return (
                   <div key={step.day} onClick={() => toggleTask(step.day)} className={`p-6 rounded-[2rem] border-2 transition-all cursor-pointer flex flex-col justify-between ${isDone ? 'bg-green-50 border-green-200' : 'bg-slate-50 border-transparent hover:border-primary/20'}`}>
                      <div className="flex flex-row-reverse justify-between items-center mb-6">
                         <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">اليوم {step.day}</span>
                         {isDone ? <CheckSquare className="text-green-600" size={20} /> : <Square className="text-slate-300" size={20} />}
                      </div>
                      <h5 className={`text-sm font-black mb-2 text-right ${isDone ? 'line-through text-slate-400' : 'text-slate-800'}`}>{step.action}</h5>
                      <div className="text-[9px] text-slate-400 font-black uppercase text-right">{step.channel} • {step.goal}</div>
                   </div>
                 );
              })}
           </div>
        </section>
      </div>
    </div>
  );
};

export default ReportView;
