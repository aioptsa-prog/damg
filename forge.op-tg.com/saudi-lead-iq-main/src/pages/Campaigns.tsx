import { getAuthToken } from "@/lib/auth";
import Navigation from "@/components/Navigation";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { SearchableSelect } from "@/components/ui/searchable-select";
import { useToast } from "@/hooks/use-toast";
import { useState, useEffect, useCallback } from "react";
import {
  Target,
  MapPin,
  Play,
  Pause,
  MoreVertical,
  Plus,
  Loader2
} from "lucide-react";

interface Campaign {
  id: number;
  name: string;
  description: string;
  query: string;
  city: string;
  target_count: number;
  result_count: number;
  progress_percent: number;
  status: string;
  created_at: string;
}

// Saudi cities list
const SAUDI_CITIES = [
  "الرياض", "جدة", "مكة المكرمة", "المدينة المنورة", "الدمام", "الخبر", "الظهران",
  "الأحساء", "القطيف", "الجبيل", "الطائف", "تبوك", "بريدة", "حائل", "خميس مشيط",
  "أبها", "نجران", "جازان", "ينبع", "الباحة", "عنيزة", "الرس", "سكاكا", "عرعر",
  "القريات", "حفر الباطن", "الخرج", "الدوادمي", "المجمعة", "شقراء", "الزلفي",
  "وادي الدواسر", "بيشة", "صبيا", "رابغ", "القنفذة", "محايل عسير"
];

// Main business categories
const MAIN_CATEGORIES = [
  "مطاعم", "كافيهات", "مقاهي", "حلويات", "مخبز", "بيتزا", "برغر", "شاورما", "مشاوي",
  "صالونات", "صالون رجالي", "صالون نسائي", "سبا", "تجميل", "مكياج", "عطور",
  "عيادات", "مستشفيات", "صيدليات", "عيادات أسنان", "مختبرات", "بصريات", "نظارات",
  "فنادق", "شاليهات", "شقق مفروشة", "منتجعات",
  "مدارس", "جامعات", "معاهد", "روضة وحضانة", "دورات", "تدريب",
  "سيارات", "قطع غيار", "مغسلة سيارات", "تأجير سيارات", "ورش",
  "عقارات", "مكاتب عقارية", "إدارة أملاك", "تقييم عقاري",
  "محلات جوالات", "إلكترونيات", "كمبيوتر", "برمجيات",
  "ملابس", "أحذية", "ساعات",
  "سوبرماركت", "بقالة", "خضار وفواكه", "لحوم", "أسماك",
  "مقاولات", "مواد بناء", "دهانات", "سباكة", "كهرباء",
  "محاماة", "استشارات", "محاسبة", "تأمين", "بنوك", "صرافة",
  "سياحة", "سفر", "حج وعمرة", "تذاكر طيران",
  "صالات رياضية", "ملاعب", "رياضة",
  "خدمات", "تنظيف", "صيانة", "نقل", "شحن",
  "حيوانات أليفة", "بيطري",
  "مكتبات", "قرطاسية", "طباعة",
  "حدائق", "زراعة", "ورد",
  "ألعاب", "ترفيه", "سينما"
];

