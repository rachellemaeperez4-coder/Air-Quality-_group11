import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import StaffLayout from "../../components/StaffLayout";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";
import { getSupabaseClient } from "../../lib/supabase";

const titles = {
  devices: ["ESP32 device", "Manage the registered ESP32 monitor."],
  sensors: ["Sensor setup", "Register the MQ-2 sensor to the ESP32 device."],
  users: ["Account management", "Manage linked account roles and access status."],
  settings: ["Threshold settings", "Adjust the shared air-quality classification limits."],
  readings: ["Sensor readings", "Review, export, or clean up stored readings."],
  audit: ["Alert history", "Review records stored in the existing alerts table."],
};
const specs = {
  zones: { id: "zone_id", fields: [{ key: "zone_name", label: "Zone name", required: true }, { key: "location", label: "Location" }] },
  devices: { id: "device_id", fields: [{ key: "device_name", label: "Device name", required: true }, { key: "device_code", label: "Device code", required: true }, { key: "status", label: "Status" }] },
  sensors: { id: "sensor_id", fields: [{ key: "sensor_name", label: "Sensor name", required: true }, { key: "sensor_type", label: "Sensor type", required: true }, { key: "unit", label: "Unit" }, { key: "status", label: "Status" }] },
};
const showTime = formatReadingTime;

