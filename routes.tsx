/**
 * Application Routes Configuration
 * P0-2: Proper URL-based routing with BrowserRouter
 */

import React, { Suspense, lazy } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { useAuth } from './hooks/useAuth';

// Lazy load components for better performance
const Dashboard = lazy(() => import('./components/Dashboard'));
const LeadList = lazy(() => import('./components/LeadList'));
const LeadDetails = lazy(() => import('./components/LeadDetails'));
const LeadForm = lazy(() => import('./components/LeadForm'));
const ReportView = lazy(() => import('./components/ReportView'));
const SettingsPanel = lazy(() => import('./components/SettingsPanel'));
const Leaderboard = lazy(() => import('./components/Leaderboard'));
const UserManagement = lazy(() => import('./components/UserManagement'));
const SmartSurvey = lazy(() => import('./components/SmartSurvey'));
const Login = lazy(() => import('./components/Login'));
const NotFound = lazy(() => import('./components/NotFound'));

// Loading fallback
const PageLoader = () => (
  <div className="flex items-center justify-center h-64">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
  </div>
);

// Protected Route wrapper
interface ProtectedRouteProps {
  children: React.ReactNode;
  adminOnly?: boolean;
}

export const ProtectedRoute: React.FC<ProtectedRouteProps> = ({ children, adminOnly = false }) => {
  const { isAuthenticated, user } = useAuth();
  
  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }
  
  if (adminOnly && user?.role !== 'SUPER_ADMIN') {
    return <Navigate to="/dashboard" replace />;
  }
  
  return <>{children}</>;
};

// Route definitions for documentation
export const ROUTE_MAP = {
  '/login': { name: 'تسجيل الدخول', auth: false, admin: false },
  '/dashboard': { name: 'لوحة التحكم', auth: true, admin: false },
  '/leads': { name: 'العملاء', auth: true, admin: false },
  '/leads/new': { name: 'عميل جديد', auth: true, admin: false },
  '/leads/:id': { name: 'تفاصيل العميل', auth: true, admin: false },
  '/leads/:id/survey': { name: 'استبيان ذكي', auth: true, admin: false },
  '/reports/:id': { name: 'التقرير', auth: true, admin: false },
  '/leaderboard': { name: 'المتصدرين', auth: true, admin: false },
  '/settings': { name: 'الإعدادات', auth: true, admin: true },
  '/users': { name: 'إدارة المستخدمين', auth: true, admin: true },
  '*': { name: 'صفحة غير موجودة', auth: false, admin: false },
};

export default ROUTE_MAP;
