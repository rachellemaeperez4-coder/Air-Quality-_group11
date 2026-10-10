import { useCallback, useEffect, useState } from "react";
import StaffLayout from "../../components/StaffLayout";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";

export default function StaffUserLogs() {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");
  const [refreshing, setRefreshing] = useState(false);

  const refresh = useCallback(async () => {
    setRefreshing(true);
    try {
      const result = await apiRequest("/api/staff/user-activity-logs", undefined, "GET");
      setData(result);
      setError("");
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    refresh();
    const interval = window.setInterval(refresh, 30000);
    return () => window.clearInterval(interval);
  }, [refresh]);

  return (
    <StaffLayout account={data?.account} module="userlogs">
      <main className="content">
        <section className="page-heading">
          <div><span className="eyebrow">System administration</span><h1>User activity</h1><p>Review user and staff sign-ins, sign-outs, and page visits.</p></div>
          <button className="button secondary" type="button" onClick={refresh} disabled={refreshing}>{refreshing ? "Refreshing…" : "Refresh"}</button>
        </section>
        {error && <p className="error" role="alert">{error}</p>}
        <section className="section">
          <div className="section-head"><div><h2>Recent activity</h2><p>Showing up to 500 latest events. This list refreshes every 30 seconds.</p></div></div>
          <div className="table-scroll"><table><thead><tr><th>Time</th><th>User</th><th>Activity</th><th>Description</th></tr></thead><tbody>
            {(data?.rows || []).map((row) => <tr key={row.log_id}><td>{formatReadingTime(row.created_at)}</td><td>{row.user_name || "Deleted account"}<small>{row.user_email || "—"}</small></td><td>{row.activity_type}</td><td>{row.description || "—"}</td></tr>)}
            {data && !data.rows.length && <tr><td className="empty-cell" colSpan="4">No user activity has been recorded yet.</td></tr>}
            {!data && !error && <tr><td className="empty-cell" colSpan="4">Loading activity…</td></tr>}
          </tbody></table></div>
        </section>
      </main>
    </StaffLayout>
  );
}
