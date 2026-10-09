import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { getSupabaseClient } from "../lib/supabase";
import { clearRoleVerification } from "../lib/role-verification";
import "../../../style.css";
import "../css/Staff.css";

const links = [
  ["overview", "Overview", "/staff/dashboard"],
  ["alerts", "Alerts", "/staff/alerts"],
  ["devices", "ESP32 device", "/staff/devices"],
  ["sensors", "Sensor setup", "/staff/sensors"],
  ["users", "Accounts", "/staff/accounts"],
  ["settings", "Thresholds", "/staff/thresholds"],
  ["readings", "Readings", "/staff/readings"],
  ["audit", "Alert history", "/staff/audit"],
];

function StaffLayout({ account, module, children }) {
  const navigate = useNavigate();
  const [theme, setTheme] = useState(() => {
    try {
      const saved = localStorage.getItem("airsense-staff-theme");
      if (saved === "light" || saved === "dark") return saved;
      return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    } catch { return "light"; }
  });

  useEffect(() => {
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem("airsense-staff-theme", theme); } catch { /* preference is optional */ }
  }, [theme]);

  async function signOut() {
    await getSupabaseClient().auth.signOut();
    clearRoleVerification();
    navigate("/");
  }

  const active = module === "accounts" ? "users" : module === "thresholds" ? "settings" : module === "dashboard" ? "overview" : module;
  return (
    <div className="app-shell staff-app">
      <aside className="sidebar" aria-label="Staff navigation">
        <Link className="brand" to="/staff/dashboard"><span className="brand-mark" aria-hidden="true">≈</span><span className="brand-name">AirSense<small>Staff console</small></span></Link>
        <div className="side-label">Management</div>
        <nav className="sidebar-nav" aria-label="Staff dashboard navigation">{links.map(([id, label, to]) => <Link className="nav-link" aria-current={active === id ? "page" : undefined} to={to} key={id}>{label}</Link>)}</nav>
        <div className="sidebar-spacer" />
        <div className="access-card"><div className="access-label"><span className="access-dot" />Staff access</div><p>Manage devices, air-quality alerts, thresholds, accounts, and readings.</p></div>
        <div className="profile"><span className="avatar">{(account?.email || "S").slice(0, 1).toUpperCase()}</span><div className="profile-copy"><strong>{account?.email || "Staff account"}</strong><span>Staff administrator</span></div></div>
      </aside>
      <div className="workspace">
        <header className="topbar"><div className="breadcrumb">Workspace <span aria-hidden="true">/</span> <strong>Staff management</strong></div><div className="top-actions"><span className="view-label"><span className="top-dot" />Staff access</span><button className="theme-toggle" type="button" onClick={() => setTheme((current) => current === "dark" ? "light" : "dark")} aria-label={`Switch to ${theme === "dark" ? "light" : "dark"} mode`} title={`Switch to ${theme === "dark" ? "light" : "dark"} mode`}>{theme === "dark" ? "☀" : "☾"}</button><button className="signout" type="button" onClick={signOut}>Sign out</button></div></header>
        {children}
      </div>
    </div>
  );
}

export default StaffLayout;
