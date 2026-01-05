
import React, { useMemo, useState, useEffect } from 'react';
import { 
  XAxis, 
  YAxis, 
  CartesianGrid, 
  Tooltip, 
  ResponsiveContainer, 
  AreaChart, 
  Area,
  BarChart,
  Bar,
  Cell,
  PieChart,
  Pie
} from 'recharts';
import { TrendingUp, Users, FileText, ArrowUp, DollarSign, ListChecks, Target, Activity, Shield, Zap, MousePointer2, Briefcase } from 'lucide-react';
import { Lead, LeadStatus, UserRole } from '../types';
import { authService } from '../services/authService';
import { db } from '../services/db';
import { SECTOR_TEMPLATES } from '../constants';
import { asArray } from '../utils/safeData';

interface DashboardProps {
  leads: Lead[];
}

const COLORS = ['#0ea5e9', '#6366f1', '#a855f7', '#ec4899', '#f43f5e', '#f97316', '#10b981'];

const Dashboard: React.FC<DashboardProps> = ({ leads }) => {
  const user = authService.getCurrentUser();
  const [analytics, setAnalytics] = useState<any>(null);

  useEffect(() => {
    if (user) {
      db.getAnalytics(user).then(setAnalytics).catch(() => setAnalytics(null));
    }
  }, [leads, user]);

  const funnelData = useMemo(() => [
    { name: 'جديد', value: analytics?.funnel.new || 0, fill: '#0ea5e9' },
    { name: 'تواصل', value: analytics?.funnel.contacted || 0, fill: '#6366f1' },
    { name: 'مهتم', value: analytics?.funnel.interested || 0, fill: '#f59e0b' },
    { name: 'ناجح', value: analytics?.funnel.won || 0, fill: '#10b981' },
  ], [analytics]);

  const stats = [
    { label: user?.role === UserRole.SUPER_ADMIN ? 'إجمالي العملاء' : 'عملائي المستهدفين', value: leads.length, change: '+12%', icon: Users, color: 'bg-blue-500' },
    { label: 'تقارير مولدة', value: analytics?.totalReports || 0, change: '+8%', icon: FileText, color: 'bg-primary' },
    { label: 'معدل التحويل', value: `${Math.round(((analytics?.wonLeads || 0) / (leads.length || 1)) * 100)}%`, change: '+2.1%', icon: TrendingUp, color: 'bg-green-500' },
    { label: 'تكلفة الذكاء', value: `$${(analytics?.totalCost || 0).toFixed(2)}`, change: '-4%', icon: DollarSign, color: 'bg-amber-500' },
  ];

  const chartData = [
    { name: 'السبت', value: 4 },
    { name: 'الأحد', value: 7 },
    { name: 'الاثنين', value: 12 },
    { name: 'الثلاثاء', value: 9 },
    { name: 'الأربعاء', value: 15 },
    { name: 'الخميس', value: 11 },
  ];

  // Map analytics top sectors to their display names from constants
  const sectorBreakdown = useMemo(() => {
    return asArray(analytics?.topSectors).map(s => {
      const template = SECTOR_TEMPLATES.find(t => t.slug === s.name);
      return {
        ...s,
        displayName: template ? template.name : s.name
      };
    });
  }, [analytics]);

  return (
    <div className="space-y-8 pb-12 animate-in fade-in duration-700 text-right rtl">
      <div className="bg-white p-8 rounded-[2.5rem] border border-slate-100 shadow-sm flex flex-col md:flex-row-reverse items-center justify-between gap-6">
         <div className="text-right">
            <h2 className="text-3xl font-black text-slate-800">أهلاً بك، {user?.name} 👋</h2>
            <p className="text-slate-400 font-bold mt-1 text-lg">
               {user?.role === UserRole.SUPER_ADMIN ? 'أنت تشاهد إحصائيات الشركة كاملة وتوزيع القطاعات' : 
                user?.role === UserRole.MANAGER ? `أنت تشاهد أداء فريقك (${user.teamId})` : 
                'أنت تشاهد ملفك الشخصي وأهدافك الحالية'}
            </p>
         </div>
         <div className="flex flex-row-reverse gap-4">
            <div className="bg-slate-50 px-6 py-4 rounded-3xl border border-slate-100 flex flex-row-reverse items-center gap-3">
               <Shield className="text-primary" size={24} />
               <div className="text-right">
                  <div className="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">دورك الحالي</div>
                  <span className="text-sm font-black text-slate-800 uppercase tracking-tighter">{user?.role}</span>
               </div>
            </div>
            <div className="bg-primary/5 px-6 py-4 rounded-3xl border border-primary/10 flex flex-row-reverse items-center gap-3">
               <Zap className="text-primary" size={24} />
               <div className="text-right">
                  <div className="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">سرعة المعالجة</div>
                  <span className="text-sm font-black text-primary uppercase tracking-tighter">~{((analytics?.avgLatency || 0) / 1000).toFixed(1)}s</span>
               </div>
            </div>
         </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        {stats.map((stat, i) => (
          <div key={i} className="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-col justify-between group hover:shadow-2xl hover:shadow-slate-200/50 transition-all duration-500 hover:-translate-y-2">
            <div className="flex flex-row-reverse justify-between items-start">
              <div className={`${stat.color} p-4 rounded-2xl text-white shadow-xl shadow-black/5 group-hover:scale-110 transition-transform duration-500`}>
                <stat.icon size={28} />
              </div>
              <div className={`flex flex-row-reverse items-center gap-1 text-[10px] font-black px-3 py-1.5 rounded-full ${stat.change.startsWith('+') ? 'bg-green-50 text-green-600 border border-green-100' : 'bg-red-50 text-red-600 border border-red-100'}`}>
                <ArrowUp size={12} className={stat.change.startsWith('-') ? 'rotate-180' : ''} />
                {stat.change}
              </div>
            </div>
            <div className="mt-8">
              <p className="text-[10px] text-slate-400 font-black uppercase tracking-[0.3em] mb-2">{stat.label}</p>
              <p className="text-4xl font-black text-slate-900 tracking-tighter">{stat.value}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
          <div className="flex flex-row-reverse items-center justify-between mb-12">
            <div className="text-right">
              <h3 className="font-black text-2xl text-slate-800">نشاط المبيعات والتقارير</h3>
              <p className="text-xs text-slate-400 font-bold uppercase tracking-widest mt-1">حجم التفاعل اليومي عبر كافة القطاعات</p>
            </div>
            <div className="flex gap-2">
              <button className="px-6 py-2.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-black hover:bg-white transition-all">7 أيام</button>
              <button className="px-6 py-2.5 bg-primary text-white rounded-2xl text-xs font-black shadow-lg shadow-primary/20">30 يوم</button>
            </div>
          </div>
          <div className="h-[350px] w-full" style={{ minWidth: 0, minHeight: 350 }}>
            <ResponsiveContainer width="100%" height={350}>
              <AreaChart data={chartData}>
                <defs>
                  <linearGradient id="colorPrimary" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#0ea5e9" stopOpacity={0.2}/>
                    <stop offset="95%" stopColor="#0ea5e9" stopOpacity={0}/>
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f1f5f9" />
                <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{ fontSize: 11, fill: '#94a3b8', fontWeight: 900 }} />
                <YAxis axisLine={false} tickLine={false} tick={{ fontSize: 11, fill: '#94a3b8', fontWeight: 900 }} />
                <Tooltip 
                  cursor={{ stroke: '#0ea5e9', strokeWidth: 2 }}
                  contentStyle={{ borderRadius: '24px', border: 'none', boxShadow: '0 25px 50px -12px rgb(0 0 0 / 0.2)', fontWeight: 900, fontSize: '13px', textAlign: 'right', padding: '16px' }} 
                />
                <Area type="monotone" dataKey="value" stroke="#0ea5e9" strokeWidth={6} fillOpacity={1} fill="url(#colorPrimary)" animationDuration={2000} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </div>

        <div className="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100 flex flex-col h-full overflow-hidden">
          <div className="mb-10">
            <h3 className="font-black text-2xl text-slate-800 flex flex-row-reverse items-center gap-3">
              <MousePointer2 size={28} className="text-primary" />
              مسار التحويل (Funnel)
            </h3>
            <p className="text-xs text-slate-400 font-bold mt-1">كفاءة الانتقال من ليد إلى عميل ناجح</p>
          </div>
          
          <div className="flex-1 flex flex-col justify-center space-y-6">
            {funnelData.map((item, i) => (
              <div key={i} className="group relative">
                <div className="flex flex-row-reverse justify-between items-end mb-2">
                  <span className="text-[11px] font-black text-slate-500 uppercase tracking-widest">{item.name}</span>
                  <span className="text-lg font-black text-slate-900">{item.value}</span>
                </div>
                <div className="h-4 bg-slate-50 rounded-full overflow-hidden border border-slate-100 shadow-inner">
                  <div 
                    className="h-full transition-all duration-1000 ease-out group-hover:brightness-110" 
                    style={{ 
                      width: `${Math.max((item.value / (analytics?.totalLeads || 1)) * 100, 5)}%`,
                      backgroundColor: item.fill
                    }}
                  ></div>
                </div>
              </div>
            ))}
          </div>

          <div className="mt-10 pt-8 border-t border-slate-100">
             <div className="bg-slate-900 text-white p-6 rounded-[2rem] flex flex-row-reverse items-center justify-between shadow-2xl relative overflow-hidden group">
                <div className="absolute top-0 right-0 w-24 h-24 bg-primary/20 blur-2xl group-hover:bg-primary/40 transition-colors"></div>
                <div className="text-right relative z-10">
                   <div className="text-[10px] font-black text-primary uppercase tracking-widest mb-1">القطاع الأعلى نمواً</div>
                   <div className="text-sm font-black">أفضل معدل إغلاق حالياً</div>
                   <div className="text-xl font-black mt-1 text-slate-100">
                      {sectorBreakdown[0]?.displayName || 'غير متوفر'}
                   </div>
                </div>
                <div className="bg-white/10 p-3 rounded-2xl relative z-10"><Briefcase size={24} /></div>
             </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
         <div className="bg-white p-10 rounded-[3rem] shadow-sm border border-slate-100">
            <h3 className="font-black text-2xl text-slate-800 mb-8 flex flex-row-reverse items-center gap-3">
               <Target size={28} className="text-primary" />
               توزيع كافة القطاعات النشطة
            </h3>
            <div className="h-[300px] flex flex-row-reverse items-center">
               <div className="flex-1 h-full" style={{ minWidth: 0, minHeight: 300 }}>
                  <ResponsiveContainer width="100%" height={300}>
                    <PieChart>
                      <Pie
                        data={sectorBreakdown}
                        cx="50%"
                        cy="50%"
                        innerRadius={60}
                        outerRadius={100}
                        paddingAngle={5}
                        dataKey="value"
                        animationDuration={1500}
                      >
                        {sectorBreakdown.map((entry: any, index: number) => (
                          <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip 
                        contentStyle={{ borderRadius: '20px', border: 'none', boxShadow: '0 10px 30px -5px rgb(0 0 0 / 0.1)', fontWeight: 800, fontSize: '12px' }} 
                      />
                    </PieChart>
                  </ResponsiveContainer>
               </div>
               <div className="w-48 space-y-4">
                  {sectorBreakdown.map((s: any, i: number) => (
                    <div key={i} className="flex flex-row-reverse items-center gap-3">
                       <div className="w-3 h-3 rounded-full" style={{ backgroundColor: COLORS[i % COLORS.length] }}></div>
                       <div className="text-right flex-1">
                          <div className="text-xs font-black text-slate-800 leading-none">{s.displayName}</div>
                          <div className="text-[10px] font-bold text-slate-400 mt-1">{s.value} عميل</div>
                       </div>
                    </div>
                  ))}
               </div>
            </div>
         </div>

         <div className="bg-gradient-to-br from-slate-900 to-slate-800 text-white p-12 rounded-[3.5rem] shadow-2xl relative overflow-hidden group">
            <div className="absolute -bottom-20 -left-20 w-80 h-80 bg-primary/10 blur-[120px] rounded-full group-hover:bg-primary/20 transition-all duration-1000"></div>
            <div className="relative z-10 h-full flex flex-col justify-between">
               <div>
                  <div className="flex flex-row-reverse justify-between items-start mb-8">
                     <div className="bg-primary p-4 rounded-3xl shadow-2xl shadow-primary/40"><Activity size={32} /></div>
                     <div className="px-6 py-2 bg-white/5 border border-white/10 rounded-2xl font-black text-[10px] uppercase tracking-widest">Enterprise Analytics</div>
                  </div>
                  <h3 className="text-3xl font-black mb-4">كفاءة محرك OP Target</h3>
                  <p className="text-slate-400 font-bold text-lg leading-relaxed max-w-md">تم معالجة <span className="text-primary">{analytics?.totalReports} تقرير</span> استراتيجي عبر كافة المجالات بدقة تحليلية تتجاوز 94%.</p>
               </div>
               
               <div className="mt-12 grid grid-cols-2 gap-8">
                  <div className="bg-white/5 border border-white/10 p-6 rounded-[2rem] hover:bg-white/10 transition-all">
                     <div className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">تكلفة الذكاء (متوسط)</div>
                     <div className="text-2xl font-black text-white">${((analytics?.totalCost || 0) / (analytics?.totalReports || 1)).toFixed(3)}</div>
                  </div>
                  <div className="bg-white/5 border border-white/10 p-6 rounded-[2rem] hover:bg-white/10 transition-all">
                     <div className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">معدل تحويل القطاعات</div>
                     <div className="text-2xl font-black text-green-400">+14.2%</div>
                  </div>
               </div>
            </div>
         </div>
      </div>
    </div>
  );
};

export default Dashboard;
