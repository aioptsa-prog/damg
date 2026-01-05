
import React, { useState, useEffect, lazy, Suspense } from 'react';
import { 
  Building2, 
  Save, 
  Trash2, 
  Paperclip, 
  History, 
  User, 
  Zap, 
  Tag, 
  MessageSquare, 
  Clock, 
  MoreVertical, 
  CheckCircle2, 
  Phone, 
  AlertCircle, 
  File, 
  Loader2, 
  Sparkles, 
  ListChecks, 
  Square, 
  CheckSquare, 
  Calendar 
} from 'lucide-react';
import { Lead, Report, LeadActivity, Task, UserRole } from '../types';
import { db } from '../services/db';
import { asArray } from '../utils/safeData';
import { authService } from '../services/authService';
import { aiService } from '../services/aiService';
import { LoadingOverlay } from './UI';
import { shouldShowForgeIntel } from '../services/featureFlags';

// Lazy load ForgeIntelTab to avoid loading when flag is off
const ForgeIntelTab = lazy(() => import('./ForgeIntelTab'));

interface LeadDetailsProps {
  lead: Lead;
  onUpdateLead: (updated: Lead) => void;
  onDeleteLead: (id: string) => void;
  onGenerateSurvey: () => void;
  onViewReport: (report: Report) => void;
}

