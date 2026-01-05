// API Client for OptForge Backend
// Connects React frontend to PHP REST API

import { getAuthToken } from './auth';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/v1/api';

export interface ApiResponse<T = any> {
    ok: boolean;
    data?: T;
    error?: string;
    message?: string;
    pagination?: {
        page: number;
        limit: number;
        total: number;
        pages: number;
        has_next: boolean;
        has_prev: boolean;
    };

    // Public Platform API specific fields
    user?: PublicUser | User;
    token?: string;
    subscription?: Subscription | null;
    leads?: Lead[];
    plans?: SubscriptionPlan[];
    searches?: SavedSearch[];
    lists?: SavedList[];
    items?: any[];
    revealed?: boolean;
    already_revealed?: boolean;
    quota?: any;
    search_id?: number;
    list_id?: number;
    item_id?: number;
}

export interface User {
    id: number;
    name: string;
    mobile: string;
    role: 'admin' | 'agent';
    is_superadmin: boolean;
    active: boolean;
}

// Public user (for public-facing platform)
export interface PublicUser {
    id: number;
    email: string;
    name: string;
    company?: string;
    phone?: string;
    email_verified: boolean;
}

export interface SubscriptionPlan {
    id: number;
    name: string;
    slug: string;
    description?: string;
    pricing: {
        monthly: number;
        yearly: number;
        currency: string;
    };
    quotas: {
        phone: number;
        email: number;
        export: number;
    };
    limits: {
        saved_searches: number;
        saved_lists: number;
        list_items: number;
    };
    features: string[];
}

export interface Subscription {
    id: number;
    plan_id: number;
    plan: {
        id: number;
        name: string;
        slug: string;
    };
    status: string;
    billing_cycle: 'monthly' | 'yearly';
    current_period_start: string;
    current_period_end: string;
    period_end: string; // backward compatibility
    quotas: {
        phone: number;
        email: number;
        export: number;
    };
    usage: {
        phone_reveals: number;
        email_reveals: number;
        exports_count: number;
        searches_count: number;
        api_calls: number;
    };
}

export interface SavedSearch {
    id: number;
    name: string;
    description?: string;
    filters: any;
    result_count: number;
    last_run?: string;
    created_at: string;
    updated_at: string;
}

export interface SavedList {
    id: number;
    name: string;
    description?: string;
    color?: string;
    items_count: number;
    created_at: string;
    updated_at: string;
}

export interface Lead {
    id: number;
    phone: string;
    phone_norm: string;
    name: string;
    city: string;
    country: string;
    category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    location: {
        city_id: number | null;
        city_name: string | null;
        district_id: number | null;
        district_name: string | null;
        lat: number | null;
        lng: number | null;
    };
    rating: number | null;
    website: string | null;
    email: string | null;
    source: string;
    created_at: string;
}

export interface Category {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    depth: number;
    icon: {
        type: string;
        value: string;
    } | null;
    children?: Category[];
}

class ApiClient {
    private baseUrl: string;

    constructor(baseUrl: string) {
        this.baseUrl = baseUrl;
    }

    private async request<T>(
        endpoint: string,
        options: RequestInit = {}
    ): Promise<ApiResponse<T>> {
        const url = `${this.baseUrl}${endpoint}`;

        // Get authentication token using centralized auth utility
        const token = getAuthToken();

        const headers: HeadersInit = {
            'Content-Type': 'application/json',
            ...options.headers,
        };

        // Add Authorization header if token exists
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        try {
            const response = await fetch(url, {
                ...options,
                headers,
                credentials: 'include', // Important for cookies
            });

            const data = await response.json();

            if (!response.ok) {
                return {
                    ok: false,
                    error: data.error || 'request_failed',
                    message: data.message || 'حدث خطأ في الطلب',
                };
            }

            return data;
        } catch (error) {
            console.error('API Request Error:', error);
            return {
                ok: false,
                error: 'network_error',
                message: 'خطأ في الاتصال بالخادم',
            };
        }
    }

    // ==================== Authentication ====================

    async login(mobile: string, password: string, remember = false) {
        return this.request<{ user: User; token: string }>('/auth/login.php', {
            method: 'POST',
            body: JSON.stringify({ mobile, password, remember }),
        });
    }

    async logout() {
        return this.request('/auth/logout.php', { method: 'POST' });
    }

    async getCurrentUser() {
        return this.request<{ user: User }>('/auth/me.php');
    }

    // ==================== Campaigns ====================

    async getCampaigns() {
        return this.request<{
            campaigns: Array<{
                id: number;
                name: string;
                city: string;
                query: string;
                target_count: number;
                result_count: number;
                status: string;
                created_at: string;
            }>;
            total: number;
        }>('/campaigns/index.php');
    }

