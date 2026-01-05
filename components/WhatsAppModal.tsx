
import React, { useState, useEffect } from 'react';
import { X, Phone, Send, CheckCircle2, Loader2, MessageCircle } from 'lucide-react';
import { whatsappService } from '../services/whatsappService';
import { authService } from '../services/authService';

interface WhatsAppModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSend: (phone: string) => void; // Keep for compatibility with parent components if needed, or refactor
  initialPhone?: string;
  message: string;
  leadId: string;
}

const WhatsAppModal: React.FC<WhatsAppModalProps> = ({ isOpen, onClose, initialPhone = '', message, leadId }) => {
  const [phone, setPhone] = useState(initialPhone);
  const [loading, setLoading] = useState(false);
  const [success, setSuccess] = useState(false);
  const user = authService.getCurrentUser();

  // Reset state on open
  useEffect(() => {
    if (isOpen) {
      setSuccess(false);
      setLoading(false);
      setPhone(initialPhone);
    }
  }, [isOpen, initialPhone]);

  const handleSend = async () => {
    if (!phone || !user) return;
    setLoading(true);
    try {
      await whatsappService.sendMessage(phone, message, leadId, user.id);
      setSuccess(true);
      setTimeout(() => {
        onClose();
      }, 2000);
    } catch (e: any) {
      alert(e.message);
    } finally {
      setLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-[150] flex items-center justify-center p-6 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200 text-right">
      <div className="bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200">
        <div className="p-8 border-b border-slate-100 flex justify-between items-center bg-slate-50">
          <button onClick={onClose} className="p-2 hover:bg-slate-200 rounded-full text-slate-400 transition-colors">
            <X size={20} />
          </button>
          <div className="flex items-center gap-3">
            <h3 className="text-lg font-black text-slate-800">إرسال عبر الواتساب</h3>
            <div className="bg-green-100 text-green-600 p-2 rounded-xl"><Send size={18} /></div>
          </div>
        </div>
        
        <div className="p-10 space-y-6">
          {success ? (
            <div className="flex flex-col items-center justify-center py-6 text-center animate-in zoom-in-95">
              <div className="w-20 h-20 bg-green-50 text-green-500 rounded-full flex items-center justify-center mb-4 shadow-inner border border-green-100">
                <CheckCircle2 size={40} />
              </div>
              <h4 className="text-xl font-black text-slate-800">تم الإرسال بنجاح!</h4>
              <p className="text-sm text-slate-400 font-bold mt-2">تم تسجيل العملية في سجل نشاطات العميل.</p>
            </div>
          ) : (
            <>
              <div className="space-y-2">
                <label className="text-xs font-black text-slate-500 uppercase tracking-widest">رقم الجوال (WhatsApp)</label>
                <div className="relative">
                  <Phone className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                  <input 
                    autoFocus
                    type="text" 
                    placeholder="9665xxxxxxxx" 
                    className="w-full pr-12 pl-6 py-4 bg-slate-100 border-none rounded-2xl text-sm font-mono focus:ring-2 focus:ring-green-500/20 outline-none text-left" 
                    value={phone}
                    onChange={e => setPhone(e.target.value)}
                  />
                </div>
                <p className="text-[10px] text-slate-400 font-bold">أدخل الرقم بالصيغة الدولية بدون (+) أو أصفار إضافية.</p>
              </div>

              <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100">
                <div className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 flex flex-row-reverse items-center gap-2">
                   <MessageCircle size={12} /> معاينة الرسالة
                </div>
                <p className="text-xs text-slate-600 font-bold leading-relaxed line-clamp-3 italic">"{message}"</p>
              </div>

              <button 
                onClick={handleSend}
                disabled={!phone || loading}
                className="w-full bg-green-600 hover:bg-green-700 disabled:bg-slate-200 text-white font-black py-4 rounded-2xl shadow-xl shadow-green-600/20 flex items-center justify-center gap-3 transition-all active:scale-95 disabled:opacity-50"
              >
                {loading ? <Loader2 className="animate-spin" size={20} /> : <Send size={20} />}
                إرسال الرسالة الآن
              </button>
              
              <button 
                onClick={() => {
                  navigator.clipboard.writeText(message);
                  alert('تم نسخ الرسالة للحافظة');
                  onClose();
                }}
                className="w-full text-slate-400 font-bold text-xs hover:text-slate-600"
              >
                اكتفِ بنسخ الرسالة فقط
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default WhatsAppModal;
