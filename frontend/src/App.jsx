import { lazy, Suspense } from "react";
import { Navigate, Route, Routes } from "react-router-dom";
import RouteErrorBoundary from "./components/RouteErrorBoundary";

const Home = lazy(() => import("./pages/landing"));
const UserDashboard = lazy(() => import("./pages/user/Dashboard"));
const UserReadings = lazy(() => import("./pages/user/Readings"));
const UserAlerts = lazy(() => import("./pages/user/Alerts"));
const StaffConsole = lazy(() => import("./pages/staff/Console"));
const StaffDashboard = lazy(() => import("./pages/staff/dashboard"));
const StaffAlerts = lazy(() => import("./pages/staff/alerts"));
const StaffDevices = lazy(() => import("./pages/staff/devices"));
const StaffSensors = lazy(() => import("./pages/staff/sensors"));
const StaffAccounts = lazy(() => import("./pages/staff/accounts"));
const StaffThresholds = lazy(() => import("./pages/staff/thresholds"));
const StaffReadings = lazy(() => import("./pages/staff/readings"));
const StaffAudit = lazy(() => import("./pages/staff/audit"));
const StaffUserLogs = lazy(() => import("./pages/staff/userlogs"));
const RequireRole = lazy(() => import("./components/RequireRole"));

function App() {


  return (
    <Suspense fallback={<div role="status" style={{ padding: 24 }}>Loading AirSense…</div>}>
    <RouteErrorBoundary>
    <Routes>
      <Route path="/" element={<Home />} />
      <Route path="/login" element={<Home />} />
      <Route path="/user/dashboard" element={<RequireRole role="user"><UserDashboard /></RequireRole>} />
      <Route path="/user/readings" element={<RequireRole role="user"><UserReadings /></RequireRole>} />
      <Route path="/user/alerts" element={<RequireRole role="user"><UserAlerts /></RequireRole>} />
      <Route path="/user/zones" element={<Navigate to="/user/dashboard" replace />} />
      <Route path="/staff" element={<Navigate to="/staff/dashboard" replace />} />
      <Route path="/staff/dashboard" element={<RequireRole role="staff"><StaffDashboard /></RequireRole>} />
      <Route path="/staff/alerts" element={<RequireRole role="staff"><StaffAlerts /></RequireRole>} />
      <Route path="/staff/devices" element={<RequireRole role="staff"><StaffDevices /></RequireRole>} />
      <Route path="/staff/sensors" element={<RequireRole role="staff"><StaffSensors /></RequireRole>} />
      <Route path="/staff/accounts" element={<RequireRole role="staff"><StaffAccounts /></RequireRole>} />
      <Route path="/staff/thresholds" element={<RequireRole role="staff"><StaffThresholds /></RequireRole>} />
      <Route path="/staff/readings" element={<RequireRole role="staff"><StaffReadings /></RequireRole>} />
      <Route path="/staff/audit" element={<RequireRole role="staff"><StaffAudit /></RequireRole>} />
      <Route path="/staff/userlogs" element={<RequireRole role="staff"><StaffUserLogs /></RequireRole>} />
      <Route path="/staff/:module" element={<RequireRole role="staff"><StaffConsole /></RequireRole>} />
      <Route path="*" element={<Home />} />
    </Routes>
    </RouteErrorBoundary>
    </Suspense>
  );
}

export default App



