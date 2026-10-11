import { useCallback, useEffect, useState } from "react";
import StaffLayout from "../../components/StaffLayout";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";

const showTime = formatReadingTime;

function StaffAlerts() {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  const refresh = useCallback(async () => {
    try {
      const result = await apiRequest("/api/staff/alerts", undefined, "GET");
      setData(result);
      setError("");
    } catch (requestError) {
      setError(requestError.message);
    }
  }, []);

  useEffect(() => {
    const initialLoad = window.setTimeout(refresh, 0);
    const interval = window.setInterval(refresh, 15000);
    return () => {
      window.clearTimeout(initialLoad);
      window.clearInterval(interval);
    };
  }, [refresh]);

  return (
    <StaffLayout account={data?.account} module="alerts">
      <main className="content">
        <section className="page-heading"><div><span className="eyebrow">System administration</span><h1>Air quality alerts</h1><p>Review alerts generated from sensor readings.</p></div></section>
        {error && <p className="error" role="alert">{error}</p>}
        <section className="section">
          <div className="section-head"><div><h2>Air quality alerts</h2><p>Newest alerts first. Status refreshes automatically.</p></div></div>
          <div className="table-scroll"><table><thead><tr><th>Alert</th><th>Severity</th><th>Message</th><th>Status</th><th>Created</th></tr></thead><tbody>
            {(data?.rows || []).map((row) => <tr key={row.alert_id}><td>#{row.alert_id}</td><td>{row.severity}</td><td>{row.message}</td><td>{row.status}</td><td>{showTime(row.created_at)}</td></tr>)}
            {data && !data.rows.length && <tr><td className="empty-cell" colSpan="5">No alerts have been recorded.</td></tr>}
            {!data && !error && <tr><td className="empty-cell" colSpan="5">Loading alerts…</td></tr>}
          </tbody></table></div>
        </section>
      </main>
    </StaffLayout>
  );
}

export default StaffAlerts;
