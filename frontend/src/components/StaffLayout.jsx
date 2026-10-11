import { useEffect, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { getSupabaseClient } from "../lib/supabase";
import { clearRoleVerification } from "../lib/role-verification";
import { apiRequest } from "../lib/api";
import { formatReadingTime } from "../lib/time";
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
  ["userlogs", "User activity", "/staff/userlogs"],
];

function StaffReadingAlert() {
  const latestReadingId = useRef((() => {
    try {
      const value = Number(sessionStorage.getItem("airsense-staff-last-reading-id"));
      return Number.isFinite(value) && value > 0 ? value : null;
    } catch { return null; }
  })());
  const initialized = useRef(latestReadingId.current !== null);
  const [queue, setQueue] = useState([]);
  const [coolingDown, setCoolingDown] = useState(false);

  useEffect(() => {
    if (!coolingDown) return;
    const timer = window.setTimeout(() => setCoolingDown(false), 3000);
    return () => window.clearTimeout(timer);
  }, [coolingDown]);

  useEffect(() => {
    let active = true;
    let loading = false;

    async function checkForReadings() {
      if (loading) return;
      loading = true;
      try {
        const path = latestReadingId.current === null
          ? "/api/staff/latest-readings"
          : `/api/staff/latest-readings?after_id=${latestReadingId.current}`;
        const result = await apiRequest(path, undefined, "GET");
        if (!active || !Array.isArray(result.rows)) return;
        if (!initialized.current) {
          initialized.current = true;
          if (result.rows.length) {
            latestReadingId.current = Math.max(...result.rows.map((row) => Number(row.reading_id)).filter(Number.isFinite));
            try { sessionStorage.setItem("airsense-staff-last-reading-id", String(latestReadingId.current)); } catch { /* optional */ }
          }
          return;
        }
        if (result.rows.length === 0) return;

        const rows = [...result.rows].sort((a, b) => Number(a.reading_id) - Number(b.reading_id));
        const newestId = Math.max(...rows.map((row) => Number(row.reading_id)).filter(Number.isFinite));
        latestReadingId.current = Number.isFinite(newestId) ? newestId : latestReadingId.current;
        try { sessionStorage.setItem("airsense-staff-last-reading-id", String(latestReadingId.current)); } catch { /* optional */ }
        const alerts = rows.filter((row) => ["moderate", "hazardous", "very hazardous"].includes(String(row.air_quality_status || "").trim().toLowerCase()));
        if (alerts.length) setQueue((current) => [...current, ...alerts]);
      } catch {
        // Keep the staff workspace usable if a background check fails.
      } finally {
        loading = false;
      }
    }

    checkForReadings();
    const interval = window.setInterval(checkForReadings, 1000);
    return () => {
      active = false;
      window.clearInterval(interval);
    };
  }, []);

  const reading = queue[0];
  if (!reading || coolingDown) return null;

  const status = String(reading.air_quality_status || "Unknown");
  return (
    <div className="staff-reading-alert-overlay">
      <section className={`staff-reading-alert staff-reading-alert-${status.toLowerCase().replaceAll(" ", "-")}`} role="alertdialog" aria-modal="true" aria-labelledby="staff-reading-alert-title" aria-describedby="staff-reading-alert-description">
        <div className="staff-reading-alert-icon" aria-hidden="true">!</div>
        <span className="staff-reading-alert-eyebrow">New sensor reading</span>
        <h2 id="staff-reading-alert-title">{status} air quality</h2>
        <p id="staff-reading-alert-description">The latest sensor reading is <strong>{reading.mq135_value ?? "—"}</strong>. Please check the monitoring zone.</p>
        <p className="staff-reading-alert-time">Reading #{reading.reading_id}{reading.recorded_at ? ` · ${formatReadingTime(reading.recorded_at)}` : ""}</p>
        <button className="button" type="button" onClick={() => { setCoolingDown(true); setQueue((current) => current.slice(1)); }}>Acknowledge</button>
      </section>
    </div>
  );
}

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
    try { await apiRequest("/api/user/activity-logs", { activity_type: "sign_out" }); } catch { /* Sign-out must still work if logging is unavailable. */ }
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
      <StaffReadingAlert />
    </div>
  );
}

export default StaffLayout;
