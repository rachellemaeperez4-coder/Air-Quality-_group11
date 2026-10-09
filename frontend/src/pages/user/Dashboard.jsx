import { useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { apiRequest } from "../../lib/api";
import { formatReadingTime, startOfPhilippineDay } from "../../lib/time";
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
  const [range, setRange] = useState("7days");
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

  const chartPoints = useMemo(() => {
    if (!data) return [];
    const now = data.trend?.[0]?.recorded_at
      ? new Date(data.trend[0].recorded_at).getTime()
      : data.latest?.recorded_at ? new Date(data.latest.recorded_at).getTime() : 0;
    const cutoff = range === "today" ? startOfPhilippineDay() : now - (range === "7days" ? 7 : 30) * 86400000;
    return (data.trend || []).filter((row) => row.sensor_value !== null && new Date(row.recorded_at).getTime() >= cutoff).slice().reverse();
  }, [data, range]);

  const latest = data?.latest;
  const values = chartPoints.map((row) => Number(row.sensor_value)).filter(Number.isFinite);
  const min = values.length ? Math.min(...values) : 0;
  const max = values.length ? Math.max(...values) : 1;
  const polyline = values.map((value, index) => `${values.length < 2 ? 50 : index / (values.length - 1) * 100},${90 - (value - min) / Math.max(1, max - min) * 80}`).join(" ");

  return (
    <UserLayout account={data?.account} page="dashboard">
      <main className="dashboard-content" id="overview">
        <section className="page-heading"><div><p className="eyebrow">Air quality overview</p><h1>Kitchen air monitor</h1><p>Live MQ-2 readings and sensor status.</p></div><Link className="button secondary" to="/user/readings">View readings</Link></section>
        {error && <p className="error" role="alert">{error}</p>}
        <section className="cards" aria-label="Latest reading summary">
          <article className="card"><div className="card-top"><h2>Latest sensor value</h2></div><div className="value">{formatValue(latest?.sensor_value)}</div><p className="card-note">Raw sensor value, not ppm</p></article>
          <article className="card"><div className="card-top"><h2>Reading status</h2></div><div className="reading-status-value"><strong>{formatValue(latest?.sensor_value)}</strong><span className={`status-badge ${latest?.status_class || "status-neutral"}`}>{latest?.status || "No data"}</span></div><p className="card-note">Latest sensor reading · raw value, not ppm</p></article>
          <article className="card"><div className="card-top"><h2>Last recorded</h2></div><div className="value time">{formatTime(latest?.recorded_at)}</div><p className="card-note">Time of the newest available reading</p></article>
        </section>
        <section className="trend-panel" aria-labelledby="trend-title">
          <div className="trend-heading"><div><h2 id="trend-title">MQ-2 reading history</h2><p>Sensor values over time · up to 1,000 recent readings</p></div><div className="trend-filters" role="group" aria-label="Filter trend"><button className="trend-filter" aria-pressed={range === "today"} onClick={() => setRange("today")}>Today</button><button className="trend-filter" aria-pressed={range === "7days"} onClick={() => setRange("7days")}>7 Days</button><button className="trend-filter" aria-pressed={range === "30days"} onClick={() => setRange("30days")}>30 Days</button></div></div>
          <div className="trend-chart-wrap"><svg className="trend-chart" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label={`MQ-2 reading chart showing ${values.length} readings`}><line x1="0" y1="90" x2="100" y2="90" /><line x1="0" y1="50" x2="100" y2="50" /><line x1="0" y1="10" x2="100" y2="10" />{values.length > 0 && <polyline points={polyline} />}</svg></div>
          <p className="trend-summary" aria-live="polite">{values.length ? `${values.length} actual sensor readings shown. Latest: ${formatValue(latest?.sensor_value)} at ${formatTime(latest?.recorded_at)}.` : "No readings for the selected time range."}</p>
        </section>
        <section className="readings" id="readings"><div className="panel-title"><div><h2>Recent MQ-2 readings</h2><span className="panel-subtitle">Latest records</span></div><Link className="panel-subtitle" to="/user/readings">View all readings</Link></div><div className="table-scroll" role="region" aria-label="Recent sensor readings"><table><thead><tr><th>Reading ID</th><th>Sensor value</th><th>Status</th><th>Recorded</th></tr></thead><tbody>{(data?.readings || []).slice(0, 10).map((row) => <tr key={row.reading_id}><td>#{row.reading_id}</td><td>{formatValue(row.sensor_value)}</td><td><span className={`status-badge ${row.status_class}`}>{row.status}</span></td><td>{formatTime(row.recorded_at)}</td></tr>)}{data && !data.readings?.length && <tr><td className="empty-cell" colSpan="4">No readings have been received yet.</td></tr>}</tbody></table></div></section>
        <section className="guide" id="status-guide"><div className="guide-head"><h2>Sensor reading status</h2><p>Uses configured limits; values are not ppm</p></div><div className="guide-items"><div className="guide-item"><span className="guide-swatch good"/><div><strong>Good</strong><span>0–{data?.thresholds?.good_max ?? "—"}</span></div></div><div className="guide-item"><span className="guide-swatch moderate"/><div><strong>Moderate</strong><span>&gt;{data?.thresholds?.good_max ?? "—"}–{data?.thresholds?.moderate_max ?? "—"}</span></div></div><div className="guide-item"><span className="guide-swatch hazard"/><div><strong>Hazardous</strong><span>&gt;{data?.thresholds?.moderate_max ?? "—"}–{data?.thresholds?.hazardous_max ?? "—"}</span></div></div><div className="guide-item"><span className="guide-swatch very-hazardous"/><div><strong>Very Hazardous</strong><span>&gt;{data?.thresholds?.hazardous_max ?? "—"}</span></div></div></div></section>
        <p className="footnote">Readings update automatically every 3 seconds. Management actions are reserved for authorized staff.</p>
        <ReadingAlert reading={latest} />
      </main>
    </UserLayout>
  );
}

export default UserDashboard;

