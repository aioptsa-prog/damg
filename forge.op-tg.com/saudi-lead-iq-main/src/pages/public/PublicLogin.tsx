import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, Mail, Lock } from 'lucide-react';
import { api } from '@/lib/api';
import { setPublicToken, setPublicUser } from '@/lib/auth';

const PublicLogin = () => {
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleLogin = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            const response = await api.loginPublic(email, password);

            if (response.ok && response.token) {
                // Store token and user
                setPublicToken(response.token);
                setPublicUser(response.user);

                // Redirect to public dashboard
                navigate('/public/dashboard');
            } else {
                setError(response.message || 'فشل تسجيل الدخول');
            }
        } catch (err) {
            setError('حدث خطأ في الاتصال');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-900 dark:to-gray-800 p-4">
            <Card className="w-full max-w-md p-8 shadow-elegant">
                <div className="text-center mb-8">
                    <h1 className="text-3xl font-bold text-foreground mb-2">تسجيل الدخول</h1>
                    <p className="text-muted-foreground">قاعدة بيانات العملاء المحتملين</p>
                </div>

                <form onSubmit={handleLogin} className="space-y-4">
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <div>
                        <label className="block text-sm font-medium mb-2">البريد الإلكتروني</label>
                        <div className="relative">
                            <Mail className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="example@email.com"
                                className="pr-10"
                                required
                                disabled={loading}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium mb-2">كلمة المرور</label>
                        <div className="relative">
                            <Lock className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                type="password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="••••••••"
                                className="pr-10"
                                required
                                disabled={loading}
                            />
                        </div>
                    </div>

                    <Button
                        type="submit"
                        className="w-full gradient-primary text-white"
                        disabled={loading}
                    >
                        {loading ? (
                            <>
                                <Loader2 className="w-4 h-4 ml-2 animate-spin" />
                                جاري تسجيل الدخول...
                            </>
                        ) : (
                            'تسجيل الدخول'
                        )}
                    </Button>
                </form>

                <div className="mt-6 text-center space-y-2">
                    <p className="text-sm text-muted-foreground">
                        ليس لديك حساب؟{' '}
                        <Link to="/public/register" className="text-primary hover:underline font-medium">
                            سجل الآن
                        </Link>
                    </p>
                    <Link to="/public/pricing" className="block text-sm text-primary hover:underline">
                        عرض الباقات والأسعار
                    </Link>
                </div>
            </Card>
        </div>
    );
};

export default PublicLogin;
