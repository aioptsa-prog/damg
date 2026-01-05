
import React, { useState, useEffect } from 'react';
import { 
  Loader2, 
  ChevronRight, 
  ChevronLeft, 
  CheckCircle, 
  ClipboardList, 
  Zap,
  ArrowRight,
  Sparkles
} from 'lucide-react';
import { Lead, Survey, Report, Task } from '../types';
import { asArray } from '../utils/safeData';
import { aiService } from '../services/aiService';
import { db } from '../services/db';
import { authService } from '../services/authService';

interface SmartSurveyProps {
  lead: Lead;
  onFinish: (report: Report) => void;
}

const SmartSurveyComponent: React.FC<SmartSurveyProps> = ({ lead, onFinish }) => {
  const [loading, setLoading] = useState(true);
  const [survey, setSurvey] = useState<Survey | null>(null);
  const [answers, setAnswers] = useState<Record<string, any>>({});
  const [currentStep, setCurrentStep] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const user = authService.getCurrentUser();

  useEffect(() => {
    async function load() {
      try {
        // AI detects gaps or we pass default strategic ones
        const gaps = ['الميزانية التسويقية', 'صاحب القرار الفعلي', 'الأهداف السنوية', 'القنوات الحالية المستخدمة'];
        const { data } = await aiService.generateSurvey(lead, gaps);
        setSurvey(data);
      } catch (e) {
        alert('فشل توليد الاستبيان. يرجى التأكد من اتصال الإنترنت.');
      } finally {
        setLoading(false);
      }
    }
    load();
  }, [lead]);

  const handleAnswer = (id: string, value: any) => {
    setAnswers({ ...answers, [id]: value });
  };

  const handleFinish = async () => {
    if (!user) return;
    setSubmitting(true);
    try {
      // 1. Extract CRM updates from answers
      const updates = await aiService.extractLeadUpdates(answers);
      const updatedLead = { ...lead, ...updates };
      await db.saveLead(updatedLead);

      // 2. Generate updated Strategic Report
      const surveyContext = Object.entries(answers)
        .map(([id, val]) => {
          const q = survey?.questions.find(q => q.id === id);
          return `${q?.question}: ${val}`;
        })
        .join('\n');

      const { data: reportOutput, usage } = await aiService.generateReport(
        { ...updatedLead, notes: (updatedLead.notes || '') + '\n\nنتائج الاستبيان الذكي:\n' + surveyContext }
      );
      
      // Fixed: Added await for async getNextReportVersion
      const versionNumber = await db.getNextReportVersion(lead.id);

      const finalReport: Report = { 
        id: Math.random().toString(36).substr(2, 9), 
        leadId: lead.id, 
        versionNumber, 
        provider: 'gemini',
        model: 'gemini-3-flash-preview',
        promptVersion: '2.6.0',
        output: reportOutput, 
        usage: {
          inputTokens: usage.inputTokens,
          outputTokens: usage.outputTokens,
          cost: usage.cost,
          latencyMs: usage.latencyMs
        },
        createdAt: new Date().toISOString() 
      };
      
      await db.saveReport(finalReport, user.id);

      // 3. Update Tasks if the follow-up plan changed
      const followUpPlan = Array.isArray(reportOutput.follow_up_plan) ? reportOutput.follow_up_plan : [];
      if (followUpPlan.length > 0) {
         const newTasks: Task[] = followUpPlan.map((step: any) => ({
          id: Math.random().toString(36).substr(2, 9),
          leadId: lead.id,
          assignedToUserId: user.id,
          dayNumber: step.day || 1,
          channel: step.channel || '',
          goal: step.goal || '',
          action: step.action || '',
          status: 'OPEN',
          dueDate: new Date(Date.now() + (step.day || 1) * 86400000).toISOString()
        }));
        await db.saveTasks(newTasks);
      }

      onFinish(finalReport);
    } catch (e) {
      console.error(e);
      alert('فشل إكمال الاستبيان وتحديث التقرير.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) return (
    <div className="flex flex-col items-center justify-center py-32 text-center animate-pulse">
      <div className="relative mb-8">
        <Loader2 className="animate-spin text-primary" size={64} strokeWidth={1.5} />
        <Sparkles className="absolute -top-2 -right-2 text-amber-500" size={24} />
      </div>
      <h3 className="text-2xl font-black text-slate-800">جاري صياغة الأسئلة الذكية...</h3>
      <p className="text-slate-400 font-bold mt-2 max-w-xs">نقوم بتحليل قطاع {lead.companyName} لإنتاج استبيان مخصص يسد الفجوات المعلوماتية.</p>
    </div>
  );

  const questions = survey?.questions || [];
  const currentQ = questions[currentStep];

  return (
    <div className="max-w-3xl mx-auto pb-20 px-4">
      <div className="bg-white rounded-[2.5rem] shadow-2xl border border-slate-100 overflow-hidden">
        <div className="bg-slate-900 text-white p-10 relative overflow-hidden">
          <div className="absolute top-0 right-0 w-64 h-64 bg-primary/20 blur-[100px] rounded-full -translate-y-1/2 translate-x-1/2"></div>
          
          <div className="relative z-10">
            <div className="flex flex-row-reverse items-center gap-4 mb-4">
               <div className="bg-primary p-3 rounded-2xl shadow-xl shadow-primary/20"><ClipboardList size={28} /></div>
               <div className="text-right">
                  <h2 className="text-2xl font-black">{survey?.title || 'استبيان تأهيل العميل'}</h2>
                  <p className="text-slate-400 text-sm font-bold mt-1">المرحلة الاستكشافية لعميل {lead.companyName}</p>
               </div>
            </div>
            
            <div className="mt-8 flex flex-row-reverse items-center gap-4">
              <div className="flex-1 flex flex-row-reverse gap-1.5 h-2 bg-white/10 rounded-full overflow-hidden">
                {questions.map((_, i) => (
                  <div key={i} className={`h-full flex-1 transition-all duration-500 ${i <= currentStep ? 'bg-primary' : 'bg-transparent'}`} />
                ))}
              </div>
              <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest tabular-nums">{currentStep + 1} / {questions.length}</span>
            </div>
          </div>
        </div>

        <div className="p-12 min-h-[450px] flex flex-col text-right">
          {currentQ && (
            <div className="flex-1 space-y-8 animate-in fade-in slide-in-from-bottom-6 duration-500">
              <div className="space-y-2">
                <span className="bg-primary/10 text-primary text-[10px] font-black px-3 py-1 rounded-lg uppercase tracking-widest inline-block border border-primary/5">{currentQ.group}</span>
                <h3 className="text-3xl font-black text-slate-800 leading-tight">{currentQ.question}</h3>
              </div>

              <div className="space-y-3">
                {currentQ.type === 'single_select' || currentQ.type === 'multi_select' ? (
                  <div className="grid grid-cols-1 gap-3">
                    {currentQ.options?.map(opt => (
                      <button 
                        key={opt} 
                        onClick={() => handleAnswer(currentQ.id, opt)} 
                        className={`p-5 rounded-2xl border-2 text-right transition-all font-bold group flex flex-row-reverse items-center justify-between ${
                          answers[currentQ.id] === opt 
                            ? 'border-primary bg-primary/5 text-primary shadow-lg shadow-primary/5' 
                            : 'border-slate-100 bg-slate-50 text-slate-600 hover:border-slate-200'
                        }`}
                      >
                        {opt}
                        <div className={`w-5 h-5 rounded-full border-2 transition-all ${answers[currentQ.id] === opt ? 'border-primary bg-primary' : 'border-slate-300 bg-white group-hover:border-slate-400'}`}>
                           {answers[currentQ.id] === opt && <div className="w-full h-full flex items-center justify-center text-white"><CheckCircle size={12} strokeWidth={4} /></div>}
                        </div>
                      </button>
                    ))}
                  </div>
                ) : currentQ.type === 'yes_no' ? (
                  <div className="grid grid-cols-2 gap-4">
                    <button 
                      onClick={() => handleAnswer(currentQ.id, 'yes')} 
                      className={`p-8 rounded-3xl border-2 font-black text-lg transition-all flex flex-col items-center gap-3 ${
                        answers[currentQ.id] === 'yes' ? 'border-green-500 bg-green-50 text-green-700 shadow-xl shadow-green-500/10' : 'border-slate-100 bg-slate-50 text-slate-400'
                      }`}
                    >
                      <div className={`p-3 rounded-2xl ${answers[currentQ.id] === 'yes' ? 'bg-green-500 text-white' : 'bg-white'}`}><CheckCircle size={24} /></div>
                      نعم
                    </button>
                    <button 
                      onClick={() => handleAnswer(currentQ.id, 'no')} 
                      className={`p-8 rounded-3xl border-2 font-black text-lg transition-all flex flex-col items-center gap-3 ${
                        answers[currentQ.id] === 'no' ? 'border-red-500 bg-red-50 text-red-700 shadow-xl shadow-red-500/10' : 'border-slate-100 bg-slate-50 text-slate-400'
                      }`}
                    >
                      <div className={`p-3 rounded-2xl ${answers[currentQ.id] === 'no' ? 'bg-red-500 text-white' : 'bg-white'}`}><ArrowRight className="rotate-45" size={24} /></div>
                      لا
                    </button>
                  </div>
                ) : (
                  <div className="relative">
                    <textarea 
                      className="w-full p-6 bg-slate-50 border-2 border-slate-100 rounded-3xl focus:border-primary focus:bg-white focus:outline-none min-h-[160px] text-right text-lg font-medium transition-all shadow-inner" 
                      placeholder="اشرح بالتفصيل هنا..." 
                      value={answers[currentQ.id] || ''} 
                      onChange={e => handleAnswer(currentQ.id, e.target.value)} 
                    />
                  </div>
                )}
              </div>

              <div className="p-6 bg-amber-50 rounded-[2rem] border border-amber-100 flex flex-row-reverse gap-4 items-start shadow-sm">
                <div className="bg-amber-100 p-2 rounded-xl text-amber-600"><Zap size={20} /></div>
                <div className="text-sm text-amber-800 leading-relaxed font-bold">
                  <span className="font-black block mb-1 text-amber-900">أهمية هذا السؤال:</span>
                  {currentQ.why_this_matters}
                </div>
              </div>
            </div>
          )}

          <div className="mt-16 flex flex-row-reverse justify-between items-center pt-10 border-t border-slate-100">
            {currentStep < questions.length - 1 ? (
              <button 
                onClick={() => setCurrentStep(s => s + 1)} 
                disabled={!answers[currentQ?.id]}
                className="bg-slate-900 text-white px-10 py-4 rounded-2xl font-black flex items-center gap-3 shadow-2xl transition-all hover:scale-105 active:scale-95 disabled:opacity-30 disabled:scale-100"
              >
                السؤال التالي <ChevronLeft size={20} className="rtl:rotate-180" />
              </button>
            ) : (
              <button 
                disabled={submitting || !answers[currentQ?.id]} 
                onClick={handleFinish} 
                className="bg-primary text-white px-12 py-4 rounded-2xl font-black flex items-center gap-3 shadow-2xl shadow-primary/30 transition-all hover:scale-105 active:scale-95 disabled:opacity-50"
              >
                {submitting ? <Loader2 className="animate-spin" size={20} /> : <><CheckCircle size={20} /> تحديث التقرير الاستراتيجي</>}
              </button>
            )}

            <button 
              disabled={currentStep === 0} 
              onClick={() => setCurrentStep(s => s - 1)} 
              className="flex items-center gap-2 text-slate-400 font-black hover:text-slate-600 disabled:opacity-0 transition-all"
            >
              <ChevronRight size={20} className="rtl:rotate-180" /> العودة للسابق
            </button>
          </div>
        </div>
      </div>
      
      {survey?.call_script_questions && (
        <div className="mt-10 bg-white/50 backdrop-blur-sm border border-slate-200 rounded-[2.5rem] p-10 text-right shadow-sm animate-in fade-in duration-700">
          <div className="flex flex-row-reverse items-center gap-3 mb-6">
            <Sparkles className="text-primary" size={20} />
            <h4 className="font-black text-slate-500 text-xs uppercase tracking-[0.2em]">أسئلة مقترحة لإدارة المكالمة</h4>
          </div>
          <div className="grid grid-cols-1 gap-3">
            {asArray(survey.call_script_questions).map((q, i) => (
              <div key={i} className="text-sm text-slate-700 bg-white p-5 rounded-2xl border border-slate-100 shadow-sm font-bold italic border-r-4 border-r-primary/30">
                "{q}"
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default SmartSurveyComponent;
