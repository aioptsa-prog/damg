/**
 * Unified API Client - Handles all API calls with proper error handling
 * Principal Engineer Quality Gate: Consistent API response handling
 */

import { z } from 'zod';

// ============================================
// API Response Envelope
// ============================================

export interface ApiResponse<T> {
  ok: boolean;
  data?: T;
  error?: {
    code: string;
    message: string;
    details?: unknown;
  };
}

export const ApiErrorSchema = z.object({
  code: z.string(),
  message: z.string(),
  details: z.any().optional(),
});

// ============================================
// Error Codes
// ============================================

export const ErrorCodes = {
  NETWORK_ERROR: 'NETWORK_ERROR',
  PARSE_ERROR: 'PARSE_ERROR',
  VALIDATION_ERROR: 'VALIDATION_ERROR',
  AUTH_ERROR: 'AUTH_ERROR',
  NOT_FOUND: 'NOT_FOUND',
  SERVER_ERROR: 'SERVER_ERROR',
  RATE_LIMIT: 'RATE_LIMIT',
  AI_ERROR: 'AI_ERROR',
  AI_CONFIG_ERROR: 'AI_CONFIG_ERROR',
} as const;

// ============================================
// API Client Class
// ============================================

class ApiClient {
  private baseUrl: string;

  constructor(baseUrl = '/api') {
    this.baseUrl = baseUrl;
  }

  /**
   * Make a GET request
   */
  async get<T>(endpoint: string, options?: RequestInit): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { ...options, method: 'GET' });
  }

  /**
   * Make a POST request
   */
  async post<T>(endpoint: string, body?: unknown, options?: RequestInit): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      ...options,
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  /**
   * Make a PUT request
   */
  async put<T>(endpoint: string, body?: unknown, options?: RequestInit): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, {
      ...options,
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    });
  }

  /**
   * Make a DELETE request
   */
  async delete<T>(endpoint: string, options?: RequestInit): Promise<ApiResponse<T>> {
    return this.request<T>(endpoint, { ...options, method: 'DELETE' });
  }

  /**
   * Core request method with unified error handling
   */
  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<ApiResponse<T>> {
    const url = `${this.baseUrl}${endpoint}`;
    
    try {
      const response = await fetch(url, {
        ...options,
        headers: {
          'Content-Type': 'application/json',
          ...options.headers,
        },
        credentials: 'include',
      });

      // Handle non-2xx responses
      if (!response.ok) {
        return this.handleErrorResponse(response);
      }

      // Parse JSON safely
      const data = await this.parseJson<T>(response);
      
      return {
        ok: true,
        data,
      };
    } catch (error) {
      return this.handleNetworkError(error);
    }
  }

  /**
   * Parse JSON response safely
   */
  private async parseJson<T>(response: Response): Promise<T> {
    const text = await response.text();
    
    if (!text) {
      return {} as T;
    }

    try {
      return JSON.parse(text) as T;
    } catch {
      console.warn('[ApiClient] Failed to parse JSON response');
      return {} as T;
    }
  }

  /**
   * Handle error responses (non-2xx)
   */
  private async handleErrorResponse<T>(response: Response): Promise<ApiResponse<T>> {
    let errorData: { message?: string; error?: string; code?: string } = {};
    
    try {
      errorData = await response.json();
    } catch {
      // Ignore JSON parse errors for error responses
    }

    const code = this.mapStatusToErrorCode(response.status);
    const message = errorData.message || errorData.error || this.getDefaultMessage(response.status);

    // Don't log 401 for guests (expected behavior)
    if (response.status !== 401) {
      console.error(`[ApiClient] Error ${response.status}:`, message);
    }

    return {
      ok: false,
      error: {
        code,
        message,
        details: errorData,
      },
    };
  }

  /**
   * Handle network errors
   */
  private handleNetworkError<T>(error: unknown): ApiResponse<T> {
    const message = error instanceof Error ? error.message : 'Network error';
    
    console.error('[ApiClient] Network error:', message);

    return {
      ok: false,
      error: {
        code: ErrorCodes.NETWORK_ERROR,
        message: 'فشل الاتصال بالسيرفر. يرجى التحقق من الإنترنت.',
        details: { originalError: message },
      },
    };
  }

  /**
   * Map HTTP status to error code
   */
  private mapStatusToErrorCode(status: number): string {
    switch (status) {
      case 400:
        return ErrorCodes.VALIDATION_ERROR;
      case 401:
      case 403:
        return ErrorCodes.AUTH_ERROR;
      case 404:
        return ErrorCodes.NOT_FOUND;
      case 429:
        return ErrorCodes.RATE_LIMIT;
      default:
        return ErrorCodes.SERVER_ERROR;
    }
  }

  /**
   * Get default error message for status
   */
  private getDefaultMessage(status: number): string {
    switch (status) {
      case 400:
        return 'البيانات المرسلة غير صحيحة';
      case 401:
        return 'يرجى تسجيل الدخول';
      case 403:
        return 'ليس لديك صلاحية لهذا الإجراء';
      case 404:
        return 'المورد المطلوب غير موجود';
      case 429:
        return 'تم تجاوز حد الطلبات. يرجى المحاولة لاحقاً';
      case 500:
        return 'حدث خطأ في السيرفر';
      default:
        return 'حدث خطأ غير متوقع';
    }
  }
}

// Export singleton instance
export const apiClient = new ApiClient();

// Export class for testing
export { ApiClient };
