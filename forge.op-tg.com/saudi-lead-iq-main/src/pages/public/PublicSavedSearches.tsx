import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import PublicNavigation from '@/components/PublicNavigation';
import { api, type SavedSearch } from '@/lib/api';
import { useAuth } from '@/contexts/AuthContext';
import {
    Search,
    Plus,
    Trash2,
    Play,
    Calendar,
    Filter,
    Loader2
} from 'lucide-react';

const PublicSavedSearches = () => {
    const navigate = useNavigate();
    // const { user } = useAuth(); // Temporarily disabled
    const [searches, setSearches] = useState<SavedSearch[]>([]);
    const [loading, setLoading] = useState(true);
    const [showNewDialog, setShowNewDialog] = useState(false);
    const [newSearch, setNewSearch] = useState({ name: '', description: '' });

    useEffect(() => {
        // TODO: Implement proper public auth
        // if (!user) {
        //     navigate('/public/login');
        //     return;
        // }
        fetchSearches();
    }, [/* user, */ navigate]);

    const fetchSearches = async () => {
        try {
            const response = await api.getSavedSearches();
            if (response.ok && response.searches) {
                setSearches(response.searches);
            }
        } catch (error) {
            console.error('Failed to fetch saved searches:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleDelete = async (id: number) => {
        if (!confirm('هل أنت متأكد من حذف هذا البحث المحفوظ؟')) return;

        try {
            await api.deleteSavedSearch(id);
            setSearches(searches.filter(s => s.id !== id));
        } catch (error) {
            console.error('Failed to delete search:', error);
            alert('فشل حذف البحث');
        }
    };

    const handleRun = (search: SavedSearch) => {
        // Navigate to search page with filters
        const params = new URLSearchParams();
        if (search.filters?.category_id) params.set('category_id', String(search.filters.category_id));
        if (search.filters?.city) params.set('city', search.filters.city);
        if (search.filters?.search) params.set('search', search.filters.search);

        navigate(`/public/leads?${params.toString()}`);
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

    return (
        <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
            <PublicNavigation />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {/* Header */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 mb-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-3">
                                <Search className="w-8 h-8 text-primary-600" />
                                البحوثات المحفوظة
                            </h1>
                            <p className="text-gray-600 mt-2">
                                احفظ بحوثاتك المفضلة واستخدمها لاحقاً
                            </p>
                        </div>
                        <button
                            onClick={() => setShowNewDialog(true)}
                            className="flex items-center gap-2 px-6 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors shadow-lg hover:shadow-xl"
                        >
                            <Plus className="w-5 h-5" />
                            بحث جديد
                        </button>
                    </div>
                </div>

                {/* Searches Grid */}
                {searches.length === 0 ? (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
                        <Search className="w-16 h-16 text-gray-300 mx-auto mb-4" />
                        <h3 className="text-xl font-semibold text-gray-900 mb-2">
                            لا توجد بحوثات محفوظة
                        </h3>
                        <p className="text-gray-600 mb-6">
                            احفظ بحوثاتك المفضلة للوصول إليها بسرعة
                        </p>
                        <button
                            onClick={() => navigate('/public/leads')}
                            className="px-6 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors"
                        >
                            ابدأ البحث
                        </button>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {searches.map((search) => (
                            <div
                                key={search.id}
                                className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300 hover:scale-[1.02]"
                            >
                                <div className="flex items-start justify-between mb-4">
                                    <div className="flex-1">
                                        <h3 className="text-lg font-semibold text-gray-900 mb-2">
                                            {search.name}
                                        </h3>
                                        {search.description && (
                                            <p className="text-sm text-gray-600 mb-3">
                                                {search.description}
                                            </p>
                                        )}
                                    </div>
                                    <button
                                        onClick={() => handleDelete(search.id)}
                                        className="text-red-600 hover:text-red-700 p-2 hover:bg-red-50 rounded-lg transition-colors"
                                    >
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                </div>

                                {/* Filters Display */}
                                <div className="space-y-2 mb-4">
                                    {search.filters?.category_id && (
                                        <div className="flex items-center gap-2 text-sm text-gray-600">
                                            <Filter className="w-4 h-4" />
                                            التصنيف: {search.filters.category_id}
                                        </div>
                                    )}
                                    {search.filters?.city && (
                                        <div className="flex items-center gap-2 text-sm text-gray-600">
                                            📍 المدينة: {search.filters.city}
                                        </div>
                                    )}
                                </div>

                                {/* Stats */}
                                <div className="flex items-center justify-between text-sm text-gray-500 mb-4 pb-4 border-b border-gray-100">
                                    <span>{search.result_count} نتيجة</span>
                                    {search.last_run && (
                                        <span className="flex items-center gap-1">
                                            <Calendar className="w-4 h-4" />
                                            {new Date(search.last_run).toLocaleDateString('ar-SA')}
                                        </span>
                                    )}
                                </div>

                                {/* Actions */}
                                <button
                                    onClick={() => handleRun(search)}
                                    className="w-full flex items-center justify-center gap-2 px-4 py-2 bg-primary-50 text-primary-700 rounded-lg hover:bg-primary-100 transition-colors font-medium"
                                >
                                    <Play className="w-4 h-4" />
                                    تشغيل البحث
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* New Search Dialog - Simplified placeholder */}
            {showNewDialog && (
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
                        <h2 className="text-2xl font-bold text-gray-900 mb-4">
                            بحث جديد
                        </h2>
                        <p className="text-gray-600 mb-4">
                            قم بإنشاء بحث جديد من صفحة البحث الرئيسية
                        </p>
                        <div className="flex gap-3">
                            <button
                                onClick={() => {
                                    setShowNewDialog(false);
                                    navigate('/public/leads');
                                }}
                                className="flex-1 px-4 py-2 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors"
                            >
                                انتقل للبحث
                            </button>
                            <button
                                onClick={() => setShowNewDialog(false)}
                                className="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors"
                            >
                                إلغاء
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default PublicSavedSearches;
