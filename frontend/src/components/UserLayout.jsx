import { useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { getSupabaseClient } from "../lib/supabase";
import { apiRequest } from "../lib/api";
import "../../../style.css";
import "../css/UserPages.css";

function UserLayout({ account, page, children }) {
  const navigate = useNavigate();

  useEffect(() => {
    if (["dashboard", "readings", "alerts"].includes(page)) {
      apiRequest("/api/user/activity-logs", { activity_type: page }).catch(() => {});
    }
  }, [page]);

  async function signOut() {
    try { await apiRequest("/api/user/activity-logs", { activity_type: "sign_out" }); } catch { /* Sign-out must still work if logging is unavailable. */ }
    await getSupabaseClient().auth.signOut();
    navigate("/");
  }

  return (
    <div className="app-shell">
      <aside className="sidebar" aria-label="Dashboard sidebar">
        <Link className="brand" to="/user/dashboard"><span className="brand-mark" aria-hidden="true">≈</span><span className="brand-name">AirSense<small>IoT monitoring</small></span></Link>
        <div className="side-label">Workspace</div>
        <nav className="sidebar-nav" aria-label="Dashboard navigation">
          <Link className="nav-link" aria-current={page === "dashboard" ? "page" : undefined} to="/user/dashboard">Overview</Link>
          <Link className="nav-link" aria-current={page === "readings" ? "page" : undefined} to="/user/readings">Readings</Link>
          <Link className="nav-link" aria-current={page === "alerts" ? "page" : undefined} to="/user/alerts">Alerts</Link>
          <a className="nav-link" href={page === "dashboard" ? "#status-guide" : "/user/dashboard#status-guide"}>Status guide</a>
        </nav>
        <div className="sidebar-spacer" />
        <div className="access-card"><div className="access-label"><span className="access-dot" />Readings access</div><p>Viewing the latest readings shared with your account.</p></div>
        <div className="profile"><span className="avatar">{(account?.email || "U").slice(0, 1).toUpperCase()}</span><div className="profile-copy"><strong>{account?.email || "User account"}</strong><span>Read-only account</span></div></div>
      </aside>
      <div className="workspace">
        <header className="topbar"><div className="breadcrumb">Workspace <span aria-hidden="true">/</span> <strong>{page === "dashboard" ? "Overview" : page[0].toUpperCase() + page.slice(1)}</strong></div><div className="top-actions"><span className="view-label"><span className="top-dot" />Read-only access</span><button className="signout" type="button" onClick={signOut}>Sign out</button></div></header>
        {children}
      </div>
    </div>
  );
}

export default UserLayout;
