import Navigation from "@/components/Navigation";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { SearchableSelect } from "@/components/ui/searchable-select";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Search,
  Download,
  MapPin,
  Phone,
  Star,
  ExternalLink,
  Clock,
  Globe,
  Loader2,
  MessageCircle,
  Send,
  CheckSquare,
  Square
} from "lucide-react";
import { useState, useEffect } from "react";
import { api, Lead as ApiLead, Category } from "@/lib/api";
import { useAuth } from "@/contexts/AuthContext";
import WhatsAppSendDialog from "@/components/WhatsAppSendDialog";
import BulkWhatsAppDialog from "@/components/BulkWhatsAppDialog";

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

const Leads = () => {
  const { isAuthenticated } = useAuth();
  const [selectedLead, setSelectedLead] = useState<ApiLead | null>(null);
  const [leads, setLeads] = useState<ApiLead[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedCategory, setSelectedCategory] = useState<string>("all");
  const [selectedCity, setSelectedCity] = useState<string>("all");
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState<any>(null);
  const [exporting, setExporting] = useState(false);
  const [whatsappDialogOpen, setWhatsappDialogOpen] = useState(false);
  const [whatsappLead, setWhatsappLead] = useState<ApiLead | null>(null);

  // Bulk selection state
  const [bulkMode, setBulkMode] = useState(false);
  const [selectedLeadIds, setSelectedLeadIds] = useState<Set<number>>(new Set());
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false);

  // Fetch leads and categories on mount
  useEffect(() => {
    fetchLeads();
    fetchCategories();
  }, [page, selectedCategory, selectedCity, searchTerm]);

  const fetchLeads = async () => {
    setLoading(true);
    try {
      const params: any = {
        page,
        limit: 10,
        search: searchTerm || undefined,
      };

      if (selectedCategory && selectedCategory !== "all") {
        params.category_id = parseInt(selectedCategory);
      }

      if (selectedCity && selectedCity !== "all") {
        params.city_id = parseInt(selectedCity);
      }

      const response = await api.getLeads(params);
      console.log('Leads Response:', response);

      if (response.ok) {
        // API returns leads in response.data or response.leads
        const leadsData = (response as any).leads || response.data || [];
        setLeads(leadsData);
        setPagination((response as any).pagination);
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
      console.log('Categories Response:', response);

      if (response.ok) {
        // API returns categories in response.flat or response.data.flat
        const categoriesData = (response as any).flat || response.data?.flat || [];
        setCategories(categoriesData);
      }
    } catch (error) {
      console.error('Failed to fetch categories:', error);
    }
  };

  // Export leads to Excel
  const handleExport = async () => {
    if (leads.length === 0) {
      alert('لا توجد نتائج للتصدير');
      return;
    }

    setExporting(true);
    try {
      // Dynamic import of xlsx library
      const XLSX = await import('xlsx');

      // Prepare data for Excel - Array of arrays format works better
      const headers = ['الاسم', 'الفئة', 'المدينة', 'الهاتف', 'التقييم', 'الموقع الإلكتروني'];
      const rows = leads.map(lead => [
        lead.name || '',
        lead.category?.name || '',
        lead.location?.city_name || lead.city || '',
        lead.phone || '',
        lead.rating || '',
        lead.website || ''
      ]);

      // Create worksheet from array of arrays
      const worksheet = XLSX.utils.aoa_to_sheet([headers, ...rows]);

      // Set column widths for better readability
      worksheet['!cols'] = [
        { wch: 40 },  // الاسم
        { wch: 25 },  // الفئة
        { wch: 20 },  // المدينة
        { wch: 18 },  // الهاتف
        { wch: 12 },  // التقييم
        { wch: 35 },  // الموقع الإلكتروني
      ];

      // Create workbook
      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(workbook, worksheet, 'العملاء');

      // Generate filename with date
      const filename = `leads_export_${new Date().toISOString().split('T')[0]}.xlsx`;

      // Write file directly - this is the most reliable method
      XLSX.writeFile(workbook, filename);

    } catch (error) {
      console.error('Export failed:', error);
      alert('حدث خطأ أثناء التصدير: ' + (error as Error).message);
    } finally {
      setExporting(false);
    }
  };

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      if (page === 1) {
        fetchLeads();
      } else {
        setPage(1);
      }
    }, 500);

    return () => clearTimeout(timer);
  }, [searchTerm]);

  return (
    <div className="min-h-screen bg-background">
      <Navigation />

      <main className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-4xl font-bold text-foreground mb-2">العملاء المحتملين</h1>
          <p className="text-muted-foreground text-lg">استعرض وفلتر قاعدة بيانات العملاء</p>
        </div>

        {/* Search and Filters */}
        <Card className="p-6 shadow-card mb-6">
          <div className="grid md:grid-cols-4 gap-4">
            <div className="md:col-span-2">
              <div className="relative">
                <Search className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                <Input
                  placeholder="ابحث عن عميل..."
                  className="pr-10 h-11"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                />
              </div>
            </div>

            <SearchableSelect
              options={[{ value: "all", label: "جميع المدن" }, ...SAUDI_CITIES.map(city => ({ value: city, label: city }))]}
              value={selectedCity}
              onValueChange={setSelectedCity}
              placeholder="المدينة"
              searchPlaceholder="ابحث عن مدينة..."
              emptyText="لم يتم العثور على مدينة"
            />

            <SearchableSelect
              options={[{ value: "all", label: "جميع الفئات" }, ...MAIN_CATEGORIES.map(cat => ({ value: cat, label: cat }))]}
              value={selectedCategory}
              onValueChange={setSelectedCategory}
              placeholder="الفئة"
              searchPlaceholder="ابحث عن فئة..."
              emptyText="لم يتم العثور على فئة"
            />
          </div>

          <div className="flex gap-2 mt-4 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              className="gap-2"
              onClick={handleExport}
              disabled={exporting || leads.length === 0}
            >
              {exporting ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Download className="w-4 h-4" />
              )}
              {exporting ? 'جاري التصدير...' : 'تصدير النتائج'}
            </Button>

            {/* Bulk Selection Toggle */}
            <Button
              variant={bulkMode ? "default" : "outline"}
              size="sm"
              className="gap-2"
              onClick={() => {
                setBulkMode(!bulkMode);
                setSelectedLeadIds(new Set());
              }}
            >
              {bulkMode ? <CheckSquare className="w-4 h-4" /> : <Square className="w-4 h-4" />}
              {bulkMode ? 'إلغاء التحديد' : 'إرسال مجمع'}
            </Button>

            {bulkMode && selectedLeadIds.size > 0 && (
              <Button
                size="sm"
                className="gap-2 bg-green-600 hover:bg-green-700"
                onClick={() => setBulkDialogOpen(true)}
              >
                <Send className="w-4 h-4" />
                إرسال لـ {selectedLeadIds.size} محدد
              </Button>
            )}

            {bulkMode && leads.length > 0 && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  if (selectedLeadIds.size === leads.length) {
                    setSelectedLeadIds(new Set());
                  } else {
                    setSelectedLeadIds(new Set(leads.map(l => l.id)));
                  }
                }}
              >
                {selectedLeadIds.size === leads.length ? 'إلغاء تحديد الكل' : 'تحديد الكل'}
              </Button>
            )}
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
          <Select defaultValue="rating">
            <SelectTrigger className="w-48">
              <SelectValue placeholder="ترتيب حسب" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="rating">الأعلى تقييماً</SelectItem>
              <SelectItem value="reviews">الأكثر مراجعات</SelectItem>
              <SelectItem value="recent">الأحدث</SelectItem>
            </SelectContent>
          </Select>
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
              {leads.map((lead) => (
                <Card key={lead.id} className={`p-6 shadow-card hover:shadow-elegant transition-smooth ${bulkMode && selectedLeadIds.has(lead.id) ? 'ring-2 ring-primary' : ''}`}>
                  <div className="flex items-start justify-between mb-4">
                    {bulkMode && (
                      <div className="ml-3">
                        <Checkbox
                          checked={selectedLeadIds.has(lead.id)}
                          onCheckedChange={(checked) => {
                            const newSet = new Set(selectedLeadIds);
                            if (checked) {
                              newSet.add(lead.id);
                            } else {
                              newSet.delete(lead.id);
                            }
                            setSelectedLeadIds(newSet);
                          }}
                        />
                      </div>
                    )}
                    <div>
                      <h3 className="text-xl font-bold text-foreground mb-2">{lead.name}</h3>
                      <span className="px-3 py-1 rounded-full bg-primary/10 text-primary text-sm font-medium">
                        {lead.category?.name || 'غير محدد'}
                      </span>
                    </div>
                    {lead.rating && (
                      <div className="flex items-center gap-1 bg-yellow-50 dark:bg-yellow-900/20 px-3 py-1 rounded-lg">
                        <Star className="w-4 h-4 text-yellow-500 fill-yellow-500" />
                        <span className="font-bold text-foreground">{lead.rating}</span>
                      </div>
                    )}
                  </div>

                  <div className="space-y-3 mb-4">
                    <div className="flex items-center gap-2 text-muted-foreground">
                      <MapPin className="w-4 h-4 text-primary" />
                      <span>
                        {lead.location.city_name || lead.city}
                        {lead.location.district_name && ` - ${lead.location.district_name}`}
                      </span>
                    </div>
                    <div className="flex items-center gap-2 text-muted-foreground" dir="ltr">
                      <Phone className="w-4 h-4 text-primary" />
                      <span>{lead.phone}</span>
                    </div>
                  </div>

                  <div className="flex gap-2">
                    <Button
                      variant="default"
                      className="flex-1 bg-green-600 hover:bg-green-700 text-white"
                      onClick={() => {
                        setWhatsappLead(lead);
                        setWhatsappDialogOpen(true);
                      }}
                    >
                      <MessageCircle className="w-4 h-4 ml-2" />
                      إرسال واتساب
                    </Button>
                    <Button variant="outline" className="flex-1" onClick={() => setSelectedLead(lead)}>
                      <ExternalLink className="w-4 h-4 ml-2" />
                      عرض التفاصيل
                    </Button>
                  </div>
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
                    variant={page === i + 1 ? "default" : "outline"}
                    className={page === i + 1 ? "gradient-primary text-white" : ""}
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

        {/* Lead Details Dialog */}
        <Dialog open={!!selectedLead} onOpenChange={() => setSelectedLead(null)}>
          <DialogContent className="max-w-md">
            <DialogHeader>
              <DialogTitle className="text-2xl">{selectedLead?.name}</DialogTitle>
            </DialogHeader>
            {selectedLead && (
              <div className="space-y-4 mt-4">
                <div className="flex items-center justify-between">
                  <span className="px-3 py-1 rounded-full bg-primary/10 text-primary text-sm font-medium">
                    {selectedLead.category?.name || 'غير محدد'}
                  </span>
                  {selectedLead.rating && (
                    <div className="flex items-center gap-1 bg-yellow-50 dark:bg-yellow-900/20 px-3 py-1 rounded-lg">
                      <Star className="w-4 h-4 text-yellow-500 fill-yellow-500" />
                      <span className="font-bold">{selectedLead.rating}</span>
                    </div>
                  )}
                </div>

                <div className="space-y-3 p-4 bg-muted/50 rounded-xl">
                  <div className="flex items-center gap-3">
                    <div className="p-2 rounded-lg bg-primary/10">
                      <MapPin className="w-5 h-5 text-primary" />
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">الموقع</p>
                      <p className="font-medium">
                        {selectedLead.location.city_name || selectedLead.city}
                        {selectedLead.location.district_name && ` - ${selectedLead.location.district_name}`}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className="p-2 rounded-lg bg-primary/10">
                      <Phone className="w-5 h-5 text-primary" />
                    </div>
                    <div>
                      <p className="text-sm text-muted-foreground">الهاتف</p>
                      <p className="font-medium" dir="ltr">{selectedLead.phone}</p>
                    </div>
                  </div>
                  {selectedLead.website && (
                    <div className="flex items-center gap-3">
                      <div className="p-2 rounded-lg bg-primary/10">
                        <Globe className="w-5 h-5 text-primary" />
                      </div>
                      <div>
                        <p className="text-sm text-muted-foreground">الموقع الإلكتروني</p>
                        <p className="font-medium text-primary">{selectedLead.website}</p>
                      </div>
                    </div>
                  )}
                </div>

                <div className="flex gap-2 pt-2">
                  <Button className="flex-1 gradient-primary text-white">
                    <Phone className="w-4 h-4 ml-2" />
                    اتصال الآن
                  </Button>
                  <Button variant="outline" className="flex-1" onClick={() => setSelectedLead(null)}>
                    إغلاق
                  </Button>
                </div>
              </div>
            )}
          </DialogContent>
        </Dialog>

        {/* WhatsApp Send Dialog */}
        <WhatsAppSendDialog
          open={whatsappDialogOpen}
          onOpenChange={setWhatsappDialogOpen}
          lead={whatsappLead}
          onSuccess={() => {
            setWhatsappDialogOpen(false);
            setWhatsappLead(null);
          }}
        />

        {/* Bulk WhatsApp Dialog */}
        <BulkWhatsAppDialog
          open={bulkDialogOpen}
          onOpenChange={(open) => {
            setBulkDialogOpen(open);
            if (!open) {
              setBulkMode(false);
              setSelectedLeadIds(new Set());
            }
          }}
          selectedLeads={leads.filter(l => selectedLeadIds.has(l.id))}
        />
      </main>
    </div>
  );
};

export default Leads;
