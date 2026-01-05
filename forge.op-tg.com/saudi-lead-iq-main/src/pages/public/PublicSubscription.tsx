import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import PublicNavigation from '@/components/PublicNavigation';
import { api, type Subscription, type SubscriptionPlan } from '@/lib/api';
import { useAuth } from '@/contexts/AuthContext';
import {
    CreditCard,
    Calendar,
    CheckCircle,
    XCircle,
    ArrowRight,
    Loader2,
    AlertCircle,
    TrendingUp
} from 'lucide-react';

const PublicSubscription = () => {
    const navigate = useNavigate();
    // const { user } = useAuth(); // Temporarily disabled to avoid redirect loop
    const [subscription, setSubscription] = useState<Subscription | null>(null);
    const [plans, setPlans] = useState<SubscriptionPlan[]>([]);
    const [loading, setLoading] = useState(true);
    const [canceling, setCanceling] = useState(false);

    useEffect(() => {
        // TODO: Implement proper public user auth check
        // if (!user) {
        //     navigate('/public/login');
        //     return;
        // }
        fetchData();
    }, [/* user, */ navigate]);

    const fetchData = async () => {
        try {
            const [userResponse, plansResponse] = await Promise.all([
                api.getCurrentPublicUser(),
                api.getSubscriptionPlans()
            ]);

            if (userResponse.ok) {
                setSubscription(userResponse.subscription);
            }
            if (plansResponse.ok) {
                setPlans(plansResponse.plans);
            }
        } catch (error) {
            console.error('Failed to fetch data:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleUpgrade = () => {
        navigate('/public/pricing');
    };

    const handleCancelSubscription = async () => {
        if (!confirm('هل أنت متأكد من إلغاء اشتراكك؟ سيظل نشطاً حتى نهاية الفترة الحالية.')) return;

        setCanceling(true);
        try {
            // TODO: Implement cancel API
            alert('سيتم إضافة API لإلغاء الاشتراك قريباً');
        } catch (error) {
            console.error('Failed to cancel subscription:', error);
            alert('فشل إلغاء الاشتراك');
        } finally {
            setCanceling(false);
        }
    };

    if (loading) {
        return (
            <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
                <PublicNavigation />
                <div className="flex items-center justify-center h-[80vh]">
                    <Loader2 className="w-8 h-8 animate-spin text-primary-600" />
                </div>
            </div>
        );
    }

    const currentPlan = plans.find(p => p.id === subscription?.plan_id);
    const isFreePlan = currentPlan?.slug === 'free' || !subscription;

    return (
        <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
            <PublicNavigation />

            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {/* Header */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
                    <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-3">
                        <CreditCard className="w-8 h-8 text-primary-600" />
                        إدارة الاشتراك
                    </h1>
                    <p className="text-gray-600 mt-2">
                        تحكم في اشتراكك ومزاياك
                    </p>
                </div>

                {/* Current Plan */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 mb-6">
                    <div className="flex items-start justify-between mb-6">
                        <div>
                            <h2 className="text-2xl font-bold text-gray-900 mb-2">
                                {currentPlan?.name || 'مجاني'}
                            </h2>
                            <p className="text-gray-600">
                                {currentPlan?.description || 'باقة مجانية للتجربة'}
                            </p>
                        </div>
                        <div className="text-left">
                            <div className="text-3xl font-bold text-primary-600">
                                {currentPlan?.pricing.monthly || 0} <span className="text-lg text-gray-500">ر.س/شهر</span>
                            </div>
                            {subscription && subscription.billing_cycle === 'yearly' && (
                                <div className="text-sm text-green-600 mt-1">
                                    ✓ توفير 20% بالدفع السنوي
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Subscription Status */}
                    {subscription && !isFreePlan && (
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 p-4 bg-gray-50 rounded-xl">
                            <div className="flex items-center gap-3">
                                <div className={`p-2 rounded-lg ${subscription.status === 'active' ? 'bg-green-100' : 'bg-red-100'
                                    }`}>
                                    {subscription.status === 'active' ? (
                                        <CheckCircle className="w-5 h-5 text-green-600" />
                                    ) : (
                                        <XCircle className="w-5 h-5 text-red-600" />
                                    )}
                                </div>
                                <div>
                                    <div className="text-sm text-gray-600">الحالة</div>
                                    <div className="font-semibold">
                                        {subscription.status === 'active' ? 'نشط' : 'غير نشط'}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-blue-100 rounded-lg">
                                    <Calendar className="w-5 h-5 text-blue-600" />
                                </div>
                                <div>
                                    <div className="text-sm text-gray-600">بدأ في</div>
                                    <div className="font-semibold">
                                        {new Date(subscription.current_period_start).toLocaleDateString('ar-SA')}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-purple-100 rounded-lg">
                                    <Calendar className="w-5 h-5 text-purple-600" />
                                </div>
                                <div>
                                    <div className="text-sm text-gray-600">ينتهي في</div>
                                    <div className="font-semibold">
                                        {new Date(subscription.current_period_end).toLocaleDateString('ar-SA')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Features */}
                    <div className="mb-6">
                        <h3 className="font-semibold text-gray-900 mb-3">المزايا المتاحة:</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                            {currentPlan?.features.map((feature, index) => (
                                <div key={index} className="flex items-center gap-2 text-gray-700">
                                    <CheckCircle className="w-5 h-5 text-green-500 flex-shrink-0" />
                                    <span>{feature}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Quotas */}
                    {currentPlan && (
                        <div className="mb-6 p-4 bg-blue-50 rounded-xl">
                            <h3 className="font-semibold text-gray-900 mb-3">الحصص الشهرية:</h3>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <div className="text-sm text-gray-600">كشف الهواتف</div>
                                    <div className="text-2xl font-bold text-gray-900">
                                        {currentPlan.quotas.phone === 0 ? '∞' : currentPlan.quotas.phone}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm text-gray-600">كشف الإيميلات</div>
                                    <div className="text-2xl font-bold text-gray-900">
                                        {currentPlan.quotas.email === 0 ? '∞' : currentPlan.quotas.email}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm text-gray-600">التصديرات</div>
                                    <div className="text-2xl font-bold text-gray-900">
                                        {currentPlan.quotas.export === 0 ? '∞' : currentPlan.quotas.export}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Actions */}
                    <div className="flex gap-3">
                        {isFreePlan ? (
                            <button
                                onClick={handleUpgrade}
                                className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-primary-600 to-primary-700 text-white rounded-xl hover:from-primary-700 hover:to-primary-800 transition-all shadow-lg hover:shadow-xl"
                            >
                                <TrendingUp className="w-5 h-5" />
                                ترقية الباقة
                                <ArrowRight className="w-5 h-5" />
                            </button>
                        ) : (
                            <>
                                <button
                                    onClick={handleUpgrade}
                                    className="flex-1 flex items-center justify-center gap-2 px-6 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors"
                                >
                                    <TrendingUp className="w-5 h-5" />
                                    ترقية / تغيير الباقة
                                </button>
                                <button
                                    onClick={handleCancelSubscription}
                                    disabled={canceling}
                                    className="px-6 py-3 bg-red-50 text-red-600 rounded-xl hover:bg-red-100 transition-colors disabled:opacity-50"
                                >
                                    {canceling ? 'جاري الإلغاء...' : 'إلغاء الاشتراك'}
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {/* Billing Notice */}
                {subscription && !isFreePlan && (
                    <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-start gap-3">
                        <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                        <div className="text-sm text-blue-900">
                            <p className="font-medium mb-1">معلومات الفوترة</p>
                            <p>سيتم تجديد اشتراكك تلقائياً في {new Date(subscription.current_period_end).toLocaleDateString('ar-SA')}.</p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default PublicSubscription;
