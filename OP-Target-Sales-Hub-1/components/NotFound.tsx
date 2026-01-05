/**
 * 404 Not Found Page
 * P0-2: Proper 404 handling for unknown routes
 */

import React from 'react';
import { Link } from 'react-router-dom';
import { Home, ArrowRight } from 'lucide-react';

const NotFound: React.FC = () => {
  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 flex items-center justify-center p-4">
      <div className="text-center max-w-md">
        <div className="text-8xl font-black text-slate-200 mb-4">404</div>
        <h1 className="text-2xl font-bold text-slate-800 mb-2">الصفحة غير موجودة</h1>
        <p className="text-slate-500 mb-8">
          عذراً، الصفحة التي تبحث عنها غير موجودة أو تم نقلها.
        </p>
        <Link 
          to="/dashboard" 
          className="inline-flex items-center gap-2 bg-primary text-white px-6 py-3 rounded-xl font-bold hover:bg-primary/90 transition-colors"
        >
          <Home size={20} />
          <span>العودة للرئيسية</span>
        </Link>
      </div>
    </div>
  );
};

export default NotFound;