function StaffConsole({ section: requestedSection }) {
  const { module: routeModule = "dashboard" } = useParams();
  const selectedSection = requestedSection || routeModule;
  const module = ({ accounts: "users", thresholds: "settings" })[selectedSection] || selectedSection;
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [saving, setSaving] = useState(false);
  const [editing, setEditing] = useState(null);
  const [draft, setDraft] = useState({});
  const [filters, setFilters] = useState({ date: "", page: 1 });
  const [accountSearch, setAccountSearch] = useState("");
  const [accountPage, setAccountPage] = useState(1);
  const [thresholdDraft, setThresholdDraft] = useState(null);

  async function load() {
    const params = module === "readings" ? `?page=${filters.page}&date=${encodeURIComponent(filters.date)}` : "";
    const result = await apiRequest(`/api/staff/${module}${params}`, undefined, "GET");
    setData(result);
    setError("");
    if (module === "settings" && result.thresholds) setThresholdDraft(result.thresholds);
  }

  useEffect(() => {
    let active = true;
    async function refresh() {
      try {
        const params = module === "readings" ? `?page=${filters.page}&date=${encodeURIComponent(filters.date)}` : "";
        const result = await apiRequest(`/api/staff/${module}${params}`, undefined, "GET");
        if (active) {
          setData(result);
          setError("");
          if (module === "settings" && result.thresholds) setThresholdDraft(result.thresholds);
        }
      } catch (requestError) { if (active) setError(requestError.message); }
    }
    refresh();
    return () => { active = false; };
  }, [module, filters]);

  async function mutate(path, body, method = "POST") {
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const result = await apiRequest(path, body, method);
      setNotice(result.message || "Saved.");
      setEditing(null);
      await load();
    } catch (requestError) { setError(requestError.message); }
    finally { setSaving(false); }
  }

  async function exportCsv() {
    try {
      const { data: sessionData } = await getSupabaseClient().auth.getSession();
      const response = await fetch(`${import.meta.env.VITE_API_BASE_URL || "http://localhost:5000"}/api/staff/readings/export.csv`, { headers: { Authorization: `Bearer ${sessionData.session?.access_token || ""}` } });
      if (!response.ok) throw new Error("CSV export failed. Check your Staff permissions.");
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a"); anchor.href = url; anchor.download = "airsense-readings.csv"; anchor.click(); URL.revokeObjectURL(url);
    } catch (requestError) { setError(requestError.message); }
  }

  function startEdit(row) {
    setEditing(row[schema.id]);
    setDraft(Object.fromEntries(schema.fields.map((field) => [field.key, row[field.key] ?? ""])));
  }

  const schema = specs[module];
  const pageTitle = titles[module] || ["Staff management", "Manage monitoring data."];
  const readingsRows = useMemo(() => data?.rows || [], [data]);
  const filteredAccounts = useMemo(() => {
    const query = accountSearch.trim().toLowerCase();
    const rows = data?.rows || [];
    return query ? rows.filter((row) => `${row.name || ""} ${row.email || ""}`.toLowerCase().includes(query)) : rows;
  }, [data, accountSearch]);
  const accountPageCount = Math.max(1, Math.ceil(filteredAccounts.length / 5));
  const currentAccountPage = Math.min(accountPage, accountPageCount);
  const visibleAccounts = filteredAccounts.slice((currentAccountPage - 1) * 5, currentAccountPage * 5);

  return (
    <StaffLayout account={data?.account} module={selectedSection}>
      <main className="content">
        <section className="page-heading"><div><span className="eyebrow">System administration</span><h1>{pageTitle[0]}</h1><p>{pageTitle[1]}</p></div></section>
        {notice && <p className="notice" role="status">{notice}</p>}{error && <p className="error" role="alert">{error}</p>}

        {module === "settings" && thresholdDraft && <section className="section"><div className="section-head"><div><h2>Shared air-quality thresholds</h2><p>Whole-number limits from 0 to 600, in increasing order.</p></div></div><form className="section-body threshold-form" onSubmit={(event) => { event.preventDefault(); mutate("/api/staff/thresholds", thresholdDraft); }}><label className="field">Good maximum<input type="number" min="0" max="598" value={thresholdDraft.good_max} onChange={(event) => setThresholdDraft({ ...thresholdDraft, good_max: Number(event.target.value) })} /></label><label className="field">Moderate maximum<input type="number" min="1" max="599" value={thresholdDraft.moderate_max} onChange={(event) => setThresholdDraft({ ...thresholdDraft, moderate_max: Number(event.target.value) })} /></label><label className="field">Hazardous maximum<input type="number" min="2" max="600" value={thresholdDraft.hazardous_max} onChange={(event) => setThresholdDraft({ ...thresholdDraft, hazardous_max: Number(event.target.value) })} /></label><button className="button" disabled={saving}>Save thresholds</button></form></section>}

        {schema && <section className="section"><div className="section-head"><div><h2>{module[0].toUpperCase() + module.slice(1)} management</h2><p>{["zones", "devices", "sensors"].includes(module) ? `This setup supports one ${module.slice(0, -1)} only.` : `Add, edit, or delete records in the existing ${module} table.`}</p></div></div><div className="section-body">{(!["zones", "devices", "sensors"].includes(module) || data?.rows?.length === 0) && <form className="management-form" onSubmit={(event) => { event.preventDefault(); mutate(`/api/staff/records/${module}`, { action: "create", fields: draft }); setDraft({}); }}>
          {schema.fields.map((field) => <label key={field.key}>{field.label}<input required={field.required} value={draft[field.key] || ""} onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })} /></label>)}<button className="button" disabled={saving}>Add {module.slice(0, -1)}</button>
        </form>}{["zones", "devices", "sensors"].includes(module) && data?.rows?.length > 0 && <p className="panel-subtitle">One {module.slice(0, -1)} is already registered. Edit the existing record below.</p>}</div><div className="table-scroll"><table><thead><tr><th>ID</th>{schema.fields.map((field) => <th key={field.key}>{field.label}</th>)}<th>Action</th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row[schema.id]}><td>#{row[schema.id]}</td>{schema.fields.map((field) => <td key={field.key}>{editing === row[schema.id] ? <input value={draft[field.key] ?? ""} onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })} /> : row[field.key] ?? "—"}</td>)}<td><div className="row-actions">{editing === row[schema.id] ? <><button className="button" disabled={saving} onClick={() => mutate(`/api/staff/records/${module}`, { action: "update", id: row[schema.id], fields: draft })}>Save</button><button className="button secondary" onClick={() => setEditing(null)}>Cancel</button></> : <><button className="button secondary" onClick={() => startEdit(row)}>Edit</button><button className="button danger" onClick={() => window.confirm(`Delete record #${row[schema.id]}?`) && mutate(`/api/staff/records/${module}`, { action: "delete", id: row[schema.id], confirm: "DELETE" })}>Delete</button></>}</div></td></tr>)}{data && !data.rows.length && <tr><td className="empty-cell" colSpan={schema.fields.length + 2}>No records available.</td></tr>}</tbody></table></div></section>}

        {module === "users" && <section className="section">
          <div className="section-head"><div><h2>Linked accounts</h2><p>Promote accounts or control access status.</p></div></div>
          <div className="section-body accounts-toolbar"><label>Search accounts<input type="search" value={accountSearch} placeholder="Name or email" onChange={(event) => { setAccountSearch(event.target.value); setAccountPage(1); }} /></label><span>Showing {filteredAccounts.length ? (currentAccountPage - 1) * 5 + 1 : 0}–{Math.min(currentAccountPage * 5, filteredAccounts.length)} of {filteredAccounts.length} accounts</span></div>
          <div className="table-scroll"><table><thead><tr><th>Name / email</th><th>Role</th><th>Account status</th><th>Action</th></tr></thead><tbody>
            {visibleAccounts.map((row) => <tr key={row.user_id}><td>{row.name}<small>{row.email}</small></td><td><select aria-label={`Role for ${row.email}`} value={row.role} onChange={(event) => setData({ ...data, rows: data.rows.map((user) => user.user_id === row.user_id ? { ...user, role: event.target.value } : user) })}><option>User</option><option>Staff</option></select></td><td><select aria-label={`Status for ${row.email}`} value={row.account_status} onChange={(event) => setData({ ...data, rows: data.rows.map((user) => user.user_id === row.user_id ? { ...user, account_status: event.target.value } : user) })}><option>Active</option><option>Disabled</option></select></td><td><div className="row-actions"><button className="button" disabled={saving} onClick={() => mutate(`/api/staff/accounts/${row.user_id}`, { role: row.role, account_status: row.account_status }, "PATCH")}>Save</button><button className="button secondary" disabled={saving} onClick={() => mutate("/api/staff/password-reset", { email: row.email })}>Send reset</button></div></td></tr>)}
            {data && !filteredAccounts.length && <tr><td className="empty-cell" colSpan="4">No accounts match your search.</td></tr>}
          </tbody></table></div>
          {filteredAccounts.length > 5 && <div className="pagination"><button className="button secondary" disabled={currentAccountPage <= 1} onClick={() => setAccountPage((page) => Math.max(1, page - 1))}>Previous</button><span>Page {currentAccountPage} of {accountPageCount}</span><button className="button secondary" disabled={currentAccountPage >= accountPageCount} onClick={() => setAccountPage((page) => Math.min(accountPageCount, page + 1))}>Next</button></div>}
        </section>}

        {module === "readings" && <section className="section"><div className="section-head"><div><h2>Stored readings</h2><p>{data ? `${data.total} total · 50 per page` : "Loading readings…"}</p></div><button className="button secondary" onClick={exportCsv}>Export CSV</button></div><div className="section-body staff-toolbar"><label>Filter by date<input type="date" value={filters.date} onChange={(event) => setFilters({ date: event.target.value, page: 1 })} /></label><button className="button secondary" onClick={() => setFilters({ date: "", page: 1 })}>Clear date</button></div><div className="table-scroll"><table><thead><tr><th>ID</th><th>Device</th><th>Sensor</th><th>MQ-2 value</th><th>Recorded</th><th>Action</th></tr></thead><tbody>{readingsRows.map((row) => <tr key={row.reading_id}><td>#{row.reading_id}</td><td>{data.devices.find((device) => device.device_id === row.device_id)?.device_name || `Device ${row.device_id || "—"}`}</td><td>{data.sensors.find((sensor) => sensor.sensor_id === row.sensor_id)?.sensor_name || `Sensor ${row.sensor_id || "—"}`}</td><td>{row.mq135_value ?? "—"}</td><td>{showTime(row.recorded_at)}</td><td><button className="button danger" onClick={() => window.confirm(`Permanently delete reading #${row.reading_id}?`) && mutate(`/api/staff/readings/${row.reading_id}`, undefined, "DELETE")}>Delete</button></td></tr>)}{data && !data.rows.length && <tr><td className="empty-cell" colSpan="6">No readings are available.</td></tr>}</tbody></table></div><div className="pagination"><button className="button secondary" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Previous</button><span>Page {filters.page}</span><button className="button secondary" disabled={!data?.has_next} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Next</button></div></section>}

        {module === "audit" && <section className="section"><div className="section-head"><div><h2>Alert history</h2><p>{data?.notice || "Records stored in the existing alerts table."}</p></div></div><div className="table-scroll"><table><thead><tr><th>Created</th><th>Type</th><th>Severity</th><th>Status</th><th>Zone</th><th>Device</th><th>Message</th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row.alert_id}><td>{showTime(row.created_at)}</td><td>{row.alert_type}</td><td>{row.severity}</td><td>{row.status}</td><td>{row.zone_id}</td><td>{row.device_id}</td><td>{row.message}</td></tr>)}{data && !data.rows.length && <tr><td className="empty-cell" colSpan="7">No alert history is available.</td></tr>}</tbody></table></div></section>}
        <p className="footnote">Staff changes are enforced by Supabase row-level security.</p>
      </main>
    </StaffLayout>
  );
}

export default StaffConsole;



