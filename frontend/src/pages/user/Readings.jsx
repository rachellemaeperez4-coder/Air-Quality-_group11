import { useEffect, useState } from "react";
import { apiRequest } from "../../lib/api";
import UserLayout from "../../components/UserLayout";

const time = (value) => value ? new Date(value).toLocaleString([], { dateStyle: "medium", timeStyle: "short" }) : "—";

function UserReadings() {
  const [filters, setFilters] = useState({ search: "", status: "", date_from: "", date_to: "" });
  const [applied, setApplied] = useState(filters);
  const [page, setPage] = useState(1);
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    const params = new URLSearchParams({ ...applied, page: String(page) });
    apiRequest(`/api/user/readings?${params}`, undefined, "GET")
      .then((result) => { if (active) { setData(result); setError(""); } })
      .catch((requestError) => { if (active) setError(requestError.message); });
    return () => { active = false; };
  }, [applied, page]);

  function submit(event) {
    event.preventDefault();
    setApplied({ ...filters });
    setPage(1);
  }

  return (
    <UserLayout account={data?.account} page="readings">
      <main className="dashboard-content">
        <section className="page-heading"><div><p className="eyebrow">Monitoring history</p><h1>Readings</h1><p>Search and filter recorded MQ-2 values.</p></div></section>
        <section className="readings">
          <form className="filters" onSubmit={submit}>
            <label>Search<input value={filters.search} maxLength={80} onChange={(event) => setFilters({ ...filters, search: event.target.value })} placeholder="Reading ID or status" /></label>
            <label>Status<select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">All statuses</option><option value="good">Good</option><option value="moderate">Moderate</option><option value="hazardous">Hazardous</option><option value="very_hazardous">Very Hazardous</option><option value="unknown">Unknown</option></select></label>
            <label>From<input type="date" value={filters.date_from} onChange={(event) => setFilters({ ...filters, date_from: event.target.value })} /></label>
            <label>To<input type="date" value={filters.date_to} onChange={(event) => setFilters({ ...filters, date_to: event.target.value })} /></label>
            <div className="filter-actions"><button className="button" type="submit">Apply filters</button><button className="button secondary" type="button" onClick={() => { const empty = { search: "", status: "", date_from: "", date_to: "" }; setFilters(empty); setApplied(empty); setPage(1); }}>Clear</button></div>
          </form>
          {error && <p className="error" role="alert">{error}</p>}
          {data?.invalid_date_range && <p className="error" role="alert">Enter valid dates and make sure the start date is not after the end date.</p>}
          <div className="panel-title"><div><h2>Recorded sensor readings</h2><span className="panel-subtitle">{data ? `${data.total} matching records` : "Loading records…"}</span></div></div>
          <div className="table-scroll" role="region" aria-label="Sensor readings"><table><thead><tr><th>Reading ID</th><th>Sensor value</th><th>Status</th><th>Recorded</th></tr></thead><tbody>{(data?.rows || []).map((row) => <tr key={row.reading_id}><td>#{row.reading_id}</td><td>{row.sensor_value ?? "—"}</td><td><span className={`status-badge ${row.status_class}`}>{row.status}</span></td><td>{time(row.recorded_at)}</td></tr>)}{data && !data.rows.length && <tr><td className="empty-cell" colSpan="4">No readings match these filters.</td></tr>}</tbody></table></div>
          <div className="pagination"><button className="button secondary" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Previous</button><span>Page {page}</span><button className="button secondary" disabled={!data?.has_next} onClick={() => setPage((value) => value + 1)}>Next</button></div>
        </section>
      </main>
    </UserLayout>
  );
}

export default UserReadings;

