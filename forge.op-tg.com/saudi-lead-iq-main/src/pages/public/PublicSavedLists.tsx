import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import PublicNavigation from '@/components/PublicNavigation';
import { api, type SavedList } from '@/lib/api';
import { useAuth } from '@/contexts/AuthContext';
import {
    List,
    Plus,
    Trash2,
    Eye,
    Calendar,
    Loader2,
    Users
} from 'lucide-react';

const PublicSavedLists = () => {
    const navigate = useNavigate();
    // const { user } = useAuth(); // Temporarily disabled
    const [lists, setLists] = useState<SavedList[]>([]);
    const [loading, setLoading] = useState(true);
    const [showNewDialog, setShowNewDialog] = useState(false);
    const [newList, setNewList] = useState({
        name: '',
        description: '',
        color: '#3b82f6'
    });

    const COLORS = [
        { value: '#3b82f6', label: 'أزرق' },
        { value: '#8b5cf6', label: 'بنفسجي' },
        { value: '#10b981', label: 'أخضر' },
        { value: '#f59e0b', label: 'برتقالي' },
        { value: '#ef4444', label: 'أحمر' },
        { value: '#ec4899', label: 'وردي' },
    ];

    useEffect(() => {
        // TODO: Implement proper public auth
        // if (!user) {
        //     navigate('/public/login');
        //     return;
        // }
        fetchLists();
    }, [/* user, */ navigate]);

    const fetchLists = async () => {
        try {
            const response = await api.getSavedLists();
            if (response.ok && response.lists) {
                setLists(response.lists);
            }
        } catch (error) {
            console.error('Failed to fetch saved lists:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleCreate = async () => {
        if (!newList.name.trim()) {
            alert('يرجى إدخال اسم القائمة');
            return;
        }

        try {
            const response = await api.createSavedList(newList);
            if (response.ok) {
                await fetchLists();
                setShowNewDialog(false);
                setNewList({ name: '', description: '', color: '#3b82f6' });
            }
        } catch (error: any) {
            console.error('Failed to create list:', error);
            const message = error.response?.message || 'فشل إنشاء القائمة';
            alert(message);
        }
    };

    const handleDelete = async (id: number) => {
        if (!confirm('هل أنت متأكد من حذف هذه القائمة وجميع عناصرها؟')) return;

        try {
            await api.deleteSavedList(id);
            setLists(lists.filter(l => l.id !== id));
        } catch (error) {
            console.error('Failed to delete list:', error);
            alert('فشل حذف القائمة');
        }
    };

    const handleView = (listId: number) => {
        navigate(`/public/lists/${listId}`);
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
                                <List className="w-8 h-8 text-primary-600" />
                                القوائم المحفوظة
                            </h1>
                            <p className="text-gray-600 mt-2">
                                نظم عملاءك المحتملين في قوائم مخصصة
                            </p>
                        </div>
                        <button
                            onClick={() => setShowNewDialog(true)}
                            className="flex items-center gap-2 px-6 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors shadow-lg hover:shadow-xl"
                        >
                            <Plus className="w-5 h-5" />
                            قائمة جديدة
                        </button>
                    </div>
                </div>

                {/* Lists Grid */}
                {lists.length === 0 ? (
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-12 text-center">
                        <List className="w-16 h-16 text-gray-300 mx-auto mb-4" />
                        <h3 className="text-xl font-semibold text-gray-900 mb-2">
                            لا توجد قوائم محفوظة
                        </h3>
                        <p className="text-gray-600 mb-6">
                            أنشئ قوائم لتنظيم عملائك المحتملين
                        </p>
                        <button
                            onClick={() => setShowNewDialog(true)}
                            className="px-6 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors"
                        >
                            إنشاء قائمة جديدة
                        </button>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {lists.map((list) => (
                            <div
                                key={list.id}
                                className="bg-white rounded-xl shadow-sm border-2 hover:shadow-lg transition-all duration-300 hover:scale-[1.02] overflow-hidden"
                                style={{ borderColor: list.color || '#3b82f6' }}
                            >
                                <div
                                    className="h-2"
                                    style={{ backgroundColor: list.color || '#3b82f6' }}
                                />
                                <div className="p-6">
                                    <div className="flex items-start justify-between mb-4">
                                        <div className="flex-1">
                                            <h3 className="text-lg font-semibold text-gray-900 mb-1">
                                                {list.name}
                                            </h3>
                                            {list.description && (
                                                <p className="text-sm text-gray-600">
                                                    {list.description}
                                                </p>
                                            )}
                                        </div>
                                        <button
                                            onClick={() => handleDelete(list.id)}
                                            className="text-red-600 hover:text-red-700 p-2 hover:bg-red-50 rounded-lg transition-colors"
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </button>
                                    </div>

                                    {/* Stats */}
                                    <div className="flex items-center gap-4 mb-4 pb-4 border-b border-gray-100">
                                        <div className="flex items-center gap-2 text-gray-600">
                                            <Users className="w-5 h-5" />
                                            <span className="text-lg font-semibold">
                                                {list.items_count}
                                            </span>
                                            <span className="text-sm">عميل</span>
                                        </div>
                                        <div className="flex items-center gap-1 text-sm text-gray-500">
                                            <Calendar className="w-4 h-4" />
                                            {new Date(list.updated_at).toLocaleDateString('ar-SA')}
                                        </div>
                                    </div>

                                    {/* Actions */}
                                    <button
                                        onClick={() => handleView(list.id)}
                                        className="w-full flex items-center justify-center gap-2 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors font-medium text-gray-700 border border-gray-200 hover:border-gray-300"
                                    >
                                        <Eye className="w-4 h-4" />
                                        عرض العناصر
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* New List Dialog */}
            {showNewDialog && (
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
                        <h2 className="text-2xl font-bold text-gray-900 mb-4">
                            قائمة جديدة
                        </h2>

                        <div className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    اسم القائمة *
                                </label>
                                <input
                                    type="text"
                                    value={newList.name}
                                    onChange={(e) => setNewList({ ...newList, name: e.target.value })}
                                    className="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 outline-none transition-colors"
                                    placeholder="مثل: عملاء مهتمين"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    الوصف
                                </label>
                                <textarea
                                    value={newList.description}
                                    onChange={(e) => setNewList({ ...newList, description: e.target.value })}
                                    className="w-full px-4 py-3 rounded-lg border border-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-200 outline-none transition-colors resize-none"
                                    rows={3}
                                    placeholder="وصف اختياري للقائمة"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    اللون
                                </label>
                                <div className="grid grid-cols-6 gap-3">
                                    {COLORS.map((color) => (
                                        <button
                                            key={color.value}
                                            onClick={() => setNewList({ ...newList, color: color.value })}
                                            className={`w-12 h-12 rounded-lg transition-transform hover:scale-110 ${newList.color === color.value ? 'ring-4 ring-offset-2 ring-gray-400' : ''
                                                }`}
                                            style={{ backgroundColor: color.value }}
                                            title={color.label}
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="flex gap-3 mt-6">
                            <button
                                onClick={handleCreate}
                                className="flex-1 px-4 py-3 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors font-medium"
                            >
                                إنشاء القائمة
                            </button>
                            <button
                                onClick={() => {
                                    setShowNewDialog(false);
                                    setNewList({ name: '', description: '', color: '#3b82f6' });
                                }}
                                className="px-4 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors"
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

export default PublicSavedLists;
