/**
 * OP Target Sales Hub - Main Application
 * P0-2: BrowserRouter with proper URL-based routing
 */

import React, { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useNavigate, useLocation, Link } from 'react-router-dom';
import { 
  LayoutDashboard, Users, Settings, TrendingUp, LogOut, PlusCircle, Bell, Menu, X, ChevronLeft, Inbox, UserCog, Home
} from 'lucide-react';
import { UserRole, Lead, Report, User } from './types';
import { db } from './services/db';
import { authService } from './services/authService';
import ErrorBoundary from './components/ErrorBoundary';
import LeadForm from './components/LeadForm';
import ReportView from './components/ReportView';
import Dashboard from './components/Dashboard';
import LeadList from './components/LeadList';
import SettingsPanel from './components/SettingsPanel';
import Leaderboard from './components/Leaderboard';
import LeadDetails from './components/LeadDetails';
import SmartSurveyComponent from './components/SmartSurvey';
import Login from './components/Login';
import UserManagement from './components/UserManagement';
import ForceChangePassword from './components/ForceChangePassword';
import NotFound from './components/NotFound';
import { Toast, EmptyState } from './components/UI';

// Page title helper
const usePageTitle = (title: string) => {
  useEffect(() => {
    document.title = `${title} | OP Target`;
  }, [title]);
};

// Navigation items configuration
const NAV_ITEMS = [
  { path: '/dashboard', label: 'لوحة التحكم', icon: LayoutDashboard },
  { path: '/leads', label: 'العملاء', icon: Users },
  { path: '/users', label: 'المستخدمين', icon: UserCog, adminOnly: true },
  { path: '/leaderboard', label: 'المتصدرين', icon: TrendingUp },
  { path: '/leads/new', label: 'تقرير جديد', icon: PlusCircle },
  { path: '/settings', label: 'الإعدادات', icon: Settings, adminOnly: true },
];

// Main Layout Component with Sidebar
const MainLayout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const [currentUser, setCurrentUser] = useState<User | null>(authService.getCurrentUser());
  const [isSidebarOpen, setSidebarOpen] = useState(true);
  const [toast, setToast] = useState<{ message: string; type: any } | null>(null);

  const handleLogout = () => {
    authService.logout();
    navigate('/login');
  };

  const isActivePath = (path: string) => {
    if (path === '/dashboard') return location.pathname === '/dashboard' || location.pathname === '/';
    return location.pathname.startsWith(path);
  };

  // Get page title from path
  const getPageTitle = () => {
    if (location.pathname.startsWith('/leads/') && location.pathname !== '/leads/new') {
      if (location.pathname.includes('/survey')) return 'الاستبيان الذكي';
      return 'تفاصيل العميل';
    }
    if (location.pathname.startsWith('/reports/')) return 'التقرير الاستراتيجي';
    const item = NAV_ITEMS.find(i => isActivePath(i.path));
    return item?.label || 'النظام';
  };

  const showBackButton = location.pathname.startsWith('/leads/') || location.pathname.startsWith('/reports/');

  if (!currentUser) return null;

  return (
    <div className="flex h-screen bg-[#f8fafc] text-slate-900 overflow-hidden font-sans rtl">
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
      
      <aside className={`bg-slate-900 text-white transition-all duration-300 ${isSidebarOpen ? 'w-64' : 'w-20'} flex flex-col z-30 shadow-2xl`}>
        <div className="p-6 flex items-center justify-between border-b border-white/5">
          {isSidebarOpen && <Link to="/dashboard" className="text-lg font-black tracking-tight uppercase hover:text-primary transition-colors">OP Target</Link>}
          <button onClick={() => setSidebarOpen(!isSidebarOpen)} className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400">
            {isSidebarOpen ? <X size={20} /> : <Menu size={20} />}
          </button>
        </div>

        <nav className="flex-1 mt-6 px-3 space-y-1">
          {NAV_ITEMS.map((item) => (
            (!item.adminOnly || currentUser.role === UserRole.SUPER_ADMIN) && (
              <Link
                key={item.path}
                to={item.path}
                className={`w-full flex items-center p-3 rounded-2xl transition-all ${isActivePath(item.path) ? 'bg-primary text-white shadow-xl shadow-primary/30' : 'text-slate-400 hover:bg-white/5 hover:text-white'}`}
              >
                <item.icon size={20} className={isSidebarOpen ? 'ml-3' : 'mx-auto'} />
                {isSidebarOpen && <span className="font-bold text-sm">{item.label}</span>}
              </Link>
            )
          ))}
        </nav>

        <div className="p-4 border-t border-white/5">
          <div className={`flex items-center gap-3 p-3 mb-2 rounded-2xl bg-white/5 ${!isSidebarOpen && 'justify-center'}`}>
            <img src={currentUser.avatar} className="w-8 h-8 rounded-full border border-white/20" alt="" />
            {isSidebarOpen && (
              <div className="flex-1 overflow-hidden">
                <p className="text-xs font-black truncate">{currentUser.name}</p>
                <p className="text-[10px] text-slate-500 font-bold uppercase tracking-tighter">{currentUser.role}</p>
              </div>
            )}
          </div>
          <button onClick={handleLogout} className="w-full flex items-center p-3 text-red-400 hover:bg-red-500/10 rounded-2xl transition-colors">
            <LogOut size={18} className={isSidebarOpen ? 'ml-3' : 'mx-auto'} />
            {isSidebarOpen && <span className="text-sm font-bold">تسجيل خروج</span>}
          </button>
        </div>
      </aside>

      <main className="flex-1 flex flex-col overflow-hidden">
        <header className="bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 z-20 shadow-sm">
          <div className="flex items-center gap-4">
            {showBackButton && (
              <button onClick={() => navigate(-1)} className="p-2 hover:bg-slate-100 rounded-full text-slate-500">
                <ChevronLeft className="rtl:rotate-180" size={20} />
              </button>
            )}
            <h1 className="text-lg font-black text-slate-800">{getPageTitle()}</h1>
          </div>
          <div className="flex items-center gap-4">
            <div className="relative group">
              <Bell size={20} className="text-slate-400 cursor-pointer hover:text-primary transition-colors" />
              <div className="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></div>
            </div>
          </div>
        </header>

        <div className="flex-1 overflow-y-auto p-8">
          {children}
        </div>
      </main>
    </div>
  );
};

