import { useState, useEffect, useRef } from "react";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
    DialogDescription,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Progress } from "@/components/ui/progress";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Loader2, Send, CheckCircle, XCircle, AlertCircle } from "lucide-react";
import { getAuthToken } from "@/lib/auth";
import { toast } from "sonner";

interface Lead {
    id: number;
    name: string;
    phone?: string;
    mobile?: string;
}

interface Template {
    id: number;
    name: string;
    message_text: string;
}

interface BulkWhatsAppDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selectedLeads: Lead[];
}

/**
 * تنسيق رقم الهاتف السعودي - إضافة 966 تلقائياً
 * @param phone رقم الهاتف
 * @returns الرقم بتنسيق دولي
 */
const formatSaudiPhone = (phone: string): string => {
    if (!phone) return '';

    // إزالة المسافات والرموز غير الرقمية
    let cleaned = phone.replace(/[^\d+]/g, '');

    // إذا كان يبدأ بـ + فهو بالفعل بتنسيق دولي
    if (cleaned.startsWith('+')) {
        return cleaned;
    }

    // إذا كان يبدأ بـ 00 (تنسيق دولي بديل)
    if (cleaned.startsWith('00')) {
        return '+' + cleaned.slice(2);
    }

    // إذا كان يبدأ بـ 966 (كود السعودية بدون +)
    if (cleaned.startsWith('966')) {
        return '+' + cleaned;
    }

    // إذا كان يبدأ بـ 05 (رقم سعودي محلي)
    if (cleaned.startsWith('05')) {
        return '+966' + cleaned.slice(1); // حذف الـ 0 وإضافة +966
    }

    // إذا كان يبدأ بـ 5 (رقم سعودي بدون 0)
    if (cleaned.startsWith('5') && cleaned.length >= 9) {
        return '+966' + cleaned;
    }

    // أي رقم آخر - افترض أنه سعودي وأضف 966
    return '+966' + cleaned;
};

const API_BASE = "http://localhost:8080/v1/api/whatsapp";

