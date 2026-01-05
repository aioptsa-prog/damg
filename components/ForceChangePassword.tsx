
import React, { useState } from 'react';
import { Lock, Shield, AlertTriangle, Loader2, CheckCircle2, Eye, EyeOff } from 'lucide-react';
import { User } from '../types';

interface ForceChangePasswordProps {
  user: User;
  onSuccess: (updatedUser: User) => void;
}

/**
 * P0 FIX: Force Change Password Screen
 * Displayed when user.mustChangePassword is true
 * User cannot access the app until they change their password
 */
const ForceChangePassword: React.FC<ForceChangePasswordProps> = ({ user, onSuccess }) => {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);

  const validatePassword = (password: string): string | null => {
    if (password.length < 8) {
      return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    }
    if (!/[A-Z]/.test(password)) {
      return 'كلمة المرور يجب أن تحتوي على حرف كبير واحد على الأقل';
    }
    if (!/[a-z]/.test(password)) {
      return 'كلمة المرور يجب أن تحتوي على حرف صغير واحد على الأقل';
    }
    if (!/[0-9]/.test(password)) {
      return 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل';
    }
    return null;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    // Validate new password
    const validationError = validatePassword(newPassword);
    if (validationError) {
      setError(validationError);
      return;
    }

    // Check passwords match
    if (newPassword !== confirmPassword) {
      setError('كلمات المرور غير متطابقة');
      return;
    }

    // Check not same as current
    if (currentPassword === newPassword) {
      setError('كلمة المرور الجديدة يجب أن تكون مختلفة عن الحالية');
      return;
    }

    setLoading(true);

    try {
      const response = await fetch('/api/password?action=change', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ currentPassword, newPassword }),
        credentials: 'include'
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || data.message || 'فشل تغيير كلمة المرور');
      }

      // Success - update user and proceed
      onSuccess({ ...user, mustChangePassword: false } as User);

    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء تغيير كلمة المرور');
    } finally {
      setLoading(false);
    }
  };

  const getPasswordStrength = (password: string): { level: number; text: string; color: string } => {
    let score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    if (score <= 2) return { level: 1, text: 'ضعيفة', color: 'bg-red-500' };
    if (score <= 4) return { level: 2, text: 'متوسطة', color: 'bg-yellow-500' };
    return { level: 3, text: 'قوية', color: 'bg-green-500' };
  };

  const strength = getPasswordStrength(newPassword);

  return (
    <div className="min-h-screen bg-slate-900 flex items-center justify-center p-6 font-sans rtl">
      <div className="max-w-md w-full bg-white rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-500">
        <div className="p-10">
          {/* Header */}
          <div className="text-center mb-8">
            <div className="flex justify-center mb-6">
              <div className="bg-amber-500 p-4 rounded-3xl shadow-xl shadow-amber-500/20">
                <AlertTriangle size={40} className="text-white" />
              </div>
            </div>
            <h1 className="text-2xl font-black text-slate-800">تغيير كلمة المرور مطلوب</h1>
            <p className="text-slate-400 font-bold mt-2">
              مرحباً {user.name}، يجب تغيير كلمة المرور قبل المتابعة
            </p>
          </div>

          {/* Security Notice */}
          <div className="bg-amber-50 border border-amber-100 p-4 rounded-2xl mb-6 flex flex-row-reverse gap-3 items-start">
            <Shield className="text-amber-600 shrink-0" size={20} />
            <p className="text-xs text-amber-800 font-bold text-right">
              لأسباب أمنية، يجب تغيير كلمة المرور المؤقتة. اختر كلمة مرور قوية تحتوي على حروف كبيرة وصغيرة وأرقام.
            </p>
          </div>

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-5">
            {/* Current Password */}
            <div className="space-y-2">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest mr-2 flex flex-row-reverse items-center gap-2">
                <Lock size={14} /> كلمة المرور الحالية
              </label>
              <div className="relative">
                <input
                  type={showCurrent ? 'text' : 'password'}
                  required
                  className="w-full px-6 py-4 bg-slate-50 border-2 border-transparent rounded-2xl text-sm font-medium focus:border-primary focus:bg-white transition-all outline-none text-right pr-12"
                  placeholder="أدخل كلمة المرور الحالية"
                  value={currentPassword}
                  onChange={(e) => setCurrentPassword(e.target.value)}
                />
                <button
                  type="button"
                  onClick={() => setShowCurrent(!showCurrent)}
                  className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                >
                  {showCurrent ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </div>

            {/* New Password */}
            <div className="space-y-2">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest mr-2 flex flex-row-reverse items-center gap-2">
                <Lock size={14} /> كلمة المرور الجديدة
              </label>
              <div className="relative">
                <input
                  type={showNew ? 'text' : 'password'}
                  required
                  className="w-full px-6 py-4 bg-slate-50 border-2 border-transparent rounded-2xl text-sm font-medium focus:border-primary focus:bg-white transition-all outline-none text-right pr-12"
                  placeholder="أدخل كلمة المرور الجديدة"
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                />
                <button
                  type="button"
                  onClick={() => setShowNew(!showNew)}
                  className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                >
                  {showNew ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              
              {/* Password Strength Indicator */}
              {newPassword && (
                <div className="space-y-2 mt-2">
                  <div className="flex gap-1">
                    {[1, 2, 3].map((level) => (
                      <div
                        key={level}
                        className={`h-1.5 flex-1 rounded-full transition-all ${
                          level <= strength.level ? strength.color : 'bg-slate-200'
                        }`}
                      />
                    ))}
                  </div>
                  <p className={`text-[10px] font-bold text-right ${
                    strength.level === 1 ? 'text-red-500' : 
                    strength.level === 2 ? 'text-yellow-600' : 'text-green-600'
                  }`}>
                    قوة كلمة المرور: {strength.text}
                  </p>
                </div>
              )}
            </div>

            {/* Confirm Password */}
            <div className="space-y-2">
              <label className="text-xs font-black text-slate-500 uppercase tracking-widest mr-2 flex flex-row-reverse items-center gap-2">
                <CheckCircle2 size={14} /> تأكيد كلمة المرور
              </label>
              <input
                type="password"
                required
                className={`w-full px-6 py-4 bg-slate-50 border-2 rounded-2xl text-sm font-medium focus:bg-white transition-all outline-none text-right ${
                  confirmPassword && confirmPassword !== newPassword 
                    ? 'border-red-300 focus:border-red-500' 
                    : 'border-transparent focus:border-primary'
                }`}
                placeholder="أعد إدخال كلمة المرور الجديدة"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
              />
              {confirmPassword && confirmPassword !== newPassword && (
                <p className="text-[10px] text-red-500 font-bold text-right">كلمات المرور غير متطابقة</p>
              )}
            </div>

            {/* Error Message */}
            {error && (
              <div className="p-4 bg-red-50 text-red-600 rounded-2xl border border-red-100 flex items-center gap-3 text-xs font-bold animate-in fade-in slide-in-from-top-2">
                <AlertTriangle size={18} />
                {error}
              </div>
            )}

            {/* Submit Button */}
            <button
              type="submit"
              disabled={loading || !currentPassword || !newPassword || !confirmPassword}
              className="w-full bg-primary hover:bg-primary/90 text-white font-black py-4 rounded-2xl shadow-xl shadow-primary/20 flex items-center justify-center gap-3 transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? <Loader2 className="animate-spin" size={20} /> : <Lock size={20} />}
              تغيير كلمة المرور والمتابعة
            </button>
          </form>

          {/* Footer */}
          <div className="mt-8 pt-6 border-t border-slate-50 text-center">
            <p className="text-[10px] text-slate-400 font-black uppercase tracking-widest">
              Security First • OP Target Sales Hub
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ForceChangePassword;
