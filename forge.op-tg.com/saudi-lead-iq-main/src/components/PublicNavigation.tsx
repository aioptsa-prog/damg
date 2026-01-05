import { useNavigate, Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    LayoutDashboard,
    Search,
    BookmarkCheck,
    List,
    CreditCard,
    LogOut,
    LogIn,
    Menu,
    X,
    Home,
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { api } from '@/lib/api';
import { clearAuthTokens, USER_KEYS, TOKEN_KEYS } from '@/lib/auth';

interface PublicNavProps {
    currentPage?: 'dashboard' | 'search' | 'saved-searches' | 'lists' | 'pricing';
}

const PublicNavigation = ({ currentPage }: PublicNavProps) => {
    const navigate = useNavigate();
    const [user, setUser] = useState<any>(null);
    const [subscription, setSubscription] = useState<any>(null);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [isLoggedIn, setIsLoggedIn] = useState(false);

    useEffect(() => {
        // Check if user is logged in
        const token = localStorage.getItem(TOKEN_KEYS.PUBLIC);
        setIsLoggedIn(!!token);

        const storedUser = localStorage.getItem(USER_KEYS.PUBLIC);
        if (storedUser) {
            setUser(JSON.parse(storedUser));
        }

        if (token) {
            fetchSubscription();
        }
    }, []);

    const fetchSubscription = async () => {
        try {
            const response = await api.getCurrentPublicUser();
            if (response.ok) {
                setSubscription((response as any).subscription);
            }
        } catch (error) {
            console.error('Failed to fetch subscription:', error);
        }
    };

    const handleLogout = async () => {
        try {
            await api.logoutPublic();
        } catch (error) {
            console.error('Logout API error:', error);
        } finally {
            clearAuthTokens();
            navigate('/public/login');
        }
    };

    // Protected nav items (only for logged in users)
    const protectedNavItems = [
        { icon: LayoutDashboard, label: 'لوحة التحكم', path: '/public/dashboard', key: 'dashboard' },
        { icon: Search, label: 'البحث', path: '/public/search', key: 'search' },
        { icon: BookmarkCheck, label: 'بحوثات محفوظة', path: '/public/saved-searches', key: 'saved-searches' },
        { icon: List, label: 'قوائمي', path: '/public/lists', key: 'lists' },
    ];

    // Public nav items (always visible)
    const publicNavItems = [
        { icon: CreditCard, label: 'الباقات', path: '/public/pricing', key: 'pricing' },
    ];

    // Combine based on login status
    const navItems = isLoggedIn ? [...protectedNavItems, ...publicNavItems] : publicNavItems;

    return (
        <nav className="bg-white dark:bg-gray-900 border-b shadow-sm sticky top-0 z-50">
            <div className="max-w-7xl mx-auto px-4">
                <div className="flex items-center justify-between h-16">
                    {/* Logo & Brand */}
                    <Link to="/" className="flex items-center gap-3 group">
                        <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center transition-transform group-hover:scale-105">
                            <span className="text-white font-bold text-xl">L</span>
                        </div>
                        <div>
                            <h1 className="font-bold text-lg text-foreground">Lead IQ</h1>
                            <p className="text-xs text-muted-foreground">قاعدة بيانات العملاء</p>
                        </div>
                    </Link>

                    {/* Desktop Navigation */}
                    <div className="hidden md:flex items-center gap-2">
                        {/* Home Link */}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate('/')}
                        >
                            <Home className="w-4 h-4 ml-2" />
                            الرئيسية
                        </Button>

                        {navItems.map((item) => {
                            const Icon = item.icon;
                            const isActive = currentPage === item.key;
                            return (
                                <Button
                                    key={item.key}
                                    variant={isActive ? 'default' : 'ghost'}
                                    size="sm"
                                    onClick={() => navigate(item.path)}
                                    className={isActive ? 'gradient-primary text-white' : ''}
                                >
                                    <Icon className="w-4 h-4 ml-2" />
                                    {item.label}
                                </Button>
                            );
                        })}
                    </div>

                    {/* User Info & Auth Buttons */}
                    <div className="hidden md:flex items-center gap-3">
                        {isLoggedIn ? (
                            <>
                                {subscription && (
                                    <Badge variant="secondary" className="gap-1">
                                        {subscription.plan?.name || 'مجاني'}
                                    </Badge>
                                )}

                                {user && (
                                    <div className="text-right">
                                        <p className="text-sm font-medium text-foreground">{user.name}</p>
                                        <p className="text-xs text-muted-foreground">{user.email}</p>
                                    </div>
                                )}

                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={handleLogout}
                                    className="gap-2 hover:bg-red-50 hover:text-red-600 hover:border-red-200 dark:hover:bg-red-900/20"
                                >
                                    <LogOut className="w-4 h-4" />
                                    خروج
                                </Button>
                            </>
                        ) : (
                            <Button
                                size="sm"
                                onClick={() => navigate('/public/login')}
                                className="gap-2 gradient-primary text-white"
                            >
                                <LogIn className="w-4 h-4" />
                                تسجيل الدخول
                            </Button>
                        )}
                    </div>

                    {/* Mobile Menu Button */}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="md:hidden"
                        onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                    >
                        {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
                    </Button>
                </div>

                {/* Mobile Menu */}
                {mobileMenuOpen && (
                    <div className="md:hidden py-4 border-t">
                        <div className="space-y-2">
                            {/* Home Link */}
                            <Button
                                variant="ghost"
                                className="w-full justify-start"
                                onClick={() => {
                                    navigate('/');
                                    setMobileMenuOpen(false);
                                }}
                            >
                                <Home className="w-4 h-4 ml-2" />
                                الرئيسية
                            </Button>

                            {navItems.map((item) => {
                                const Icon = item.icon;
                                const isActive = currentPage === item.key;
                                return (
                                    <Button
                                        key={item.key}
                                        variant={isActive ? 'default' : 'ghost'}
                                        className={`w-full justify-start ${isActive ? 'gradient-primary text-white' : ''}`}
                                        onClick={() => {
                                            navigate(item.path);
                                            setMobileMenuOpen(false);
                                        }}
                                    >
                                        <Icon className="w-4 h-4 ml-2" />
                                        {item.label}
                                    </Button>
                                );
                            })}

                            <div className="pt-4 border-t">
                                {isLoggedIn ? (
                                    <>
                                        {user && (
                                            <div className="mb-3 px-3">
                                                <p className="text-sm font-medium text-foreground">{user.name}</p>
                                                <p className="text-xs text-muted-foreground">{user.email}</p>
                                                {subscription && (
                                                    <Badge variant="secondary" className="mt-2">
                                                        {subscription.plan?.name || 'مجاني'}
                                                    </Badge>
                                                )}
                                            </div>
                                        )}

                                        <Button
                                            variant="outline"
                                            className="w-full justify-start gap-2 hover:bg-red-50 hover:text-red-600 hover:border-red-200"
                                            onClick={handleLogout}
                                        >
                                            <LogOut className="w-4 h-4" />
                                            تسجيل خروج
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        className="w-full justify-start gap-2 gradient-primary text-white"
                                        onClick={() => {
                                            navigate('/public/login');
                                            setMobileMenuOpen(false);
                                        }}
                                    >
                                        <LogIn className="w-4 h-4" />
                                        تسجيل الدخول
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </nav>
    );
};

export default PublicNavigation;
