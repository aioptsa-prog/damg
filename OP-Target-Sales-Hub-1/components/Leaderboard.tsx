
import React, { useEffect, useState, useMemo } from 'react';
// Fix: Added missing icon imports (FileText, Activity, Target) from lucide-react to resolve compilation errors
import { Trophy, Medal, Star, ArrowUpRight, ChevronRight, User as UserIcon, Filter, Search, Calendar, Users, TrendingUp, Sparkles, FileText, Activity, Target } from 'lucide-react';
import { db } from '../services/db';

const Leaderboard: React.FC = () => {
  const [users, setUsers] = useState<any[]>([]);
  const [teamFilter, setTeamFilter] = useState('ALL');
  const [searchTerm, setSearchTerm] = useState('');

  // Fixed async issue: Wrapped logic in an async function inside useEffect to correctly await database calls
  useEffect(() => {
    const fetchData = async () => {
      try {
        const allUsers = await db.getUsers();
        if (!Array.isArray(allUsers)) {
          setUsers([]);
          return;
        }
        const usersWithPoints = await Promise.all(allUsers.map(async u => {
          try {
            const analytics = await db.getAnalytics(u);
            const points = await db.calculateUserPoints(u.id);
            return {
              ...u,
              points: points || 0,
              reportsCount: analytics?.totalReports || 0,
              wonCount: analytics?.wonLeads || 0,
              conversionRate: Math.round(((analytics?.wonLeads || 0) / (analytics?.totalLeads || 1)) * 100),
              team: u.teamId === 'team-riyadh' ? 'فريق الرياض' : 'فريق جدة'
            };
          } catch {
            return {
              ...u,
              points: 0,
              reportsCount: 0,
              wonCount: 0,
              conversionRate: 0,
              team: u.teamId === 'team-riyadh' ? 'فريق الرياض' : 'فريق جدة'
            };
          }
        }));
        setUsers(usersWithPoints.sort((a, b) => b.points - a.points));
      } catch {
        setUsers([]);
      }
    };
    fetchData();
  }, []);

  const filteredUsers = useMemo(() => {
    return users.filter(u => {
      const matchesTeam = teamFilter === 'ALL' || u.teamId === teamFilter;
      const matchesSearch = u.name.toLowerCase().includes(searchTerm.toLowerCase());
      return matchesTeam && matchesSearch;
    });
  }, [users, teamFilter, searchTerm]);

  const topThree = filteredUsers.slice(0, 3);
  const rest = filteredUsers.slice(3);

  return (
    <div className="max-w-5xl mx-auto space-y-12 pb-24 text-right rtl animate-in fade-in duration-700">
      {/* Header & Stats Header */}
      <div className="flex flex-col md:flex-row-reverse items-center justify-between gap-8">
        <div className="text-right">
          <div className="flex flex-row-reverse items-center gap-4 mb-2">
             <div className="bg-primary/10 p-4 rounded-[2rem] text-primary shadow-inner border border-primary/5"><Trophy size={40} /></div>
             <h2 className="text-4xl font-black text-slate-900 tracking-tight">نخبة مبيعات <span className="text-primary underline decoration-primary/20 underline-offset-8">OP Target</span></h2>
          </div>
          <p className="text-slate-400 font-bold text-lg mt-2 pr-2">نظام التحفيز القائم على النتائج الحقيقية والتحليلات الذكية.</p>
        </div>
        
        <div className="flex flex-row-reverse items-center gap-4 bg-white p-3 rounded-[2.5rem] shadow-sm border border-slate-100">
           <div className="px-6 py-3 bg-slate-50 rounded-2xl flex flex-col items-center">
              <div className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">إجمالي النقاط</div>
              <div className="text-xl font-black text-slate-800">{users.reduce((s, u) => s + u.points, 0)}</div>
           </div>
           <div className="w-px h-10 bg-slate-100"></div>
           <div className="px-6 py-3 bg-slate-50 rounded-2xl flex flex-col items-center">
              <div className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">المشاركين</div>
              <div className="text-xl font-black text-slate-800">{users.length}</div>
           </div>
        </div>
      </div>

      {/* Podium Section */}
      {topThree.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-end mt-16 px-4">
          {/* #2 */}
          {topThree[1] && (
            <div className="relative group order-2 md:order-1">
              <div className="absolute inset-0 bg-slate-200 blur-3xl opacity-0 group-hover:opacity-20 transition-opacity"></div>
              <div className="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 text-center relative flex flex-col items-center justify-center transition-all duration-500 hover:-translate-y-2 hover:shadow-xl">
                <div className="absolute -top-6 left-1/2 -translate-x-1/2 bg-slate-100 text-slate-500 px-4 py-1.5 rounded-full font-black text-[10px] shadow-sm border border-white uppercase tracking-widest flex items-center gap-2">
                   <Medal size={14} /> المركز الثاني
                </div>
                <div className="relative mb-6">
                   <img src={topThree[1].avatar} className="w-24 h-24 rounded-full border-4 border-slate-50 shadow-2xl" alt="" />
                   <div className="absolute -bottom-2 -right-2 bg-slate-400 text-white w-8 h-8 rounded-full flex items-center justify-center font-black border-2 border-white shadow-lg">2</div>
                </div>
                <h3 className="font-black text-xl text-slate-800">{topThree[1].name}</h3>
                <p className="text-xs text-slate-400 font-bold mt-1">{topThree[1].team}</p>
                <div className="mt-6 pt-6 border-t border-slate-50 w-full">
                   <div className="text-3xl font-black text-slate-700 tracking-tighter">{topThree[1].points}</div>
                   <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-1">نقطة نشاط</p>
                </div>
              </div>
            </div>
          )}

          {/* #1 */}
          {topThree[0] && (
            <div className="relative group order-1 md:order-2">
              <div className="absolute -inset-4 bg-primary/5 blur-[50px] opacity-100 rounded-full animate-pulse"></div>
              <div className="bg-white p-10 rounded-[3.5rem] shadow-2xl border-t-8 border-primary text-center relative flex flex-col items-center justify-center transition-all duration-500 hover:-translate-y-3 scale-105 z-10 border-x border-b border-primary/5">
                <div className="absolute -top-8 left-1/2 -translate-x-1/2 bg-primary text-white px-6 py-2.5 rounded-full font-black text-xs shadow-2xl shadow-primary/30 border-2 border-white uppercase tracking-widest flex items-center gap-2">
                   <Trophy size={18} /> بطل المبيعات
                </div>
                <div className="relative mb-8">
                   <img src={topThree[0].avatar} className="w-32 h-32 rounded-full border-4 border-primary/20 shadow-inner p-1" alt="" />
                   <div className="absolute -bottom-2 -right-2 bg-primary text-white w-10 h-10 rounded-full flex items-center justify-center text-lg font-black border-4 border-white shadow-2xl animate-bounce">1</div>
                   <Sparkles className="absolute -top-2 -left-6 text-amber-400 animate-pulse" size={24} />
                </div>
                <h3 className="font-black text-2xl text-slate-900">{topThree[0].name}</h3>
                <p className="text-sm text-primary font-black mt-1 uppercase tracking-widest">{topThree[0].team}</p>
                <div className="mt-8 pt-8 border-t border-slate-50 w-full">
                   <div className="text-5xl font-black text-primary tracking-tighter">{topThree[0].points}</div>
                   <p className="text-xs text-slate-400 font-black uppercase tracking-widest mt-2">نقطة تميز ذهبية</p>
                </div>
                <div className="mt-6 flex gap-4">
                   <div className="text-center">
                      <div className="text-xs font-black text-slate-800">{topThree[0].wonCount}</div>
                      <div className="text-[9px] text-slate-400 font-black uppercase">ناجح</div>
                   </div>
                   <div className="w-px h-6 bg-slate-100"></div>
                   <div className="text-center">
                      <div className="text-xs font-black text-slate-800">%{topThree[0].conversionRate}</div>
                      <div className="text-[9px] text-slate-400 font-black uppercase">كفاءة</div>
                   </div>
                </div>
              </div>
            </div>
          )}

          {/* #3 */}
          {topThree[2] && (
            <div className="relative group order-3 md:order-3">
              <div className="bg-white p-8 rounded-[3rem] shadow-sm border border-slate-100 text-center relative flex flex-col items-center justify-center transition-all duration-500 hover:-translate-y-2 hover:shadow-xl">
                <div className="absolute -top-6 left-1/2 -translate-x-1/2 bg-orange-50 text-orange-600 px-4 py-1.5 rounded-full font-black text-[10px] shadow-sm border border-white uppercase tracking-widest flex items-center gap-2">
                   <Medal size={14} /> المركز الثالث
                </div>
                <div className="relative mb-6">
                   <img src={topThree[2].avatar} className="w-24 h-24 rounded-full border-4 border-orange-50 shadow-2xl" alt="" />
                   <div className="absolute -bottom-2 -right-2 bg-orange-600 text-white w-8 h-8 rounded-full flex items-center justify-center font-black border-2 border-white shadow-lg">3</div>
                </div>
                <h3 className="font-black text-xl text-slate-800">{topThree[2].name}</h3>
                <p className="text-xs text-slate-400 font-bold mt-1">{topThree[2].team}</p>
                <div className="mt-6 pt-6 border-t border-slate-50 w-full">
                   <div className="text-3xl font-black text-orange-700 tracking-tighter">{topThree[2].points}</div>
                   <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest mt-1">نقطة نشاط</p>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Filter Bar */}
      <div className="bg-white p-6 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-wrap items-center justify-between gap-6">
         <div className="flex-1 min-w-[280px] relative group">
            <Search className="absolute right-5 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary transition-colors" size={20} />
            <input 
              type="text" 
              placeholder="البحث عن مندوب محدد..." 
              className="w-full pr-14 pl-6 py-4 bg-slate-50 border-2 border-transparent rounded-2xl text-sm font-black focus:border-primary/20 focus:bg-white transition-all outline-none"
              value={searchTerm}
              onChange={e => setSearchTerm(e.target.value)}
            />
         </div>
         <div className="flex items-center gap-4">
            <div className="flex items-center gap-2 px-4 py-2 bg-slate-100 text-slate-500 rounded-xl text-[10px] font-black uppercase tracking-widest">
               <Filter size={14} /> تصفية النتائج
            </div>
            <select 
              className="px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-black text-slate-800 hover:bg-slate-100 transition-all outline-none appearance-none cursor-pointer pr-10"
              value={teamFilter}
              onChange={e => setTeamFilter(e.target.value)}
            >
              <option value="ALL">كافة الفرق</option>
              <option value="team-riyadh">فريق الرياض</option>
              <option value="team-jeddah">فريق جدة</option>
            </select>
         </div>
      </div>

      {/* Detailed List */}
      <div className="bg-white rounded-[3rem] shadow-sm border border-slate-100 overflow-hidden">
        <div className="p-10 border-b border-slate-50 bg-slate-50/30 flex flex-row-reverse justify-between items-center">
           <div className="flex flex-row-reverse items-center gap-3">
              <Users size={24} className="text-slate-400" />
              <h3 className="font-black text-xl text-slate-800">التصنيف الكامل للأداء</h3>
           </div>
           <div className="flex gap-4">
              <div className="flex flex-row-reverse items-center gap-2 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                 <div className="w-2 h-2 bg-green-500 rounded-full"></div> أداء تصاعدي
              </div>
           </div>
        </div>

        <div className="divide-y divide-slate-50">
           {filteredUsers.length > 0 ? filteredUsers.map((u, i) => (
             <div key={u.id} className={`p-8 flex flex-col sm:flex-row-reverse items-center justify-between gap-8 hover:bg-slate-50/50 transition-all group ${i < 3 ? 'bg-primary/5' : ''}`}>
                <div className="flex flex-row-reverse items-center gap-6">
                  <div className={`w-12 h-12 rounded-2xl flex items-center justify-center font-black text-lg transition-transform group-hover:scale-110 shadow-sm ${
                    i === 0 ? 'bg-primary text-white' : i === 1 ? 'bg-slate-200 text-slate-600' : i === 2 ? 'bg-orange-100 text-orange-700' : 'bg-slate-50 text-slate-400'
                  }`}>
                    {i + 1}
                  </div>
                  <div className="relative">
                    <img src={u.avatar} className="w-16 h-16 rounded-full border-2 border-white shadow-xl" />
                    {u.points > 10 && <div className="absolute -top-1 -left-1 bg-green-500 w-4 h-4 rounded-full border-2 border-white"></div>}
                  </div>
                  <div className="text-right">
                    <div className="font-black text-lg text-slate-900 flex items-center justify-end gap-2">
                       {u.name}
                       {i === 0 && <Star size={16} className="text-amber-400 fill-amber-400" />}
                    </div>
                    <div className="text-xs text-slate-400 font-bold tracking-tight uppercase flex flex-row-reverse items-center gap-2 mt-1">
                       <TrendingUp size={12} className="text-green-500" /> {u.team}
                    </div>
                  </div>
                </div>

                <div className="flex flex-wrap items-center justify-center sm:justify-end gap-12">
                  <div className="text-center group-hover:translate-y-[-2px] transition-transform">
                    <div className="text-2xl font-black text-primary tracking-tighter">{u.points}</div>
                    <div className="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-1">إجمالي النقاط</div>
                  </div>
                  <div className="hidden lg:block w-px h-10 bg-slate-100"></div>
                  <div className="hidden lg:block text-center">
                    <div className="text-xl font-black text-slate-700">{u.reportsCount}</div>
                    <div className="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-1">تقارير AI</div>
                  </div>
                  <div className="hidden lg:block w-px h-10 bg-slate-100"></div>
                  <div className="text-center">
                    <div className="text-xl font-black text-green-600">{u.wonCount}</div>
                    <div className="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-1">صفقات ناجحة</div>
                  </div>
                  <div className="hidden sm:block">
                    <button className="p-3 bg-white border border-slate-100 rounded-2xl text-slate-300 hover:text-primary hover:border-primary/20 hover:shadow-xl transition-all">
                      <ChevronRight size={20} className="rotate-180" />
                    </button>
                  </div>
                </div>
             </div>
           )) : (
             <div className="py-32 text-center flex flex-col items-center">
                <Search size={64} className="text-slate-100 mb-6" strokeWidth={1} />
                <h4 className="text-xl font-black text-slate-400">لم نجد نتائج مطابقة لبحثك</h4>
                <p className="text-sm text-slate-300 font-bold mt-1">حاول البحث باستخدام اسم المندوب أو تغيير الفريق المختار.</p>
             </div>
           )}
        </div>
      </div>

      {/* Gamification Rules */}
      <div className="bg-slate-900 rounded-[3.5rem] p-12 text-white relative overflow-hidden shadow-2xl">
         <div className="absolute top-0 right-0 w-96 h-96 bg-primary/10 blur-[150px] rounded-full"></div>
         <div className="relative z-10 grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
            <div className="text-right">
               <h3 className="text-3xl font-black mb-4 flex flex-row-reverse items-center gap-4">
                  <Star className="text-amber-400 fill-amber-400" /> كيف تحصد النقاط؟
               </h3>
               <p className="text-slate-400 text-lg font-bold leading-relaxed">يعتمد نظام المكافآت على جهدك الفعلي في إغلاق الصفقات وتحويل الليدز المهتمة إلى عملاء حقيقيين للشركة.</p>
            </div>
            <div className="grid grid-cols-2 gap-4">
               {[
                 { label: 'تقرير ذكي', pts: '+1', icon: FileText, color: 'text-blue-400' },
                 { label: 'تحديث حالة', pts: '+2', icon: Activity, color: 'text-purple-400' },
                 { label: 'عميل مهتم', pts: '+3', icon: Target, color: 'text-amber-400' },
                 { label: 'صفقة ناجحة', pts: '+10', icon: Trophy, color: 'text-green-400' },
               ].map((rule, idx) => (
                 <div key={idx} className="bg-white/5 border border-white/10 p-6 rounded-[2.5rem] flex flex-row-reverse items-center justify-between hover:bg-white/10 transition-all cursor-default group">
                    <div className="text-right">
                       <div className="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">{rule.label}</div>
                       <div className={`text-xl font-black ${rule.color}`}>{rule.pts}</div>
                    </div>
                    <rule.icon size={24} className="text-slate-600 group-hover:scale-110 transition-transform" />
                 </div>
               ))}
            </div>
         </div>
      </div>
    </div>
  );
};

export default Leaderboard;
