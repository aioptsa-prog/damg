import { useState, useEffect } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicNavigation from '@/components/PublicNavigation';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import {
    Search,
    MapPin,
    Star,
    Eye,
    EyeOff,
    Loader2,
    Phone,
    Mail,
    Zap,
    BookmarkPlus,
} from 'lucide-react';
import { api, type Lead, type Category } from '@/lib/api';
import { useNavigate } from 'react-router-dom';

const PublicSearch = () => {
    const navigate = useNavigate();
    const [leads, setLeads] = useState<Lead[]>([]);
    const [categories, setCategories] = useState<Category[]>([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedCategory, setSelectedCategory] = useState<string>('all');
    const [selectedCity, setSelectedCity] = useState<string>('all');
    const [page, setPage] = useState(1);
    const [pagination, setPagination] = useState<any>(null);
    const [subscription, setSubscription] = useState<any>(null);
    const [revealingId, setRevealingId] = useState<number | null>(null);

    useEffect(() => {
        fetchLeads();
        fetchCategories();
    }, [page, selectedCategory, selectedCity, searchTerm]);

    const fetchLeads = async () => {
        setLoading(true);
        try {
            const params: any = {
                page,
                limit: 20,
                search: searchTerm || undefined,
            };

            if (selectedCategory && selectedCategory !== 'all') {
                params.category_id = parseInt(selectedCategory);
            }

            if (selectedCity && selectedCity !== 'all') {
                params.city = selectedCity;
            }

            const response = await api.searchLeads(params);

            if (response.ok) {
                setLeads(response.leads || []);
                setPagination(response.pagination);
                setSubscription(response.subscription);
            } else {
                // Unauthorized - redirect to login
                if (response.error === 'UNAUTHORIZED') {
                    navigate('/public/login');
                }
            }
        } catch (error) {
            console.error('Failed to fetch leads:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchCategories = async () => {
        try {
            const response = await api.getCategories();
            if (response.ok) {
                const categoriesData = (response as any).flat || response.data?.flat || [];
                setCategories(categoriesData);
            }
        } catch (error) {
            console.error('Failed to fetch categories:', error);
        }
    };

    const handleReveal = async (leadId: number, revealType: 'phone' | 'email') => {
        setRevealingId(leadId);
        try {
            const response = await api.revealContact(leadId, revealType);

            if (response.ok && response.revealed) {
                // Update lead with revealed data
                setLeads(prevLeads =>
                    prevLeads.map(lead =>
                        lead.id === leadId
                            ? { ...lead, [revealType]: response.data[revealType] }
                            : lead
                    )
                );
            } else if (response.error === 'QUOTA_EXCEEDED') {
                // Show upgrade modal
                alert('لقد استنفدت حصتك! يرجى ترقية الاشتراك للمتابعة.');
                navigate('/public/pricing');
            }
        } catch (error) {
            console.error('Reveal failed:', error);
        } finally {
            setRevealingId(null);
        }
    };

    return (
        <>
            <PublicNavigation currentPage="search" />
            <div className="min-h-screen bg-background p-6">
                <div className="max-w-7xl mx-auto">
                    {/* Header */}
                    <div className="mb-8 flex items-center justify-between">
                        <div>
                            <h1 className="text-4xl font-bold text-foreground mb-2">البحث عن العملاء</h1>
                            <p className="text-muted-foreground">
                                باقتك الحالية: <span className="font-semibold text-primary">{subscription?.name || 'مجاني'}</span>
                            </p>
                        </div>
                        <Button variant="outline" onClick={() => navigate('/public/dashboard')}>
                            لوحة التحكم
                        </Button>
                    </div>

                    {/* Search & Filters */}
                    <Card className="p-6 shadow-card mb-6">
                        <div className="grid md:grid-cols-3 gap-4">
                            <div className="md:col-span-1">
                                <div className="relative">
                                    <Search className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                                    <Input
                                        placeholder="ابحث عن عميل..."
                                        className="pr-10"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                    />
                                </div>
                            </div>

                            <Select value={selectedCity} onValueChange={setSelectedCity}>
                                <SelectTrigger>
                                    <SelectValue placeholder="المدينة" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">جميع المدن</SelectItem>
                                    <SelectItem value="الرياض">الرياض</SelectItem>
                                    <SelectItem value="جدة">جدة</SelectItem>
                                    <SelectItem value="الدمام">الدمام</SelectItem>
                                    <SelectItem value="مكة">مكة</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={selectedCategory} onValueChange={setSelectedCategory}>
                                <SelectTrigger>
                                    <SelectValue placeholder="الفئة" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">جميع الفئات</SelectItem>
                                    {categories.slice(0, 20).map((cat) => (
                                        <SelectItem key={cat.id} value={cat.id.toString()}>
                                            {cat.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </Card>

                    {/* Results */}
                    <div className="mb-4 flex items-center justify-between">
                        <p className="text-muted-foreground">
                            {loading ? (
                                <span>جاري التحميل...</span>
                            ) : (
                                <>عرض <span className="font-semibold text-foreground">{leads.length}</span> نتيجة</>
                            )}
                        </p>
                    </div>

                    {loading ? (
                        <div className="flex items-center justify-center py-20">
                            <Loader2 className="w-10 h-10 animate-spin text-primary" />
                        </div>
                    ) : leads.length === 0 ? (
                        <Card className="p-12 text-center">
                            <p className="text-muted-foreground text-lg">لا توجد نتائج</p>
                        </Card>
                    ) : (
                        <>
                            <div className="grid md:grid-cols-2 gap-6">
                                {leads.map((lead: any) => (
                                    <Card key={lead.id} className="p-6 shadow-card hover:shadow-elegant transition-smooth">
                                        <div className="flex items-start justify-between mb-4">
                                            <div>
                                                <h3 className="text-xl font-bold text-foreground mb-2">{lead.name}</h3>
                                                <Badge variant="secondary">{lead.category?.name || 'غير محدد'}</Badge>
                                            </div>
                                            {lead.rating && (
                                                <div className="flex items-center gap-1 bg-yellow-50 dark:bg-yellow-900/20 px-3 py-1 rounded-lg">
                                                    <Star className="w-4 h-4 text-yellow-500 fill-yellow-500" />
                                                    <span className="font-bold">{lead.rating}</span>
                                                </div>
                                            )}
                                        </div>

                                        <div className="space-y-3 mb-4">
                                            <div className="flex items-center gap-2 text-muted-foreground">
                                                <MapPin className="w-4 h-4 text-primary" />
                                                <span>{lead.location?.city_name || lead.city}</span>
                                            </div>

                                            {/* Phone Reveal */}
                                            <div className="flex items-center gap-2">
                                                <Phone className="w-4 h-4 text-primary" />
                                                {lead.phone ? (
                                                    <span className="font-medium" dir="ltr">{lead.phone}</span>
                                                ) : lead.phone_available ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="gap-2"
                                                        onClick={() => handleReveal(lead.id, 'phone')}
                                                        disabled={revealingId === lead.id}
                                                    >
                                                        {revealingId === lead.id ? (
                                                            <Loader2 className="w-3 h-3 animate-spin" />
                                                        ) : (
                                                            <Eye className="w-3 h-3" />
                                                        )}
                                                        كشف الهاتف
                                                        <Zap className="w-3 h-3 text-yellow-500" />
                                                    </Button>
                                                ) : (
                                                    <span className="text-muted-foreground text-sm">غير متوفر</span>
                                                )}
                                            </div>

                                            {/* Email Reveal */}
                                            {lead.email_available && (
                                                <div className="flex items-center gap-2">
                                                    <Mail className="w-4 h-4 text-primary" />
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="gap-2"
                                                        onClick={() => handleReveal(lead.id, 'email')}
                                                        disabled={revealingId === lead.id}
                                                    >
                                                        {revealingId === lead.id ? (
                                                            <Loader2 className="w-3 h-3 animate-spin" />
                                                        ) : (
                                                            <Eye className="w-3 h-3" />
                                                        )}
                                                        كشف الإيميل
                                                        <Zap className="w-3 h-3 text-yellow-500" />
                                                    </Button>
                                                </div>
                                            )}
                                        </div>

                                        <Button variant="ghost" size="sm" className="w-full gap-2">
                                            <BookmarkPlus className="w-4 h-4" />
                                            إضافة لقائمة
                                        </Button>
                                    </Card>
                                ))}
                            </div>

                            {/* Pagination */}
                            {pagination && pagination.pages > 1 && (
                                <div className="flex justify-center gap-2 mt-8">
                                    <Button
                                        variant="outline"
                                        onClick={() => setPage(page - 1)}
                                        disabled={!pagination.has_prev}
                                    >
                                        السابق
                                    </Button>
                                    {[...Array(Math.min(5, pagination.pages))].map((_, i) => (
                                        <Button
                                            key={i + 1}
                                            variant={page === i + 1 ? 'default' : 'outline'}
                                            className={page === i + 1 ? 'gradient-primary text-white' : ''}
                                            onClick={() => setPage(i + 1)}
                                        >
                                            {i + 1}
                                        </Button>
                                    ))}
                                    <Button
                                        variant="outline"
                                        onClick={() => setPage(page + 1)}
                                        disabled={!pagination.has_next}
                                    >
                                        التالي
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
};

export default PublicSearch;
