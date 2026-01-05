import Navigation from "@/components/Navigation";
import StatsCard from "@/components/StatsCard";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Users,
  Target,
  BarChart3,
  Download,
  Calendar,
  MapPin,
  Loader2
} from "lucide-react";
import { useState, useEffect } from "react";
import { getAuthToken } from "@/lib/auth";

interface AnalyticsData {
  stats: {
    totalLeads: number;
    totalCampaigns: number;
    activeCampaigns: number;
    completedCampaigns: number;
    uniqueCities: number;
  };
  cityData: { city: string; leads: number; percentage: number }[];
  categoryData: { category: string; leads: number }[];
  monthlyTrend: { month: string; value: number; percentage: number }[];
}

const API_BASE = "http://localhost:8080/v1/api";

const Analytics = () => {
  const [loading, setLoading] = useState(true);
  const [period, setPeriod] = useState("30days");
  const [data, setData] = useState<AnalyticsData | null>(null);

  useEffect(() => {
    fetchAnalytics();
  }, [period]);

  const fetchAnalytics = async () => {
    try {
      setLoading(true);
      const token = getAuthToken();
      const res = await fetch(`${API_BASE}/analytics.php?period=${period}`, {
        headers: { Authorization: `Bearer ${token}` }
      });
      const result = await res.json();
      if (result.ok) {
        setData(result);
      }
    } catch (error) {
      console.error("Failed to fetch analytics:", error);
    } finally {
      setLoading(false);
    }
  };

  const handleExport = () => {
    if (!data) return;

    // Create CSV report
    let csv = "التقرير التحليلي\n\n";
    csv += `إجمالي العملاء,${data.stats.totalLeads}\n`;
    csv += `إجمالي الحملات,${data.stats.totalCampaigns}\n`;
    csv += `المدن المغطاة,${data.stats.uniqueCities}\n\n`;

    csv += "توزيع المدن\n";
    csv += "المدينة,العملاء,النسبة\n";
    data.cityData.forEach(c => {
      csv += `${c.city},${c.leads},${c.percentage}%\n`;
    });

    csv += "\nتوزيع الفئات\n";
    csv += "الفئة,العملاء\n";
    data.categoryData.forEach(c => {
      csv += `${c.category},${c.leads}\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `analytics_report_${period}.csv`;
    link.click();
  };

  return (
    <div className="min-h-screen bg-background">
      <Navigation />

      <main className="container mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex items-center justify-between mb-8">
          <div>
            <h1 className="text-4xl font-bold text-foreground mb-2">التحليلات والإحصائيات</h1>
            <p className="text-muted-foreground text-lg">تقارير تفصيلية عن أداء حملاتك وعملائك المحتملين</p>
          </div>

          <div className="flex gap-2">
            <Select value={period} onValueChange={setPeriod}>
              <SelectTrigger className="w-48">
                <Calendar className="w-4 h-4 ml-2" />
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="7days">آخر 7 أيام</SelectItem>
                <SelectItem value="30days">آخر 30 يوم</SelectItem>
                <SelectItem value="90days">آخر 90 يوم</SelectItem>
                <SelectItem value="year">السنة الحالية</SelectItem>
              </SelectContent>
            </Select>
            <Button variant="outline" className="gap-2" onClick={handleExport} disabled={!data}>
              <Download className="w-4 h-4" />
              تصدير التقرير
            </Button>
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="w-8 h-8 animate-spin text-primary" />
          </div>
        ) : (
          <>
            {/* Overview Stats */}
            <div className="grid md:grid-cols-4 gap-6 mb-8">
              <StatsCard
                title="إجمالي العملاء"
                value={data?.stats.totalLeads.toLocaleString() || "0"}
                icon={Users}
              />
              <StatsCard
                title="إجمالي الحملات"
                value={data?.stats.totalCampaigns.toString() || "0"}
                icon={Target}
              />
              <StatsCard
                title="المدن المغطاة"
                value={data?.stats.uniqueCities.toString() || "0"}
                icon={MapPin}
              />
              <StatsCard
                title="الحملات النشطة"
                value={data?.stats.activeCampaigns.toString() || "0"}
                icon={BarChart3}
              />
            </div>

            {/* Charts Grid */}
            <div className="grid md:grid-cols-2 gap-6 mb-8">
              {/* City Distribution */}
              <Card className="p-6 shadow-card">
                <h2 className="text-2xl font-bold text-foreground mb-6">توزيع العملاء حسب المدينة</h2>
                {data?.cityData && data.cityData.length > 0 ? (
                  <div className="space-y-4">
                    {data.cityData.map((item, index) => (
                      <div key={index}>
                        <div className="flex items-center justify-between mb-2">
                          <span className="font-semibold text-foreground">{item.city}</span>
                          <span className="text-muted-foreground">{item.leads} عميل ({item.percentage}%)</span>
                        </div>
                        <div className="w-full bg-muted rounded-full h-3 overflow-hidden">
                          <div
                            className="h-full gradient-primary transition-all duration-500"
                            style={{ width: `${item.percentage}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    لا توجد بيانات بعد. أنشئ حملة لجمع العملاء.
                  </div>
                )}
              </Card>

              {/* Category Performance */}
              <Card className="p-6 shadow-card">
                <h2 className="text-2xl font-bold text-foreground mb-6">الأداء حسب الفئة</h2>
                {data?.categoryData && data.categoryData.length > 0 ? (
                  <div className="space-y-4">
                    {data.categoryData.map((item, index) => (
                      <div
                        key={index}
                        className="flex items-center justify-between p-4 rounded-lg bg-muted/50 hover:bg-muted transition-smooth"
                      >
                        <div>
                          <h3 className="font-bold text-foreground mb-1">{item.category}</h3>
                          <p className="text-sm text-muted-foreground">{item.leads} عميل محتمل</p>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    لا توجد بيانات بعد. أنشئ حملة لجمع العملاء.
                  </div>
                )}
              </Card>
            </div>

            {/* Performance Trends */}
            <Card className="p-6 shadow-card">
              <h2 className="text-2xl font-bold text-foreground mb-6">اتجاهات الأداء الشهرية</h2>
              {data?.monthlyTrend && data.monthlyTrend.some(m => m.value > 0) ? (
                <div className="h-64 flex items-end justify-between gap-4">
                  {data.monthlyTrend.map((item, index) => (
                    <div key={index} className="flex-1 flex flex-col items-center gap-2">
                      <span className="text-sm font-bold text-primary">{item.value}</span>
                      <div className="w-full flex flex-col justify-end h-48">
                        <div
                          className="w-full rounded-t-lg gradient-primary transition-all duration-500 hover:opacity-80"
                          style={{ height: `${item.percentage || 5}%` }}
                        />
                      </div>
                      <span className="text-sm text-muted-foreground font-medium">{item.month}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-16 text-muted-foreground">
                  لا توجد بيانات شهرية بعد. ستظهر الإحصائيات عند إنشاء حملات.
                </div>
              )}
            </Card>

            {/* Insights - Dynamic based on data */}
            {data && data.stats.totalLeads > 0 && (
              <div className="grid md:grid-cols-3 gap-6 mt-8">
                <Card className="p-6 shadow-card border-r-4 border-r-primary">
                  <h3 className="font-bold text-foreground mb-2">📊 ملخص</h3>
                  <p className="text-muted-foreground text-sm">
                    لديك {data.stats.totalLeads} عميل محتمل من {data.stats.totalCampaigns} حملة موزعين على {data.stats.uniqueCities} مدينة.
                  </p>
                </Card>

                {data.cityData.length > 0 && (
                  <Card className="p-6 shadow-card border-r-4 border-r-success">
                    <h3 className="font-bold text-foreground mb-2">🏙️ أفضل مدينة</h3>
                    <p className="text-muted-foreground text-sm">
                      {data.cityData[0].city} هي الأفضل أداءً بـ {data.cityData[0].leads} عميل ({data.cityData[0].percentage}%).
                    </p>
                  </Card>
                )}

                {data.categoryData.length > 0 && (
                  <Card className="p-6 shadow-card border-r-4 border-r-secondary">
                    <h3 className="font-bold text-foreground mb-2">🎯 أفضل فئة</h3>
                    <p className="text-muted-foreground text-sm">
                      فئة "{data.categoryData[0].category}" تحتوي على {data.categoryData[0].leads} عميل محتمل.
                    </p>
                  </Card>
                )}
              </div>
            )}
          </>
        )}
      </main>
    </div>
  );
};

export default Analytics;