    // ==================== Leads ====================

    async getLeads(params: {
        page?: number;
        limit?: number;
        category_id?: number;
        city_id?: number;
        search?: string;
        status?: string;
    } = {}) {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                query.append(key, String(value));
            }
        });

        return this.request<Lead[]>(`/leads/index.php?${query}`);
    }

    // ==================== Categories ====================

    async getCategories() {
        return this.request<{
            data: Category[];
            flat: Category[];
            total: number;
        }>('/categories/index.php');
    }

    // ==================== Jobs ====================

    async getJobs(params: {
        page?: number;
        limit?: number;
        status?: string;
    } = {}) {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined) query.append(key, String(value));
        });

        return this.request(`/jobs/index.php?${query}`);
    }

    async createJob(data: {
        query: string;
        ll: string;
        radius_km: number;
        category_id?: number;
        target_count?: number;
    }) {
        return this.request('/jobs/create.php', {
            method: 'POST',
            body: JSON.stringify(data),
        });
    }

    // ==================== Public Platform APIs ====================

    // Public Authentication
    async registerPublic(data: {
        email: string;
        password: string;
        name: string;
        company?: string;
        phone?: string;
    }) {
        return this.request<{ user: PublicUser; token: string }>('/public/auth/register.php', {
            method: 'POST',
            body: JSON.stringify(data),
        });
    }

    async loginPublic(email: string, password: string) {
        return this.request<{
            user: PublicUser;
            token: string;
            subscription: Subscription | null;
        }>('/public/auth/login.php', {
            method: 'POST',
            body: JSON.stringify({ email, password }),
        });
    }

    async getCurrentPublicUser() {
        return this.request<{
            user: PublicUser;
            subscription: Subscription | null;
        }>('/public/auth/me.php');
    }

    async logoutPublic() {
        return this.request('/public/auth/logout.php', { method: 'POST' });
    }

    // Public Search
    async searchLeads(params: {
        page?: number;
        limit?: number;
        category_id?: number;
        city?: string;
        search?: string;
    } = {}) {
        const query = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                query.append(key, String(value));
            }
        });

        return this.request<{
            leads: Lead[];
            pagination: any;
            subscription: any;
        }>(`/public/leads/search.php?${query}`);
    }

    async revealContact(lead_id: number, reveal_type: 'phone' | 'email') {
        return this.request<{
            revealed: boolean;
            already_revealed: boolean;
            data: { [key: string]: string };
            quota?: any;
        }>('/public/leads/reveal.php', {
            method: 'POST',
            body: JSON.stringify({ lead_id, reveal_type }),
        });
    }

    // Subscription Plans
    async getSubscriptionPlans() {
        return this.request<{ plans: SubscriptionPlan[] }>('/public/subscriptions/plans.php');
    }

    // Saved Searches
    async getSavedSearches() {
        return this.request<{ searches: SavedSearch[] }>('/public/searches/index.php');
    }

    async createSavedSearch(data: {
        name: string;
        description?: string;
        filters: any;
    }) {
        return this.request<{ search_id: number }>('/public/searches/index.php', {
            method: 'POST',
            body: JSON.stringify(data),
        });
    }

    async deleteSavedSearch(search_id: number) {
        return this.request('/public/searches/index.php', {
            method: 'DELETE',
            body: JSON.stringify({ search_id }),
        });
    }

    // Saved Lists
    async getSavedLists() {
        return this.request<{ lists: SavedList[] }>('/public/lists/index.php');
    }

    async createSavedList(data: {
        name: string;
        description?: string;
        color?: string;
    }) {
        return this.request<{ list_id: number }>('/public/lists/index.php', {
            method: 'POST',
            body: JSON.stringify(data),
        });
    }

    async deleteSavedList(list_id: number) {
        return this.request('/public/lists/index.php', {
            method: 'DELETE',
            body: JSON.stringify({ list_id }),
        });
    }

    async getListItems(list_id: number) {
        return this.request<{ items: any[] }>(`/public/lists/items.php?list_id=${list_id}`);
    }

    async addToList(list_id: number, lead_id: number, notes?: string) {
        return this.request<{ item_id: number }>('/public/lists/items.php', {
            method: 'POST',
            body: JSON.stringify({ list_id, lead_id, notes }),
        });
    }

    async removeFromList(item_id: number) {
        return this.request('/public/lists/items.php', {
            method: 'DELETE',
            body: JSON.stringify({ item_id }),
        });
    }
}

export const api = new ApiClient(API_BASE_URL);
export default api;
