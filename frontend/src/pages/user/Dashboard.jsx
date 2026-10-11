import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";
import UserLayout from "../../components/UserLayout";

const formatTime = (value) => value ? formatReadingTime(value) : "—";
const formatValue = (value) => value === null || value === undefined ? "—" : String(value);

function ReadingAlert({ reading }) {
  const dialog = useRef(null);

  useEffect(() => {
    if (!reading || !["Hazardous", "Very Hazardous"].includes(reading.status)) return;
    const id = String(reading.reading_id || "");
    if (!id) return;
    try {
      if (sessionStorage.getItem("airsense-last-alert-reading") === id) return;
      sessionStorage.setItem("airsense-last-alert-reading", id);
    } catch { /* Alert display still works if session storage is unavailable. */ }
    if (dialog.current && !dialog.current.open) dialog.current.showModal();
  }, [reading]);

  return <dialog className="reading-alert-dialog" ref={dialog} data-level={reading?.status.toLowerCase().replaceAll(" ", "-")}><h2>Air quality alert</h2><p>Latest reading is <strong>{reading?.status}</strong>.</p><p>Sensor value: {formatValue(reading?.sensor_value)}</p><p>{reading?.recorded_at ? `Recorded ${formatTime(reading.recorded_at)}` : ""}</p><form method="dialog"><button className="button">Dismiss</button></form></dialog>;
}

function UserDashboard() {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [readingsPage, setReadingsPage] = useState(1);
  const lastId = useRef("0");

  useEffect(() => {
    let active = true;
    let loaded = false;
    let timer;

    async function refresh(initial = false) {
      try {
        const firstLoad = initial || !loaded;
        const path = firstLoad ? "/api/user/dashboard" : `/api/user/dashboard?after_id=${encodeURIComponent(lastId.current)}`;
        const result = await apiRequest(path, undefined, "GET");
        if (!active) return;
        loaded = true;
        const incoming = firstLoad ? (result.readings || []) : (result.new_readings || []);
        for (const row of incoming) {
          if (BigInt(row.reading_id) > BigInt(lastId.current)) lastId.current = row.reading_id;
        }
        setData((previous) => {
          if (firstLoad || !previous) {
            return result;
          }
          const byId = new Map(previous.readings.map((row) => [row.reading_id, row]));
          for (const row of result.new_readings || []) {
            byId.set(row.reading_id, row);
          }
          return { ...previous, latest: result.latest, readings: [...byId.values()].sort((a, b) => Number(b.reading_id) - Number(a.reading_id)).slice(0, 100) };
        });
        setError("");
      } catch (requestError) {
        if (active) setError(requestError.message);
      } finally {
        if (active) timer = window.setTimeout(() => refresh(false), 3000);
      }
    }

    refresh(true);
    return () => { active = false; window.clearTimeout(timer); };
  }, []);

  const latest = data?.latest;
  const recentReadings = data?.readings || [];
  const readingsPageCount = Math.max(1, Math.ceil(recentReadings.length / 5));
  const currentReadingsPage = Math.min(readingsPage, readingsPageCount);
  const visibleReadings = recentReadings.slice((currentReadingsPage - 1) * 5, currentReadingsPage * 5);
  const readingQualityClass = latest?.status_class === "status-very-hazardous" ? "status-hazard" : latest?.status_class || "status-neutral";

  return (
    <UserLayout account={data?.account} page="dashboard">
      <main className="dashboard-content" id="overview">
        <section className="page-heading"><div><p className="eyebrow">Air quality overview</p><h1>Kitchen air monitor</h1><p>Live MQ-2 readings and sensor status.</p></div><Link className="button secondary" to="/user/readings">View readings</Link></section>
        {error && <p className="error" role="alert">{error}</p>}
        <section className="cards" aria-label="Latest reading summary">
          <article className="card"><div className="card-top"><h2>Latest sensor value</h2></div><div className="value">{formatValue(latest?.sensor_value)}</div><p className="card-note">Raw sensor value, not ppm</p></article>
          <article className={`card user-reading-card ${readingQualityClass}`}><i className={`user-reading-glow ${readingQualityClass}`} aria-hidden="true" /><div className="card-top"><h2>Reading status</h2></div><div className="reading-status-value"><strong>{formatValue(latest?.sensor_value)}</strong><span className={`status-badge ${latest?.status_class || "status-neutral"}`}>{latest?.status || "No data"}</span></div><p className="card-note">Latest sensor reading · raw value, not ppm</p></article>
          <article className="card"><div className="card-top"><h2>Last recorded</h2></div><div className="value time">{formatTime(latest?.recorded_at)}</div><p className="card-note">Time of the newest available reading</p></article>
        </section>
        <section className="readings" id="readings"><div className="panel-title"><div><h2>Recent MQ-2 readings</h2><span className="panel-subtitle">Latest records · 5 per page</span></div><Link className="panel-subtitle" to="/user/readings">View all readings</Link></div><div className="table-scroll" role="region" aria-label="Recent sensor readings"><table><thead><tr><th>Reading ID</th><th>Sensor value</th><th>Status</th><th>Recorded</th></tr></thead><tbody>{visibleReadings.map((row) => <tr key={row.reading_id}><td>#{row.reading_id}</td><td>{formatValue(row.sensor_value)}</td><td><span className={`status-badge ${row.status_class}`}>{row.status}</span></td><td>{formatTime(row.recorded_at)}</td></tr>)}{data && !data.readings?.length && <tr><td className="empty-cell" colSpan="4">No readings have been received yet.</td></tr>}</tbody></table></div><nav className="pagination" aria-label="Recent readings pagination"><button className="button secondary" type="button" disabled={currentReadingsPage <= 1} onClick={() => setReadingsPage(currentReadingsPage - 1)}>Previous</button><span>Page {currentReadingsPage} of {readingsPageCount}</span><button className="button secondary" type="button" disabled={currentReadingsPage >= readingsPageCount} onClick={() => setReadingsPage(currentReadingsPage + 1)}>Next</button></nav></section>
        <p className="footnote">Readings update automatically every 3 seconds. Management actions are reserved for authorized staff.</p>
        <ReadingAlert reading={latest} />
      </main>
    </UserLayout>
  );
}

export default UserDashboard;

