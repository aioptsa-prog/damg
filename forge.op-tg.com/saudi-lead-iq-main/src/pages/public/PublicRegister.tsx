import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Loader2, Mail, Lock, User, Building, Phone } from 'lucide-react';
import { api } from '@/lib/api';
import { setPublicToken, setPublicUser } from '@/lib/auth';

const PublicRegister = () => {
    const navigate = useNavigate();
    const [formData, setFormData] = useState({
        email: '',
        password: '',
        confirmPassword: '',
        name: '',
        company: '',
        phone: '',
    });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setFormData({ ...formData, [e.target.name]: e.target.value });
    };

    const handleRegister = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');

        // Validate password match
        if (formData.password !== formData.confirmPassword) {
            setError('كلمات المرور غير متطابقة');
            return;
        }

        // Validate password strength
        if (formData.password.length < 8) {
            setError('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
            return;
        }

        setLoading(true);

        try {
            const response = await api.registerPublic({
                email: formData.email,
                password: formData.password,
                name: formData.name,
                company: formData.company || undefined,
                phone: formData.phone || undefined,
            });

            if (response.ok && response.token) {
                // Store token and user
                setPublicToken(response.token);
                setPublicUser(response.user);

                // Redirect to dashboard
                navigate('/public/dashboard');
            } else {
                setError(response.message || 'فشل إنشاء الحساب');
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
                    <h1 className="text-3xl font-bold text-foreground mb-2">إنشاء حساب جديد</h1>
                    <p className="text-muted-foreground">ابدأ رحلتك في البحث عن العملاء</p>
                </div>

                <form onSubmit={handleRegister} className="space-y-4">
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    <div>
                        <label className="block text-sm font-medium mb-2">الاسم الكامل</label>
                        <div className="relative">
                            <User className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                name="name"
                                type="text"
                                value={formData.name}
                                onChange={handleChange}
                                placeholder="أحمد محمد"
                                className="pr-10"
                                required
                                disabled={loading}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium mb-2">البريد الإلكتروني</label>
                        <div className="relative">
                            <Mail className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                name="email"
                                type="email"
                                value={formData.email}
                                onChange={handleChange}
                                placeholder="example@email.com"
                                className="pr-10"
                                required
                                disabled={loading}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium mb-2">اسم الشركة (اختياري)</label>
                        <div className="relative">
                            <Building className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                name="company"
                                type="text"
                                value={formData.company}
                                onChange={handleChange}
                                placeholder="شركتك"
                                className="pr-10"
                                disabled={loading}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium mb-2">رقم الجوال (اختياري)</label>
                        <div className="relative">
                            <Phone className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                name="phone"
                                type="tel"
                                value={formData.phone}
                                onChange={handleChange}
                                placeholder="+966XXXXXXXXX"
                                className="pr-10"
                                disabled={loading}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium mb-2">كلمة المرور</label>
                        <div className="relative">
                            <Lock className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                name="password"
                                type="password"
                                value={formData.password}
                                onChange={handleChange}
                                placeholder="••••••••"
                                className="pr-10"
                                required
                                disabled={loading}
                            />
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">8 أحرف على الأقل</p>
                    </div>

                    <div>
                        <label className="block text-sm font-medium mb-2">تأكيد كلمة المرور</label>
                        <div className="relative">
                            <Lock className="absolute right-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-5 h-5" />
                            <Input
                                name="confirmPassword"
                                type="password"
                                value={formData.confirmPassword}
                                onChange={handleChange}
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
                                جاري إنشاء الحساب...
                            </>
                        ) : (
                            'إنشاء حساب'
                        )}
                    </Button>
                </form>

                <p className="text-center mt-6 text-sm text-muted-foreground">
                    لديك حساب بالفعل؟{' '}
                    <Link to="/public/login" className="text-primary hover:underline font-medium">
                        سجل دخولك
                    </Link>
                </p>
            </Card>
        </div>
    );
};

export default PublicRegister;
