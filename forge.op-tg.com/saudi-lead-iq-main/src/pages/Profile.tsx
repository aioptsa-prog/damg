import { useState, useEffect } from "react";
import Navigation from "@/components/Navigation";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { User, Lock, Mail, Phone, Building, Calendar, Save, Loader2, CheckCircle, AlertCircle } from "lucide-react";
import { getAuthToken } from "@/lib/auth";
import { toast } from "sonner";

interface UserProfile {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    company_name: string | null;
    role: string;
    created_at: string;
}

const API_BASE = "http://localhost:8080/v1/api/auth";

const Profile = () => {
    const [profile, setProfile] = useState<UserProfile | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    // Password change state
    const [passwords, setPasswords] = useState({
        current: "",
        new: "",
        confirm: ""
    });

    useEffect(() => {
        fetchProfile();
    }, []);

    const fetchProfile = async () => {
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/profile.php`, {
                headers: { Authorization: `Bearer ${token}` }
            });
            const data = await res.json();
            if (data.ok) {
                setProfile(data.profile);
            } else {
                toast.error("فشل في جلب بيانات الملف الشخصي");
            }
        } catch (error) {
            toast.error("خطأ في الاتصال بالخادم");
        } finally {
            setLoading(false);
        }
    };

    const handleChangePassword = async (e: React.FormEvent) => {
        e.preventDefault();

        if (passwords.new !== passwords.confirm) {
            toast.error("كلمة المرور الجديدة غير متطابقة");
            return;
        }

        if (passwords.new.length < 6) {
            toast.error("كلمة المرور يجب أن تكون 6 أحرف على الأقل");
            return;
        }

        setSaving(true);
        try {
            const token = getAuthToken();
            const res = await fetch(`${API_BASE}/change-password.php`, {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${token}`,
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    current_password: passwords.current,
                    new_password: passwords.new,
                    confirm_password: passwords.confirm
                })
            });

            const data = await res.json();
            if (data.ok) {
                toast.success("تم تغيير كلمة المرور بنجاح");
                setPasswords({ current: "", new: "", confirm: "" });
            } else {
                toast.error(data.error || "فشل في تغيير كلمة المرور");
            }
        } catch (error) {
            toast.error("خطأ في الاتصال بالخادم");
        } finally {
            setSaving(false);
        }
    };

    const getRoleBadge = (role: string) => {
        const roles: Record<string, { label: string; variant: "default" | "secondary" | "outline" }> = {
            admin: { label: "مدير", variant: "default" },
            employee: { label: "موظف", variant: "secondary" },
            public: { label: "مستخدم", variant: "outline" },
            user: { label: "مستخدم", variant: "outline" }
        };
        const r = roles[role] || roles.user;
        return <Badge variant={r.variant}>{r.label}</Badge>;
    };

    const formatDate = (dateStr: string) => {
        if (!dateStr) return "غير معروف";
        const date = new Date(dateStr);
        return date.toLocaleDateString('ar-SA', { year: 'numeric', month: 'long', day: 'numeric' });
    };

    return (
        <div className="min-h-screen bg-background">
            <Navigation />

            <main className="container mx-auto px-4 py-8">
                <div className="mb-8 text-right">
                    <h1 className="text-4xl font-bold text-foreground mb-2">الملف الشخصي</h1>
                    <p className="text-muted-foreground text-lg">إدارة معلومات حسابك وتغيير كلمة المرور</p>
                </div>

                {loading ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="w-8 h-8 animate-spin text-primary" />
                    </div>
                ) : (
                    <Tabs defaultValue="info" className="space-y-6 max-w-2xl">
                        <TabsList className="grid w-full grid-cols-2">
                            <TabsTrigger value="info" className="gap-2 flex-row-reverse">
                                <User className="w-4 h-4" />
                                المعلومات الشخصية
                            </TabsTrigger>
                            <TabsTrigger value="security" className="gap-2 flex-row-reverse">
                                <Lock className="w-4 h-4" />
                                الأمان
                            </TabsTrigger>
                        </TabsList>

                        {/* Profile Info Tab */}
                        <TabsContent value="info">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between flex-row-reverse">
                                        <div className="text-right">
                                            <CardTitle>المعلومات الشخصية</CardTitle>
                                            <CardDescription>معلومات حسابك الأساسية</CardDescription>
                                        </div>
                                        {profile && getRoleBadge(profile.role)}
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {profile && (
                                        <>
                                            {/* Avatar & Name */}
                                            <div className="flex items-center gap-4 p-4 bg-muted rounded-lg flex-row-reverse">
                                                <div className="w-16 h-16 rounded-full gradient-primary flex items-center justify-center">
                                                    <span className="text-white font-bold text-2xl">
                                                        {profile.name?.charAt(0)?.toUpperCase() || 'U'}
                                                    </span>
                                                </div>
                                                <div className="text-right">
                                                    <h3 className="text-xl font-bold text-foreground">{profile.name}</h3>
                                                    <p className="text-muted-foreground">{profile.email || 'لا يوجد بريد إلكتروني'}</p>
                                                </div>
                                            </div>

                                            {/* Info Grid */}
                                            <div className="grid gap-4">
                                                {profile.email && (
                                                    <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg flex-row-reverse">
                                                        <Mail className="w-5 h-5 text-primary" />
                                                        <div className="text-right flex-1">
                                                            <p className="text-sm text-muted-foreground">البريد الإلكتروني</p>
                                                            <p className="font-medium" dir="ltr">{profile.email}</p>
                                                        </div>
                                                    </div>
                                                )}

                                                {profile.phone && (
                                                    <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg flex-row-reverse">
                                                        <Phone className="w-5 h-5 text-primary" />
                                                        <div className="text-right flex-1">
                                                            <p className="text-sm text-muted-foreground">رقم الهاتف</p>
                                                            <p className="font-medium" dir="ltr">{profile.phone}</p>
                                                        </div>
                                                    </div>
                                                )}

                                                {profile.company_name && (
                                                    <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg flex-row-reverse">
                                                        <Building className="w-5 h-5 text-primary" />
                                                        <div className="text-right flex-1">
                                                            <p className="text-sm text-muted-foreground">الشركة</p>
                                                            <p className="font-medium">{profile.company_name}</p>
                                                        </div>
                                                    </div>
                                                )}

                                                <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg flex-row-reverse">
                                                    <Calendar className="w-5 h-5 text-primary" />
                                                    <div className="text-right flex-1">
                                                        <p className="text-sm text-muted-foreground">تاريخ التسجيل</p>
                                                        <p className="font-medium">{formatDate(profile.created_at)}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* Security Tab */}
                        <TabsContent value="security">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-right">تغيير كلمة المرور</CardTitle>
                                    <CardDescription className="text-right">
                                        يُنصح بتغيير كلمة المرور بشكل دوري للحفاظ على أمان حسابك
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={handleChangePassword} className="space-y-4">
                                        <div className="space-y-2">
                                            <Label className="text-right block">كلمة المرور الحالية</Label>
                                            <Input
                                                type="password"
                                                value={passwords.current}
                                                onChange={(e) => setPasswords({ ...passwords, current: e.target.value })}
                                                placeholder="أدخل كلمة المرور الحالية"
                                                required
                                                dir="ltr"
                                                className="text-left"
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="text-right block">كلمة المرور الجديدة</Label>
                                            <Input
                                                type="password"
                                                value={passwords.new}
                                                onChange={(e) => setPasswords({ ...passwords, new: e.target.value })}
                                                placeholder="أدخل كلمة المرور الجديدة"
                                                required
                                                minLength={6}
                                                dir="ltr"
                                                className="text-left"
                                            />
                                            <p className="text-xs text-muted-foreground text-right">6 أحرف على الأقل</p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label className="text-right block">تأكيد كلمة المرور الجديدة</Label>
                                            <Input
                                                type="password"
                                                value={passwords.confirm}
                                                onChange={(e) => setPasswords({ ...passwords, confirm: e.target.value })}
                                                placeholder="أعد إدخال كلمة المرور الجديدة"
                                                required
                                                dir="ltr"
                                                className="text-left"
                                            />
                                        </div>

                                        <Button type="submit" disabled={saving} className="gap-2 w-full">
                                            {saving ? (
                                                <Loader2 className="w-4 h-4 animate-spin" />
                                            ) : (
                                                <Save className="w-4 h-4" />
                                            )}
                                            تغيير كلمة المرور
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>
                )}
            </main>
        </div>
    );
};

export default Profile;
