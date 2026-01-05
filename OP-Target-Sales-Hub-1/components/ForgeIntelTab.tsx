/**
 * Forge Intel Tab Component
 * 
 * Unified UI for forge integration within Lead details.
 * Contains: Link Status, Survey Generation, WhatsApp Send
 * 
 * @since Phase 5
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  Link2,
  Unlink,
  RefreshCw,
  Send,
  Eye,
  Loader2,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Sparkles,
  MessageSquare,
  Phone,
  MapPin,
  Building2,
  Clock,
  Zap,
} from 'lucide-react';
import {
  integrationClient,
  ForgeLink,
  ForgeSurveyReport,
  WhatsAppSendResult,
  EnrichJob,
  LeadSnapshot,
  isError,
  getErrorMessage,
} from '../services/integrationClient';

interface ForgeIntelTabProps {
  leadId: string;
  leadPhone?: string;
  leadName?: string;
}

type LoadingState = 'idle' | 'loading' | 'success' | 'error';

interface StatusBadgeProps {
  state: LoadingState;
  successText?: string;
  errorText?: string;
}

const StatusBadge: React.FC<StatusBadgeProps> = ({ state, successText, errorText }) => {
  if (state === 'loading') {
    return (
      <span className="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 text-blue-600 rounded-full text-xs font-bold">
        <Loader2 size={12} className="animate-spin" /> جاري التحميل...
      </span>
    );
  }
  if (state === 'success') {
    return (
      <span className="inline-flex items-center gap-1 px-3 py-1 bg-green-50 text-green-600 rounded-full text-xs font-bold">
        <CheckCircle2 size={12} /> {successText || 'نجح'}
      </span>
    );
  }
  if (state === 'error') {
    return (
      <span className="inline-flex items-center gap-1 px-3 py-1 bg-red-50 text-red-600 rounded-full text-xs font-bold">
        <XCircle size={12} /> {errorText || 'فشل'}
      </span>
    );
  }
  return null;
};

const ForgeIntelTab: React.FC<ForgeIntelTabProps> = ({ leadId, leadPhone, leadName }) => {
  // Link state
  const [link, setLink] = useState<ForgeLink | null>(null);
  const [linkState, setLinkState] = useState<LoadingState>('idle');
  const [linkError, setLinkError] = useState<string>('');
  const [linkInput, setLinkInput] = useState({ forgeLeadId: '', phone: '' });
  const [showLinkForm, setShowLinkForm] = useState(false);

  // Survey state
  const [survey, setSurvey] = useState<ForgeSurveyReport | null>(null);
  const [surveyState, setSurveyState] = useState<LoadingState>('idle');
  const [surveyError, setSurveyError] = useState<string>('');
  const [surveyCached, setSurveyCached] = useState(false);

  // WhatsApp state
  const [message, setMessage] = useState('');
  const [sendState, setSendState] = useState<LoadingState>('idle');
  const [sendError, setSendError] = useState<string>('');
  const [sendResult, setSendResult] = useState<WhatsAppSendResult | null>(null);
  const [previewResult, setPreviewResult] = useState<WhatsAppSendResult | null>(null);

  // Phase 6: Enrichment state
  // Phase 7: Added google_web module
  const [selectedModules, setSelectedModules] = useState<string[]>(['maps', 'website', 'google_web']);
  const [enrichJob, setEnrichJob] = useState<EnrichJob | null>(null);
  const [enrichState, setEnrichState] = useState<LoadingState>('idle');
  const [enrichError, setEnrichError] = useState<string>('');
  const [snapshot, setSnapshot] = useState<LeadSnapshot | null>(null);
  const [snapshotState, setSnapshotState] = useState<LoadingState>('idle');
  const [pollingInterval, setPollingInterval] = useState<NodeJS.Timeout | null>(null);

  // Load link status on mount
  const loadLink = useCallback(async () => {
    setLinkState('loading');
    setLinkError('');
    
    const result = await integrationClient.getLink(leadId);
    
    if (isError(result)) {
      setLinkState('error');
      setLinkError(getErrorMessage(result));
      return;
    }
    
    setLink(result.link);
    setLinkState('success');
  }, [leadId]);

  useEffect(() => {
    loadLink();
  }, [loadLink]);

  // Create link
  const handleCreateLink = async () => {
    if (!linkInput.forgeLeadId && !linkInput.phone) {
      setLinkError('أدخل معرف Forge أو رقم الهاتف');
      return;
    }

    setLinkState('loading');
    setLinkError('');

    const result = await integrationClient.createLink({
      opLeadId: leadId,
      forgeLeadId: linkInput.forgeLeadId || linkInput.phone,
      forgePhone: linkInput.phone || leadPhone,
      forgeName: leadName,
    });

    if (isError(result)) {
      setLinkState('error');
      setLinkError(getErrorMessage(result));
      return;
    }

    setLink(result.link);
    setLinkState('success');
    setShowLinkForm(false);
    setLinkInput({ forgeLeadId: '', phone: '' });
  };

  // Remove link
  const handleRemoveLink = async () => {
    if (!confirm('هل تريد إلغاء الربط؟')) return;

    setLinkState('loading');
    const result = await integrationClient.removeLink(leadId);

    if (isError(result)) {
      setLinkState('error');
      setLinkError(getErrorMessage(result));
      return;
    }

    setLink(null);
    setSurvey(null);
    setMessage('');
    setLinkState('success');
  };

  // Generate survey
  const handleGenerateSurvey = async (force = false) => {
    if (!link) {
      setSurveyError('يجب ربط العميل بـ Forge أولاً');
      return;
    }

    setSurveyState('loading');
    setSurveyError('');

    const result = await integrationClient.generateSurvey(leadId, force);

    if (isError(result)) {
      setSurveyState('error');
      setSurveyError(getErrorMessage(result));
      return;
    }

    setSurvey(result.report);
    setSurveyCached(result.cached);
    setSurveyState('success');

    // Auto-fill message from survey
    const suggestedMsg = result.report.suggested_message || 
                         result.report.output?.suggested_message || '';
    if (suggestedMsg) {
      setMessage(suggestedMsg);
    }
  };

  // Preview WhatsApp
  const handlePreview = async () => {
    setSendState('loading');
    setSendError('');
    setPreviewResult(null);

    const result = await integrationClient.previewWhatsApp(leadId, message || undefined);

    if (isError(result)) {
      setSendState('error');
      setSendError(getErrorMessage(result));
      return;
    }

    setPreviewResult(result);
    setSendState('idle');
  };

  // Send WhatsApp
  const handleSend = async () => {
    if (!message.trim()) {
      setSendError('أدخل رسالة للإرسال');
      return;
    }

    if (!confirm('هل تريد إرسال الرسالة؟')) return;

    setSendState('loading');
    setSendError('');
    setSendResult(null);

    const result = await integrationClient.sendWhatsApp({
      opLeadId: leadId,
      message: message.trim(),
    });

    if (isError(result)) {
      setSendState('error');
      setSendError(getErrorMessage(result));
      return;
    }

    setSendResult(result);
    setSendState('success');
  };

  // Phase 6: Start enrichment job
  const handleStartEnrich = async (force = false) => {
    if (!link) {
      setEnrichError('يجب ربط العميل بـ Forge أولاً');
      return;
    }

    setEnrichState('loading');
    setEnrichError('');

    const result = await integrationClient.startEnrich({
      opLeadId: leadId,
      modules: selectedModules,
      force,
    });

    if (isError(result)) {
      setEnrichState('error');
      setEnrichError(getErrorMessage(result));
      return;
    }

    // Start polling for status
    setEnrichJob({ 
      jobId: result.jobId, 
      status: 'queued', 
      progress: 0, 
      modules: selectedModules.map(m => ({ module: m, status: 'pending', attempt: 0 }))
    });
    setEnrichState('success');
    startPolling(result.jobId);
  };

  // Poll for job status
  const startPolling = (jobId: string) => {
    if (pollingInterval) clearInterval(pollingInterval);
    
    const interval = setInterval(async () => {
      const result = await integrationClient.getEnrichStatus(jobId);
      
      if (isError(result)) {
        clearInterval(interval);
        setPollingInterval(null);
        return;
      }

      setEnrichJob(result);

      // Stop polling if job is done
      if (['success', 'partial', 'failed', 'cancelled'].includes(result.status)) {
        clearInterval(interval);
        setPollingInterval(null);
        // Load snapshot after completion
        if (result.status === 'success' || result.status === 'partial') {
          loadSnapshot();
        }
      }
    }, 2000);

    setPollingInterval(interval);
  };

  // Load snapshot
  const loadSnapshot = async () => {
    setSnapshotState('loading');
    
    const result = await integrationClient.getSnapshot(leadId);
    
    if (isError(result)) {
      setSnapshotState('error');
      return;
    }

    setSnapshot(result);
    setSnapshotState('success');
  };

  // Load snapshot on mount if linked
  useEffect(() => {
    if (link) {
      loadSnapshot();
    }
  }, [link]);

  // Cleanup polling on unmount
  useEffect(() => {
    return () => {
      if (pollingInterval) clearInterval(pollingInterval);
    };
  }, [pollingInterval]);

  // Module toggle
  const toggleModule = (module: string) => {
    setSelectedModules(prev => 
      prev.includes(module) 
        ? prev.filter(m => m !== module)
        : [...prev, module]
    );
  };

  return (
    <div className="space-y-8 text-right">
      {/* ==================== Link Status Card ==================== */}
      <div className="bg-gradient-to-br from-slate-50 to-slate-100/50 rounded-3xl p-8 border border-slate-200">
        <div className="flex flex-row-reverse items-center justify-between mb-6">
          <div className="flex flex-row-reverse items-center gap-3">
            <div className="p-3 bg-white rounded-2xl shadow-sm">
              <Link2 size={24} className="text-primary" />
            </div>
            <div>
              <h3 className="font-black text-slate-800 text-lg">حالة الربط</h3>
              <p className="text-xs text-slate-500">ربط العميل بـ Forge للحصول على بيانات إضافية</p>
            </div>
          </div>
          <StatusBadge 
            state={linkState} 
            successText={link ? 'مربوط' : 'غير مربوط'}
            errorText={linkError}
          />
        </div>

        {link ? (
          <div className="space-y-4">
            {/* Link Info */}
            <div className="bg-white rounded-2xl p-6 border border-slate-100">
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div className="flex flex-row-reverse items-center gap-2">
                  <Building2 size={16} className="text-slate-400" />
                  <span className="font-bold text-slate-700">{link.external_name || 'غير متوفر'}</span>
                </div>
                <div className="flex flex-row-reverse items-center gap-2">
                  <Phone size={16} className="text-slate-400" />
                  <span className="font-bold text-slate-700 dir-ltr">{link.external_phone || 'غير متوفر'}</span>
                </div>
                <div className="flex flex-row-reverse items-center gap-2">
                  <MapPin size={16} className="text-slate-400" />
                  <span className="font-bold text-slate-700">{link.external_city || 'غير متوفر'}</span>
                </div>
                <div className="flex flex-row-reverse items-center gap-2">
                  <Clock size={16} className="text-slate-400" />
                  <span className="font-bold text-slate-500 text-xs">
                    {new Date(link.linked_at).toLocaleDateString('ar-SA')}
                  </span>
                </div>
              </div>
              <div className="mt-4 pt-4 border-t border-slate-100 flex flex-row-reverse items-center justify-between">
                <span className="text-xs text-slate-400">Forge ID: {link.external_lead_id}</span>
                <button
                  onClick={handleRemoveLink}
                  className="text-red-500 hover:text-red-600 text-xs font-bold flex flex-row-reverse items-center gap-1"
                >
                  <Unlink size={14} /> إلغاء الربط
                </button>
              </div>
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            {!showLinkForm ? (
              <button
                onClick={() => setShowLinkForm(true)}
                className="w-full py-4 bg-white border-2 border-dashed border-slate-200 rounded-2xl text-slate-500 font-bold hover:border-primary hover:text-primary transition-all flex flex-row-reverse items-center justify-center gap-2"
              >
                <Link2 size={18} /> ربط بـ Forge
              </button>
            ) : (
              <div className="bg-white rounded-2xl p-6 border border-slate-100 space-y-4">
                <div className="space-y-2">
                  <label className="text-xs font-bold text-slate-500">معرف Forge Lead</label>
                  <input
                    type="text"
                    value={linkInput.forgeLeadId}
                    onChange={(e) => setLinkInput({ ...linkInput, forgeLeadId: e.target.value })}
                    placeholder="مثال: 12345"
                    className="w-full px-4 py-3 bg-slate-50 rounded-xl text-sm border-none outline-none"
                    dir="ltr"
                  />
                </div>
                <div className="text-center text-slate-400 text-xs font-bold">أو</div>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-slate-500">رقم الهاتف</label>
                  <input
                    type="text"
                    value={linkInput.phone}
                    onChange={(e) => setLinkInput({ ...linkInput, phone: e.target.value })}
                    placeholder="مثال: 0501234567"
                    className="w-full px-4 py-3 bg-slate-50 rounded-xl text-sm border-none outline-none"
                    dir="ltr"
                  />
                </div>
                {linkError && (
                  <div className="text-red-500 text-xs font-bold flex flex-row-reverse items-center gap-1">
                    <AlertCircle size={14} /> {linkError}
                  </div>
                )}
                <div className="flex flex-row-reverse gap-2">
                  <button
                    onClick={handleCreateLink}
                    disabled={linkState === 'loading'}
                    className="flex-1 py-3 bg-primary text-white rounded-xl font-bold text-sm flex flex-row-reverse items-center justify-center gap-2 disabled:opacity-50"
                  >
                    {linkState === 'loading' ? <Loader2 size={16} className="animate-spin" /> : <Link2 size={16} />}
                    ربط
                  </button>
                  <button
                    onClick={() => { setShowLinkForm(false); setLinkError(''); }}
                    className="px-6 py-3 bg-slate-100 text-slate-600 rounded-xl font-bold text-sm"
                  >
                    إلغاء
                  </button>
                </div>
              </div>
            )}
          </div>
        )}
      </div>

      {/* ==================== Phase 6: Enrichment Panel ==================== */}
      <div className="bg-gradient-to-br from-cyan-50/50 to-blue-50/50 rounded-3xl p-8 border border-cyan-100">
        <div className="flex flex-row-reverse items-center justify-between mb-6">
          <div className="flex flex-row-reverse items-center gap-3">
            <div className="p-3 bg-white rounded-2xl shadow-sm">
              <Zap size={24} className="text-cyan-600" />
            </div>
            <div>
              <h3 className="font-black text-slate-800 text-lg">جمع البيانات (Worker)</h3>
              <p className="text-xs text-slate-500">جمع بيانات إضافية من مصادر متعددة</p>
            </div>
          </div>
          {enrichJob && (
            <span className={`px-3 py-1 rounded-full text-xs font-bold ${
              enrichJob.status === 'success' ? 'bg-green-50 text-green-600' :
              enrichJob.status === 'partial' ? 'bg-amber-50 text-amber-600' :
              enrichJob.status === 'failed' ? 'bg-red-50 text-red-600' :
              'bg-blue-50 text-blue-600'
            }`}>
              {enrichJob.status === 'queued' ? 'في الانتظار' :
               enrichJob.status === 'running' ? `${enrichJob.progress}%` :
               enrichJob.status === 'success' ? 'اكتمل' :
               enrichJob.status === 'partial' ? 'جزئي' :
               enrichJob.status === 'failed' ? 'فشل' : enrichJob.status}
            </span>
          )}
        </div>

        {!link ? (
          <div className="text-center py-8 text-slate-400">
            <AlertCircle size={32} className="mx-auto mb-2 opacity-50" />
            <p className="font-bold">يجب ربط العميل بـ Forge أولاً</p>
          </div>
        ) : (
          <div className="space-y-4">
            {/* Module Selection */}
            <div className="bg-white rounded-2xl p-4 border border-slate-100">
              <label className="text-xs font-bold text-slate-500 mb-3 block">اختر المصادر</label>
              <div className="flex flex-row-reverse flex-wrap gap-2">
                {[
                  { id: 'maps', label: 'خرائط Google', icon: '🗺️' },
                  { id: 'website', label: 'الموقع الإلكتروني', icon: '🌐' },
                  { id: 'google_web', label: 'بحث Google', icon: '🔍' },
                ].map(mod => (
                  <button
                    key={mod.id}
                    onClick={() => toggleModule(mod.id)}
                    disabled={enrichJob?.status === 'running' || enrichJob?.status === 'queued'}
                    className={`px-4 py-2 rounded-xl text-sm font-bold flex items-center gap-2 transition-all ${
                      selectedModules.includes(mod.id)
                        ? 'bg-cyan-100 text-cyan-700 border-2 border-cyan-300'
                        : 'bg-slate-50 text-slate-500 border-2 border-transparent'
                    } disabled:opacity-50`}
                  >
                    <span>{mod.icon}</span> {mod.label}
                  </button>
                ))}
              </div>
            </div>

            {/* Progress */}
            {enrichJob && ['queued', 'running'].includes(enrichJob.status) && (
              <div className="bg-white rounded-2xl p-4 border border-slate-100">
                <div className="flex flex-row-reverse items-center justify-between mb-2">
                  <span className="text-xs font-bold text-slate-500">التقدم</span>
                  <span className="text-xs font-bold text-cyan-600">{enrichJob.progress}%</span>
                </div>
                <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div 
                    className="bg-cyan-500 h-full rounded-full transition-all duration-500"
                    style={{ width: `${enrichJob.progress}%` }}
                  />
                </div>
                {/* Module statuses */}
                <div className="mt-3 space-y-1">
                  {enrichJob.modules?.map(mod => (
                    <div key={mod.module} className="flex flex-row-reverse items-center justify-between text-xs">
                      <span className="font-bold text-slate-600">
                        {mod.module === 'google_web' ? 'بحث Google' : 
                         mod.module === 'maps' ? 'خرائط' : 
                         mod.module === 'website' ? 'الموقع' : mod.module}
                      </span>
                      <div className="flex items-center gap-2">
                        <span className={`font-bold ${
                          mod.status === 'success' ? 'text-green-600' :
                          mod.status === 'failed' ? 'text-red-600' :
                          mod.status === 'skipped' ? 'text-amber-600' :
                          mod.status === 'running' ? 'text-blue-600' :
                          'text-slate-400'
                        }`}>
                          {mod.status === 'pending' ? '⏳' :
                           mod.status === 'running' ? '🔄' :
                           mod.status === 'success' ? '✓' :
                           mod.status === 'failed' ? '✗' :
                           mod.status === 'skipped' ? '⏭️' : mod.status}
                        </span>
                        {/* Phase 7: Show error/skip reason */}
                        {(mod.status === 'failed' || mod.status === 'skipped') && mod.error_code && (
                          <span className="text-[10px] text-slate-400">
                            ({mod.error_code === 'no_api_key' ? 'مفتاح API مفقود' :
                              mod.error_code === 'blocked' ? 'محظور' :
                              mod.error_code === 'caps_exceeded' ? 'تجاوز الحد' :
                              mod.error_code})
                          </span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {enrichError && (
              <div className="text-red-500 text-sm font-bold flex flex-row-reverse items-center gap-1 bg-red-50 p-3 rounded-xl">
                <AlertCircle size={16} /> {enrichError}
              </div>
            )}

            {/* Action Buttons */}
            <div className="flex flex-row-reverse gap-2">
              <button
                onClick={() => handleStartEnrich(false)}
                disabled={enrichState === 'loading' || selectedModules.length === 0 || enrichJob?.status === 'running'}
                className="flex-1 py-3 bg-cyan-600 text-white rounded-xl font-bold text-sm flex flex-row-reverse items-center justify-center gap-2 disabled:opacity-50 hover:bg-cyan-700 transition-all"
              >
                {enrichJob?.status === 'running' ? (
                  <><Loader2 size={16} className="animate-spin" /> جاري الجمع...</>
                ) : (
                  <><Zap size={16} /> تشغيل Worker</>
                )}
              </button>
              {snapshot && (
                <button
                  onClick={() => handleStartEnrich(true)}
                  disabled={enrichState === 'loading' || enrichJob?.status === 'running'}
                  className="px-4 py-3 bg-white border border-cyan-200 text-cyan-600 rounded-xl font-bold text-sm flex flex-row-reverse items-center gap-2 disabled:opacity-50 hover:bg-cyan-50 transition-all"
                >
                  <RefreshCw size={16} /> تحديث
                </button>
              )}
            </div>

            {/* Snapshot Display */}
            {snapshot && snapshotState === 'success' && (
              <div className="bg-white rounded-2xl p-4 border border-slate-100 space-y-3">
                <div className="flex flex-row-reverse items-center justify-between">
                  <span className="text-xs font-bold text-slate-500">البيانات المُجمّعة</span>
                  <span className="text-xs text-slate-400">
                    {new Date(snapshot.created_at).toLocaleString('ar-SA')}
                  </span>
                </div>
                
                {/* Maps Data */}
                {snapshot.snapshot.maps && (
                  <div className="border-t border-slate-100 pt-3">
                    <h5 className="text-xs font-bold text-cyan-600 mb-2">🗺️ خرائط Google</h5>
                    <div className="grid grid-cols-2 gap-2 text-xs">
                      {snapshot.snapshot.maps.name && (
                        <div><span className="text-slate-400">الاسم:</span> <span className="font-bold">{snapshot.snapshot.maps.name}</span></div>
                      )}
                      {snapshot.snapshot.maps.category && (
                        <div><span className="text-slate-400">التصنيف:</span> <span className="font-bold">{snapshot.snapshot.maps.category}</span></div>
                      )}
                      {snapshot.snapshot.maps.rating && (
                        <div><span className="text-slate-400">التقييم:</span> <span className="font-bold">⭐ {snapshot.snapshot.maps.rating}</span></div>
                      )}
                      {snapshot.snapshot.maps.reviews_count && (
                        <div><span className="text-slate-400">التقييمات:</span> <span className="font-bold">{snapshot.snapshot.maps.reviews_count}</span></div>
                      )}
                    </div>
                    {snapshot.snapshot.maps.address && (
                      <div className="mt-2 text-xs"><span className="text-slate-400">العنوان:</span> <span className="font-bold">{snapshot.snapshot.maps.address}</span></div>
                    )}
                  </div>
                )}

                {/* Website Data */}
                {snapshot.snapshot.website_data && (
                  <div className="border-t border-slate-100 pt-3">
                    <h5 className="text-xs font-bold text-cyan-600 mb-2">🌐 الموقع الإلكتروني</h5>
                    {snapshot.snapshot.website_data.title && (
                      <div className="text-xs mb-1"><span className="text-slate-400">العنوان:</span> <span className="font-bold">{snapshot.snapshot.website_data.title}</span></div>
                    )}
                    {snapshot.snapshot.website_data.emails?.length > 0 && (
                      <div className="text-xs mb-1"><span className="text-slate-400">البريد:</span> <span className="font-bold">{snapshot.snapshot.website_data.emails.join(', ')}</span></div>
                    )}
                    {snapshot.snapshot.website_data.social_links && Object.keys(snapshot.snapshot.website_data.social_links).length > 0 && (
                      <div className="text-xs"><span className="text-slate-400">التواصل:</span> <span className="font-bold">{Object.keys(snapshot.snapshot.website_data.social_links).join(', ')}</span></div>
                    )}
                  </div>
                )}

                {/* Sources */}
                {snapshot.snapshot.sources && snapshot.snapshot.sources.length > 0 && (
                  <div className="border-t border-slate-100 pt-2 flex flex-row-reverse items-center gap-2">
                    <span className="text-xs text-slate-400">المصادر:</span>
                    {snapshot.snapshot.sources.map(s => (
                      <span key={s} className="px-2 py-0.5 bg-slate-100 rounded text-xs font-bold">{s}</span>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>

      {/* ==================== Survey Card ==================== */}
      <div className="bg-gradient-to-br from-purple-50/50 to-indigo-50/50 rounded-3xl p-8 border border-purple-100">
        <div className="flex flex-row-reverse items-center justify-between mb-6">
          <div className="flex flex-row-reverse items-center gap-3">
            <div className="p-3 bg-white rounded-2xl shadow-sm">
              <Sparkles size={24} className="text-purple-600" />
            </div>
            <div>
              <h3 className="font-black text-slate-800 text-lg">التقرير الذكي</h3>
              <p className="text-xs text-slate-500">تحليل AI للعميل من بيانات Forge</p>
            </div>
          </div>
          <div className="flex flex-row-reverse items-center gap-2">
            {survey && (
              <span className={`px-3 py-1 rounded-full text-xs font-bold ${surveyCached ? 'bg-amber-50 text-amber-600' : 'bg-green-50 text-green-600'}`}>
                {surveyCached ? 'من الذاكرة' : 'جديد'}
              </span>
            )}
            <StatusBadge state={surveyState} errorText={surveyError} />
          </div>
        </div>

        {!link ? (
          <div className="text-center py-8 text-slate-400">
            <AlertCircle size={32} className="mx-auto mb-2 opacity-50" />
            <p className="font-bold">يجب ربط العميل بـ Forge أولاً</p>
          </div>
        ) : (
          <div className="space-y-4">
            {/* Action Buttons */}
            <div className="flex flex-row-reverse gap-2">
              <button
                onClick={() => handleGenerateSurvey(false)}
                disabled={surveyState === 'loading'}
                className="flex-1 py-3 bg-purple-600 text-white rounded-xl font-bold text-sm flex flex-row-reverse items-center justify-center gap-2 disabled:opacity-50 hover:bg-purple-700 transition-all"
              >
                {surveyState === 'loading' ? <Loader2 size={16} className="animate-spin" /> : <Sparkles size={16} />}
                توليد التقرير
              </button>
              {survey && (
                <button
                  onClick={() => handleGenerateSurvey(true)}
                  disabled={surveyState === 'loading'}
                  className="px-4 py-3 bg-white border border-purple-200 text-purple-600 rounded-xl font-bold text-sm flex flex-row-reverse items-center gap-2 disabled:opacity-50 hover:bg-purple-50 transition-all"
                >
                  <RefreshCw size={16} /> تحديث
                </button>
              )}
            </div>

            {surveyError && (
              <div className="text-red-500 text-sm font-bold flex flex-row-reverse items-center gap-1 bg-red-50 p-3 rounded-xl">
                <AlertCircle size={16} /> {surveyError}
              </div>
            )}

            {/* Survey Result */}
            {survey && (
              <div className="bg-white rounded-2xl p-6 border border-purple-100 space-y-4">
                {survey.output?.analysis && (
                  <>
                    {/* Summary */}
                    {survey.output.analysis.summary && (
                      <div>
                        <h4 className="text-xs font-black text-slate-400 mb-2">الملخص</h4>
                        <p className="text-sm text-slate-700 leading-relaxed">{survey.output.analysis.summary}</p>
                      </div>
                    )}

                    {/* Potential */}
                    {survey.output.analysis.potential && (
                      <div className="flex flex-row-reverse items-center gap-2">
                        <span className="text-xs font-black text-slate-400">الإمكانية:</span>
                        <span className={`px-3 py-1 rounded-full text-xs font-bold ${
                          survey.output.analysis.potential === 'high' ? 'bg-green-100 text-green-700' :
                          survey.output.analysis.potential === 'medium' ? 'bg-amber-100 text-amber-700' :
                          'bg-slate-100 text-slate-600'
                        }`}>
                          {survey.output.analysis.potential === 'high' ? 'عالية' :
                           survey.output.analysis.potential === 'medium' ? 'متوسطة' : 'منخفضة'}
                        </span>
                      </div>
                    )}

                    {/* Key Points */}
                    {survey.output.analysis.key_points && survey.output.analysis.key_points.length > 0 && (
                      <div>
                        <h4 className="text-xs font-black text-slate-400 mb-2">نقاط مهمة</h4>
                        <ul className="space-y-1">
                          {survey.output.analysis.key_points.map((point, i) => (
                            <li key={i} className="text-sm text-slate-600 flex flex-row-reverse items-start gap-2">
                              <Zap size={14} className="text-purple-400 mt-0.5 flex-shrink-0" />
                              {point}
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </>
                )}

                {/* Usage Info */}
                {survey.usage && (
                  <div className="pt-4 border-t border-slate-100 flex flex-row-reverse items-center gap-4 text-xs text-slate-400">
                    <span>⏱️ {survey.usage.latencyMs}ms</span>
                    <span>📊 {survey.usage.inputTokens + survey.usage.outputTokens} tokens</span>
                    <span>💰 ${survey.usage.cost.toFixed(5)}</span>
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </div>

      {/* ==================== WhatsApp Send Card ==================== */}
      <div className="bg-gradient-to-br from-green-50/50 to-emerald-50/50 rounded-3xl p-8 border border-green-100">
        <div className="flex flex-row-reverse items-center justify-between mb-6">
          <div className="flex flex-row-reverse items-center gap-3">
            <div className="p-3 bg-white rounded-2xl shadow-sm">
              <MessageSquare size={24} className="text-green-600" />
            </div>
            <div>
              <h3 className="font-black text-slate-800 text-lg">إرسال واتساب</h3>
              <p className="text-xs text-slate-500">إرسال الرسالة المقترحة للعميل</p>
            </div>
          </div>
          <StatusBadge state={sendState} successText="تم الإرسال" errorText={sendError} />
        </div>

        {!link ? (
          <div className="text-center py-8 text-slate-400">
            <AlertCircle size={32} className="mx-auto mb-2 opacity-50" />
            <p className="font-bold">يجب ربط العميل بـ Forge أولاً</p>
          </div>
        ) : (
          <div className="space-y-4">
            {/* Message Input */}
            <div className="space-y-2">
              <label className="text-xs font-bold text-slate-500">الرسالة</label>
              <textarea
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder="اكتب رسالة أو قم بتوليد التقرير للحصول على رسالة مقترحة..."
                className="w-full px-4 py-4 bg-white rounded-xl text-sm border border-slate-200 outline-none focus:border-green-300 min-h-[120px] resize-none"
                dir="rtl"
              />
              <div className="flex flex-row-reverse items-center justify-between text-xs text-slate-400">
                <span>{message.length} حرف</span>
                {link.external_phone && (
                  <span className="flex flex-row-reverse items-center gap-1">
                    <Phone size={12} /> {link.external_phone}
                  </span>
                )}
              </div>
            </div>

            {sendError && (
              <div className="text-red-500 text-sm font-bold flex flex-row-reverse items-center gap-1 bg-red-50 p-3 rounded-xl">
                <AlertCircle size={16} /> {sendError}
              </div>
            )}

            {/* Preview Result */}
            {previewResult && previewResult.dry_run && (
              <div className="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm">
                <div className="font-bold text-amber-700 mb-2 flex flex-row-reverse items-center gap-1">
                  <Eye size={14} /> معاينة
                </div>
                <div className="text-slate-600 space-y-1">
                  <p><strong>الهاتف:</strong> {previewResult.phone}</p>
                  <p><strong>طول الرسالة:</strong> {previewResult.message_preview?.length || message.length} حرف</p>
                </div>
              </div>
            )}

            {/* Send Result */}
            {sendResult && sendResult.sent && (
              <div className="bg-green-50 border border-green-200 rounded-xl p-4 text-sm">
                <div className="font-bold text-green-700 mb-2 flex flex-row-reverse items-center gap-1">
                  <CheckCircle2 size={14} /> تم الإرسال بنجاح
                </div>
                <div className="text-slate-600">
                  <p>تم إرسال الرسالة إلى {sendResult.phone}</p>
                </div>
              </div>
            )}

            {/* Action Buttons */}
            <div className="flex flex-row-reverse gap-2">
              <button
                onClick={handleSend}
                disabled={sendState === 'loading' || !message.trim()}
                className="flex-1 py-3 bg-green-600 text-white rounded-xl font-bold text-sm flex flex-row-reverse items-center justify-center gap-2 disabled:opacity-50 hover:bg-green-700 transition-all"
              >
                {sendState === 'loading' ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                إرسال
              </button>
              <button
                onClick={handlePreview}
                disabled={sendState === 'loading' || !message.trim()}
                className="px-4 py-3 bg-white border border-green-200 text-green-600 rounded-xl font-bold text-sm flex flex-row-reverse items-center gap-2 disabled:opacity-50 hover:bg-green-50 transition-all"
              >
                <Eye size={16} /> معاينة
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default ForgeIntelTab;
