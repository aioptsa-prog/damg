import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { useToast } from "@/hooks/use-toast";

export default function Login() {
    const [mobile, setMobile] = useState("");
    const [password, setPassword] = useState("");
    const [remember, setRemember] = useState(false);
    const [loading, setLoading] = useState(false);

    const { login } = useAuth();
    const navigate = useNavigate();
    const { toast } = useToast();

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);

        try {
            const success = await login(mobile, password, remember);

            if (success) {
                toast({
                    title: "تم تسجيل الدخول بنجاح",
                    description: "مرحباً بك في OptForge",
                });
                navigate("/dashboard");
            } else {
                toast({
                    variant: "destructive",
                    title: "فشل تسجيل الدخول",
                    description: "رقم الهاتف أو كلمة المرور غير صحيحة",
                });
            }
        } catch (error) {
            toast({
                variant: "destructive",
                title: "خطأ",
                description: "حدث خطأ أثناء تسجيل الدخول",
            });
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-primary/5 via-background to-secondary/5 p-4">
            <Card className="w-full max-w-md">
                <CardHeader className="text-center">
                    <CardTitle className="text-3xl font-bold">تسجيل الدخول</CardTitle>
                    <CardDescription>
                        أدخل بياناتك للوصول إلى OptForge
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="mobile">رقم الهاتف</Label>
                            <Input
                                id="mobile"
                                type="text"
                                placeholder="590000000"
                                value={mobile}
                                onChange={(e) => setMobile(e.target.value)}
                                required
                                dir="ltr"
                                className="text-right"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">كلمة المرور</Label>
                            <Input
                                id="password"
                                type="password"
                                placeholder="••••••••"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                required
                                dir="ltr"
                                className="text-right"
                            />
                        </div>

                        <div className="flex items-center space-x-2 space-x-reverse">
                            <Checkbox
                                id="remember"
                                checked={remember}
                                onCheckedChange={(checked) => setRemember(checked as boolean)}
                            />
                            <Label
                                htmlFor="remember"
                                className="text-sm font-normal cursor-pointer"
                            >
                                تذكرني لمدة 30 يوماً
                            </Label>
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={loading}
                        >
                            {loading ? "جاري تسجيل الدخول..." : "تسجيل الدخول"}
                        </Button>
                    </form>

                    <div className="mt-6 text-center text-sm text-muted-foreground">
                        <p>بيانات تجريبية:</p>
                        <p className="font-mono text-xs mt-1">
                            Mobile: 590000000<br />
                            Password: Forge@2025!
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
