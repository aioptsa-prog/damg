import Navigation from "@/components/Navigation";
import StatsCard from "@/components/StatsCard";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { useNavigate } from "react-router-dom";
import { useToast } from "@/hooks/use-toast";
import { useEffect, useState } from "react";
import api from "@/lib/api";
import {
  Users,
  MapPin,
  Target,
  Plus,
  Search,
  Filter,
  Download,
  Loader2
} from "lucide-react";

interface Lead {
  id: number;
  name: string;
  category?: { name: string } | null;
  city?: string;
  phone?: string;
  rating?: number;
}

interface DashboardStats {
  totalLeads: number;
  totalCities: number;
  activeCampaigns: number;
}

const Dashboard = () => {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [recentLeads, setRecentLeads] = useState<Lead[]>([]);
  const [stats, setStats] = useState<DashboardStats>({ totalLeads: 0, totalCities: 0, activeCampaigns: 0 });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true);

        // Fetch recent leads
        const leadsResponse = await api.getLeads({ limit: 5 });
        if (leadsResponse.ok && leadsResponse.data) {
          setRecentLeads(leadsResponse.data);
        }

        // Fetch campaigns for stats
        const campaignsResponse = await api.getCampaigns();
        if (campaignsResponse.ok) {
          const campaigns = (campaignsResponse as any).campaigns || [];
          const totalResults = campaigns.reduce((sum: number, c: any) => sum + (c.result_count || 0), 0);

          // Get unique cities from both campaigns and leads
          const campaignCities = new Set(campaigns.map((c: any) => c.city).filter(Boolean));
          const leadCities = new Set(recentLeads.map(l => l.city).filter(Boolean));
          const allCities = new Set([...campaignCities, ...leadCities]);

          // Use stats from API if available, otherwise calculate
          const totalCitiesFromAPI = (leadsResponse as any).stats?.totalCities || allCities.size;

          setStats({
            totalLeads: (leadsResponse as any).pagination?.total || totalResults || 0,
            totalCities: totalCitiesFromAPI,
            activeCampaigns: campaigns.length
          });
        }
      } catch (error) {
        console.error('Failed to fetch dashboard data:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchDashboardData();
  }, []);

  const handleExport = () => {
    // Create CSV data from leads
    const csvContent = "data:text/csv;charset=utf-8,الاسم,الفئة,الموقع,الهاتف,التقييم\n" +
      recentLeads.map(lead =>
        `${lead.name},${lead.category?.name || ''},${lead.city || ''},${lead.phone || ''},${lead.rating || ''}`
      ).join("\n");

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "leads_data.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    toast({
      title: "تم التصدير بنجاح",
      description: "تم تحميل ملف البيانات",
    });
  };

  const handleAddLead = () => {
    navigate("/leads");
  };

  return (
    <div className="min-h-screen bg-background">
      <Navigation />

      <main className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-4xl font-bold text-foreground mb-2">لوحة التحكم</h1>
          <p className="text-muted-foreground text-lg">نظرة عامة على أداء عملك</p>
        </div>

        {/* Stats Grid */}
        <div className="grid md:grid-cols-3 gap-6 mb-8">
          <StatsCard
            title="إجمالي العملاء المحتملين"
            value={loading ? "..." : stats.totalLeads.toLocaleString()}
            icon={Users}
            trend="+12.5%"
            trendUp={true}
          />
          <StatsCard
            title="المدن المغطاة"
            value={loading ? "..." : stats.totalCities.toString()}
            icon={MapPin}
            trend="+3"
            trendUp={true}
          />
          <StatsCard
            title="الحملات النشطة"
            value={loading ? "..." : stats.activeCampaigns.toString()}
            icon={Target}
            trend="+2"
            trendUp={true}
          />
        </div>

        {/* Recent Leads */}
        <Card className="p-6 shadow-card">
          <div className="flex items-center justify-between mb-6">
            <div>
              <h2 className="text-2xl font-bold text-foreground mb-1">العملاء المحتملين الأخيرين</h2>
              <p className="text-muted-foreground">آخر 5 عملاء تم جمعهم</p>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" className="gap-2" onClick={() => navigate("/leads")}>
                <Filter className="w-4 h-4" />
                تصفية
              </Button>
              <Button variant="outline" size="sm" className="gap-2" onClick={handleExport}>
                <Download className="w-4 h-4" />
                تصدير
              </Button>
              <Button size="sm" className="gap-2 gradient-primary text-white" onClick={handleAddLead}>
                <Plus className="w-4 h-4" />
                إضافة عميل
              </Button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-border text-right">
                  <th className="pb-3 pr-4 text-sm font-semibold text-muted-foreground">الاسم</th>
                  <th className="pb-3 pr-4 text-sm font-semibold text-muted-foreground">الفئة</th>
                  <th className="pb-3 pr-4 text-sm font-semibold text-muted-foreground">الموقع</th>
                  <th className="pb-3 pr-4 text-sm font-semibold text-muted-foreground">الهاتف</th>
                  <th className="pb-3 pr-4 text-sm font-semibold text-muted-foreground">التقييم</th>
                  <th className="pb-3 pr-4 text-sm font-semibold text-muted-foreground">الإجراءات</th>
                </tr>
              </thead>
              <tbody>
                {recentLeads.map((lead) => (
                  <tr key={lead.id} className="border-b border-border hover:bg-muted/50 transition-smooth">
                    <td className="py-4 pr-4">
                      <div className="font-semibold text-foreground">{lead.name}</div>
                    </td>
                    <td className="py-4 pr-4">
                      <span className="px-3 py-1 rounded-full bg-primary/10 text-primary text-sm font-medium">
                        {lead.category?.name || 'غير محدد'}
                      </span>
                    </td>
                    <td className="py-4 pr-4 text-muted-foreground">{lead.city || '-'}</td>
                    <td className="py-4 pr-4 text-muted-foreground" dir="ltr">{lead.phone}</td>
                    <td className="py-4 pr-4">
                      <div className="flex items-center gap-1">
                        <span className="text-foreground font-semibold">{lead.rating}</span>
                        <span className="text-yellow-500">★</span>
                      </div>
                    </td>
                    <td className="py-4 pr-4">
                      <Button variant="ghost" size="sm" className="gap-2" onClick={() => navigate("/leads")}>
                        <Search className="w-4 h-4" />
                        عرض
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        {/* Quick Actions */}
        <div className="grid md:grid-cols-3 gap-6 mt-8">
          <Card
            className="p-6 shadow-card hover:shadow-elegant transition-smooth cursor-pointer group"
            onClick={() => navigate("/leads")}
          >
            <div className="flex items-center gap-4">
              <div className="p-3 rounded-xl gradient-primary shadow-elegant group-hover:scale-110 transition-smooth">
                <Search className="w-6 h-6 text-white" />
              </div>
              <div>
                <h3 className="font-bold text-foreground mb-1">بحث متقدم</h3>
                <p className="text-sm text-muted-foreground">ابحث عن عملاء محددين</p>
              </div>
            </div>
          </Card>

          <Card
            className="p-6 shadow-card hover:shadow-elegant transition-smooth cursor-pointer group"
            onClick={() => navigate("/campaigns")}
          >
            <div className="flex items-center gap-4">
              <div className="p-3 rounded-xl gradient-success shadow-elegant group-hover:scale-110 transition-smooth">
                <Plus className="w-6 h-6 text-white" />
              </div>
              <div>
                <h3 className="font-bold text-foreground mb-1">حملة جديدة</h3>
                <p className="text-sm text-muted-foreground">أنشئ حملة جمع بيانات</p>
              </div>
            </div>
          </Card>

          <Card
            className="p-6 shadow-card hover:shadow-elegant transition-smooth cursor-pointer group"
            onClick={handleExport}
          >
            <div className="flex items-center gap-4">
              <div className="p-3 rounded-xl bg-secondary shadow-elegant group-hover:scale-110 transition-smooth">
                <Download className="w-6 h-6 text-white" />
              </div>
              <div>
                <h3 className="font-bold text-foreground mb-1">تصدير البيانات</h3>
                <p className="text-sm text-muted-foreground">تنزيل قاعدة البيانات</p>
              </div>
            </div>
          </Card>
        </div>
      </main>
    </div>
  );
};

export default Dashboard;
