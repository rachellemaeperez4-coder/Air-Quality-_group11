const express = require("express");
const cors = require("cors");
const { createClient } = require("@supabase/supabase-js");
const { authenticate } = require("./authenticate");
const staffRouter = require("./routes/staff");
const userRouter = require("./routes/user");

require("dotenv").config();

const app = express();
const PORT = process.env.PORT || 5000;

const allowedOrigins = (process.env.CORS_ORIGIN || "http://localhost:5173")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

const isAllowedOrigin = (origin) => !origin
  || allowedOrigins.includes(origin)
  || /^http:\/\/(localhost|127\.0\.0\.1):517\d+$/.test(origin);

app.use(cors({ origin: (origin, callback) => callback(null, isAllowedOrigin(origin)) }));
app.use(express.json({ limit: "20kb" }));

function getSupabase() {
  const url = process.env.SUPABASE_URL;
  const key = process.env.SUPABASE_PUBLISHABLE_KEY || process.env.SUPABASE_KEY;

  if (!url || !key) {
    const error = new Error("Supabase is not configured on the Node backend.");
    error.status = 503;
    throw error;
  }

  return createClient(url, key, {
    auth: { persistSession: false, autoRefreshToken: false },
  });
}

function sendError(res, error) {
  const status = Number.isInteger(error.status) ? error.status : 400;
  res.status(status).json({ error: error.message || "Request failed." });
}

app.get("/", (_req, res) => {
  res.json({ message: "Air Quality Monitoring API is running." });
});

app.get("/api/public/latest-readings", async (_req, res) => {
  try {
    const { data, error } = await getSupabase().rpc("aqm_public_latest_zone_readings");
    if (error) throw error;

    const zones = (data || []).slice(0, 1).map((row) => {
      const value = Number(row.mq135_value);
      const status = row.quality_status || "Unknown";
      const className = {
        Good: "good",
        Moderate: "moderate",
        Hazardous: "hazardous",
        "Very Hazardous": "very-hazardous",
      }[status] || "unknown";

      return {
        name: row.zone_name,
        location: row.location || "Monitoring zone",
        recorded_at: row.recorded_at,
        aqi: row.mq135_value,
        status,
        class: className,
        width: Number.isFinite(value) ? Math.round(Math.max(0, Math.min(600, value)) / 600 * 100) : 0,
      };
    });

    res.set("Cache-Control", "no-store").json({ zones });
  } catch (error) {
    res.status(503).json({
      zones: [],
      error: String(error.message).includes("fetch failed")
        ? "Live readings could not connect to Supabase. Check the API configuration and network access."
        : error.message || "Latest readings could not be loaded. Check the Supabase setup.",
    });
  }
});

app.post("/api/auth/signup", async (req, res) => {
  try {
    const name = typeof req.body?.name === "string" ? req.body.name.trim() : "";
    const email = typeof req.body?.email === "string" ? req.body.email.trim() : "";
    const password = typeof req.body?.password === "string" ? req.body.password : "";

    if (!name || name.length > 100 || !email || password.length < 8) {
      return res.status(400).json({ error: "Enter your name, a valid email, and a password with at least 8 characters." });
    }

    const supabase = getSupabase();
    const { data, error } = await supabase.auth.signUp({
      email,
      password,
      options: { data: { name } },
    });
    if (error) throw error;

    res.status(201).json({
      message: data.session
        ? "Account created successfully."
        : "Account created. Please check your email to confirm your account.",
      session: data.session,
    });
  } catch (error) {
    sendError(res, error);
  }
});

app.get("/api/auth/me", authenticate(), (req, res) => {
  res.set("Cache-Control", "no-store").json({
    account: {
      user_id: req.account.user_id,
      name: req.account.name,
      email: req.account.email,
      role: req.account.role,
      account_status: req.account.account_status,
    },
  });
});

app.post("/api/auth/login", async (req, res) => {
  try {
    const email = typeof req.body?.email === "string" ? req.body.email.trim() : "";
    const password = typeof req.body?.password === "string" ? req.body.password : "";
    if (!email || !password) {
      return res.status(400).json({ error: "Email and password are required." });
    }

    const supabase = getSupabase();
    const { data, error } = await supabase.auth.signInWithPassword({ email, password });
    if (error) throw error;

    const { data: profile, error: profileError } = await supabase
      .from("users")
      .select("role, account_status")
      .eq("auth_user_id", data.user.id)
      .single();

    if (profileError || !profile) {
      await supabase.auth.signOut({ scope: "local" });
      return res.status(403).json({ error: "Unable to verify your account. Check the Supabase users table setup." });
    }

    const role = String(profile.role || "").trim().toLowerCase();
    if (!['user', 'staff'].includes(role)) {
      return res.status(403).json({ error: "Your account does not have a valid role." });
    }
    if (String(profile.account_status || "").trim().toLowerCase() !== "active") {
      return res.status(403).json({ error: "This account is disabled. Contact your administrator." });
    }

    res.json({
      session: data.session,
      account: { role, accountStatus: profile.account_status },
    });
  } catch (error) {
    sendError(res, error);
  }
});

app.use("/api/user", userRouter);
app.use("/api/staff", staffRouter);

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`Server running at http://localhost:${PORT}`);
  });
}

module.exports = app;


