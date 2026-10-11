import { lazy, Suspense, useState } from "react";
import { useLocation } from "react-router-dom";
import "../../css/Home.css";

const Login = lazy(() => import("../Login"));

function Home() {
  const location = useLocation();
  const [loginOpen, setLoginOpen] = useState(location.pathname === "/login");

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
        <section className="home-hero home-hero-intro">
          <div className="home-copy">
            <p className="home-eyebrow"><span /> A better view of your environment</p>
            <h1>One zone.<br />One clear view<br />of <em>your air.</em></h1>
            <p className="home-lead">Monitor air quality in one place with live MQ-2 readings and clear status updates.</p>
            <button className="home-button" onClick={() => setLoginOpen(true)}>Access your account →</button>
            <p className="home-caption">One monitored zone. Clear air quality updates.</p>
          </div>

        </section>
      </main>

      <footer className="home-footer"><span>© {new Date().getFullYear()} Air Quality Monitoring</span><span>Know your air. Stay aware.</span></footer>
      {loginOpen && <Suspense fallback={null}><Login onClose={() => setLoginOpen(false)} /></Suspense>}
    </div>
  );
}

export default Home;

