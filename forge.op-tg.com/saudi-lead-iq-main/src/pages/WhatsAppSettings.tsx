import { useState, useEffect } from "react";
import Navigation from "@/components/Navigation";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
    DialogFooter,
} from "@/components/ui/dialog";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    Settings,
    MessageSquare,
    History,
    BarChart3,
    Plus,
    Edit,
    Trash2,
    Save,
    Loader2,
    CheckCircle,
    XCircle,
    RefreshCw,
} from "lucide-react";
import { getAuthToken } from "@/lib/auth";

// Types
interface WhatsAppSettings {
    api_url: string;
    auth_token: string;
    auth_token_masked?: string;
    sender_number: string;
    is_active: number;
}

interface Template {
    id: number;
    name: string;
    content_type: string;
    message_text: string;
    media_url: string;
    is_default: number;
    user_id: number;
}

interface Log {
    id: number;
    recipient_number: string;
    recipient_name: string;
    message_text: string;
    content_type: string;
    status: string;
    template_name: string;
    created_at: string;
    error_message: string;
}

interface Stats {
    total: number;
    sent: number;
    failed: number;
    success_rate: number;
}

const API_BASE = "http://localhost:8080/v1/api/whatsapp";

const WhatsAppSettings = () => {
    // Settings state
    const [settings, setSettings] = useState<WhatsAppSettings>({
        api_url: "https://wa.washeej.com/api/qr/rest/send_message",
        auth_token: "",
        sender_number: "",
        is_active: 0,
    });
    const [savingSettings, setSavingSettings] = useState(false);

    // Templates state
    const [templates, setTemplates] = useState<Template[]>([]);
    const [loadingTemplates, setLoadingTemplates] = useState(false);
    const [templateDialogOpen, setTemplateDialogOpen] = useState(false);
    const [editingTemplate, setEditingTemplate] = useState<Template | null>(null);
    const [newTemplate, setNewTemplate] = useState({
        name: "",
        content_type: "text",
        message_text: "",
        media_url: "",
        is_default: false,
    });
    const [savingTemplate, setSavingTemplate] = useState(false);

    // Logs state
    const [logs, setLogs] = useState<Log[]>([]);
    const [loadingLogs, setLoadingLogs] = useState(false);
    const [stats, setStats] = useState<Stats>({ total: 0, sent: 0, failed: 0, success_rate: 0 });
    const [logsPage, setLogsPage] = useState(1);
    const [logsPagination, setLogsPagination] = useState({ total: 0, pages: 0 });

    // Fetch functions
    const fetchSettings = async () => {
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/settings.php`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            if (data.ok && data.settings) {
                setSettings(data.settings);
            }
        } catch (error) {
            console.error("Failed to fetch settings:", error);
        }
    };

    const fetchTemplates = async () => {
        setLoadingTemplates(true);
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
        } finally {
            setLoadingTemplates(false);
        }
    };

    const fetchLogs = async (page = 1) => {
        setLoadingLogs(true);
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/logs.php?page=${page}&limit=10`, {
                headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            if (data.ok) {
                setLogs(data.logs || []);
                setStats(data.stats || { total: 0, sent: 0, failed: 0, success_rate: 0 });
                setLogsPagination(data.pagination || { total: 0, pages: 0 });
            }
        } catch (error) {
            console.error("Failed to fetch logs:", error);
        } finally {
            setLoadingLogs(false);
        }
    };

    useEffect(() => {
        fetchSettings();
        fetchTemplates();
        fetchLogs();
    }, []);

    // Save settings
    const handleSaveSettings = async () => {
        setSavingSettings(true);
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/settings.php`, {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(settings),
            });
            const data = await res.json();
            if (data.ok) {
                alert("تم حفظ الإعدادات بنجاح");
                fetchSettings();
            } else {
                alert(data.error || "فشل حفظ الإعدادات");
            }
        } catch (error) {
            alert("حدث خطأ أثناء الحفظ");
        } finally {
            setSavingSettings(false);
        }
    };

    // Template CRUD
    const handleSaveTemplate = async () => {
        setSavingTemplate(true);
        try {
            const token = getAuthToken();
            const method = editingTemplate ? "PUT" : "POST";
            const body = editingTemplate
                ? { ...newTemplate, id: editingTemplate.id }
                : newTemplate;

            const res = await fetch(`${API_BASE}/templates.php`, {
                method,
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json",
                },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.ok) {
                setTemplateDialogOpen(false);
                setEditingTemplate(null);
                setNewTemplate({ name: "", content_type: "text", message_text: "", media_url: "", is_default: false });
                fetchTemplates();
            } else {
                alert(data.error || "فشل حفظ القالب");
            }
        } catch (error) {
            alert("حدث خطأ");
        } finally {
            setSavingTemplate(false);
        }
    };

    const handleDeleteTemplate = async (id: number) => {
        if (!confirm("هل تريد حذف هذا القالب؟")) return;
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/templates.php?id=${id}`, {
                method: "DELETE",
                headers: { Authorization: `Bearer ${token}` },
            });
            const data = await res.json();
            if (data.ok) {
                fetchTemplates();
            } else {
                alert(data.error || "فشل حذف القالب");
            }
        } catch (error) {
            alert("حدث خطأ");
        }
    };

    const openEditTemplate = (template: Template) => {
        setEditingTemplate(template);
        setNewTemplate({
            name: template.name,
            content_type: template.content_type,
            message_text: template.message_text,
            media_url: template.media_url,
            is_default: template.is_default === 1,
        });
        setTemplateDialogOpen(true);
    };

    const openNewTemplate = () => {
        setEditingTemplate(null);
        setNewTemplate({ name: "", content_type: "text", message_text: "", media_url: "", is_default: false });
        setTemplateDialogOpen(true);
    };

    return (
        <div className="min-h-screen bg-background">
            <Navigation />

            <main className="container mx-auto px-4 py-8">
                <div className="mb-8">
                    <h1 className="text-4xl font-bold text-foreground mb-2">إعدادات الواتساب</h1>
                    <p className="text-muted-foreground text-lg">إدارة ربط الواتساب وقوالب الرسائل وسجل الإرسال</p>
                </div>

                <Tabs defaultValue="settings" className="space-y-6">
                    <TabsList className="grid w-full grid-cols-4 max-w-2xl">
                        <TabsTrigger value="settings" className="gap-2">
                            <Settings className="w-4 h-4" />
                            الإعدادات
                        </TabsTrigger>
                        <TabsTrigger value="templates" className="gap-2">
                            <MessageSquare className="w-4 h-4" />
                            القوالب
                        </TabsTrigger>
                        <TabsTrigger value="logs" className="gap-2">
                            <History className="w-4 h-4" />
                            السجل
                        </TabsTrigger>
                        <TabsTrigger value="stats" className="gap-2">
                            <BarChart3 className="w-4 h-4" />
                            الإحصائيات
                        </TabsTrigger>
                    </TabsList>

                    {/* Settings Tab */}
                    <TabsContent value="settings">
                        <Card>
                            <CardHeader>
                                <CardTitle>إعدادات الربط</CardTitle>
                                <CardDescription>قم بإعداد اتصال الواتساب باستخدام Washeej API</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="flex items-center justify-between flex-row-reverse p-4 bg-muted rounded-lg">
                                    <div className="text-right">
                                        <Label className="text-base font-semibold">تفعيل إرسال الواتساب</Label>
                                        <p className="text-sm text-muted-foreground">عند التفعيل، سيظهر زر إرسال الواتساب في صفحة العملاء</p>
                                    </div>
                                    <Switch
                                        checked={settings.is_active === 1}
                                        onCheckedChange={(checked) => setSettings({ ...settings, is_active: checked ? 1 : 0 })}
                                    />
                                </div>

                                <div className="space-y-4">
                                    <div className="space-y-2">
                                        <Label className="text-right block">رابط API</Label>
                                        <Input
                                            value={settings.api_url}
                                            onChange={(e) => setSettings({ ...settings, api_url: e.target.value })}
                                            placeholder="https://wa.washeej.com/api/qr/rest/send_message"
                                            dir="ltr"
                                            className="text-left"
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label className="text-right block">Authorization Token</Label>
                                        <Input
                                            type="password"
                                            value={settings.auth_token}
                                            onChange={(e) => setSettings({ ...settings, auth_token: e.target.value })}
                                            placeholder={settings.auth_token_masked || "أدخل JWT Token الخاص بك"}
                                            dir="ltr"
                                            className="text-left"
                                        />
                                        <p className="text-xs text-muted-foreground text-right">يُحفظ بشكل آمن ولا يظهر بالكامل</p>
                                    </div>

                                    <div className="space-y-2">
                                        <Label className="text-right block">رقم المرسل (from)</Label>
                                        <Input
                                            value={settings.sender_number}
                                            onChange={(e) => setSettings({ ...settings, sender_number: e.target.value })}
                                            placeholder="+966XXXXXXXXX"
                                            dir="ltr"
                                            className="text-left"
                                        />
                                    </div>
                                </div>

                                <Button onClick={handleSaveSettings} disabled={savingSettings} className="gap-2">
                                    {savingSettings ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                                    حفظ الإعدادات
                                </Button>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Templates Tab */}
                    <TabsContent value="templates">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <div>
                                    <CardTitle>قوالب الرسائل</CardTitle>
                                    <CardDescription>إنشاء وإدارة قوالب رسائل الواتساب</CardDescription>
                                </div>
                                <Button onClick={openNewTemplate} className="gap-2">
                                    <Plus className="w-4 h-4" />
                                    قالب جديد
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {loadingTemplates ? (
                                    <div className="flex justify-center py-8">
                                        <Loader2 className="w-8 h-8 animate-spin text-primary" />
                                    </div>
                                ) : templates.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        لا توجد قوالب. أنشئ قالبك الأول!
                                    </div>
                                ) : (
                                    <div className="grid gap-4">
                                        {templates.map((template) => (
                                            <div
                                                key={template.id}
                                                className="flex items-center justify-between p-4 border rounded-lg hover:bg-muted/50 transition-colors"
                                            >
                                                <div className="space-y-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-semibold">{template.name}</span>
                                                        {template.is_default === 1 && (
                                                            <Badge variant="secondary">افتراضي</Badge>
                                                        )}
                                                        <Badge variant="outline">{template.content_type}</Badge>
                                                    </div>
                                                    <p className="text-sm text-muted-foreground line-clamp-1">
                                                        {template.message_text || "(بدون نص)"}
                                                    </p>
                                                </div>
                                                <div className="flex gap-2">
                                                    {template.user_id !== 0 && (
                                                        <>
                                                            <Button variant="ghost" size="sm" onClick={() => openEditTemplate(template)}>
                                                                <Edit className="w-4 h-4" />
                                                            </Button>
                                                            <Button variant="ghost" size="sm" onClick={() => handleDeleteTemplate(template.id)}>
                                                                <Trash2 className="w-4 h-4 text-destructive" />
                                                            </Button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Template Dialog */}
                        <Dialog open={templateDialogOpen} onOpenChange={setTemplateDialogOpen}>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>{editingTemplate ? "تعديل القالب" : "قالب جديد"}</DialogTitle>
                                </DialogHeader>
                                <div className="space-y-4 py-4">
                                    <div className="space-y-2">
                                        <Label>اسم القالب</Label>
                                        <Input
                                            value={newTemplate.name}
                                            onChange={(e) => setNewTemplate({ ...newTemplate, name: e.target.value })}
                                            placeholder="ترحيب، متابعة، عرض خاص..."
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label>نوع المحتوى</Label>
                                        <Select
                                            value={newTemplate.content_type}
                                            onValueChange={(v) => setNewTemplate({ ...newTemplate, content_type: v })}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="text">نص فقط</SelectItem>
                                                <SelectItem value="image">صورة</SelectItem>
                                                <SelectItem value="video">فيديو</SelectItem>
                                                <SelectItem value="document">ملف</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-2">
                                        <Label>نص الرسالة</Label>
                                        <Textarea
                                            value={newTemplate.message_text}
                                            onChange={(e) => setNewTemplate({ ...newTemplate, message_text: e.target.value })}
                                            placeholder="مرحباً {{name}}، شكراً لتواصلك معنا..."
                                            rows={4}
                                        />
                                        <p className="text-xs text-muted-foreground">استخدم {"{{name}}"} لإدراج اسم العميل تلقائياً</p>
                                    </div>

                                    {newTemplate.content_type !== "text" && (
                                        <div className="space-y-2">
                                            <Label>رابط الوسائط</Label>
                                            <Input
                                                value={newTemplate.media_url}
                                                onChange={(e) => setNewTemplate({ ...newTemplate, media_url: e.target.value })}
                                                placeholder="https://example.com/image.jpg"
                                                dir="ltr"
                                            />
                                        </div>
                                    )}

                                    <div className="flex items-center gap-2">
                                        <Switch
                                            checked={newTemplate.is_default}
                                            onCheckedChange={(c) => setNewTemplate({ ...newTemplate, is_default: c })}
                                        />
                                        <Label>قالب افتراضي</Label>
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setTemplateDialogOpen(false)}>إلغاء</Button>
                                    <Button onClick={handleSaveTemplate} disabled={savingTemplate}>
                                        {savingTemplate ? <Loader2 className="w-4 h-4 animate-spin" /> : "حفظ"}
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </TabsContent>

                    {/* Logs Tab */}
                    <TabsContent value="logs">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <div>
                                    <CardTitle>سجل الإرسال</CardTitle>
                                    <CardDescription>تاريخ الرسائل المرسلة</CardDescription>
                                </div>
                                <Button variant="outline" onClick={() => fetchLogs(logsPage)} className="gap-2">
                                    <RefreshCw className="w-4 h-4" />
                                    تحديث
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {loadingLogs ? (
                                    <div className="flex justify-center py-8">
                                        <Loader2 className="w-8 h-8 animate-spin text-primary" />
                                    </div>
                                ) : logs.length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        لا توجد رسائل مرسلة بعد
                                    </div>
                                ) : (
                                    <>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>المستلم</TableHead>
                                                    <TableHead>الرسالة</TableHead>
                                                    <TableHead>القالب</TableHead>
                                                    <TableHead>الحالة</TableHead>
                                                    <TableHead>التاريخ</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {logs.map((log) => (
                                                    <TableRow key={log.id}>
                                                        <TableCell>
                                                            <div>
                                                                <div className="font-medium">{log.recipient_name || "-"}</div>
                                                                <div className="text-sm text-muted-foreground" dir="ltr">{log.recipient_number}</div>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell className="max-w-xs truncate">{log.message_text || "-"}</TableCell>
                                                        <TableCell>{log.template_name || "-"}</TableCell>
                                                        <TableCell>
                                                            {log.status === "sent" ? (
                                                                <Badge className="bg-green-100 text-green-800">
                                                                    <CheckCircle className="w-3 h-3 ml-1" />
                                                                    نجح
                                                                </Badge>
                                                            ) : (
                                                                <Badge variant="destructive">
                                                                    <XCircle className="w-3 h-3 ml-1" />
                                                                    فشل
                                                                </Badge>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-sm">{new Date(log.created_at).toLocaleString("ar-SA")}</TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>

                                        {logsPagination.pages > 1 && (
                                            <div className="flex justify-center gap-2 mt-4">
                                                <Button
                                                    variant="outline"
                                                    disabled={logsPage === 1}
                                                    onClick={() => { setLogsPage(logsPage - 1); fetchLogs(logsPage - 1); }}
                                                >
                                                    السابق
                                                </Button>
                                                <span className="px-4 py-2">{logsPage} / {logsPagination.pages}</span>
                                                <Button
                                                    variant="outline"
                                                    disabled={logsPage >= logsPagination.pages}
                                                    onClick={() => { setLogsPage(logsPage + 1); fetchLogs(logsPage + 1); }}
                                                >
                                                    التالي
                                                </Button>
                                            </div>
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Stats Tab */}
                    <TabsContent value="stats">
                        <div className="grid md:grid-cols-4 gap-4">
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="text-center">
                                        <div className="text-4xl font-bold text-primary">{stats.total}</div>
                                        <div className="text-muted-foreground">إجمالي الرسائل</div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="text-center">
                                        <div className="text-4xl font-bold text-green-600">{stats.sent}</div>
                                        <div className="text-muted-foreground">نجحت</div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="text-center">
                                        <div className="text-4xl font-bold text-red-600">{stats.failed}</div>
                                        <div className="text-muted-foreground">فشلت</div>
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6">
                                    <div className="text-center">
                                        <div className="text-4xl font-bold text-blue-600">{stats.success_rate}%</div>
                                        <div className="text-muted-foreground">نسبة النجاح</div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>
            </main>
        </div>
    );
};

export default WhatsAppSettings;
