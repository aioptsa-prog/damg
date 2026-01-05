import { Link, useLocation, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import {
  Home,
  LayoutDashboard,
  Users,
  BarChart3,
  Target,
  Menu,
  X,
  LogOut,
  MessageCircle,
  User,
  LogIn,
  CreditCard
} from "lucide-react";
import { useState } from "react";
import { useAuth } from "@/contexts/AuthContext";

const Navigation = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { logout, user, isAuthenticated } = useAuth();
  const [isMenuOpen, setIsMenuOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  // Links that require authentication
  const protectedNavItems = [
    { path: "/dashboard", label: "لوحة التحكم", icon: LayoutDashboard },
    { path: "/leads", label: "العملاء المحتملين", icon: Users },
    { path: "/campaigns", label: "الحملات", icon: Target },
    { path: "/analytics", label: "التحليلات", icon: BarChart3 },
    { path: "/whatsapp-settings", label: "الواتساب", icon: MessageCircle },
  ];

  // Public links (always visible)
  const publicNavItems = [
    { path: "/", label: "الرئيسية", icon: Home },
    { path: "/public/pricing", label: "الباقات", icon: CreditCard },
  ];

  // Combine based on auth status
  const navItems = isAuthenticated
    ? [...publicNavItems, ...protectedNavItems]
    : publicNavItems;

  return (
    <nav className="sticky top-0 z-50 bg-card/80 backdrop-blur-lg border-b border-border shadow-sm">
      <div className="container mx-auto px-4">
        <div className="flex items-center justify-between h-16">
          <Link to="/" className="flex items-center gap-2 group">
            <div className="w-10 h-10 rounded-lg gradient-primary flex items-center justify-center shadow-elegant transition-smooth group-hover:scale-105">
              <span className="text-white font-bold text-xl">L</span>
            </div>
            <span className="text-xl font-bold gradient-text">
              LeadHub
            </span>
          </Link>

          {/* Desktop Navigation */}
          <div className="hidden md:flex items-center gap-1">
            {navItems.map((item) => {
              const Icon = item.icon;
              const isActive = location.pathname === item.path;

              return (
                <Link key={item.path} to={item.path}>
                  <Button
                    variant={isActive ? "default" : "outline"}
                    className={`gap-2 transition-smooth ${isActive
                      ? "gradient-primary text-white shadow-elegant"
                      : "hover:bg-muted"
                      }`}
                  >
                    <Icon className="w-4 h-4" />
                    {item.label}
                  </Button>
                </Link>
              );
            })}

            {/* Show these only when authenticated */}
            {isAuthenticated ? (
              <>
                {/* Profile Button */}
                <Link to="/profile">
                  <Button variant="ghost" className="gap-2">
                    <User className="w-4 h-4" />
                    الملف الشخصي
                  </Button>
                </Link>

                {/* Logout Button */}
                <Button
                  variant="ghost"
                  onClick={handleLogout}
                  className="gap-2 text-red-600 hover:bg-red-50 hover:text-red-700"
                >
                  <LogOut className="w-4 h-4" />
                  تسجيل الخروج
                </Button>
              </>
            ) : (
              /* Login Button - Only when NOT authenticated */
              <Link to="/login">
                <Button className="gap-2 gradient-primary text-white">
                  <LogIn className="w-4 h-4" />
                  تسجيل الدخول
                </Button>
              </Link>
            )}
          </div>

          {/* Mobile Menu Button */}
          <button
            onClick={() => setIsMenuOpen(!isMenuOpen)}
            className="md:hidden p-2 rounded-lg hover:bg-muted transition-smooth"
          >
            {isMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
          </button>
        </div>

        {/* Mobile Navigation */}
        {isMenuOpen && (
          <div className="md:hidden py-4 space-y-2 border-t border-border">
            {navItems.map((item) => {
              const Icon = item.icon;
              const isActive = location.pathname === item.path;

              return (
                <Link
                  key={item.path}
                  to={item.path}
                  onClick={() => setIsMenuOpen(false)}
                >
                  <Button
                    variant={isActive ? "default" : "ghost"}
                    className={`w-full justify-start gap-2 transition-smooth ${isActive
                      ? "gradient-primary text-white"
                      : ""
                      }`}
                  >
                    <Icon className="w-4 h-4" />
                    {item.label}
                  </Button>
                </Link>
              );
            })}

            {/* Show these only when authenticated */}
            {isAuthenticated ? (
              <>
                {/* Mobile Profile Button */}
                <Link to="/profile" onClick={() => setIsMenuOpen(false)}>
                  <Button variant="ghost" className="w-full justify-start gap-2">
                    <User className="w-4 h-4" />
                    الملف الشخصي
                  </Button>
                </Link>

                {/* Mobile Logout Button */}
                <Button
                  variant="ghost"
                  onClick={() => {
                    setIsMenuOpen(false);
                    handleLogout();
                  }}
                  className="w-full justify-start gap-2 text-red-600 hover:bg-red-50"
                >
                  <LogOut className="w-4 h-4" />
                  تسجيل الخروج
                </Button>
              </>
            ) : (
              /* Mobile Login Button */
              <Link to="/login" onClick={() => setIsMenuOpen(false)}>
                <Button className="w-full justify-start gap-2 gradient-primary text-white">
                  <LogIn className="w-4 h-4" />
                  تسجيل الدخول
                </Button>
              </Link>
            )}
          </div>
        )}
      </div>
    </nav>
  );
};

export default Navigation;
