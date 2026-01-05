
import React from 'react';
import { UserRole } from '../types';

interface RoleGuardProps {
  userRole: UserRole;
  allowedRoles: UserRole[];
  children: React.ReactNode;
  fallback?: React.ReactNode;
}

const RoleGuard: React.FC<RoleGuardProps> = ({ userRole, allowedRoles, children, fallback = null }) => {
  if (allowedRoles.includes(userRole)) {
    return <>{children}</>;
  }
  return <>{fallback}</>;
};

export default RoleGuard;
