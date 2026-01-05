import { useLocation, Link } from "react-router-dom";
import { useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Home, ArrowLeft } from "lucide-react";

const NotFound = () => {
  const location = useLocation();

  useEffect(() => {
    console.error("404 Error: User attempted to access non-existent route:", location.pathname);
  }, [location.pathname]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <div className="text-center max-w-md px-4">
        <div className="mb-8">
          <h1 className="text-9xl font-bold gradient-primary bg-clip-text text-transparent mb-4">404</h1>
          <h2 className="text-3xl font-bold text-foreground mb-2">الصفحة غير موجودة</h2>
          <p className="text-lg text-muted-foreground">
            عذراً، لم نتمكن من العثور على الصفحة التي تبحث عنها
          </p>
        </div>
        
        <div className="flex gap-4 justify-center">
          <Link to="/">
            <Button size="lg" className="gradient-primary text-white shadow-elegant gap-2">
              <Home className="w-5 h-5" />
              <span>العودة للرئيسية</span>
            </Button>
          </Link>
          <Link to="/dashboard">
            <Button size="lg" variant="outline" className="gap-2">
              <span>لوحة التحكم</span>
              <ArrowLeft className="w-5 h-5" />
            </Button>
          </Link>
        </div>
      </div>
    </div>
  );
};

export default NotFound;