// Protected Route wrapper
const ProtectedRoute: React.FC<{ children: React.ReactNode; adminOnly?: boolean }> = ({ children, adminOnly = false }) => {
  const isAuthenticated = authService.isAuthenticated();
  const user = authService.getCurrentUser();
  
  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }
  
  if (user?.mustChangePassword) {
    return <Navigate to="/change-password" replace />;
  }
  
  if (adminOnly && user?.role !== UserRole.SUPER_ADMIN) {
    return <Navigate to="/dashboard" replace />;
  }
  
  return <MainLayout>{children}</MainLayout>;
};

// Page Components with proper routing
const DashboardPage: React.FC = () => {
  usePageTitle('لوحة التحكم');
  const [leads, setLeads] = useState<Lead[]>([]);
  const user = authService.getCurrentUser();
  
  useEffect(() => {
    db.seed();
    if (user) {
      db.getLeads(user).then(setLeads);
    }
  }, []);
  
  return <Dashboard leads={leads} />;
};

const LeadsPage: React.FC = () => {
  usePageTitle('العملاء');
  const navigate = useNavigate();
  const [leads, setLeads] = useState<Lead[]>([]);
  const user = authService.getCurrentUser();
  
  useEffect(() => {
    if (user) {
      db.getLeads(user).then(setLeads);
    }
  }, []);
  
  if (leads.length === 0) {
    return <EmptyState title="لا يوجد عملاء" description="ابدأ بإضافة أول عميل متوقع." icon={Inbox} action={{ label: 'إضافة عميل', onClick: () => navigate('/leads/new') }} />;
  }
  
  return <LeadList leads={leads} onSelect={(l) => navigate(`/leads/${l.id}`)} />;
};

const NewLeadPage: React.FC = () => {
  usePageTitle('تقرير جديد');
  const navigate = useNavigate();
  
  const handleSuccess = (lead: Lead, report: Report) => {
    navigate(`/reports/${report.id}`, { state: { lead, report } });
  };
  
  return <LeadForm onSuccess={handleSuccess} />;
};

