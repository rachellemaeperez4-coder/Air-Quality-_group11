import { useEffect, useState } from "react";
import { Navigate } from "react-router-dom";
import { apiRequest } from "../lib/api";
import { isRoleVerified, verifyRole } from "../lib/role-verification";

function RequireRole({ role, children }) {
  const [allowed, setAllowed] = useState(() => isRoleVerified(role));
  const [checked, setChecked] = useState(() => isRoleVerified(role));

  useEffect(() => {
    if (isRoleVerified(role)) return undefined;
    let active = true;
    apiRequest("/api/auth/me", undefined, "GET")
      .then((result) => {
        if (active) {
          const matchesRole = result.account?.role === role;
          setAllowed(matchesRole);
          if (matchesRole) verifyRole(role);
        }
      })
      .catch(() => { if (active) setAllowed(false); })
      .finally(() => { if (active) setChecked(true); });
    return () => { active = false; };
  }, [role]);

  if (!checked) return <div role="status" style={{ minHeight: "100vh", padding: 24, background: "#071722", color: "#e4eef5", fontFamily: "system-ui, sans-serif" }}>Checking your account…</div>;
  if (!allowed) return <Navigate to="/login" replace />;
  return children;
}

export default RequireRole;


