const { createClient } = require("@supabase/supabase-js");

function supabaseForToken(accessToken) {
  const url = process.env.SUPABASE_URL;
  const key = process.env.SUPABASE_PUBLISHABLE_KEY || process.env.SUPABASE_KEY;
  if (!url || !key) {
    const error = new Error("Supabase is not configured on the Node backend.");
    error.status = 503;
    throw error;
  }
  return createClient(url, key, {
    global: { headers: { Authorization: `Bearer ${accessToken}` } },
    auth: { persistSession: false, autoRefreshToken: false },
  });
}

function authenticate(requiredRole) {
  return async (req, res, next) => {
    try {
      const token = req.get("authorization")?.match(/^Bearer\s+(.+)$/i)?.[1];
      if (!token) return res.status(401).json({ error: "Sign in to continue." });

      const supabase = supabaseForToken(token);
      const { data: authData, error: authError } = await supabase.auth.getUser(token);
      if (authError || !authData.user) return res.status(401).json({ error: "Your session has expired. Sign in again." });

      const { data: account, error: accountError } = await supabase
        .from("users")
        .select("user_id, auth_user_id, name, email, role, account_status")
        .eq("auth_user_id", authData.user.id)
        .single();
      if (accountError || !account) return res.status(403).json({ error: "Unable to verify your account." });
      if (String(account.account_status || "").toLowerCase() !== "active") {
        return res.status(403).json({ error: "This account is disabled. Contact your administrator." });
      }

      const role = String(account.role || "").trim().toLowerCase();
      if (requiredRole && role !== requiredRole) return res.status(403).json({ error: "You do not have permission to open this page." });
      req.account = { ...account, role };
      req.supabase = supabase;
      next();
    } catch (error) {
      res.status(error.status || 500).json({ error: error.message || "Authentication failed." });
    }
  };
}

module.exports = { authenticate, supabaseForToken };
