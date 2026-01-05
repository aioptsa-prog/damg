
import React, { useState } from 'react';
import { Lead, LeadStatus } from '../types';
import { Filter, Search, MoreHorizontal, User } from 'lucide-react';

interface LeadListProps {
  leads: Lead[];
  onSelect: (lead: Lead) => void;
}

const LeadList: React.FC<LeadListProps> = ({ leads, onSelect }) => {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('ALL');

  // حساب أعداد الحالات للفلترة بدقة
  const counts = {
    ALL: leads.length,
    [LeadStatus.NEW]: leads.filter(l => l.status === LeadStatus.NEW).length,
    [LeadStatus.CONTACTED]: leads.filter(l => l.status === LeadStatus.CONTACTED).length,
    [LeadStatus.FOLLOW_UP]: leads.filter(l => l.status === LeadStatus.FOLLOW_UP).length,
    [LeadStatus.INTERESTED]: leads.filter(l => l.status === LeadStatus.INTERESTED).length,
    [LeadStatus.WON]: leads.filter(l => l.status === LeadStatus.WON).length,
    [LeadStatus.LOST]: leads.filter(l => l.status === LeadStatus.LOST).length,
  };

  const statusLabels: Record<string, string> = {
    [LeadStatus.NEW]: 'جديد',
    [LeadStatus.CONTACTED]: 'تم التواصل',
    [LeadStatus.FOLLOW_UP]: 'متابعة',
    [LeadStatus.INTERESTED]: 'مهتم',
    [LeadStatus.WON]: 'مغلق (ناجح)',
    [LeadStatus.LOST]: 'مستبعد',
  };

  const filteredLeads = leads.filter(lead => {
    const matchesSearch = lead.companyName.toLowerCase().includes(searchTerm.toLowerCase()) || 
                         (lead.activity && lead.activity.toLowerCase().includes(searchTerm.toLowerCase()));
    const matchesStatus = statusFilter === 'ALL' || lead.status === statusFilter;
    return matchesSearch && matchesStatus;
  });

  return (
    <div className="space-y-6 text-right rtl">
      {/* Search & Filter */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex-1 min-w-[300px] relative">
          <Search className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
          <input 
            type="text" 
            placeholder="البحث باسم الشركة أو النشاط..."
            className="w-full bg-white border border-slate-200 pr-12 pl-4 py-3 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/5 focus:border-primary transition-all text-right font-medium"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2 px-4 py-3 bg-slate-100/50 border border-slate-200 rounded-2xl text-xs font-black text-slate-500">
            <Filter size={16} />
            تصفية الحالات
          </div>
          <select 
            className="px-6 py-3 bg-white border border-slate-200 rounded-2xl text-sm font-black text-slate-800 hover:border-primary focus:outline-none appearance-none cursor-pointer transition-all shadow-sm"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            <option value="ALL">الكل ({counts.ALL})</option>
            {Object.values(LeadStatus).map(status => (
              <option key={status} value={status}>
                {statusLabels[status]} ({counts[status]})
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Grid View */}
      {filteredLeads.length > 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {filteredLeads.map((lead) => (
            <div 
              key={lead.id} 
              onClick={() => onSelect(lead)}
              className="group bg-white p-8 rounded-[2rem] shadow-sm border border-slate-100 hover:border-primary/40 hover:shadow-xl hover:shadow-slate-200/40 transition-all cursor-pointer relative overflow-hidden flex flex-col justify-between"
            >
              <div className="absolute top-0 right-0 w-1.5 h-full bg-primary opacity-0 group-hover:opacity-100 transition-opacity"></div>
              
              <div className="flex flex-row-reverse justify-between items-start mb-6">
                <div className={`px-4 py-1 rounded-xl text-[10px] font-black uppercase border ${
                  lead.status === LeadStatus.NEW ? 'bg-blue-50 text-blue-600 border-blue-100' :
                  lead.status === LeadStatus.INTERESTED ? 'bg-green-50 text-green-600 border-green-100' :
                  lead.status === LeadStatus.WON ? 'bg-emerald-50 text-emerald-600 border-emerald-100' :
                  lead.status === LeadStatus.LOST ? 'bg-red-50 text-red-600 border-red-100' :
                  'bg-slate-50 text-slate-600 border-slate-100'
                }`}>
                  {statusLabels[lead.status as any] || lead.status}
                </div>
                <button className="text-slate-300 hover:text-slate-600 p-1">
                  <MoreHorizontal size={20} />
                </button>
              </div>

              <div className="mb-8">
                <h3 className="text-xl font-black text-slate-900 group-hover:text-primary transition-colors leading-tight">{lead.companyName}</h3>
                <p className="text-sm text-slate-500 mt-2 font-bold">{lead.activity || 'نشاط غير محدد'} • {lead.city || 'غير محدد'}</p>
              </div>

              <div className="flex flex-row-reverse items-center justify-between pt-6 border-t border-slate-50 mt-auto">
                <div className="flex flex-row-reverse items-center gap-2">
                  <div className="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-[10px] font-black text-slate-500 border border-slate-200">
                    <User size={14} />
                  </div>
                  <span className="text-xs text-slate-600 font-black truncate max-w-[100px]">{lead.createdBy || 'غير معروف'}</span>
                </div>
                <div className="text-[10px] text-slate-400 font-black tracking-widest uppercase">
                  {new Date(lead.createdAt).toLocaleDateString('ar-SA')}
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="bg-white py-32 rounded-[3rem] border-2 border-dashed border-slate-100 text-center animate-in fade-in zoom-in-95">
          <div className="w-20 h-20 bg-slate-50 rounded-[2rem] flex items-center justify-center mx-auto mb-6 text-slate-200">
            <Search size={40} />
          </div>
          <h4 className="text-lg font-black text-slate-800">لا يوجد نتائج</h4>
          <p className="text-sm text-slate-400 font-bold mt-2">جرب تغيير معايير البحث أو الفلترة لعرض نتائج أخرى.</p>
        </div>
      )}
    </div>
  );
};

export default LeadList;
