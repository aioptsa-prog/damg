import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import { api, User } from '@/lib/api';
import { TOKEN_KEYS } from '@/lib/auth';

interface AuthContextType {
    user: User | null;
    loading: boolean;
    login: (mobile: string, password: string, remember?: boolean) => Promise<boolean>;
    logout: () => Promise<void>;
    isAuthenticated: boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(null);
    const [loading, setLoading] = useState(true);

    // Check if user is logged in on mount
    useEffect(() => {
        checkAuth();
    }, []);

    const checkAuth = async () => {
        try {
            const response = await api.getCurrentUser();
            console.log('Current User Response:', response); // Debug log
            if (response.ok) {
                // API returns user directly in the response, not nested under response.data
                const userData = (response as any).user || response.data?.user;
                if (userData) {
                    setUser(userData);
                } else {
                    setUser(null);
                }
            } else {
                setUser(null);
            }
        } catch (error) {
            console.error('Auth check failed:', error);
            setUser(null);
        } finally {
            setLoading(false);
        }
    };

    const login = async (mobile: string, password: string, remember = false): Promise<boolean> => {
        try {
            const response = await api.login(mobile, password, remember);
            console.log('Login Response:', response); // Debug log
            if (response.ok) {
                // API returns user and token directly in the response
                const userData = (response as any).user || response.data?.user;
                const token = (response as any).token || response.data?.token;

                if (userData && token) {
                    // Save token to localStorage
                    localStorage.setItem(TOKEN_KEYS.ADMIN, token);
                    console.log('Token saved to localStorage:', token);

                    setUser(userData);
                    return true;
                } else {
                    console.error('Login failed: No user data or token in response');
                    return false;
                }
            } else {
                console.error('Login failed:', response.error);
                return false;
            }
        } catch (error) {
            console.error('Login error:', error);
            return false;
        }
    };

    const logout = async () => {
        try {
            await api.logout();
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            // Clear token from localStorage
            localStorage.removeItem(TOKEN_KEYS.ADMIN);
            setUser(null);
        }
    };

    return (
        <AuthContext.Provider
            value={{
                user,
                loading,
                login,
                logout,
                isAuthenticated: !!user,
            }}
        >
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (context === undefined) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
}
