
import React, { useEffect } from 'react';
import { X, AlertCircle, CheckCircle2, Info, AlertTriangle, Loader2, RefreshCw } from 'lucide-react';

// --- Loading Overlay ---
export const LoadingOverlay: React.FC<{ message: string }> = ({ message }) => (
  <div className="fixed inset-0 z-[200] flex flex-col items-center justify-center bg-white/90 backdrop-blur-md animate-in fade-in duration-300">
    <div className="relative">
      <div className="w-24 h-24 border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div>
      <div className="absolute inset-0 flex items-center justify-center">
        <div className="w-12 h-12 bg-primary/10 rounded-full animate-pulse"></div>
      </div>
    </div>
    <h3 className="mt-8 text-xl font-black text-slate-800 animate-pulse">{message}</h3>
    <p className="mt-2 text-slate-400 text-sm font-bold">يرجى الانتظار، الذكاء الاصطناعي يحلل البيانات...</p>
  </div>
);

// --- Error State ---
export const ErrorState: React.FC<{ title: string; message: string; onRetry?: () => void }> = ({ title, message, onRetry }) => (
  <div className="flex flex-col items-center justify-center py-20 text-center animate-in zoom-in-95">
    <div className="w-20 h-20 bg-red-50 text-red-500 rounded-[2rem] flex items-center justify-center mb-6 shadow-sm border border-red-100">
      <AlertCircle size={40} />
    </div>
    <h3 className="text-xl font-black text-slate-800">{title}</h3>
    <p className="text-sm text-slate-500 mt-2 max-w-sm font-medium leading-relaxed">{message}</p>
    {onRetry && (
      <button 
        onClick={onRetry}
        className="mt-8 bg-slate-900 text-white px-8 py-3 rounded-2xl font-black text-sm shadow-xl flex items-center gap-2 hover:scale-105 transition-all"
      >
        <RefreshCw size={18} /> إعادة المحاولة
      </button>
    )}
  </div>
);

// --- Toast System ---
interface ToastProps {
  message: string;
  type: 'success' | 'error' | 'info' | 'warning';
  onClose: () => void;
}

export const Toast: React.FC<ToastProps> = ({ message, type, onClose }) => {
  useEffect(() => {
    const timer = setTimeout(onClose, 5000);
    return () => clearTimeout(timer);
  }, [onClose]);

  const styles = {
    success: 'bg-green-50 border-green-200 text-green-800',
    error: 'bg-red-50 border-red-200 text-red-800',
    info: 'bg-blue-50 border-blue-200 text-blue-800',
    warning: 'bg-amber-50 border-amber-200 text-amber-800',
  };

  const Icons = {
    success: CheckCircle2,
    error: AlertCircle,
    info: Info,
    warning: AlertTriangle,
  };

  const Icon = Icons[type];

  return (
    <div className={`fixed bottom-8 left-8 z-[100] flex items-center gap-3 px-6 py-4 rounded-2xl border shadow-2xl animate-in slide-in-from-left-10 duration-300 ${styles[type]}`}>
      <Icon size={20} className="shrink-0" />
      <p className="text-sm font-black">{message}</p>
      <button onClick={onClose} className="p-1 hover:bg-black/5 rounded-full transition-colors">
        <X size={16} />
      </button>
    </div>
  );
};

// --- Empty State ---
interface EmptyStateProps {
  title: string;
  description: string;
  icon: React.ElementType;
  action?: { label: string; onClick: () => void };
}

export const EmptyState: React.FC<EmptyStateProps> = ({ title, description, icon: Icon, action }) => (
  <div className="flex flex-col items-center justify-center py-20 text-center animate-in fade-in zoom-in-95">
    <div className="w-24 h-24 bg-slate-50 rounded-[2.5rem] flex items-center justify-center mb-8 text-slate-300 shadow-inner">
      <Icon size={48} strokeWidth={1.5} />
    </div>
    <h3 className="text-xl font-black text-slate-800">{title}</h3>
    <p className="text-sm text-slate-500 mt-2 max-w-xs font-medium leading-relaxed">{description}</p>
    {action && (
      <button 
        onClick={action.onClick}
        className="mt-8 bg-slate-900 text-white px-10 py-3 rounded-2xl font-black text-sm shadow-xl hover:scale-105 transition-all"
      >
        {action.label}
      </button>
    )}
  </div>
);

// --- Skeleton Loaders ---
export const Skeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={`animate-pulse bg-slate-200 rounded-xl ${className}`}></div>
);