const Campaigns = () => {
  const { toast } = useToast();
  const [showForm, setShowForm] = useState(false);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [categories] = useState<{ value: string; label: string }[]>(
    MAIN_CATEGORIES.map(name => ({ value: name, label: name }))
  );


  // Form state
  const [formData, setFormData] = useState({
    name: '',
    city: '',
    category: '',
    target: '100',
    description: ''
  });



  const fetchCampaigns = useCallback(async () => {
    try {
      const response = await fetch('/v1/api/campaigns/index.php', {
        headers: {
          'Authorization': `Bearer ${getAuthToken()}`
        }
      });
      const data = await response.json();
      if (data.ok) {
        setCampaigns(data.campaigns || []);
      }
    } catch (error) {
      console.error('Error fetching campaigns:', error);
    } finally {
      setLoading(false);
    }
  }, []);


  // Process jobs in background
  const processJobs = useCallback(async () => {
    try {
      await fetch('/v1/api/campaigns/process.php', {
        headers: {
          'Authorization': `Bearer ${getAuthToken()}`
        }
      });
    } catch (error) {
      console.error('Error processing jobs:', error);
    }
  }, []);

  useEffect(() => {
    fetchCampaigns();
    // Process jobs and refresh every 5 seconds to show progress
    const processInterval = setInterval(processJobs, 5000);
    const fetchInterval = setInterval(fetchCampaigns, 5000);
    return () => {
      clearInterval(processInterval);
      clearInterval(fetchInterval);
    };
  }, [fetchCampaigns, processJobs]);

  const handleCreateCampaign = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);

    try {
      const response = await fetch('/v1/api/campaigns/create.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getAuthToken()}`
        },
        body: JSON.stringify({
          name: formData.name,
          city: formData.city,
          query: formData.category,
          target: parseInt(formData.target),
          description: formData.description
        })
      });

      const data = await response.json();

      if (data.ok) {
        toast({
          title: "تم إنشاء الحملة بنجاح",
          description: "ستبدأ الحملة في جمع البيانات قريباً",
        });
        setShowForm(false);
        setFormData({ name: '', city: '', category: '', target: '100', description: '' });
        fetchCampaigns();
      } else {
        toast({
          variant: "destructive",
          title: "خطأ",
          description: data.message || "فشل في إنشاء الحملة",
        });
      }
    } catch (error) {
      toast({
        variant: "destructive",
        title: "خطأ",
        description: "حدث خطأ أثناء إنشاء الحملة",
      });
    } finally {
      setSubmitting(false);
    }
  };

  const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
      'pending': 'قيد الانتظار',
      'processing': 'نشطة',
      'completed': 'مكتملة',
      'failed': 'فشلت',
      'cancelled': 'ملغاة'
    };
    return labels[status] || status;
  };

  const getStatusStyle = (status: string) => {
    if (status === 'processing') return "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400";
    if (status === 'completed') return "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400";
    if (status === 'failed') return "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400";
    return "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400";
  };

  const handleToggleCampaign = async (campaign: Campaign) => {
    const action = campaign.status === 'processing' ? 'pause' : 'resume';
    try {
      const response = await fetch(`/v1/api/campaigns/toggle.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getAuthToken()}`
        },
        body: JSON.stringify({ campaign_id: campaign.id, action })
      });
      const data = await response.json();
      if (data.ok) {
        toast({
          title: action === 'pause' ? 'تم إيقاف الحملة' : 'تم تشغيل الحملة',
          description: campaign.name
        });
        fetchCampaigns();
      } else {
        toast({
          title: 'خطأ',
          description: data.message || 'حدث خطأ',
          variant: 'destructive'
        });
      }
    } catch (error) {
      toast({
        title: 'خطأ',
        description: 'فشل الاتصال بالخادم',
        variant: 'destructive'
      });
    }
  };

  const handleDeleteCampaign = async (campaign: Campaign) => {
    if (!confirm(`هل أنت متأكد من حذف الحملة "${campaign.name}"؟`)) {
      return;
    }
    try {
      const response = await fetch(`/v1/api/campaigns/delete.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${getAuthToken()}`
        },
        body: JSON.stringify({ campaign_id: campaign.id })
      });
      const data = await response.json();
      if (data.ok) {
        toast({
          title: 'تم حذف الحملة',
          description: campaign.name
        });
        fetchCampaigns();
      } else {
        toast({
          title: 'خطأ',
          description: data.message || 'حدث خطأ',
          variant: 'destructive'
        });
      }
    } catch (error) {
      toast({
        title: 'خطأ',
        description: 'فشل الاتصال بالخادم',
        variant: 'destructive'
      });
    }
  };

  return (
    <div className="min-h-screen bg-background">
      <Navigation />

      <main className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex items-center justify-between mb-8">
          <div>
            <h1 className="text-4xl font-bold text-foreground mb-2">الحملات</h1>
            <p className="text-muted-foreground text-lg">إدارة حملات جمع البيانات</p>
          </div>
          <Button
            className="gap-2 gradient-primary text-white"
            onClick={() => setShowForm(true)}
          >
            <Plus className="w-5 h-5" />
            حملة جديدة
          </Button>
        </div>

        {/* Create Campaign Form */}
        {showForm && (
          <Card className="p-6 shadow-card mb-8">
            <h2 className="text-2xl font-bold text-foreground mb-6">إنشاء حملة جديدة</h2>
            <form onSubmit={handleCreateCampaign} className="space-y-6">
              <div className="grid md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <Label htmlFor="name">اسم الحملة</Label>
                  <Input
                    id="name"
                    placeholder="أدخل اسم الحملة"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    required
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="city">المدينة</Label>
                  <SearchableSelect
                    options={SAUDI_CITIES.map(city => ({ value: city, label: city }))}
                    value={formData.city}
                    onValueChange={(value) => setFormData({ ...formData, city: value })}
                    placeholder="اختر المدينة"
                    searchPlaceholder="ابحث عن مدينة..."
                    emptyText="لم يتم العثور على مدينة"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="category">الفئة (كلمة البحث)</Label>
                  <SearchableSelect
                    options={categories}
                    value={formData.category}
                    onValueChange={(value) => setFormData({ ...formData, category: value })}
                    placeholder="اختر الفئة"
                    searchPlaceholder="ابحث عن فئة..."
                    emptyText="لم يتم العثور على فئة"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="target">الهدف (عدد العملاء)</Label>
                  <Input
                    id="target"
                    type="number"
                    placeholder="100"
                    value={formData.target}
                    onChange={(e) => setFormData({ ...formData, target: e.target.value })}
                    required
                  />
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="description">وصف الحملة</Label>
                <Textarea
                  id="description"
                  placeholder="أدخل وصف الحملة..."
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  rows={3}
                />
              </div>

              <div className="flex gap-3">
                <Button
                  type="submit"
                  className="gradient-primary text-white"
                  disabled={submitting}
                >
                  {submitting ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin mr-2" />
                      جاري الإنشاء...
                    </>
                  ) : (
                    'إنشاء الحملة'
                  )}
                </Button>
                <Button type="button" variant="outline" onClick={() => setShowForm(false)}>
                  إلغاء
                </Button>
              </div>
            </form>
          </Card>
        )}

        {/* Loading State */}
        {loading && (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="w-8 h-8 animate-spin text-primary" />
          </div>
        )}

        {/* Empty State */}
        {!loading && campaigns.length === 0 && (
          <Card className="p-12 text-center">
            <Target className="w-16 h-16 mx-auto text-muted-foreground mb-4" />
            <h3 className="text-xl font-bold text-foreground mb-2">لا توجد حملات</h3>
            <p className="text-muted-foreground mb-4">ابدأ بإنشاء حملتك الأولى لجمع بيانات العملاء</p>
            <Button onClick={() => setShowForm(true)} className="gradient-primary text-white">
              <Plus className="w-4 h-4 mr-2" />
              إنشاء حملة جديدة
            </Button>
          </Card>
        )}

        {/* Campaigns List */}
        {!loading && campaigns.length > 0 && (
          <div className="grid gap-6">
            {campaigns.map((campaign) => (
              <Card key={campaign.id} className="p-6 shadow-card">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-4">
                    <div className={`p-3 rounded-xl ${campaign.status === "processing" ? "gradient-primary" : "bg-muted"}`}>
                      <Target className="w-6 h-6 text-white" />
                    </div>
                    <div>
                      <h3 className="text-xl font-bold text-foreground">{campaign.name}</h3>
                      <div className="flex items-center gap-4 mt-1 text-muted-foreground">
                        <span className="flex items-center gap-1">
                          <MapPin className="w-4 h-4" />
                          {campaign.city}
                        </span>
                        <span className="px-2 py-0.5 rounded-full bg-primary/10 text-primary text-sm">
                          {campaign.query}
                        </span>
                      </div>
                    </div>
                  </div>

                  <div className="flex items-center gap-6">
                    <div className="text-center">
                      <p className="text-2xl font-bold text-foreground">{campaign.result_count}</p>
                      <p className="text-sm text-muted-foreground">عميل</p>
                    </div>

                    <div className="w-32">
                      <div className="flex justify-between text-sm mb-1">
                        <span className="text-muted-foreground">التقدم</span>
                        <span className="font-semibold text-foreground">{Math.round(campaign.progress_percent)}%</span>
                      </div>
                      <div className="h-2 bg-muted rounded-full overflow-hidden">
                        <div
                          className="h-full gradient-primary rounded-full transition-all"
                          style={{ width: `${Math.min(campaign.progress_percent, 100)}%` }}
                        />
                      </div>
                    </div>

                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusStyle(campaign.status)}`}>
                      {getStatusLabel(campaign.status)}
                    </span>

                    <div className="flex gap-2">
                      <Button
                        variant="outline"
                        size="icon"
                        onClick={() => handleToggleCampaign(campaign)}
                        title={campaign.status === "processing" ? "إيقاف" : "تشغيل"}
                      >
                        {campaign.status === "processing" ? (
                          <Pause className="w-4 h-4" />
                        ) : (
                          <Play className="w-4 h-4" />
                        )}
                      </Button>
                      <Button
                        variant="outline"
                        size="icon"
                        onClick={() => handleDeleteCampaign(campaign)}
                        title="حذف الحملة"
                      >
                        <MoreVertical className="w-4 h-4" />
                      </Button>
                    </div>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )}
      </main>
    </div>
  );
};

export default Campaigns;
