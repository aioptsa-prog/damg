import { useState, useEffect } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import PublicNavigation from '@/components/PublicNavigation';
import {
    Search,
    Phone,
    Mail,
    Download,
    TrendingUp,
    Zap,
    CreditCard,
    List,
    BookmarkCheck,
    LogOut,
} from 'lucide-react';
import { api, type Subscription } from '@/lib/api';
import { useNavigate } from 'react-router-dom';
import { clearAuthTokens } from '@/lib/auth';

const PublicDashboard = () => {
    const navigate = useNavigate();
    const [user, setUser] = useState<any>(null);
    const [subscription, setSubscription] = useState<Subscription | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchUserData();
    }, []);

    const fetchUserData = async () => {
        try {
            const response = await api.getCurrentPublicUser();

            if (response.ok) {
                setUser(response.user);
                setSubscription(response.subscription);
            } else {
                // Unauthorized - redirect to login
                navigate('/public/login');
            }
        } catch (error) {
            console.error('Failed to fetch user data:', error);
            navigate('/public/login');
        } finally {
            setLoading(false);
        }
    };

    const handleLogout = async () => {
        await api.logoutPublic();
        clearAuthTokens();
        navigate('/public/login');
    };

    const calculateUsagePercent = (used: number, limit: number) => {
        if (limit === 0) return 0; // Unlimited
        return (used / limit) * 100;
    };

    if (loading) {
        return (
            <div className="min-h-screen flex items-center justify-center">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
            </div>
        );
    }

    return (
        <>
            <PublicNavigation currentPage="dashboard" />
            <div className="min-h-screen bg-background p-6">
                <div className="max-w-7xl mx-auto">
                    {/* Header */}
                    <div className="flex items-center justify-between mb-8">
                        <div>
                            <h1 className="text-4xl font-bold text-foreground mb-2">
                                مرحباً، {user?.name || 'مستخدم'}
                            </h1>
                            <p className="text-muted-foreground">لوحة التحكم الخاصة بك</p>
                        </div>
                        <Button variant="outline" onClick={handleLogout} className="gap-2">
                            <LogOut className="w-4 h-4" />
                            تسجيل خروج
                        </Button>
                    </div>

                    {/* Subscription Card */}
                    <Card className="p-6 mb-6 bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-elegant">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-white/80 mb-2">باقتك الحالية</p>
                                <h2 className="text-3xl font-bold">{subscription?.plan?.name || 'مجاني'}</h2>
                            </div>
                            <Button variant="secondary" onClick={() => navigate('/public/pricing')}>
                                <TrendingUp className="w-4 h-4 ml-2" />
                                ترقية الباقة
                            </Button>
                        </div>
                    </Card>

                    {/* Usage Stats */}
                    <div className="grid md:grid-cols-3 gap-6 mb-8">
                        <Card className="p-6">
                            <div className="flex items-center gap-4 mb-4">
                                <div className="p-3 rounded-full bg-blue-100 dark:bg-blue-900/20">
                                    <Phone className="w-6 h-6 text-blue-600 dark:text-blue-400" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">كشف الهواتف</p>
                                    <p className="text-2xl font-bold">
                                        {subscription?.usage?.phone_reveals || 0}
                                        {subscription?.quotas?.phone && subscription.quotas.phone > 0 ? ` / ${subscription.quotas.phone}` : ''}
                                    </p>
                                </div>
                            </div>
                            {subscription?.quotas?.phone && subscription.quotas.phone > 0 && (
                                <Progress
                                    value={calculateUsagePercent(
                                        subscription.usage?.phone_reveals || 0,
                                        subscription.quotas.phone
                                    )}
                                    className="h-2"
                                />
                            )}
                            {(!subscription || !subscription.quotas || subscription.quotas.phone === 0) && (
                                <Badge variant="secondary" className="gap-1">
                                    <Zap className="w-3 h-3" />
                                    غير محدود
                                </Badge>
                            )}
                        </Card>

                        <Card className="p-6">
                            <div className="flex items-center gap-4 mb-4">
                                <div className="p-3 rounded-full bg-green-100 dark:bg-green-900/20">
                                    <Mail className="w-6 h-6 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">كشف الإيميلات</p>
                                    <p className="text-2xl font-bold">
                                        {subscription?.usage?.email_reveals || 0}
                                        {subscription?.quotas?.email && subscription.quotas.email > 0 ? ` / ${subscription.quotas.email}` : ''}
                                    </p>
                                </div>
                            </div>
                            {subscription?.quotas?.email && subscription.quotas.email > 0 && (
                                <Progress
                                    value={calculateUsagePercent(
                                        subscription.usage?.email_reveals || 0,
                                        subscription.quotas.email
                                    )}
                                    className="h-2"
                                />
                            )}
                            {(!subscription || !subscription.quotas || subscription.quotas.email === 0) && (
                                <Badge variant="secondary" className="gap-1">
                                    <Zap className="w-3 h-3" />
                                    غير محدود
                                </Badge>
                            )}
                        </Card>

                        <Card className="p-6">
                            <div className="flex items-center gap-4 mb-4">
                                <div className="p-3 rounded-full bg-purple-100 dark:bg-purple-900/20">
                                    <Download className="w-6 h-6 text-purple-600 dark:text-purple-400" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">التصديرات</p>
                                    <p className="text-2xl font-bold">
                                        {subscription?.usage?.exports_count || 0}
                                        {subscription?.quotas?.export && subscription.quotas.export > 0 ? ` / ${subscription.quotas.export}` : ''}
                                    </p>
                                </div>
                            </div>
                            {subscription?.quotas?.export && subscription.quotas.export > 0 && (
                                <Progress
                                    value={calculateUsagePercent(
                                        subscription.usage?.exports_count || 0,
                                        subscription.quotas.export
                                    )}
                                    className="h-2"
                                />
                            )}
                            {(!subscription || !subscription.quotas || subscription.quotas.export === 0) && (
                                <Badge variant="secondary" className="gap-1">
                                    <Zap className="w-3 h-3" />
                                    غير محدود
                                </Badge>
                            )}
                        </Card>
                    </div>

                    {/* Quick Actions */}
                    <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        <Button
                            variant="outline"
                            className="h-24 flex-col gap-2"
                            onClick={() => navigate('/public/search')}
                        >
                            <Search className="w-6 h-6" />
                            <span>بحث عن عملاء</span>
                        </Button>

                        <Button variant="outline" className="h-24 flex-col gap-2">
                            <BookmarkCheck className="w-6 h-6" />
                            <span>بحوثات محفوظة</span>
                        </Button>

                        <Button variant="outline" className="h-24 flex-col gap-2">
                            <List className="w-6 h-6" />
                            <span>قوائمي</span>
                        </Button>

                        <Button variant="outline" className="h-24 flex-col gap-2">
                            <CreditCard className="w-6 h-6" />
                            <span>إدارة الاشتراك</span>
                        </Button>
                    </div>

                    {/* Account Info */}
                    <Card className="p-6">
                        <h3 className="text-xl font-bold mb-4">معلومات الحساب</h3>
                        <div className="space-y-3">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">البريد الإلكتروني</span>
                                <span className="font-medium">{user?.email}</span>
                            </div>
                            {user?.company && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">الشركة</span>
                                    <span className="font-medium">{user.company}</span>
                                </div>
                            )}
                            {user?.phone && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">الجوال</span>
                                    <span className="font-medium" dir="ltr">{user.phone}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">حالة البريد</span>
                                <Badge variant={user?.email_verified ? 'default' : 'secondary'}>
                                    {user?.email_verified ? 'مفعّل' : 'غير مفعّل'}
                                </Badge>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </>
    );
};

export default PublicDashboard;
