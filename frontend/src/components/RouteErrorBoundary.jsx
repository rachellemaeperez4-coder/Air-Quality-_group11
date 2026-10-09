import { Component } from "react";

class RouteErrorBoundary extends Component {
  state = { error: null };

  static getDerivedStateFromError(error) {
    return { error };
  }

  render() {
    if (this.state.error) {
      return (
        <main role="alert" style={{ minHeight: "100vh", padding: 32, background: "#071722", color: "#e4eef5", fontFamily: "system-ui, sans-serif" }}>
          <h1>Page failed to load</h1>
          <pre style={{ maxWidth: 900, whiteSpace: "pre-wrap", color: "#ffb4a8" }}>{this.state.error.message}</pre>
          <button type="button" onClick={() => window.location.reload()}>Reload page</button>
        </main>
      );
    }

    return this.props.children;
  }
}

export default RouteErrorBoundary;
