
import React, { useState } from 'react';
import { LogIn, Shield, Mail, Lock, AlertCircle, Loader2 } from 'lucide-react';
import { authService } from '../services/authService';
import { User } from '../types';

interface LoginProps {
  onSuccess: (user: User) => void;
}

const Login: React.FC<LoginProps> = ({ onSuccess }) => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const user = await authService.login(email, password);
      onSuccess(user);
    } catch (err: any) {
      setError(err.message || 'فشل تسجيل الدخول');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-900 flex items-center justify-center p-6 font-sans rtl">
      <div className="max-w-md w-full bg-white rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-500">
        <div className="p-10">
          <div className="text-center mb-10">
            <div className="flex justify-center mb-6">
              <div className="bg-primary p-4 rounded-3xl shadow-xl shadow-primary/20">
                <Shield size={40} className="text-white" />
              </div>
            </div>
            <h1 className="text-2xl font-black text-slate-800">الهدف الأمثل للتسويق</h1>
            <p className="text-slate-400 font-bold mt-2">Hub المبيعات والتحليل الذكي</p>
          </div>

          <form onSubmit={handleLogin} className="space-y-6">
            <div className="space-y-2">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest mr-2 flex flex-row-reverse items-center gap-2">
                <Mail size={14} /> البريد الإلكتروني
              </label>
              <input 
                type="email" 
                required
                className="w-full px-6 py-4 bg-slate-50 border-2 border-transparent rounded-2xl text-sm font-medium focus:border-primary focus:bg-white transition-all outline-none text-right"
                placeholder="admin@optarget.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest mr-2 flex flex-row-reverse items-center gap-2">
                <Lock size={14} /> كلمة المرور
              </label>
              <input 
                type="password" 
                required
                className="w-full px-6 py-4 bg-slate-50 border-2 border-transparent rounded-2xl text-sm font-medium focus:border-primary focus:bg-white transition-all outline-none text-right"
                placeholder="••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
              <div className="text-left">
                 <button type="button" className="text-[10px] font-black text-primary hover:underline">نسيت كلمة المرور؟</button>
              </div>
            </div>

            {error && (
              <div className="p-4 bg-red-50 text-red-600 rounded-2xl border border-red-100 flex items-center gap-3 text-xs font-bold animate-in fade-in slide-in-from-top-2">
                <AlertCircle size={18} />
                {error}
              </div>
            )}

            <button 
              disabled={loading}
              className="w-full bg-primary hover:bg-primary/90 text-white font-black py-4 rounded-2xl shadow-xl shadow-primary/20 flex items-center justify-center gap-3 transition-all active:scale-95 disabled:opacity-50"
            >
              {loading ? <Loader2 className="animate-spin" size={20} /> : <LogIn size={20} />}
              دخول للنظام
            </button>
          </form>

          <div className="mt-10 pt-6 border-t border-slate-50 text-center">
            <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest">Version 2.1 Pro • Enterprise Ready</p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Login;
