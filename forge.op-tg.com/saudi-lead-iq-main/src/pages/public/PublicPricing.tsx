import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import PublicNavigation from '@/components/PublicNavigation';
import {
    Check,
    Zap,
    Crown,
    Rocket,
    Phone,
    Mail,
    Download,
    BookmarkCheck,
    List,
    Loader2,
    TrendingUp,
    Star,
} from 'lucide-react';
import { api, type SubscriptionPlan } from '@/lib/api';

// Mock data fallback in case API fails
const MOCK_PLANS: SubscriptionPlan[] = [
    {
        id: 1,
        name: 'مجاني',
        slug: 'free',
        description: 'للتجربة والاستكشاف',
        pricing: { monthly: 0, yearly: 0, currency: 'SAR' },
        quotas: { phone: 10, email: 0, export: 0 },
        limits: { saved_searches: 0, saved_lists: 0, list_items: 0 },
        features: [
            '10 كشف أرقام هاتف',
            'حملة بحث واحدة',
            'تصدير غير محدود',
            'بدون واتساب'
        ]
    },
    {
        id: 2,
        name: 'أساسي',
        slug: 'basic',
        description: 'مثالي للأفراد والشركات الصغيرة',
        pricing: { monthly: 149, yearly: 1490, currency: 'SAR' },
        quotas: { phone: 100, email: 0, export: 0 },
        limits: { saved_searches: 0, saved_lists: 0, list_items: 0 },
        features: [
            '100 كشف أرقام هاتف شهرياً',
            '10 حملات بحث',
            '100 رسالة واتساب شهرياً',
            'تصدير غير محدود',
            'دعم أولوية'
        ]
    },
    {
        id: 3,
        name: 'احترافي',
        slug: 'professional',
        description: 'للشركات المتوسطة والفرق',
        pricing: { monthly: 349, yearly: 3490, currency: 'SAR' },
        quotas: { phone: 500, email: 0, export: 0 },
        limits: { saved_searches: 0, saved_lists: 0, list_items: 0 },
        features: [
            '500 كشف أرقام هاتف شهرياً',
            '50 حملة بحث',
            '500 رسالة واتساب شهرياً',
            'تصدير غير محدود',
            'دعم متقدم',
            'أولوية في الدعم'
        ]
    },
    {
        id: 4,
        name: 'مؤسسات',
        slug: 'enterprise',
        description: 'للشركات الكبيرة والاحتياجات الخاصة',
        pricing: { monthly: 699, yearly: 6990, currency: 'SAR' },
        quotas: { phone: 0, email: 0, export: 0 },
        limits: { saved_searches: 0, saved_lists: 0, list_items: 0 },
        features: [
            'كشف أرقام هاتف غير محدود',
            'حملات بحث غير محدودة',
            'رسائل واتساب غير محدودة',
            'تصدير غير محدود',
            'مدير حساب مخصص',
            'دعم على مدار الساعة',
            'API مخصص'
        ]
    }
];


