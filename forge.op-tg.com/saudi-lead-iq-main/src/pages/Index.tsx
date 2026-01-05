import Navigation from "@/components/Navigation";
import FeatureCard from "@/components/FeatureCard";
import { Button } from "@/components/ui/button";
import { ArrowLeft, Database, Brain, TrendingUp, MapPin, Sparkles, Target } from "lucide-react";
import { Link } from "react-router-dom";
import heroImage from "@/assets/hero-bg.jpg";
import featureData from "@/assets/feature-data.jpg";
import featureAI from "@/assets/feature-ai.jpg";
import featureGrowth from "@/assets/feature-growth.jpg";

const Index = () => {
  return (
    <div className="min-h-screen">
      <Navigation />

      {/* Hero Section */}
      <section className="relative min-h-[90vh] flex items-center overflow-hidden">
        {/* Background with gradient overlay */}
        <div className="absolute inset-0 z-0">
          <div
            className="absolute inset-0"
            style={{
              backgroundImage: `url(${heroImage})`,
              backgroundSize: 'cover',
              backgroundPosition: 'center',
            }}
          />
          <div className="absolute inset-0 bg-gradient-to-br from-background via-background/95 to-primary/10" />
          <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-primary/20 via-transparent to-transparent" />
        </div>

        {/* Animated floating elements */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute top-20 right-10 w-72 h-72 bg-primary/10 rounded-full blur-3xl animate-pulse" />
          <div className="absolute bottom-20 left-10 w-96 h-96 bg-secondary/10 rounded-full blur-3xl animate-pulse" style={{ animationDelay: '1s' }} />
          <div className="absolute top-1/2 left-1/4 w-4 h-4 bg-primary/40 rounded-full animate-bounce" style={{ animationDelay: '0.5s' }} />
          <div className="absolute top-1/3 right-1/4 w-3 h-3 bg-success/40 rounded-full animate-bounce" style={{ animationDelay: '1s' }} />
          <div className="absolute bottom-1/3 right-1/3 w-2 h-2 bg-primary/60 rounded-full animate-bounce" style={{ animationDelay: '1.5s' }} />
        </div>

        <div className="container mx-auto px-4 py-20 relative z-10">
          <div className="max-w-4xl mx-auto text-center">
            {/* Animated badge */}
            <div className="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-gradient-to-r from-primary/20 to-primary/5 border border-primary/30 mb-8 animate-fade-in backdrop-blur-sm shadow-lg">
              <span className="relative flex h-2 w-2">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                <span className="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
              </span>
              <Sparkles className="w-4 h-4 text-primary" />
              <span className="text-sm font-bold text-primary">نظام ذكي متكامل</span>
            </div>

            {/* Main heading with enhanced typography */}
            <h1 className="text-5xl md:text-7xl lg:text-8xl font-bold mb-8 leading-[1.1] tracking-tight">
              <span className="block bg-clip-text text-transparent bg-gradient-to-l from-primary via-primary to-primary-glow drop-shadow-sm animate-fade-in" style={{ animationDelay: '0.1s' }}>
                LeadHub
              </span>
              <span className="block text-foreground text-4xl md:text-5xl lg:text-6xl mt-4 animate-fade-in" style={{ animationDelay: '0.2s' }}>
                مستقبل توليد العملاء المحتملين
              </span>
            </h1>

            {/* Enhanced description */}
            <p className="text-lg md:text-xl text-muted-foreground mb-10 max-w-2xl mx-auto leading-relaxed animate-fade-in" style={{ animationDelay: '0.3s' }}>
              منصة متكاملة تجمع وتحلل البيانات من
              <span className="text-primary font-semibold"> جوجل ماب </span>
              ومصادر متعددة بالذكاء الاصطناعي لتوليد عملاء محتملين
              <span className="text-primary font-semibold"> عالي الجودة </span>
              لشركتك
            </p>

            {/* CTA buttons with enhanced styling */}
            <div className="flex flex-col sm:flex-row gap-4 justify-center items-center mb-14 animate-fade-in" style={{ animationDelay: '0.4s' }}>
              <Link to="/dashboard">
                <Button size="lg" className="group relative gradient-primary text-white shadow-elegant hover:shadow-xl transition-all duration-300 text-lg px-10 py-7 rounded-xl overflow-hidden">
                  <span className="absolute inset-0 bg-white/20 translate-y-full group-hover:translate-y-0 transition-transform duration-300" />
                  <span className="relative flex items-center gap-2">
                    ابدأ الآن
                    <ArrowLeft className="w-5 h-5 group-hover:-translate-x-1 transition-transform" />
                  </span>
                </Button>
              </Link>
              <Link to="/analytics">
                <Button size="lg" variant="outline" className="text-lg px-10 py-7 rounded-xl border-2 border-primary/30 hover:border-primary hover:bg-primary/5 transition-all duration-300 backdrop-blur-sm">
                  استكشف الميزات
                </Button>
              </Link>
            </div>

            {/* Trust indicators with cards */}
            <div className="flex flex-wrap gap-4 justify-center animate-fade-in" style={{ animationDelay: '0.5s' }}>
              <div className="flex items-center gap-3 px-5 py-3 rounded-xl bg-card/50 border border-border/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-all hover:-translate-y-0.5">
                <div className="p-2 rounded-lg bg-primary/10">
                  <MapPin className="w-5 h-5 text-primary" />
                </div>
                <span className="font-medium text-foreground">جميع مدن المملكة</span>
              </div>
              <div className="flex items-center gap-3 px-5 py-3 rounded-xl bg-card/50 border border-border/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-all hover:-translate-y-0.5">
                <div className="p-2 rounded-lg bg-primary/10">
                  <Database className="w-5 h-5 text-primary" />
                </div>
                <span className="font-medium text-foreground">مصادر بيانات متعددة</span>
              </div>
              <div className="flex items-center gap-3 px-5 py-3 rounded-xl bg-card/50 border border-border/50 backdrop-blur-sm shadow-sm hover:shadow-md transition-all hover:-translate-y-0.5">
                <div className="p-2 rounded-lg bg-primary/10">
                  <Brain className="w-5 h-5 text-primary" />
                </div>
                <span className="font-medium text-foreground">تحليل بالذكاء الاصطناعي</span>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom gradient fade */}
        <div className="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-t from-background to-transparent z-10" />
      </section>

      {/* Features Section */}
      <section className="py-20 bg-muted/30">
        <div className="container mx-auto px-4">
          <div className="text-center mb-16">
            <h2 className="text-4xl md:text-5xl font-bold text-foreground mb-4">
              ميزات قوية لنمو أعمالك
            </h2>
            <p className="text-xl text-muted-foreground max-w-2xl mx-auto">
              نوفر لك أدوات متقدمة لجمع وتحليل البيانات واستخراج رؤى قيمة
            </p>
          </div>

          <div className="grid md:grid-cols-3 gap-8 max-w-6xl mx-auto">
            <FeatureCard
              title="جمع البيانات الذكي"
              description="نجمع البيانات من جوجل ماب ومصادر متعددة تلقائياً، مع تنظيف وتحقق من الجودة في الوقت الفعلي"
              image={featureData}
            />
            <FeatureCard
              title="تحليل بالذكاء الاصطناعي"
              description="محرك ذكاء اصطناعي متقدم يحلل البيانات ويتنبأ بسلوك العملاء ويحدد أفضل الفرص"
              image={featureAI}
            />
            <FeatureCard
              title="نمو مضمون"
              description="تقارير وتحليلات تفصيلية تساعدك على اتخاذ قرارات مبنية على البيانات وتحقيق نمو مستدام"
              image={featureGrowth}
            />
          </div>
        </div>
      </section>

      {/* Stats Section */}
      <section className="py-20">
        <div className="container mx-auto px-4">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-8 max-w-5xl mx-auto">
            {[
              { value: "500K+", label: "عميل محتمل" },
              { value: "10000+", label: "شركة سعودية" },
              { value: "498", label: "مدينة مغطاة" },
              { value: "98%", label: "دقة البيانات" },
            ].map((stat, index) => (
              <div key={index} className="text-center">
                <div className="text-4xl md:text-5xl font-bold gradient-text mb-2">
                  {stat.value}
                </div>
                <div className="text-muted-foreground">{stat.label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="py-20 gradient-hero">
        <div className="container mx-auto px-4">
          <div className="max-w-3xl mx-auto text-center">
            <Target className="w-16 h-16 mx-auto mb-6 text-primary" />
            <h2 className="text-4xl md:text-5xl font-bold text-foreground mb-6">
              هل أنت مستعد لتنمية أعمالك؟
            </h2>
            <p className="text-xl text-muted-foreground mb-8">
              انضم إلى المئات من الشركات السعودية التي تستخدم LeadHub لتوليد عملاء محتملين عالي الجودة
            </p>
            <Link to="/dashboard">
              <Button size="lg" className="gradient-primary text-white shadow-elegant hover:shadow-xl transition-smooth text-lg px-8 py-6">
                <span>ابدأ مجاناً الآن</span>
                <ArrowLeft className="w-5 h-5 mr-2" />
              </Button>
            </Link>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="py-8 border-t border-border">
        <div className="container mx-auto px-4 text-center text-muted-foreground">
          <p>© 2025 LeadHub. جميع الحقوق محفوظة.</p>
        </div>
      </footer>
    </div>
  );
};

export default Index;
