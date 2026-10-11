import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import StaffLayout from "../../components/StaffLayout";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";

const showTime = formatReadingTime;
const qualityClasses = {
  good: "status-good",
  moderate: "status-moderate",
  hazardous: "status-hazard",
  "very hazardous": "status-hazard",
};

const modules = [
  ["alerts", "Alerts", "Review and update alert states."],
  ["devices", "ESP32 device", "Manage the registered monitor."],
  ["sensors", "Sensor setup", "Manage the MQ-2 sensor."],
  ["accounts", "Accounts", "Manage roles and access."],
  ["thresholds", "Thresholds", "Configure shared limits."],
  ["audit", "Alert history", "Review alert records."],
];

function StaffDashboard() {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function refresh() {
      try {
        const result = await apiRequest("/api/staff/overview", undefined, "GET");
        if (active) {
          setData(result);
          setError("");
        }
      } catch (requestError) {
        if (active) setError(requestError.message);
      }
    }

    refresh();
    const interval = window.setInterval(refresh, 3000);
    return () => {
      active = false;
      window.clearInterval(interval);
    };
  }, []);

  return (
    <StaffLayout account={data?.account} module="dashboard">
      <main className="content">
        <section className="page-heading">
          <div><span className="eyebrow">System administration</span><h1>Admin Dashboard</h1><p>Monitor the single-zone AirSense setup.</p></div>
        </section>
        {error && <p className="error" role="alert">{error}</p>}
        <p className="footnote">Live updates every 3 seconds.</p>
        <section className="overview-stats" aria-label="Admin dashboard summary">
          {[
            ["Alerts today", data?.counts?.alerts_today],
            ["Current MQ-2 reading", data?.readings?.[0]?.mq135_value],
            ["Total accounts", data?.counts?.users],
          ].map(([label, value]) => (
            <article className="overview-stat" key={label}>
              <span>{label}</span>
              <strong>{value ?? "—"}</strong>
              <small>{label === "Current MQ-2 reading"
                ? data?.readings?.[0]
                  ? <span className={`status-badge current-reading-status ${qualityClasses[data.readings[0].air_quality_status?.trim().toLowerCase()] || "status-neutral"}`}>
                      {(data.readings[0].air_quality_status || "Unknown").toUpperCase()}
                    </span>
                  : "No readings received yet"
                : label === "Alerts today"
                  ? `Active today: ${data?.counts?.active_alerts_today ?? "—"}`
                  : "Registered user and staff accounts"}</small>
            </article>
          ))}
        </section>
        <section className="section">
          <div className="section-head"><div><h2>Latest readings</h2><p>Newest sensor readings first · up to 10 records</p></div><Link className="button secondary" to="/staff/readings">View all readings</Link></div>
          <div className="table-scroll"><table><thead><tr><th>Reading ID</th><th>MQ-2 value</th><th>Status</th><th>Recorded</th></tr></thead><tbody>
            {(data?.readings || []).map((row) => <tr key={row.reading_id}><td>#{row.reading_id}</td><td>{row.mq135_value ?? "—"}</td><td>{row.air_quality_status || "Unknown"}</td><td>{showTime(row.recorded_at)}</td></tr>)}
            {data && !data.readings.length && <tr><td className="empty-cell" colSpan="4">No sensor readings are available yet.</td></tr>}
          </tbody></table></div>
        </section>
        <section className="section"><div className="section-head"><h2>Management modules</h2></div><div className="section-body module-grid">
          {modules.map(([path, label, description]) => <Link className="module-card" to={`/staff/${path}`} key={path}><span>{label}</span><small>{description}</small></Link>)}
        </div></section>
        <p className="footnote">Staff changes are enforced by Supabase row-level security.</p>
      </main>
    </StaffLayout>
  );
}

export default StaffDashboard;