const BulkWhatsAppDialog = ({ open, onOpenChange, selectedLeads }: BulkWhatsAppDialogProps) => {
    const [templates, setTemplates] = useState<Template[]>([]);
    const [selectedTemplate, setSelectedTemplate] = useState<string>("");
    const [customMessage, setCustomMessage] = useState("");
    const [sending, setSending] = useState(false);
    const [campaignId, setCampaignId] = useState<number | null>(null);
    const [progress, setProgress] = useState({ sent: 0, failed: 0, total: 0, remaining: 0 });
    const [status, setStatus] = useState<'idle' | 'sending' | 'completed'>('idle');
    const processingRef = useRef<NodeJS.Timeout | null>(null);

    // جلب القوالب
    useEffect(() => {
        if (open) {
            fetchTemplates();
        }
        return () => {
            if (processingRef.current) {
                clearInterval(processingRef.current);
            }
        };
    }, [open]);

    const fetchTemplates = async () => {
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/templates.php`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            if (data.ok) {
                setTemplates(data.templates || []);
            }
        } catch (error) {
            console.error("Failed to fetch templates:", error);
        }
    };

    // عند اختيار قالب
    const handleTemplateChange = (templateId: string) => {
        setSelectedTemplate(templateId);
        const template = templates.find(t => t.id.toString() === templateId);
        if (template) {
            setCustomMessage(template.message_text);
        }
    };

    // بدء الإرسال
    const startSending = async () => {
        if (selectedLeads.length === 0) {
            toast.error("لم يتم تحديد أي عملاء");
            return;
        }

        if (!customMessage.trim()) {
            toast.error("يرجى كتابة نص الرسالة أو اختيار قالب");
            return;
        }

        setSending(true);
        setStatus('sending');

        try {
            const token = getAuthToken();

            // تجهيز قائمة المستلمين مع تنسيق الأرقام السعودية
            const recipients = selectedLeads.map(lead => ({
                lead_id: lead.id,
                number: formatSaudiPhone(lead.phone || lead.mobile || ''),
                name: lead.name
            })).filter(r => r.number);

            if (recipients.length === 0) {
                toast.error("لا توجد أرقام صالحة");
                setSending(false);
                setStatus('idle');
                return;
            }

            // إنشاء الحملة
            const res = await fetch(`${API_BASE}/bulk.php`, {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    name: `حملة ${new Date().toLocaleString('ar-SA')}`,
                    template_id: selectedTemplate ? parseInt(selectedTemplate) : null,
                    message_text: customMessage,
                    recipients
                }),
            });

            const data = await res.json();

            if (data.ok) {
                setCampaignId(data.campaign_id);
                setProgress({ sent: 0, failed: 0, total: data.total, remaining: data.total });
                toast.success(`بدأ إرسال ${data.total} رسالة`);

                // بدء معالجة القائمة
                startProcessing();
            } else {
                toast.error(data.error || "فشل إنشاء الحملة");
                setSending(false);
                setStatus('idle');
            }
        } catch (error) {
            toast.error("حدث خطأ أثناء إنشاء الحملة");
            setSending(false);
            setStatus('idle');
        }
    };

    // معالجة القائمة
    const startProcessing = () => {
        processingRef.current = setInterval(async () => {
            try {
                const token = getAuthToken();
                const res = await fetch(`${API_BASE}/process_queue.php`, {
                    headers: { Authorization: `Bearer ${token}` },
                });
                const data = await res.json();

                if (data.ok && data.campaign) {
                    setProgress({
                        sent: data.campaign.sent_count,
                        failed: data.campaign.failed_count,
                        total: data.campaign.total_count,
                        remaining: data.remaining
                    });

                    if (data.campaign.status === 'completed' || data.remaining === 0) {
                        if (processingRef.current) {
                            clearInterval(processingRef.current);
                        }
                        setStatus('completed');
                        setSending(false);
                        toast.success("اكتملت الحملة!");
                    }
                } else if (data.processed === 0) {
                    // لا توجد رسائل معلقة
                    if (processingRef.current) {
                        clearInterval(processingRef.current);
                    }
                    setStatus('completed');
                    setSending(false);
                }
            } catch (error) {
                console.error("Process queue error:", error);
            }
        }, 3000); // كل 3 ثوانٍ
    };

    const handleClose = () => {
        if (status === 'sending') {
            toast.info("الإرسال مستمر في الخلفية");
        }
        onOpenChange(false);
        // Reset state
        setSelectedTemplate("");
        setCustomMessage("");
        setCampaignId(null);
        setProgress({ sent: 0, failed: 0, total: 0, remaining: 0 });
        setStatus('idle');
    };

    const progressPercent = progress.total > 0
        ? Math.round(((progress.sent + progress.failed) / progress.total) * 100)
        : 0;

    return (
        <Dialog open={open} onOpenChange={handleClose}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>إرسال واتساب مجمع</DialogTitle>
                    <DialogDescription>
                        إرسال رسالة لـ {selectedLeads.length} عميل محدد
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-4">
                    {status === 'idle' && (
                        <>
                            {/* اختيار القالب */}
                            <div className="space-y-2">
                                <Label>اختر قالب (اختياري)</Label>
                                <Select value={selectedTemplate} onValueChange={handleTemplateChange}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="اختر قالباً أو اكتب رسالة مخصصة" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {templates.map((template) => (
                                            <SelectItem key={template.id} value={template.id.toString()}>
                                                {template.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* نص الرسالة */}
                            <div className="space-y-2">
                                <Label>نص الرسالة</Label>
                                <Textarea
                                    value={customMessage}
                                    onChange={(e) => setCustomMessage(e.target.value)}
                                    placeholder="مرحباً {{name}}، ..."
                                    rows={4}
                                />
                                <p className="text-xs text-muted-foreground">
                                    استخدم {"{{name}}"} لإدراج اسم العميل تلقائياً
                                </p>
                            </div>

                            {/* ملخص */}
                            <div className="bg-muted p-3 rounded-lg">
                                <p className="text-sm">
                                    سيتم إرسال الرسالة لـ <strong>{selectedLeads.length}</strong> عميل
                                </p>
                            </div>
                        </>
                    )}

                    {(status === 'sending' || status === 'completed') && (
                        <div className="space-y-4">
                            {/* شريط التقدم */}
                            <div className="space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span>التقدم</span>
                                    <span>{progressPercent}%</span>
                                </div>
                                <Progress value={progressPercent} className="h-3" />
                            </div>

                            {/* الإحصائيات */}
                            <div className="grid grid-cols-3 gap-4 text-center">
                                <div className="bg-blue-50 p-3 rounded-lg">
                                    <div className="text-2xl font-bold text-blue-600">{progress.total}</div>
                                    <div className="text-xs text-muted-foreground">الإجمالي</div>
                                </div>
                                <div className="bg-green-50 p-3 rounded-lg">
                                    <div className="text-2xl font-bold text-green-600 flex items-center justify-center gap-1">
                                        <CheckCircle className="w-5 h-5" />
                                        {progress.sent}
                                    </div>
                                    <div className="text-xs text-muted-foreground">نجح</div>
                                </div>
                                <div className="bg-red-50 p-3 rounded-lg">
                                    <div className="text-2xl font-bold text-red-600 flex items-center justify-center gap-1">
                                        <XCircle className="w-5 h-5" />
                                        {progress.failed}
                                    </div>
                                    <div className="text-xs text-muted-foreground">فشل</div>
                                </div>
                            </div>

                            {status === 'sending' && (
                                <div className="flex items-center justify-center gap-2 text-muted-foreground">
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                    <span>جاري الإرسال... ({progress.remaining} متبقي)</span>
                                </div>
                            )}

                            {status === 'completed' && (
                                <div className="flex items-center justify-center gap-2 text-green-600">
                                    <CheckCircle className="w-5 h-5" />
                                    <span>اكتملت الحملة!</span>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <DialogFooter>
                    {status === 'idle' && (
                        <>
                            <Button variant="outline" onClick={handleClose}>
                                إلغاء
                            </Button>
                            <Button
                                onClick={startSending}
                                disabled={sending || !customMessage.trim()}
                                className="gap-2"
                            >
                                {sending ? (
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                ) : (
                                    <Send className="w-4 h-4" />
                                )}
                                بدء الإرسال
                            </Button>
                        </>
                    )}
                    {(status === 'sending' || status === 'completed') && (
                        <Button onClick={handleClose}>
                            {status === 'sending' ? 'إغلاق (يستمر في الخلفية)' : 'إغلاق'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
};

export default BulkWhatsAppDialog;