const LeadDetailsPage: React.FC = () => {
  usePageTitle('تفاصيل العميل');
  const navigate = useNavigate();
  const location = useLocation();
  const [lead, setLead] = useState<Lead | null>(location.state?.lead || null);
  const [toast, setToast] = useState<{ message: string; type: any } | null>(null);
  const user = authService.getCurrentUser();
  
  // Get lead ID from URL
  const leadId = location.pathname.split('/leads/')[1]?.split('/')[0];
  
  useEffect(() => {
    if (!lead && leadId && user) {
      db.getLeads(user).then(leads => {
        const found = leads.find(l => l.id === leadId);
        if (found) setLead(found);
      });
    }
  }, [leadId, lead, user]);
  
  if (!lead) {
    return <div className="text-center py-12 text-slate-500">جاري التحميل...</div>;
  }
  
  return (
    <>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
      <LeadDetails 
        lead={lead} 
        onUpdateLead={async (updated) => { 
          await db.saveLead(updated); 
          setLead(updated); 
          setToast({ message: 'تم التحديث', type: 'success' });
        }}
        onDeleteLead={async (id) => {
          if (user) {
            await db.deleteLead(id, user.id);
            navigate('/leads');
          }
        }}
        onGenerateSurvey={() => navigate(`/leads/${lead.id}/survey`, { state: { lead } })}
        onViewReport={(r) => navigate(`/reports/${r.id}`, { state: { lead, report: r } })}
      />
    </>
  );
};

const SurveyPage: React.FC = () => {
  usePageTitle('الاستبيان الذكي');
  const navigate = useNavigate();
  const location = useLocation();
  const lead = location.state?.lead;
  
  if (!lead) {
    return <Navigate to="/leads" replace />;
  }
  
  return <SmartSurveyComponent lead={lead} onFinish={(r) => navigate(`/reports/${r.id}`, { state: { lead, report: r } })} />;
};

const ReportPage: React.FC = () => {
  usePageTitle('التقرير الاستراتيجي');
  const location = useLocation();
  const { lead, report } = location.state || {};
  const user = authService.getCurrentUser();
  
  if (!lead || !report) {
    return <Navigate to="/leads" replace />;
  }
  
  return <ReportView lead={lead} report={report} userRole={user?.role || UserRole.SALES_REP} />;
};

const LoginPage: React.FC = () => {
  usePageTitle('تسجيل الدخول');
  const navigate = useNavigate();
  
  const handleSuccess = (user: User) => {
    if (user.mustChangePassword) {
      navigate('/change-password', { state: { user } });
    } else {
      navigate('/dashboard');
    }
  };
  
  // If already authenticated, redirect
  if (authService.isAuthenticated()) {
    return <Navigate to="/dashboard" replace />;
  }
  
  return <Login onSuccess={handleSuccess} />;
};

const ChangePasswordPage: React.FC = () => {
  usePageTitle('تغيير كلمة المرور');
  const navigate = useNavigate();
  const user = authService.getCurrentUser();
  
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  
  return (
    <ForceChangePassword 
      user={user}
      onSuccess={() => navigate('/dashboard')}
    />
  );
};

// Main App with Router
const App: React.FC = () => {
  return (
    <BrowserRouter>
      <Routes>
        {/* Public routes */}
        <Route path="/login" element={<LoginPage />} />
        <Route path="/change-password" element={<ChangePasswordPage />} />
        
        {/* Protected routes */}
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<ProtectedRoute><DashboardPage /></ProtectedRoute>} />
        <Route path="/leads" element={<ProtectedRoute><LeadsPage /></ProtectedRoute>} />
        <Route path="/leads/new" element={<ProtectedRoute><NewLeadPage /></ProtectedRoute>} />
        <Route path="/leads/:id" element={<ProtectedRoute><LeadDetailsPage /></ProtectedRoute>} />
        <Route path="/leads/:id/survey" element={<ProtectedRoute><SurveyPage /></ProtectedRoute>} />
        <Route path="/reports/:id" element={<ProtectedRoute><ReportPage /></ProtectedRoute>} />
        <Route path="/leaderboard" element={<ProtectedRoute><Leaderboard /></ProtectedRoute>} />
        <Route path="/settings" element={<ProtectedRoute adminOnly><SettingsPanel /></ProtectedRoute>} />
        <Route path="/users" element={<ProtectedRoute adminOnly><UserManagement /></ProtectedRoute>} />
        
        {/* 404 */}
        <Route path="*" element={<NotFound />} />
      </Routes>
    </BrowserRouter>
  );
};

// Wrap App with ErrorBoundary for global error handling
const AppWithErrorBoundary: React.FC = () => (
  <ErrorBoundary>
    <App />
  </ErrorBoundary>
);

export default AppWithErrorBoundary;
