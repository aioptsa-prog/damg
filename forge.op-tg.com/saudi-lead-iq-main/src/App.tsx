import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import { AuthProvider } from "@/contexts/AuthContext";
import ProtectedRoute from "@/components/ProtectedRoute";
import Index from "./pages/Index";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Leads from "./pages/Leads";
import Analytics from "./pages/Analytics";
import Campaigns from "./pages/Campaigns";
import NotFound from "./pages/NotFound";

// Public Platform Pages
import PublicLogin from "./pages/public/PublicLogin";
import PublicRegister from "./pages/public/PublicRegister";
import PublicSearch from "./pages/public/PublicSearch";
import PublicDashboard from "./pages/public/PublicDashboard";
import PublicPricing from "./pages/public/PublicPricing";
import PublicSavedSearches from "./pages/public/PublicSavedSearches";
import PublicSavedLists from "./pages/public/PublicSavedLists";
import PublicSubscription from "./pages/public/PublicSubscription";
import WhatsAppSettings from "./pages/WhatsAppSettings";
import Profile from "./pages/Profile";

const queryClient = new QueryClient();

const App = () => (
  <QueryClientProvider client={queryClient}>
    <TooltipProvider>
      <Toaster />
      <Sonner />
      <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <AuthProvider>
          <Routes>
            <Route path="/" element={<Index />} />

            {/* Admin/Agent Routes */}
            <Route path="/login" element={<Login />} />
            <Route
              path="/dashboard"
              element={
                <ProtectedRoute>
                  <Dashboard />
                </ProtectedRoute>
              }
            />
            <Route
              path="/leads"
              element={
                <ProtectedRoute>
                  <Leads />
                </ProtectedRoute>
              }
            />
            <Route
              path="/analytics"
              element={
                <ProtectedRoute>
                  <Analytics />
                </ProtectedRoute>
              }
            />
            <Route
              path="/campaigns"
              element={
                <ProtectedRoute>
                  <Campaigns />
                </ProtectedRoute>
              }
            />
            <Route
              path="/whatsapp-settings"
              element={
                <ProtectedRoute>
                  <WhatsAppSettings />
                </ProtectedRoute>
              }
            />
            <Route
              path="/profile"
              element={
                <ProtectedRoute>
                  <Profile />
                </ProtectedRoute>
              }
            />

            {/* Public Platform Routes */}
            <Route path="/public/login" element={<PublicLogin />} />
            <Route path="/public/register" element={<PublicRegister />} />
            <Route path="/public/search" element={<PublicSearch />} />
            <Route path="/public/leads" element={<PublicSearch />} />
            <Route path="/public/dashboard" element={<PublicDashboard />} />
            <Route path="/public/pricing" element={<PublicPricing />} />
            <Route path="/public/subscription" element={<PublicSubscription />} />
            <Route path="/public/saved-searches" element={<PublicSavedSearches />} />
            <Route path="/public/saved-lists" element={<PublicSavedLists />} />

            {/* ADD ALL CUSTOM ROUTES ABOVE THE CATCH-ALL "*" ROUTE */}
            <Route path="*" element={<NotFound />} />
          </Routes>
        </AuthProvider>
      </BrowserRouter>
    </TooltipProvider>
  </QueryClientProvider>
);

export default App;
