
import React, { useState, useEffect } from 'react';
import {
  Users, UserPlus, Shield, Mail, Edit, Trash2,
  CheckCircle2, XCircle, Search, Filter,
  MoreVertical, ShieldCheck, UserCog, Building2,
  Lock, Key, X, Save, Plus, UsersRound
} from 'lucide-react';
import { db } from '../services/db';
import { User, UserRole, Team } from '../types';
import { authService } from '../services/authService';

interface TeamWithCount extends Team {
  memberCount?: number;
  managerName?: string;
}

const UserManagement: React.FC = () => {
  const [users, setUsers] = useState<User[]>([]);
  const [teams, setTeams] = useState<TeamWithCount[]>([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [roleFilter, setRoleFilter] = useState<string>('ALL');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  
  // Team management state
  const [activeTab, setActiveTab] = useState<'users' | 'teams'>('users');
  const [isTeamModalOpen, setIsTeamModalOpen] = useState(false);
  const [editingTeam, setEditingTeam] = useState<Team | null>(null);
  const [teamFormData, setTeamFormData] = useState<Partial<Team>>({
    name: '',
    managerUserId: ''
  });

  const currentUser = authService.getCurrentUser();

  const [formData, setFormData] = useState<Partial<User> & { password?: string }>({
    name: '',
    email: '',
    role: UserRole.SALES_REP,
    teamId: '',
    isActive: true,
    avatar: 'https://picsum.photos/seed/newuser/100/100',
    password: ''
  });

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      const [usersData, teamsData] = await Promise.all([
        db.getUsers(),
        db.getTeams()
      ]);
      setUsers(Array.isArray(usersData) ? usersData : []);
      setTeams(Array.isArray(teamsData) ? teamsData : []);
    } catch {
      setUsers([]);
      setTeams([]);
    }
  };

  const handleOpenModal = (user: User | null = null) => {
    if (user) {
      setEditingUser(user);
      setFormData({ ...user, password: '' });
    } else {
      setEditingUser(null);
      setFormData({
        id: Math.random().toString(36).substr(2, 9),
        name: '',
        email: '',
        role: UserRole.SALES_REP,
        teamId: teams[0]?.id || '',
        isActive: true,
        avatar: `https://picsum.photos/seed/${Math.random()}/100/100`,
        password: ''
      });
    }
    setIsModalOpen(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentUser) return;

    try {
      await db.saveUser(formData as User, currentUser.id);
      await loadData();
      setIsModalOpen(false);
      alert(editingUser ? 'تم تحديث بيانات الموظف' : 'تم إضافة الموظف بنجاح');
    } catch {
      alert('حدث خطأ أثناء الحفظ');
    }
  };

  const handleDelete = async (userId: string) => {
    if (userId === currentUser?.id) {
      alert('لا يمكنك حذف حسابك الحالي!');
      return;
    }
    if (confirm('هل أنت متأكد من حذف هذا المستخدم نهائياً؟')) {
      if (currentUser) {
        try {
          await db.deleteUser(userId, currentUser.id);
          await loadData();
        } catch {
          alert('حدث خطأ أثناء الحذف');
        }
      }
    }
  };

  const toggleStatus = async (user: User) => {
    if (!currentUser) return;
    const updated = { ...user, isActive: !user.isActive };
    try {
      await db.saveUser(updated, currentUser.id);
      await loadData();
    } catch {
      alert('حدث خطأ أثناء التحديث');
    }
  };

  // Team management functions
  const handleOpenTeamModal = (team: Team | null = null) => {
    if (team) {
      setEditingTeam(team);
      setTeamFormData(team);
    } else {
      setEditingTeam(null);
      setTeamFormData({
        name: '',
        managerUserId: ''
      });
    }
    setIsTeamModalOpen(true);
  };

  const handleTeamSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentUser) return;

    try {
      await db.saveTeam(teamFormData);
      await loadData();
      setIsTeamModalOpen(false);
      alert(editingTeam ? 'تم تحديث الفريق' : 'تم إضافة الفريق بنجاح');
    } catch (err: any) {
      alert(err.message || 'حدث خطأ أثناء الحفظ');
    }
  };

  const handleDeleteTeam = async (teamId: string) => {
    if (confirm('هل أنت متأكد من حذف هذا الفريق؟')) {
      try {
        await db.deleteTeam(teamId);
        await loadData();
      } catch (err: any) {
        alert(err.message || 'حدث خطأ أثناء الحذف');
      }
    }
  };

  const filteredUsers = users.filter(u => {
    const matchesSearch = u.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      u.email.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesRole = roleFilter === 'ALL' || u.role === roleFilter;
    return matchesSearch && matchesRole;
  });

  return (
    <div className="max-w-6xl mx-auto space-y-8 pb-20 text-right animate-in fade-in duration-500">
      <div className="flex flex-col md:flex-row-reverse items-center justify-between gap-6">
        <div className="text-right">
          <div className="flex flex-row-reverse items-center gap-4 mb-2">
            <div className="bg-primary/10 p-4 rounded-3xl text-primary"><UserCog size={32} /></div>
            <div>
              <h2 className="text-3xl font-black text-slate-800">إدارة الموظفين والفرق</h2>
              <p className="text-slate-400 font-bold">التحكم في الأدوار، الصلاحيات، الفرق، وحالة الحسابات.</p>
            </div>
          </div>
        </div>
        <div className="flex gap-3">
          {activeTab === 'users' ? (
            <button
              onClick={() => handleOpenModal()}
              className="bg-primary text-white px-8 py-4 rounded-2xl font-black text-sm shadow-xl shadow-primary/20 flex flex-row-reverse items-center gap-2 hover:scale-105 transition-all"
            >
              <UserPlus size={20} /> إضافة موظف جديد
            </button>
          ) : (
            <button
              onClick={() => handleOpenTeamModal()}
              className="bg-emerald-600 text-white px-8 py-4 rounded-2xl font-black text-sm shadow-xl shadow-emerald-600/20 flex flex-row-reverse items-center gap-2 hover:scale-105 transition-all"
            >
              <Plus size={20} /> إضافة فريق جديد
            </button>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-2 bg-slate-100 p-2 rounded-2xl w-fit">
        <button
          onClick={() => setActiveTab('users')}
          className={`px-6 py-3 rounded-xl font-black text-sm flex items-center gap-2 transition-all ${
            activeTab === 'users' 
              ? 'bg-white text-primary shadow-sm' 
              : 'text-slate-500 hover:text-slate-700'
          }`}
        >
          <Users size={18} /> الموظفين ({users.length})
        </button>
        <button
          onClick={() => setActiveTab('teams')}
          className={`px-6 py-3 rounded-xl font-black text-sm flex items-center gap-2 transition-all ${
            activeTab === 'teams' 
              ? 'bg-white text-emerald-600 shadow-sm' 
              : 'text-slate-500 hover:text-slate-700'
          }`}
        >
          <UsersRound size={18} /> الفرق ({teams.length})
        </button>
      </div>

      {activeTab === 'users' && (
      <>
      <div className="bg-white p-6 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-wrap items-center justify-between gap-6">
        <div className="flex-1 min-w-[300px] relative">
          <Search className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
          <input
            type="text"
            placeholder="البحث بالاسم أو البريد..."
            className="w-full bg-slate-50 border-none pr-12 pl-4 py-4 rounded-2xl focus:ring-2 focus:ring-primary/20 transition-all text-right font-bold text-sm"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2 px-4 py-3 bg-slate-100/50 rounded-2xl text-[10px] font-black text-slate-500 uppercase tracking-widest">
            <Filter size={14} /> تصفية الرتب
          </div>
          <select
            className="px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-black text-slate-800 appearance-none cursor-pointer"
            value={roleFilter}
            onChange={(e) => setRoleFilter(e.target.value)}
          >
            <option value="ALL">جميع الرتب</option>
            {Object.values(UserRole).map(role => (
              <option key={role} value={role}>{role}</option>
            ))}
          </select>
        </div>
      </div>

      <div className="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
        <table className="w-full text-right border-collapse">
          <thead>
            <tr className="bg-slate-50 border-b border-slate-100">
              <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">الموظف</th>
              <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">الرتبة</th>
              <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">الفريق</th>
              <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">الحالة</th>
              <th className="p-6"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-50">
            {filteredUsers.map(u => (
              <tr key={u.id} className="hover:bg-slate-50/50 transition-colors">
                <td className="p-6">
                  <div className="flex flex-row-reverse items-center gap-4">
                    <img src={u.avatar} className="w-12 h-12 rounded-full border-2 border-white shadow-sm" alt="" />
                    <div className="text-right">
                      <div className="font-black text-slate-800">{u.name}</div>
                      <div className="text-xs text-slate-400 font-bold">{u.email}</div>
                    </div>
                  </div>
                </td>
                <td className="p-6 text-center">
                  <span className={`px-4 py-1.5 rounded-xl text-[10px] font-black border ${u.role === UserRole.SUPER_ADMIN ? 'bg-purple-50 text-purple-600 border-purple-100' :
                      u.role === UserRole.MANAGER ? 'bg-blue-50 text-blue-600 border-blue-100' :
                        'bg-slate-50 text-slate-600 border-slate-100'
                    }`}>
                    {u.role}
                  </span>
                </td>
                <td className="p-6 text-center">
                  <div className="flex flex-row-reverse items-center justify-center gap-2 text-xs font-bold text-slate-500">
                    <Building2 size={14} className="text-slate-300" />
                    {teams.find(t => t.id === u.teamId)?.name || 'بدون فريق'}
                  </div>
                </td>
                <td className="p-6 text-center">
                  <button
                    onClick={() => toggleStatus(u)}
                    className={`inline-flex flex-row-reverse items-center gap-2 px-4 py-1.5 rounded-full text-[10px] font-black transition-all ${u.isActive ? 'bg-green-50 text-green-600 border border-green-100' : 'bg-red-50 text-red-600 border border-red-100'
                      }`}
                  >
                    {u.isActive ? <CheckCircle2 size={12} /> : <XCircle size={12} />}
                    {u.isActive ? 'نشط' : 'معطل'}
                  </button>
                </td>
                <td className="p-6 text-left">
                  <div className="flex items-center gap-2">
                    <button onClick={() => handleOpenModal(u)} className="p-2.5 bg-white border border-slate-200 text-slate-400 hover:text-primary hover:border-primary/30 rounded-xl transition-all"><Edit size={16} /></button>
                    <button onClick={() => handleDelete(u.id)} className="p-2.5 bg-white border border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-100 rounded-xl transition-all"><Trash2 size={16} /></button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {filteredUsers.length === 0 && (
          <div className="py-20 text-center">
            <Search size={48} className="mx-auto text-slate-100 mb-4" />
            <h4 className="text-lg font-black text-slate-300">لم يتم العثور على نتائج لبحثك</h4>
          </div>
        )}
      </div>
      </>
      )}

      {/* Teams Tab Content */}
      {activeTab === 'teams' && (
        <div className="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
          <table className="w-full text-right border-collapse">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-100">
                <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">الفريق</th>
                <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">المدير</th>
                <th className="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">عدد الأعضاء</th>
                <th className="p-6"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {teams.map(team => (
                <tr key={team.id} className="hover:bg-slate-50/50 transition-colors">
                  <td className="p-6">
                    <div className="flex flex-row-reverse items-center gap-4">
                      <div className="bg-emerald-100 p-3 rounded-2xl">
                        <UsersRound size={24} className="text-emerald-600" />
                      </div>
                      <div className="text-right">
                        <div className="font-black text-slate-800">{team.name}</div>
                        <div className="text-xs text-slate-400 font-bold">ID: {team.id.slice(0, 8)}...</div>
                      </div>
                    </div>
                  </td>
                  <td className="p-6 text-center">
                    <span className="text-sm font-bold text-slate-600">
                      {team.managerName || users.find(u => u.id === team.managerUserId)?.name || 'غير محدد'}
                    </span>
                  </td>
                  <td className="p-6 text-center">
                    <span className="bg-slate-100 px-4 py-2 rounded-xl text-sm font-black text-slate-600">
                      {team.memberCount ?? users.filter(u => u.teamId === team.id).length} عضو
                    </span>
                  </td>
                  <td className="p-6 text-left">
                    <div className="flex items-center gap-2">
                      <button 
                        onClick={() => handleOpenTeamModal(team)} 
                        className="p-2.5 bg-white border border-slate-200 text-slate-400 hover:text-emerald-600 hover:border-emerald-200 rounded-xl transition-all"
                      >
                        <Edit size={16} />
                      </button>
                      <button 
                        onClick={() => handleDeleteTeam(team.id)} 
                        className="p-2.5 bg-white border border-slate-200 text-slate-400 hover:text-red-500 hover:border-red-100 rounded-xl transition-all"
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {teams.length === 0 && (
            <div className="py-20 text-center">
              <UsersRound size={48} className="mx-auto text-slate-100 mb-4" />
              <h4 className="text-lg font-black text-slate-300">لا توجد فرق بعد</h4>
              <p className="text-sm text-slate-400 mt-2">أضف فريقاً جديداً للبدء</p>
            </div>
          )}
        </div>
      )}

      {isModalOpen && (
        <div className="fixed inset-0 z-[200] bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-6 animate-in fade-in duration-200">
          <div className="bg-white w-full max-w-xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-8 border-b border-slate-100 bg-slate-50 flex flex-row-reverse justify-between items-center">
              <h3 className="text-xl font-black text-slate-800">{editingUser ? 'تعديل بيانات موظف' : 'إضافة موظف جديد'}</h3>
              <button onClick={() => setIsModalOpen(false)} className="p-2 hover:bg-slate-200 rounded-full text-slate-400 transition-colors"><X size={20} /></button>
            </div>

            <form onSubmit={handleSubmit} className="p-10 space-y-8 text-right">
              <div className="grid grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">الاسم الكامل</label>
                  <input
                    required
                    className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-bold text-right outline-none focus:ring-2 focus:ring-primary/20"
                    value={formData.name}
                    onChange={e => setFormData({ ...formData, name: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">البريد الإلكتروني</label>
                  <input
                    required
                    type="email"
                    className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-bold text-left outline-none focus:ring-2 focus:ring-primary/20"
                    dir="ltr"
                    value={formData.email}
                    onChange={e => setFormData({ ...formData, email: e.target.value })}
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">الرتبة / الصلاحية</label>
                  <select
                    className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-black text-right appearance-none cursor-pointer"
                    value={formData.role}
                    onChange={e => setFormData({ ...formData, role: e.target.value as UserRole })}
                  >
                    {Object.values(UserRole).map(role => (
                      <option key={role} value={role}>{role}</option>
                    ))}
                  </select>
                </div>
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">الفريق (اختياري)</label>
                  <select
                    className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-black text-right appearance-none cursor-pointer"
                    value={formData.teamId || ''}
                    onChange={e => setFormData({ ...formData, teamId: e.target.value })}
                  >
                    <option value="">-- بدون فريق --</option>
                    {teams.map(team => (
                      <option key={team.id} value={team.id}>{team.name}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="p-6 bg-slate-50 rounded-3xl border border-slate-100 flex flex-row-reverse items-center justify-between">
                <div>
                  <h5 className="text-sm font-black text-slate-800">حالة الحساب</h5>
                  <p className="text-[10px] text-slate-400 font-bold">الحسابات المعطلة لا يمكنها الدخول للنظام</p>
                </div>
                <button
                  type="button"
                  onClick={() => setFormData({ ...formData, isActive: !formData.isActive })}
                  className={`w-14 h-8 rounded-full transition-all relative ${formData.isActive ? 'bg-green-500' : 'bg-slate-300'}`}
                >
                  <div className={`absolute top-1 w-6 h-6 bg-white rounded-full transition-all ${formData.isActive ? 'left-7' : 'left-1'}`}></div>
                </button>
              </div>

              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2 flex items-center gap-2">
                  <Key size={12} /> {editingUser ? 'كلمة المرور الجديدة (اتركها فارغة للإبقاء على القديمة)' : 'كلمة المرور'}
                </label>
                <input
                  type="password"
                  required={!editingUser}
                  minLength={6}
                  placeholder={editingUser ? '••••••••' : 'أدخل كلمة مرور قوية'}
                  className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-bold text-left outline-none focus:ring-2 focus:ring-primary/20"
                  dir="ltr"
                  value={(formData as any).password || ''}
                  onChange={e => setFormData({ ...formData, password: e.target.value })}
                />
                <p className="text-[9px] text-slate-400 font-bold">يجب أن تحتوي على 6 أحرف على الأقل</p>
              </div>

              <div className="flex flex-row-reverse gap-4 pt-4">
                <button type="submit" className="flex-1 bg-primary text-white py-5 rounded-2xl font-black text-sm shadow-xl shadow-primary/20 hover:scale-105 transition-all flex items-center justify-center gap-2">
                  <Save size={18} /> {editingUser ? 'حفظ التغييرات' : 'إضافة الموظف الآن'}
                </button>
                <button type="button" onClick={() => setIsModalOpen(false)} className="px-10 py-5 bg-slate-100 text-slate-500 rounded-2xl font-black text-sm">إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Team Modal */}
      {isTeamModalOpen && (
        <div className="fixed inset-0 z-[200] bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-6 animate-in fade-in duration-200">
          <div className="bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200">
            <div className="p-8 border-b border-slate-100 bg-emerald-50 flex flex-row-reverse justify-between items-center">
              <h3 className="text-xl font-black text-slate-800">{editingTeam ? 'تعديل الفريق' : 'إضافة فريق جديد'}</h3>
              <button onClick={() => setIsTeamModalOpen(false)} className="p-2 hover:bg-emerald-100 rounded-full text-slate-400 transition-colors"><X size={20} /></button>
            </div>

            <form onSubmit={handleTeamSubmit} className="p-10 space-y-8 text-right">
              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">اسم الفريق</label>
                <input
                  required
                  placeholder="مثال: فريق المبيعات - الرياض"
                  className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-bold text-right outline-none focus:ring-2 focus:ring-emerald-500/20"
                  value={teamFormData.name}
                  onChange={e => setTeamFormData({ ...teamFormData, name: e.target.value })}
                />
              </div>

              <div className="space-y-2">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">مدير الفريق (اختياري)</label>
                <select
                  className="w-full px-6 py-4 bg-slate-50 border-none rounded-2xl text-sm font-black text-right appearance-none cursor-pointer"
                  value={teamFormData.managerUserId || ''}
                  onChange={e => setTeamFormData({ ...teamFormData, managerUserId: e.target.value })}
                >
                  <option value="">-- اختر مدير الفريق --</option>
                  {users.filter(u => u.role === UserRole.MANAGER || u.role === UserRole.SUPER_ADMIN).map(user => (
                    <option key={user.id} value={user.id}>{user.name} ({user.role})</option>
                  ))}
                </select>
              </div>

              <div className="bg-emerald-50 p-6 rounded-3xl border border-emerald-100 flex flex-row-reverse items-start gap-4">
                <UsersRound className="text-emerald-600 shrink-0" size={24} />
                <div className="text-right">
                  <h6 className="text-sm font-black text-emerald-900">ملاحظة</h6>
                  <p className="text-[10px] text-emerald-700 font-bold leading-relaxed">بعد إنشاء الفريق، يمكنك تعيين الموظفين إليه من خلال تعديل بيانات كل موظف.</p>
                </div>
              </div>

              <div className="flex flex-row-reverse gap-4 pt-4">
                <button type="submit" className="flex-1 bg-emerald-600 text-white py-5 rounded-2xl font-black text-sm shadow-xl shadow-emerald-600/20 hover:scale-105 transition-all flex items-center justify-center gap-2">
                  <Save size={18} /> {editingTeam ? 'حفظ التغييرات' : 'إضافة الفريق'}
                </button>
                <button type="button" onClick={() => setIsTeamModalOpen(false)} className="px-10 py-5 bg-slate-100 text-slate-500 rounded-2xl font-black text-sm">إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default UserManagement;
