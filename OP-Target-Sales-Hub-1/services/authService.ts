
import { User, UserRole } from '../types';

/**
 * Auth Service - Client Side
 * Uses httpOnly cookies managed by backend
 * No secrets or tokens stored in localStorage
 */

class AuthService {
  private currentUser: User | null = null;
  private initialized = false;

  constructor() {
    // Try to restore user from session check on init
    this.checkSession();
  }

  /**
   * Check if there's a valid session via API
   * 401 = Guest (expected, not an error)
   */
  private async checkSession(): Promise<void> {
    try {
      const response = await fetch('/api/auth', {
        credentials: 'include'
      });

      if (response.ok) {
        const data = await response.json();
        this.currentUser = data.user;
      } else {
        // 401 = Not authenticated (Guest) - this is expected, not an error
        this.currentUser = null;
      }
    } catch (e) {
      // Network error - treat as guest
      this.currentUser = null;
    }
    this.initialized = true;
  }

  /**
   * Login - calls backend API which sets httpOnly cookie
   */
  async login(email: string, password: string): Promise<User> {
    const response = await fetch('/api/auth', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email, password }),
      credentials: 'include' // Include cookies
    });

    const data = await response.json();

    if (!response.ok) {
      // Handle specific error types
      if (response.status === 429) {
        throw new Error(`AUTH_LOCKED: ${data.message}`);
      }
      if (response.status === 401) {
        throw new Error(`AUTH_INVALID: ${data.message}`);
      }
      if (response.status === 403) {
        throw new Error(`AUTH_LOCKED: ${data.message}`);
      }
      throw new Error(data.message || 'Login failed');
    }

    this.currentUser = data.user;
    return data.user;
  }

  /**
   * Logout - calls backend API which clears httpOnly cookie
   */
  async logout(): Promise<void> {
    try {
      await fetch('/api/auth', {
        method: 'DELETE',
        credentials: 'include'
      });
    } catch (e) {
      // Ignore logout errors
    }
    this.currentUser = null;
  }

  getCurrentUser(): User | null {
    return this.currentUser;
  }

  isAuthenticated(): boolean {
    return !!this.currentUser;
  }

  isAdmin(): boolean {
    return this.currentUser?.role === UserRole.SUPER_ADMIN;
  }

  isManager(): boolean {
    return this.currentUser?.role === UserRole.MANAGER;
  }

  hasRole(roles: UserRole[]): boolean {
    return this.currentUser ? roles.includes(this.currentUser.role) : false;
  }

  /**
   * Wait for initialization (session check) to complete
   */
  async waitForInit(): Promise<boolean> {
    if (this.initialized) return this.isAuthenticated();

    // Wait for checkSession to complete
    return new Promise((resolve) => {
      const check = () => {
        if (this.initialized) {
          resolve(this.isAuthenticated());
        } else {
          setTimeout(check, 50);
        }
      };
      check();
    });
  }
}

export const authService = new AuthService();