const PublicPricing = () => {
    const navigate = useNavigate();
    const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
    const [loading, setLoading] = useState(true);
    const [billingPeriod, setBillingPeriod] = useState<'monthly' | 'yearly'>('monthly');
    const [currentSubscription, setCurrentSubscription] = useState<any>(null);

    useEffect(() => {
        fetchPlans();
        fetchCurrentSubscription();
    }, []);

    const fetchPlans = async () => {
        try {
            const response = await api.getSubscriptionPlans();
            if (response.ok && response.plans && response.plans.length > 0) {
                setPlans(response.plans);
            } else {
                // Fallback to mock data if API fails
                console.warn('API failed, using mock data');
                setPlans(MOCK_PLANS);
            }
        } catch (error) {
            console.error('Failed to fetch plans:', error);
            // Use mock data as fallback
            setPlans(MOCK_PLANS);
        } finally {
            setLoading(false);
        }
    };

    const fetchCurrentSubscription = async () => {
        try {
            const token = localStorage.getItem('lead_iq_public_token');
            if (token) {
                const response = await api.getCurrentPublicUser();
                if (response.ok && response.subscription) {
                    setCurrentSubscription(response.subscription);
                }
            }
        } catch (error) {
            console.error('Failed to fetch current subscription:', error);
        }
    };

    const getPlanIcon = (slug: string) => {
        switch (slug) {
            case 'free':
                return <Zap className="w-8 h-8" />;
            case 'starter':
                return <Rocket className="w-8 h-8" />;
            case 'professional':
                return <Star className="w-8 h-8" />;
            case 'enterprise':
                return <Crown className="w-8 h-8" />;
            default:
                return <Zap className="w-8 h-8" />;
        }
    };

    const getPlanColor = (slug: string) => {
        switch (slug) {
            case 'free':
                return 'from-gray-400 to-gray-600';
            case 'starter':
                return 'from-blue-500 to-blue-600';
            case 'professional':
                return 'from-purple-500 to-purple-600';
            case 'enterprise':
                return 'from-amber-500 to-amber-600';
            default:
                return 'from-gray-400 to-gray-600';
        }
    };

    const isCurrentPlan = (planId: number) => {
        return currentSubscription?.plan?.id === planId;
    };

    const handleSubscribe = (plan: SubscriptionPlan) => {
        const token = localStorage.getItem('lead_iq_public_token');
        if (!token) {
            // Redirect to login if not authenticated
            navigate('/public/login');
            return;
        }

        if (isCurrentPlan(plan.id)) {
            // Already subscribed
            return;
        }

        // TODO: Integrate payment gateway
        alert(`سيتم توجيهك لصفحة الدفع لاشتراك ${plan.name}`);
    };

    if (loading) {
        return (
            <>
                <PublicNavigation currentPage="pricing" />
                <div className="min-h-screen flex items-center justify-center">
                    <Loader2 className="w-12 h-12 animate-spin text-primary" />
                </div>
            </>
        );
    }

    return (
        <>
            <PublicNavigation currentPage="pricing" />
            <div className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-indigo-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 py-12 px-4">
                <div className="max-w-7xl mx-auto">
                    {/* Header */}
                    <div className="text-center mb-12">
                        <h1 className="text-5xl font-bold text-foreground mb-4">
                            اختر الباقة المناسبة لك
                        </h1>
                        <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
                            خطط مرنة تناسب احتياجاتك، ابدأ مجاناً أو اختر الباقة الاحترافية
                        </p>
                    </div>

                    {/* Billing Period Toggle */}
                    <div className="flex justify-center mb-12">
                        <div className="bg-background border rounded-full p-1 inline-flex shadow-sm">
                            <Button
                                variant={billingPeriod === 'monthly' ? 'default' : 'ghost'}
                                className={`rounded-full px-8 ${billingPeriod === 'monthly' ? 'gradient-primary text-white' : ''
                                    }`}
                                onClick={() => setBillingPeriod('monthly')}
                            >
                                شهرياً
                            </Button>
                            <Button
                                variant={billingPeriod === 'yearly' ? 'default' : 'ghost'}
                                className={`rounded-full px-8 ${billingPeriod === 'yearly' ? 'gradient-primary text-white' : ''
                                    }`}
                                onClick={() => setBillingPeriod('yearly')}
                            >
                                سنوياً
                                <Badge variant="secondary" className="mr-2 bg-green-100 text-green-700">
                                    وفر 20%
                                </Badge>
                            </Button>
                        </div>
                    </div>

                    {/* Pricing Cards */}
                    <div className="grid md:grid-cols-2 lg:grid-cols-4 gap-8 mb-16">
                        {plans.map((plan) => {
                            const price = billingPeriod === 'monthly'
                                ? plan.pricing.monthly
                                : plan.pricing.yearly;
                            const isPro = plan.slug === 'professional';
                            const isCurrent = isCurrentPlan(plan.id);

                            return (
                                <Card
                                    key={plan.id}
                                    className={`relative overflow-hidden transition-all hover:shadow-2xl hover:scale-105 ${isPro ? 'border-2 border-primary shadow-elegant' : ''
                                        } ${isCurrent ? 'ring-2 ring-green-500' : ''}`}
                                >
                                    {isPro && (
                                        <div className="absolute top-0 left-0 right-0 bg-gradient-to-r from-purple-500 to-pink-500 text-white text-center py-2 text-sm font-bold">
                                            ⭐ الأكثر شعبية
                                        </div>
                                    )}

                                    {isCurrent && (
                                        <div className="absolute top-0 left-0 right-0 bg-green-500 text-white text-center py-2 text-sm font-bold">
                                            ✓ باقتك الحالية
                                        </div>
                                    )}

                                    <div className={`p-8 ${isPro || isCurrent ? 'pt-14' : ''}`}>
                                        {/* Plan Icon */}
                                        <div
                                            className={`inline-flex p-4 rounded-2xl bg-gradient-to-br ${getPlanColor(
                                                plan.slug
                                            )} text-white mb-4`}
                                        >
                                            {getPlanIcon(plan.slug)}
                                        </div>

                                        {/* Plan Name */}
                                        <h3 className="text-2xl font-bold mb-2">{plan.name}</h3>
                                        <p className="text-muted-foreground text-sm mb-6 min-h-[40px]">
                                            {plan.description || 'باقة مثالية للبدء'}
                                        </p>

                                        {/* Price */}
                                        <div className="mb-6">
                                            <div className="flex items-baseline gap-2">
                                                <span className="text-4xl font-bold">
                                                    {price === 0 ? 'مجاناً' : `${price.toLocaleString()} ${plan.pricing.currency}`}
                                                </span>
                                                {price > 0 && (
                                                    <span className="text-muted-foreground">
                                                        / {billingPeriod === 'monthly' ? 'شهر' : 'سنة'}
                                                    </span>
                                                )}
                                            </div>
                                        </div>

                                        {/* Subscribe Button */}
                                        <Button
                                            className={`w-full mb-6 ${isPro
                                                ? 'gradient-primary text-white'
                                                : isCurrent
                                                    ? 'bg-green-500 hover:bg-green-600 text-white'
                                                    : ''
                                                }`}
                                            variant={isPro || isCurrent ? 'default' : 'outline'}
                                            onClick={() => handleSubscribe(plan)}
                                            disabled={isCurrent}
                                        >
                                            {isCurrent ? (
                                                <>
                                                    <Check className="w-4 h-4 ml-2" />
                                                    مشترك حالياً
                                                </>
                                            ) : price === 0 ? (
                                                'ابدأ مجاناً'
                                            ) : (
                                                <>
                                                    <TrendingUp className="w-4 h-4 ml-2" />
                                                    اشترك الآن
                                                </>
                                            )}
                                        </Button>

                                        {/* Features */}
                                        <div className="space-y-3 border-t pt-6">
                                            <div className="flex items-center gap-2 text-sm">
                                                <Phone className="w-4 h-4 text-blue-600" />
                                                <span>
                                                    {plan.quotas.phone === 0
                                                        ? 'كشف أرقام هاتف غير محدود'
                                                        : `${plan.quotas.phone} كشف هاتف/شهر`}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2 text-sm">
                                                <Download className="w-4 h-4 text-purple-600" />
                                                <span>تصدير غير محدود</span>
                                            </div>

                                            {/* Additional Features */}
                                            {plan.features && plan.features.length > 0 && (
                                                <div className="pt-3 border-t">
                                                    {plan.features.map((feature, index) => (
                                                        <div
                                                            key={index}
                                                            className="flex items-start gap-2 text-sm text-muted-foreground py-1"
                                                        >
                                                            <Check className="w-4 h-4 text-primary flex-shrink-0 mt-0.5" />
                                                            <span>{feature}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>

                    {/* FAQ Section */}
                    <div className="max-w-4xl mx-auto">
                        <h2 className="text-3xl font-bold text-center mb-8">الأسئلة الشائعة</h2>
                        <div className="grid md:grid-cols-2 gap-6">
                            <Card className="p-6">
                                <h3 className="font-bold text-lg mb-2">هل يمكنني تجربة الخدمة مجاناً؟</h3>
                                <p className="text-muted-foreground">
                                    نعم! نوفر باقة مجانية تتيح لك الوصول لميزات أساسية لتجربة المنصة قبل
                                    الاشتراك.
                                </p>
                            </Card>

                            <Card className="p-6">
                                <h3 className="font-bold text-lg mb-2">هل يمكنني تغيير الباقة لاحقاً؟</h3>
                                <p className="text-muted-foreground">
                                    بالتأكيد! يمكنك الترقية أو التخفيض في أي وقت وسيتم احتساب الفرق تلقائياً.
                                </p>
                            </Card>

                            <Card className="p-6">
                                <h3 className="font-bold text-lg mb-2">ما طرق الدفع المتاحة؟</h3>
                                <p className="text-muted-foreground">
                                    نقبل جميع البطاقات الائتمانية (Visa, Mastercard, Mada) والتحويل البنكي
                                    المحلي.
                                </p>
                            </Card>

                            <Card className="p-6">
                                <h3 className="font-bold text-lg mb-2">هل هناك خصومات للدفع السنوي؟</h3>
                                <p className="text-muted-foreground">
                                    نعم! احصل على خصم 20% عند الاشتراك السنوي بدلاً من الشهري.
                                </p>
                            </Card>
                        </div>
                    </div>

                    {/* CTA Section */}
                    <div className="mt-16 text-center">
                        <Card className="p-12 bg-gradient-to-br from-blue-500 to-indigo-600 text-white shadow-elegant">
                            <h2 className="text-4xl font-bold mb-4">جاهز للبدء؟</h2>
                            <p className="text-xl mb-8 text-white/90">
                                انضم إلى مئات الشركات التي تستخدم Lead IQ للعثور على عملاء محتملين جدد
                            </p>
                            <div className="flex gap-4 justify-center">
                                <Button
                                    size="lg"
                                    variant="secondary"
                                    className="text-lg px-8"
                                    onClick={() => navigate('/public/register')}
                                >
                                    <Zap className="w-5 h-5 ml-2" />
                                    ابدأ مجاناً الآن
                                </Button>
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className="text-lg px-8 bg-white/10 hover:bg-white/20 text-white border-white/30"
                                    onClick={() => navigate('/public/search')}
                                >
                                    <TrendingUp className="w-5 h-5 ml-2" />
                                    استكشف قاعدة البيانات
                                </Button>
                            </div>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
};

export default PublicPricing;
