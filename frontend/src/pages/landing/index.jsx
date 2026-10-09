import { lazy, Suspense, useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import { apiRequest } from "../../lib/api";
import { formatReadingTime } from "../../lib/time";
import "../../css/Home.css";

const Login = lazy(() => import("../Login"));

function Home() {
  const location = useLocation();
  const [loginOpen, setLoginOpen] = useState(location.pathname === "/login");
  const [zones, setZones] = useState([]);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    async function refresh() {
      try {
        const result = await apiRequest("/api/public/latest-readings", undefined, "GET");
        if (active) {
          setZones(result.zones || []);
          setError(result.error || "");
        }
      } catch (requestError) {
        if (active) setError(requestError.message);
      } finally {
        if (active) setLoading(false);
      }
    }

    refresh();
    const timer = window.setInterval(refresh, 10000);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, []);

  return (
    <div className="home-page">
      <header className="home-header">
        <a className="home-brand" href="/" aria-label="AirSense home">
          <span className="home-mark" aria-hidden="true">≈</span>
          <span>AirSense<small>Air Quality Monitoring</small></span>
        </a>
        <nav aria-label="Main navigation">
          <a href="#overview">Overview</a>
          <button className="home-button" onClick={() => setLoginOpen(true)}>Sign in →</button>
        </nav>
      </header>

      <main className="home-main" id="overview">
        <section className="home-hero">
          <div className="home-copy">
            <p className="home-eyebrow"><span /> A better view of your environment</p>
            <h1>One zone.<br />One clear view<br />of <em>your air.</em></h1>
            <p className="home-lead">Monitor air quality in one place with live MQ-2 readings and clear status updates.</p>
            <button className="home-button" onClick={() => setLoginOpen(true)}>Access your account →</button>
            <p className="home-caption">One monitored zone. Clear air quality updates.</p>
          </div>

          <section className="live-card" aria-label="Latest air quality reading">
            <div className="live-card-heading"><h2>Latest reading</h2><span>Live database</span></div>
            <div className="live-summary"><strong>{error ? "—" : zones.length}</strong><div><b>Monitored zone</b><small>Latest available MQ-2 reading</small></div></div>
            {error && <p className="live-note error" role="status">{error}</p>}
            {!error && !loading && zones.length === 0 && <p className="live-note">No readings are available yet. New readings will appear here when an ESP32 uploads data.</p>}
            <div className="live-zones" aria-live="polite">
              {zones.map((zone) => (
                <article className={`live-zone ${zone.class}`} key={zone.name}>
                  <div className="live-zone-row">
                    <h3>{zone.name}<small>{[zone.location, zone.recorded_at && formatReadingTime(zone.recorded_at)].filter(Boolean).join(" · ")}</small></h3>
                    <div className="live-reading">{zone.aqi}<span>{zone.status}</span></div>
                  </div>
                  <div className="live-track"><span style={{ width: `${zone.width}%` }} /></div>
                </article>
              ))}
            </div>
            {zones.length > 0 && <p className="live-note">Showing the latest database reading for the monitored zone.</p>}
            <p className="live-note">Checking for new readings automatically.</p>
          </section>
        </section>
      </main>

      <footer className="home-footer"><span>© {new Date().getFullYear()} Air Quality Monitoring</span><span>Know your air. Stay aware.</span></footer>
      {loginOpen && <Suspense fallback={null}><Login onClose={() => setLoginOpen(false)} /></Suspense>}
    </div>
  );
}

export default Home;

