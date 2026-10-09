import { useEffect, useState } from "react";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";
import UserLayout from "../../components/UserLayout";

const time = formatReadingTime;

function UserAlerts() {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    apiRequest("/api/user/alerts", undefined, "GET")
      .then((result) => { if (active) setData(result); })
      .catch((requestError) => { if (active) setError(requestError.message); });
    return () => { active = false; };
  }, []);

  const counts = data?.counts || {};
  return (
    <UserLayout account={data?.account} page="alerts">
      <main className="dashboard-content">
        <section className="page-heading"><div><p className="eyebrow">Sensor activity</p><h1>Alerts</h1><p>Recent status changes based on the configured air-quality limits.</p></div></section>
        {error && <p className="error" role="alert">{error}</p>}
        <section className="cards" aria-label="Reading status counts">{["Good", "Moderate", "Hazardous", "Very Hazardous", "Unknown"].map((status) => <article className="card" key={status}><div className="card-top"><h2>{status}</h2></div><div className="value">{counts[status] ?? 0}</div><p className="card-note">Recent readings</p></article>)}</section>
        <section className="readings"><div className="panel-title"><div><h2>Active alerts</h2><span className="panel-subtitle">Hazardous readings not followed by a Good or Moderate reading</span></div></div><div className="table-scroll" role="region" aria-label="Active alerts"><table><thead><tr><th>Reading ID</th><th>Device</th><th>Sensor value</th><th>Status</th><th>Recorded</th></tr></thead><tbody>{(data?.active || []).map((row) => <tr key={row.reading_id}><td>#{row.reading_id}</td><td>{row.device_id ?? "—"}</td><td>{row.sensor_value}</td><td><span className={`status-badge ${row.status_class}`}>{row.status}</span></td><td>{time(row.recorded_at)}</td></tr>)}{data && !data.active.length && <tr><td className="empty-cell" colSpan="5">There are no active alerts.</td></tr>}</tbody></table></div></section>
      </main>
    </UserLayout>
  );
}

export default UserAlerts;

