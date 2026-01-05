
import React, { useState, useEffect } from 'react';
import { Save, Zap, MessageSquare, History, ShieldCheck, Activity, DollarSign, Database, Key, Target, FileSpreadsheet, Lock, Globe, Server, ChevronDown, ChevronUp, Cpu, Sliders, CheckCircle2, Terminal } from 'lucide-react';
import { db } from '../services/db';
import { authService } from '../services/authService';
import { whatsappService } from '../services/whatsappService';
import { AuditLog, ScoringSettings, WhatsAppSettings, AISettings } from '../types';

const SettingsPanel: React.FC = () => {
  const [activeTab, setActiveTab] = useState('ai');
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [analytics, setAnalytics] = useState<any>(null);
  const [scoring, setScoring] = useState<ScoringSettings>(db.getScoringSettings());
  const [expandedLog, setExpandedLog] = useState<string | null>(null);
  
  // Fixed: Initial state for aiSettings as it's fetched asynchronously
  const [aiSettings, setAiSettings] = useState<AISettings>({
    activeProvider: 'gemini',
    geminiApiKey: '',
    geminiModel: 'gemini-3-flash-preview',
    openaiApiKey: '',
    openaiModel: 'gpt-4o',
    temperature: 0.7,
    maxTokens: 2048,
    systemInstruction: ''
  });

  const [waSettings, setWaSettings] = useState<WhatsAppSettings>(() => {
    const saved = localStorage.getItem('opt_whatsapp_settings');
    const s = saved ? JSON.parse(saved) : {
      enabled: false,
      providerName: 'WHSender',
      baseUrl: 'https://api.whsender.com/v1/send',
      apiKey: '',
      senderId: ''
    };
    return s;
  });

  const [sheetsSettings, setSheetsSettings] = useState(() => {
    const saved = localStorage.getItem('opt_sheets_settings');
    return saved ? JSON.parse(saved) : {
      enabled: true,
      sheetId: '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms',
      tabName: 'Leads_2024',
      serviceAccount: 'opt-sales-hub@optarget.iam.gserviceaccount.com'
    };
  });

  // Fixed async calls: Await database methods inside useEffect
  useEffect(() => {
    const loadData = async () => {
      try {
        const user = authService.getCurrentUser();
        const auditLogs = await db.getAuditLogs();
        setLogs(Array.isArray(auditLogs) ? auditLogs.slice(0, 30) : []);
        
        if (user) {
          const analyticsData = await db.getAnalytics(user);
          setAnalytics(analyticsData);
        }

        const settings = await db.getAISettings();
        if (settings) setAiSettings(settings);
      } catch {
        setLogs([]);
      }
    };
    loadData();
  }, [activeTab]);

  const handleSaveAISettings = async () => {
    const currentUser = authService.getCurrentUser();
    if (currentUser) {
      try {
        await db.saveAISettings(aiSettings, currentUser.id);
        alert('تم حفظ إعدادات الذكاء الاصطناعي والبرومبت العام بنجاح!');
      } catch (error: any) {
        console.error('Save AI Settings Error:', error);
        alert('حدث خطأ أثناء الحفظ: ' + (error.message || 'خطأ غير معروف'));
      }
    }
  };

  // Fixed: handleSaveScoring made async to correctly call db.saveScoringSettings
  const handleSaveScoring = async () => {
    await db.saveScoringSettings(scoring);
    alert('تم تحديث قواعد النقاط بنجاح!');
  };

  const handleSaveSheets = () => {
    const old = JSON.parse(localStorage.getItem('opt_sheets_settings') || '{}');
    localStorage.setItem('opt_sheets_settings', JSON.stringify(sheetsSettings));
    db.addAuditLog({ 
      actorUserId: 'u1', 
      action: 'UPDATE_SHEETS_CONFIG', 
      entityType: 'SETTINGS', 
      entityId: 'global',
      before: old,
      after: sheetsSettings
    });
    alert('تم حفظ إعدادات جداول بيانات جوجل!');
  };

  const handleSaveWhatsApp = () => {
    whatsappService.saveSettings(waSettings);
    alert('تم حفظ إعدادات الواتساب بنجاح!');
  };

  const tabs = [
    { id: 'ai', label: 'الذكاء الاصطناعي', icon: Cpu },
    { id: 'whatsapp', label: 'تكامل الواتساب', icon: MessageSquare },
    { id: 'sheets', label: 'جداول جوجل', icon: FileSpreadsheet },
    { id: 'scoring', label: 'نظام النقاط', icon: Target },
    { id: 'analytics', label: 'الاستهلاك والتكلفة', icon: Activity },
    { id: 'audit', label: 'سجل الرقابة (Audit)', icon: ShieldCheck },
  ];

  return (
    <div className="max-w-6xl mx-auto flex flex-col md:flex-row gap-8 text-right rtl pb-20">
      <aside className="w-full md:w-64 space-y-2 shrink-0">
        <div className="p-4 mb-4 bg-slate-900 text-white rounded-2xl flex flex-row-reverse items-center gap-3 shadow-xl">
          <Database size={20} className="text-primary" />
          <div className="font-black text-sm uppercase tracking-widest">Settings Hub</div>
        </div>
        {tabs.map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`w-full flex flex-row-reverse items-center gap-3 p-4 rounded-2xl transition-all font-bold text-sm ${
              activeTab === tab.id 
                ? 'bg-primary text-white shadow-xl shadow-primary/30' 
                : 'bg-white text-slate-600 border border-slate-200/50 hover:bg-slate-50'
            }`}
          >
            <tab.icon size={20} />
            {tab.label}
          </button>
        ))}
      </aside>

      <div className="flex-1 bg-white rounded-[2.5rem] shadow-sm border border-slate-200 overflow-hidden min-h-[600px] flex flex-col">
        <div className="p-8 border-b border-slate-100 bg-slate-50/50 flex flex-row-reverse justify-between items-center">
          <h2 className="text-xl font-black text-slate-800">إدارة {tabs.find(t => t.id === activeTab)?.label}</h2>
          <div className="flex items-center gap-2">
            <Lock size={14} className="text-slate-400" />
            <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">SECURE ZONE</span>
          </div>
        </div>

        <div className="p-10 flex-1 overflow-y-auto">
          {activeTab === 'ai' && (
            <div className="space-y-10 max-w-2xl text-right">
              <div className="bg-amber-50 border border-amber-100 p-6 rounded-3xl flex flex-row-reverse gap-4 items-start shadow-sm">
                 <ShieldCheck className="text-amber-600 shrink-0" size={24} />
                 <div className="text-right">
                    <h4 className="font-black text-amber-900 mb-1 text-sm">تشفير وأمان الـ API Keys</h4>
                    <p className="text-[10px] text-amber-800 font-bold leading-relaxed">تُخزن مفاتيحك مشفرة ولا تُرسل أبداً لمتصفح المندوبين. الإعدادات التالية تتحكم في سلوك المحرك الاستراتيجي.</p>
                 </div>
              </div>

              {/* System Instruction / General Prompt */}
              <div className="space-y-4">
                <label className="text-sm font-black text-slate-800 flex flex-row-reverse items-center gap-2">
                   <Terminal size={18} className="text-primary" /> البرومبت العام (System Instruction)
                </label>
                <p className="text-[10px] text-slate-400 font-bold mb-2">هذه التعليمات تُرسل مع كل طلب للذكاء الاصطناعي لتحديد هويته وقواعده العامة.</p>
                <textarea 
                  className="w-full p-6 bg-slate-50 border border-slate-200 rounded-3xl text-sm font-bold text-right outline-none focus:ring-2 focus:ring-primary/20 transition-all min-h-[180px]"
                  value={aiSettings.systemInstruction}
                  onChange={(e) => setAiSettings({...aiSettings, systemInstruction: e.target.value})}
                  placeholder="اكتب التعليمات الأساسية هنا..."
                />
              </div>

              <div className="space-y-6">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mr-2">المزود النشط حالياً</label>
                <div className="grid grid-cols-2 gap-4">
                   <button 
                     onClick={() => setAiSettings({...aiSettings, activeProvider: 'gemini'})}
                     className={`p-6 border-2 rounded-3xl flex flex-col items-center gap-3 transition-all relative ${aiSettings.activeProvider === 'gemini' ? 'border-primary bg-primary/5 shadow-xl shadow-primary/10' : 'border-slate-100 bg-slate-50 grayscale opacity-60 hover:opacity-100'}`}
                   >
                      {aiSettings.activeProvider === 'gemini' && <CheckCircle2 size={20} className="absolute top-4 right-4 text-primary" />}
                      <div className="w-12 h-12 bg-white rounded-2xl shadow-sm flex items-center justify-center font-black text-primary text-xl">G</div>
                      <span className="font-black text-slate-800">Google Gemini</span>
                      <p className="text-[9px] text-slate-400 font-bold">نموذج Flash فائق السرعة</p>
                   </button>
                   <button 
                     onClick={() => setAiSettings({...aiSettings, activeProvider: 'openai'})}
                     className={`p-6 border-2 rounded-3xl flex flex-col items-center gap-3 transition-all relative ${aiSettings.activeProvider === 'openai' ? 'border-primary bg-primary/5 shadow-xl shadow-primary/10' : 'border-slate-100 bg-slate-50 grayscale opacity-60 hover:opacity-100'}`}
                   >
                      {aiSettings.activeProvider === 'openai' && <CheckCircle2 size={20} className="absolute top-4 right-4 text-primary" />}
                      <div className="w-12 h-12 bg-white rounded-2xl shadow-sm flex items-center justify-center font-black text-emerald-500 text-xl">O</div>
                      <span className="font-black text-slate-800">OpenAI (GPT-4)</span>
                      <p className="text-[9px] text-slate-400 font-bold">دقة عالية في الربط المنطقي</p>
                   </button>
                </div>
              </div>

              <div className="space-y-8 pt-6 border-t border-slate-100">
                <div className="space-y-4">
                  <h5 className="font-black text-slate-800 text-sm flex flex-row-reverse items-center gap-2">
                    <Key size={16} className="text-primary" /> إعدادات الربط
                  </h5>
                  <div className="grid grid-cols-1 gap-6">
                    {/* Gemini API Key */}
                    <div className="space-y-2">
                      <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mr-2">Gemini API Key</label>
                      <input 
                        type="password" 
                        placeholder="AIza..."
                        value={aiSettings.geminiApiKey?.startsWith('***') ? '' : aiSettings.geminiApiKey || ''}
                        className="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-left font-mono text-xs focus:ring-2 focus:ring-primary/20 outline-none"
                        dir="ltr"
                        onChange={(e) => setAiSettings({...aiSettings, geminiApiKey: e.target.value})}
                      />
                      {aiSettings.geminiApiKey?.startsWith('***') && (
                        <p className="text-[10px] text-green-600 font-bold">✓ Key محفوظ (مشفر)</p>
                      )}
                    </div>
                    {/* OpenAI API Key */}
                    <div className="space-y-2">
                      <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mr-2">OpenAI API Key</label>
                      <input 
                        type="password" 
                        placeholder="sk-..."
                        value={aiSettings.openaiApiKey?.startsWith('***') ? '' : aiSettings.openaiApiKey || ''}
                        className="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-left font-mono text-xs focus:ring-2 focus:ring-primary/20 outline-none"
                        dir="ltr"
                        onChange={(e) => setAiSettings({...aiSettings, openaiApiKey: e.target.value})}
                      />
                      {aiSettings.openaiApiKey?.startsWith('***') && (
                        <p className="text-[10px] text-green-600 font-bold">✓ Key محفوظ (مشفر)</p>
                      )}
                    </div>
                  </div>
                </div>

                <div className="p-6 bg-slate-50 rounded-[2rem] border border-slate-100 grid grid-cols-2 gap-6">
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mr-2">Temperature (الإبداع)</label>
                    <input 
                      type="range" min="0" max="1" step="0.1"
                      className="w-full accent-primary"
                      value={aiSettings.temperature}
                      onChange={(e) => setAiSettings({...aiSettings, temperature: parseFloat(e.target.value)})}
                    />
                    <div className="flex justify-between text-[10px] font-bold text-slate-400">
                      <span>1.0 إبداعي</span>
                      <span>{aiSettings.temperature}</span>
                      <span>0.0 دقيق</span>
                    </div>
                  </div>
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mr-2">Max Tokens</label>
                    <input 
                      type="number"
                      className="w-full p-4 bg-white border border-slate-200 rounded-2xl text-center font-mono text-xs"
                      value={aiSettings.maxTokens}
                      onChange={(e) => setAiSettings({...aiSettings, maxTokens: parseInt(e.target.value)})}
                    />
                  </div>
                </div>
              </div>

              <button 
                onClick={handleSaveAISettings}
                className="w-full bg-slate-900 text-white py-5 rounded-[2rem] font-black text-sm shadow-xl shadow-slate-900/20 hover:scale-105 transition-all flex items-center justify-center gap-3"
              >
                 <Save size={20} /> حفظ كافة إعدادات الذكاء الاصطناعي
              </button>
            </div>
          )}

          {activeTab === 'whatsapp' && (
            <div className="space-y-8 max-w-2xl text-right">
              <div className="flex flex-row-reverse items-center justify-between">
                <div className="text-right">
                  <h4 className="font-black text-slate-800">تكامل الواتساب (Direct API)</h4>
                  <p className="text-xs text-slate-400 font-bold">تفعيل الإرسال المباشر بضغطة زر عبر HTTP API</p>
                </div>
                <div className={`w-14 h-8 rounded-full transition-all cursor-pointer relative ${waSettings.enabled ? 'bg-green-600' : 'bg-slate-200'}`} onClick={() => setWaSettings({...waSettings, enabled: !waSettings.enabled})}>
                  <div className={`absolute top-1 w-6 h-6 bg-white rounded-full transition-all ${waSettings.enabled ? 'left-7' : 'left-1'}`}></div>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-6">
                <div className="space-y-2">
                   <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center justify-end gap-2">API Base URL <Globe size={12} /></label>
                   <input className="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-left font-mono text-sm" dir="ltr" placeholder="https://api.provider.com/v1/send" value={waSettings.baseUrl} onChange={e => setWaSettings({...waSettings, baseUrl: e.target.value})} />
                </div>
                <div className="space-y-2">
                   <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center justify-end gap-2">API Key / Token <Key size={12} /></label>
                   <input 
                    type="password" 
                    className="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-left font-mono text-sm" 
                    dir="ltr" 
                    placeholder={waSettings.apiKey.startsWith('enc_v1:') ? '•••••••• (Encrypted)' : 'Your secret token...'} 
                    value={waSettings.apiKey.startsWith('enc_v1:') ? '' : waSettings.apiKey} 
                    onChange={e => setWaSettings({...waSettings, apiKey: e.target.value})} 
                  />
                </div>
              </div>

              <button onClick={handleSaveWhatsApp} className="bg-slate-900 text-white px-10 py-4 rounded-2xl font-black text-sm shadow-xl flex items-center gap-3">
                 <Save size={18} /> حفظ وتشفير الإعدادات
              </button>
            </div>
          )}

          {activeTab === 'audit' && (
            <div className="space-y-6">
              <div className="border border-slate-100 rounded-[2rem] overflow-hidden shadow-sm">
                <table className="w-full text-right text-sm">
                  <thead className="bg-slate-50 text-slate-500 font-black text-[10px] uppercase tracking-widest">
                    <tr>
                      <th className="p-5">العملية</th>
                      <th className="p-5">المستهدف</th>
                      <th className="p-5">بواسطة</th>
                      <th className="p-5">الوقت</th>
                      <th className="p-5"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-50">
                    {logs.map(log => (
                      <React.Fragment key={log.id}>
                        <tr className="hover:bg-slate-50 transition-colors group">
                          <td className="p-5"><span className="font-black text-slate-800">{log.action}</span></td>
                          <td className="p-5 text-slate-500 font-bold">{log.entityType} ({log.entityId})</td>
                          <td className="p-5 text-slate-400 font-bold">{log.actorUserId === 'u1' ? 'أحمد (Admin)' : log.actorUserId}</td>
                          <td className="p-5 text-slate-400 font-bold tabular-nums">{new Date(log.createdAt).toLocaleString('ar-SA')}</td>
                          <td className="p-5">
                            {(log.before || log.after) && (
                              <button 
                                onClick={() => setExpandedLog(expandedLog === log.id ? null : log.id)}
                                className="p-2 bg-white border border-slate-200 rounded-xl text-slate-400 hover:text-primary transition-colors"
                              >
                                {expandedLog === log.id ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                              </button>
                            )}
                          </td>
                        </tr>
                        {expandedLog === log.id && (
                          <tr>
                            <td colSpan={5} className="p-8 bg-slate-50/50">
                              <div className="grid grid-cols-2 gap-8 font-mono text-[10px]">
                                <div className="space-y-2">
                                  <div className="font-black text-slate-400 uppercase">الحالة السابقة (Before)</div>
                                  <div className="p-4 bg-white border border-slate-200 rounded-xl overflow-auto max-h-40 text-left" dir="ltr">
                                    <pre>{JSON.stringify(log.before, null, 2)}</pre>
                                  </div>
                                </div>
                                <div className="space-y-2">
                                  <div className="font-black text-slate-400 uppercase">الحالة الجديدة (After)</div>
                                  <div className="p-4 bg-white border border-slate-200 rounded-xl overflow-auto max-h-40 text-left" dir="ltr">
                                    <pre>{JSON.stringify(log.after, null, 2)}</pre>
                                  </div>
                                </div>
                              </div>
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
          
          {activeTab === 'analytics' && analytics && (
            <div className="space-y-8">
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div className="bg-slate-50 p-6 rounded-3xl border border-slate-100 shadow-inner">
                   <div className="text-[10px] font-black text-slate-400 uppercase mb-2">إجمالي تكلفة الذكاء</div>
                   <div className="text-2xl font-black text-slate-900">${analytics.totalCost.toFixed(3)}</div>
                </div>
                <div className="bg-slate-50 p-6 rounded-3xl border border-slate-100 shadow-inner">
                   <div className="text-[10px] font-black text-slate-400 uppercase mb-2">عدد التقارير</div>
                   <div className="text-2xl font-black text-primary">{analytics.totalReports}</div>
                </div>
                <div className="bg-slate-50 p-6 rounded-3xl border border-slate-100 shadow-inner">
                   <div className="text-[10px] font-black text-slate-400 uppercase mb-2">معدل الإغلاق</div>
                   <div className="text-2xl font-black text-green-600">%{Math.round((analytics.wonLeads / (analytics.totalLeads || 1)) * 100)}</div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default SettingsPanel;