const LeadDetails: React.FC<LeadDetailsProps> = ({ lead, onUpdateLead, onDeleteLead, onGenerateSurvey, onViewReport }) => {
  const [activeTab, setActiveTab] = useState<'info' | 'custom' | 'files' | 'history' | 'timeline' | 'tasks' | 'forge'>('info');
  const [localLead, setLocalLead] = useState<Lead>(lead);
  const [reports, setReports] = useState<Report[]>([]);
  const [activities, setActivities] = useState<LeadActivity[]>([]);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [newNote, setNewNote] = useState('');
  const [isSubmittingNote, setIsSubmittingNote] = useState(false);
  const [isRegenerating, setIsRegenerating] = useState(false);
  const [showForgeTab, setShowForgeTab] = useState(false);
  const user = authService.getCurrentUser();

  useEffect(() => {
    setReports(db.getReportsByLeadId(lead.id) || []);
    setActivities(db.getActivities(lead.id) || []);
    setTasks(db.getTasks(lead.id) || []);
    setLocalLead(lead);
  }, [lead.id, lead]);

  // Check if Forge Intel tab should be shown
  useEffect(() => {
    shouldShowForgeIntel().then(setShowForgeTab);
  }, []);

  const handleSave = () => {
    onUpdateLead(localLead);
    alert('تم حفظ البيانات بنجاح!');
  };

  const handleRegenerateReport = async () => {
    if (!user) return;
    setIsRegenerating(true);
    try {
      const currentReport = reports[0]; // Newest
      const { data: reportOutput, usage } = await aiService.generateReport(
        localLead, 
        undefined, 
        true, 
        currentReport
      );
      
      // Fixed: Added await for async getNextReportVersion
      const versionNumber = await db.getNextReportVersion(lead.id);

      const newReport: Report = { 
        id: Math.random().toString(36).substr(2, 9), 
        leadId: lead.id, 
        versionNumber, 
        provider: 'gemini',
        model: 'gemini-3-flash-preview',
        promptVersion: '3.1.0 (Update)',
        output: reportOutput, 
        change_log: `تحديث بناءً على معلومات جديدة (الميزانية: ${localLead.budgetRange})`,
        usage: {
          inputTokens: usage.inputTokens,
          outputTokens: usage.outputTokens,
          cost: usage.cost,
          latencyMs: usage.latencyMs
        },
        createdAt: new Date().toISOString() 
      };
      
      db.saveReport(newReport, user.id);
      
      // Sync tasks
      const followUpPlan = Array.isArray(reportOutput.follow_up_plan) ? reportOutput.follow_up_plan : [];
      if (followUpPlan.length > 0) {
         const newTasks: Task[] = followUpPlan.map((step: any) => ({
          id: Math.random().toString(36).substr(2, 9),
          leadId: lead.id,
          assignedToUserId: user.id,
          dayNumber: step.day,
          channel: step.channel,
          goal: step.goal,
          action: step.action,
          status: 'OPEN',
          dueDate: new Date(Date.now() + step.day * 86400000).toISOString()
        }));
        db.saveTasks(newTasks);
      }
      
      setReports(db.getReportsByLeadId(lead.id));
      alert(`تم توليد النسخة V${versionNumber} بنجاح!`);
      onViewReport(newReport);
    } catch (e) {
      alert('فشل إعادة توليد التقرير.');
    } finally {
      setIsRegenerating(false);
    }
  };

  const handleTaskToggle = (taskId: string, currentStatus: string) => {
    if (!user) return;
    db.updateTaskStatus(taskId, currentStatus === 'DONE' ? 'OPEN' : 'DONE' as any, user.id);
    setTasks(db.getTasks(lead.id));
  };

  const handleAddNote = async () => {
    if (!newNote.trim() || !user) return;
    setIsSubmittingNote(true);
    db.addActivity({ leadId: lead.id, userId: user.id, type: 'note', payload: { text: newNote } });
    setNewNote('');
    setActivities(db.getActivities(lead.id));
    setIsSubmittingNote(false);
  };

  return (
    <div className="max-w-6xl mx-auto space-y-8 pb-20 text-right">
      {isRegenerating && <LoadingOverlay message="جاري تحليل التغييرات الجديدة وتحديث الاستراتيجية..." />}
      
      <div className="flex flex-col md:flex-row-reverse items-start md:items-center justify-between gap-6">
        <div className="flex flex-row-reverse items-center gap-6">
          <div className="bg-primary/10 text-primary p-5 rounded-[2rem] shadow-inner"><Building2 size={40} /></div>
          <div className="text-right">
            <h2 className="text-3xl font-black text-slate-800 tracking-tight">{lead.companyName}</h2>
            <p className="text-slate-500 font-bold text-sm mt-1">{lead.activity} • {lead.city}</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-3 flex-row-reverse">
          <button onClick={handleRegenerateReport} className="bg-primary/5 text-primary border border-primary/20 px-6 py-3 rounded-2xl font-black text-sm flex flex-row-reverse items-center gap-2 hover:bg-primary hover:text-white transition-all group">
            <Sparkles size={18} /> تحديث التقرير (v{reports[0]?.versionNumber || 1}+)
          </button>
          <button onClick={onGenerateSurvey} className="bg-primary text-white px-6 py-3 rounded-2xl font-black text-sm shadow-xl flex flex-row-reverse items-center gap-2">
            <ListChecks size={18} /> الاستبيان الذكي
          </button>
          <button onClick={handleSave} className="bg-slate-900 text-white px-6 py-3 rounded-2xl font-black text-sm flex flex-row-reverse items-center gap-2 shadow-xl">
            <Save size={18} /> حفظ التغييرات
          </button>
          <button onClick={() => confirm('حذف؟') && onDeleteLead(lead.id)} className="p-3 bg-red-50 text-red-500 border border-red-100 rounded-2xl hover:bg-red-500 hover:text-white transition-all"><Trash2 size={20} /></button>
        </div>
      </div>

      <div className="flex flex-row-reverse gap-2 p-1 bg-slate-100/50 rounded-3xl w-fit flex-wrap">
        {[
          { id: 'info', label: 'المعلومات', icon: User },
          { id: 'tasks', label: 'المهام', icon: ListChecks },
          { id: 'timeline', label: 'النشاطات', icon: Clock },
          { id: 'custom', label: 'حقول ديناميكية', icon: Tag },
          { id: 'files', label: 'المرفقات', icon: Paperclip },
          { id: 'history', label: 'سجل التقارير', icon: History },
          ...(showForgeTab ? [{ id: 'forge', label: 'Forge Intel', icon: Zap }] : []),
        ].map(t => (
          <button key={t.id} onClick={() => setActiveTab(t.id as any)} className={`px-6 py-2.5 rounded-2xl font-black text-xs flex flex-row-reverse items-center gap-2 transition-all ${activeTab === t.id ? 'bg-white text-primary shadow-sm' : 'text-slate-400 hover:text-slate-600'} ${t.id === 'forge' ? 'bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200' : ''}`}>
            <t.icon size={16} /> {t.label}
          </button>
        ))}
      </div>

      <div className="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 p-10 min-h-[500px]">
        {activeTab === 'info' && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-12 text-right">
            <div className="space-y-6">
              <h4 className="font-black text-slate-400 text-[10px] uppercase tracking-widest border-b border-slate-50 pb-2">صاحب القرار والاتصال</h4>
              <div className="space-y-4">
                <div className="space-y-1">
                  <label className="text-[10px] font-black mr-2 text-slate-400">اسم صاحب القرار</label>
                  <input className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-medium text-right" value={localLead.decisionMakerName || ''} onChange={e => setLocalLead({...localLead, decisionMakerName: e.target.value})} />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-black mr-2 text-slate-400">البريد الإلكتروني</label>
                  <input className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-medium text-left" dir="ltr" value={localLead.contactEmail || ''} onChange={e => setLocalLead({...localLead, contactEmail: e.target.value})} />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-black mr-2 text-slate-400">رقم التواصل</label>
                  <input className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-medium text-left" dir="ltr" value={localLead.phone || ''} onChange={e => setLocalLead({...localLead, phone: e.target.value})} />
                </div>
              </div>
            </div>

            <div className="space-y-6">
              <h4 className="font-black text-slate-400 text-[10px] uppercase tracking-widest border-b border-slate-50 pb-2">بيانات المبيعات (تؤثر على التقرير)</h4>
              <div className="space-y-4">
                <div className="space-y-1">
                  <label className="text-[10px] font-black mr-2 text-slate-400">الميزانية المتوقعة</label>
                  <select className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-black text-right" value={localLead.budgetRange || ''} onChange={e => setLocalLead({...localLead, budgetRange: e.target.value})}>
                    <option value="">اختر الميزانية</option>
                    <option value="low">منخفضة (تحت 3000 ريال)</option>
                    <option value="medium">متوسطة (3000 - 10,000 ريال)</option>
                    <option value="high">مرتفعة (فوق 10,000 ريال)</option>
                  </select>
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-black mr-2 text-slate-400">تفريغ المكالمة / ملاحظات حاسمة</label>
                  <textarea className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-medium min-h-[160px] text-right" placeholder="ألصق تفريغ المكالمة هنا للحصول على تقرير أدق..." value={localLead.transcript || ''} onChange={e => setLocalLead({...localLead, transcript: e.target.value})} />
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'tasks' && (
          <div className="space-y-4">
            {asArray(tasks).map((task) => (
              <div key={task.id} className={`p-6 rounded-3xl border-2 transition-all flex flex-row-reverse items-center justify-between gap-6 ${task.status === 'DONE' ? 'bg-green-50 border-green-100 opacity-75' : 'bg-slate-50 border-transparent'}`}>
                <div className="flex flex-row-reverse items-center gap-4 flex-1">
                  <button onClick={() => handleTaskToggle(task.id, task.status)} className={`p-2 rounded-xl transition-all ${task.status === 'DONE' ? 'bg-green-600 text-white' : 'bg-white text-slate-300 border border-slate-200'}`}>
                    {task.status === 'DONE' ? <CheckSquare size={24} /> : <Square size={24} />}
                  </button>
                  <div className="text-right">
                    <h5 className={`font-black text-slate-800 ${task.status === 'DONE' ? 'line-through text-slate-400' : ''}`}>{task.action}</h5>
                    <p className="text-[10px] text-slate-500 font-black uppercase mt-1">اليوم {task.dayNumber} • {task.channel}</p>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {activeTab === 'history' && (
          <div className="space-y-4">
            {asArray(reports).map((r) => (
              <div key={r.id} className="flex flex-row-reverse items-center justify-between p-6 bg-slate-50 border border-slate-200 rounded-3xl hover:border-primary transition-all group">
                <div className="flex flex-row-reverse items-center gap-6">
                  <div className="w-12 h-12 bg-white rounded-2xl flex items-center justify-center font-black text-primary shadow-sm">V{r.versionNumber}</div>
                  <div className="text-right">
                    <div className="font-black text-slate-900">تقرير استراتيجية OP Target</div>
                    <div className="text-[10px] text-slate-400 font-bold uppercase mt-1">{new Date(r.createdAt).toLocaleString('ar-SA')}</div>
                  </div>
                </div>
                <button onClick={() => onViewReport(r)} className="bg-white text-primary border border-primary/20 px-6 py-2 rounded-xl text-xs font-black hover:bg-primary hover:text-white transition-all">عرض التقرير</button>
              </div>
            ))}
          </div>
        )}

        {activeTab === 'timeline' && (
          <div className="space-y-6">
            <div className="flex flex-row-reverse gap-4">
               <textarea className="flex-1 px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-medium outline-none text-right" placeholder="أضف ملاحظة أو نتيجة مكالمة..." value={newNote} onChange={e => setNewNote(e.target.value)} />
               <button onClick={handleAddNote} disabled={!newNote.trim() || isSubmittingNote} className="bg-primary text-white px-8 rounded-2xl font-black text-sm shadow-xl flex items-center gap-2">
                 {isSubmittingNote ? <Loader2 size={18} className="animate-spin" /> : 'حفظ'}
               </button>
            </div>
            <div className="relative pt-6 pr-8 text-right border-r-2 border-slate-100">
              {asArray(activities).map((act) => (
                <div key={act.id} className="relative pb-10 group last:pb-0">
                  <div className="absolute top-1 -right-[41px] w-5 h-5 rounded-full bg-white border-4 border-slate-100 z-10"></div>
                  <div className="flex flex-row-reverse items-center gap-3 mb-2 text-slate-400 font-black text-[10px]">
                     {new Date(act.createdAt).toLocaleString('ar-SA')}
                  </div>
                  <div className="bg-slate-50 p-5 rounded-[1.5rem] border border-slate-100">
                    {act.type === 'note' && <p className="text-sm font-bold text-slate-700">{act.payload.text}</p>}
                    {act.type === 'report_generated' && <p className="text-sm font-bold text-slate-700">تم توليد نسخة جديدة من التقرير الاستراتيجي (الإصدار {act.payload.version})</p>}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {activeTab === 'forge' && showForgeTab && (
          <Suspense fallback={
            <div className="flex items-center justify-center py-20">
              <Loader2 size={32} className="animate-spin text-primary" />
            </div>
          }>
            <ForgeIntelTab 
              leadId={lead.id} 
              leadPhone={lead.phone} 
              leadName={lead.companyName}
            />
          </Suspense>
        )}
      </div>
    </div>
  );
};

export default LeadDetails;
