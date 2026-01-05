import { useState, useEffect } from "react";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { MessageCircle, Send, Loader2, User, Phone, AlertCircle } from "lucide-react";
import { getAuthToken } from "@/lib/auth";

interface Lead {
    id: number;
    name: string;
    phone: string;
    category?: { name: string };
    city?: string;
    location?: { city_name?: string };
}

interface Template {
    id: number;
    name: string;
    content_type: string;
    message_text: string;
    is_default: number;
}

interface WhatsAppSendDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Lead | null;
    onSuccess?: () => void;
}

/**
 * تنسيق رقم الهاتف السعودي - إضافة 966 تلقائياً
 */
const formatSaudiPhone = (phone: string): string => {
    if (!phone) return '';
    let cleaned = phone.replace(/[^\d+]/g, '');

    if (cleaned.startsWith('+')) return cleaned;
    if (cleaned.startsWith('00')) return '+' + cleaned.slice(2);
    if (cleaned.startsWith('966')) return '+' + cleaned;
    if (cleaned.startsWith('05')) return '+966' + cleaned.slice(1);
    if (cleaned.startsWith('5') && cleaned.length >= 9) return '+966' + cleaned;

    return '+966' + cleaned;
};

const API_BASE = "http://localhost:8080/v1/api/whatsapp";

const WhatsAppSendDialog = ({ open, onOpenChange, lead, onSuccess }: WhatsAppSendDialogProps) => {
    const [templates, setTemplates] = useState<Template[]>([]);
    const [selectedTemplateId, setSelectedTemplateId] = useState<string>("");
    const [messageText, setMessageText] = useState("");
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState("");
    const [settingsActive, setSettingsActive] = useState(false);

    // Fetch templates and check settings
    useEffect(() => {
        if (open) {
            fetchTemplates();
            checkSettings();
        }
    }, [open]);

    // Update message when template changes
    useEffect(() => {
        if (selectedTemplateId && lead) {
            const template = templates.find(t => t.id.toString() === selectedTemplateId);
            if (template) {
                // Replace {{name}} with lead name
                let text = template.message_text || "";
                text = text.replace(/\{\{name\}\}/g, lead.name || "عميلنا العزيز");
                setMessageText(text);
            }
        }
    }, [selectedTemplateId, templates, lead]);

    const checkSettings = async () => {
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/settings.php`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            setSettingsActive(data.ok && data.settings?.is_active === 1);
        } catch (error) {
            setSettingsActive(false);
        }
    };

    const fetchTemplates = async () => {
        setLoading(true);
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/templates.php`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            if (data.ok) {
                setTemplates(data.templates || []);
                // Select default template
                const defaultTemplate = data.templates?.find((t: Template) => t.is_default === 1);
                if (defaultTemplate) {
                    setSelectedTemplateId(defaultTemplate.id.toString());
                }
            }
        } catch (error) {
            console.error("Failed to fetch templates:", error);
        } finally {
            setLoading(false);
        }
    };

    const handleSend = async () => {
        if (!lead) return;

        setError("");
        setSending(true);

        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/send.php`, {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify({
                    recipient_number: formatSaudiPhone(lead.phone),
                    recipient_name: lead.name,
                    lead_id: lead.id,
                    template_id: selectedTemplateId ? parseInt(selectedTemplateId) : null,
                    message_text: messageText,
                    content_type: "text",
                }),
            });

            const data = await res.json();

            if (data.ok) {
                onOpenChange(false);
                onSuccess?.();
                alert("تم إرسال الرسالة بنجاح! ✅");
            } else {
                setError(data.error || "فشل إرسال الرسالة");
            }
        } catch (error) {
            setError("حدث خطأ أثناء الإرسال");
        } finally {
            setSending(false);
        }
    };

    if (!lead) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <MessageCircle className="w-5 h-5 text-green-600" />
                        إرسال رسالة واتساب
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4 py-4">
                    {/* Lead Info */}
                    <div className="p-4 bg-muted rounded-lg space-y-2">
                        <div className="flex items-center gap-2">
                            <User className="w-4 h-4 text-muted-foreground" />
                            <span className="font-semibold">{lead.name}</span>
                            {lead.category?.name && (
                                <Badge variant="secondary">{lead.category.name}</Badge>
                            )}
                        </div>
                        <div className="flex items-center gap-2 text-muted-foreground" dir="ltr">
                            <Phone className="w-4 h-4" />
                            <span>{lead.phone}</span>
                        </div>
                    </div>

                    {/* Settings Warning */}
                    {!settingsActive && (
                        <div className="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg flex items-center gap-2 text-yellow-800 dark:text-yellow-200">
                            <AlertCircle className="w-4 h-4" />
                            <span className="text-sm">يرجى تفعيل إعدادات الواتساب أولاً</span>
                        </div>
                    )}

                    {/* Template Selection */}
                    <div className="space-y-2">
                        <Label>اختر قالب الرسالة</Label>
                        <Select value={selectedTemplateId} onValueChange={setSelectedTemplateId}>
                            <SelectTrigger>
                                <SelectValue placeholder="اختر قالباً..." />
                            </SelectTrigger>
                            <SelectContent>
                                {templates.map((template) => (
                                    <SelectItem key={template.id} value={template.id.toString()}>
                                        {template.name}
                                        {template.is_default === 1 && " (افتراضي)"}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Message Preview/Edit */}
                    <div className="space-y-2">
                        <Label>نص الرسالة</Label>
                        <Textarea
                            value={messageText}
                            onChange={(e) => setMessageText(e.target.value)}
                            rows={4}
                            placeholder="اكتب رسالتك هنا..."
                        />
                    </div>

                    {/* Error */}
                    {error && (
                        <div className="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-800 dark:text-red-200 text-sm">
                            {error}
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        إلغاء
                    </Button>
                    <Button
                        onClick={handleSend}
                        disabled={sending || !messageText || !settingsActive}
                        className="gap-2 bg-green-600 hover:bg-green-700"
                    >
                        {sending ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                        ) : (
                            <Send className="w-4 h-4" />
                        )}
                        إرسال الرسالة
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
};

export default WhatsAppSendDialog;
