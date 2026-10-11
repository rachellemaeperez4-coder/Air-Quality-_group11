const express = require("express");
const { authenticate } = require("../authenticate");
const { getThresholds, qualityStatus, statusClass } = require("../air-quality");

const router = express.Router();
router.use(authenticate("staff"));

const TABLES = {
  zones: { id: "zone_id", fields: ["zone_name", "location", "description"], required: ["zone_name"] },
  devices: { id: "device_id", fields: ["zone_id", "device_name", "device_code", "status"], required: ["device_name", "device_code"] },
  sensors: { id: "sensor_id", fields: ["device_id", "sensor_name", "sensor_type", "unit", "status"], required: ["device_id", "sensor_name", "sensor_type"] },
};

function fail(res, error) {
  res.status(error.status || 500).json({ error: error.message || "Staff request failed." });
}

async function allRows(supabase, table, orderBy) {
  const { data, error } = await supabase.from(table).select("*").order(orderBy, { ascending: true }).limit(5000);
  if (error) throw error;
  return data || [];
}

router.get("/overview", async (req, res) => {
  try {
    const supabase = req.supabase;
    const manilaStart = new Date(Date.now() + 8 * 60 * 60 * 1000);
    manilaStart.setUTCHours(0, 0, 0, 0);
    const todayIso = new Date(manilaStart.getTime() - 8 * 60 * 60 * 1000).toISOString();
    const [limits, readings, users, devices, sensors, zones, alerts, activeAlerts] = await Promise.all([
      getThresholds(supabase),
      supabase.from("air_quality_readings").select("reading_id,mq135_value,air_quality_status,recorded_at").order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).limit(10),
      supabase.from("users").select("user_id,role,account_status", { count: "exact", head: true }),
      supabase.from("devices").select("device_id", { count: "exact", head: true }),
      supabase.from("sensors").select("sensor_id", { count: "exact", head: true }),
      supabase.from("zones").select("zone_id", { count: "exact", head: true }),
      supabase.from("alerts").select("alert_id", { count: "exact", head: true }).gte("created_at", todayIso),
      supabase.from("alerts").select("alert_id", { count: "exact", head: true }).gte("created_at", todayIso).in("status", ["Active", "Acknowledged"]),
    ]);
    const firstError = [limits, readings, users, devices, sensors, zones, alerts, activeAlerts].find((result) => result.error);
    if (firstError) return res.status(503).json({ error: "Staff overview could not be loaded. Check the Staff SQL migrations and permissions." });
    res.set("Cache-Control", "no-store").json({
      readings: readings.data || [],
      counts: { users: users.count || 0, devices: devices.count || 0, sensors: sensors.count || 0, zones: zones.count || 0, alerts_today: alerts.count || 0, active_alerts_today: activeAlerts.count || 0 },
      thresholds: limits.data,
      account: { name: req.account.name, email: req.account.email },
    });
  } catch (error) { fail(res, error); }
});

router.get("/latest-readings", async (req, res) => {
  try {
    const afterId = Number.parseInt(req.query.after_id, 10);
    let query = req.supabase
      .from("air_quality_readings")
      .select("reading_id,mq135_value,air_quality_status,recorded_at")
      .order("reading_id", { ascending: Number.isInteger(afterId) && afterId > 0 })
      .limit(100);

    if (Number.isInteger(afterId) && afterId > 0) {
      query = query.gt("reading_id", afterId);
    }

    const { data, error } = await query;
    if (error) throw error;

    res.set("Cache-Control", "no-store").json({ rows: data || [] });
  } catch (error) {
    fail(res, error);
  }
});

router.get("/user-activity-logs", async (req, res) => {
  try {
    const { data: logs, error } = await req.supabase
      .from("user_activity_logs")
      .select("log_id,user_id,auth_user_id,activity_type,description,created_at")
      .order("created_at", { ascending: false })
      .limit(500);
    if (error) throw error;

    const userIds = [...new Set((logs || []).map((log) => log.user_id).filter((id) => id !== null))];
    let usersById = new Map();
    if (userIds.length) {
      const { data: users, error: usersError } = await req.supabase.from("users").select("user_id,name,email").in("user_id", userIds);
      if (usersError) throw usersError;
      usersById = new Map((users || []).map((user) => [user.user_id, user]));
    }
    const rows = (logs || []).map((log) => {
      const user = usersById.get(log.user_id);
      return { ...log, user_name: user?.name || null, user_email: user?.email || null };
    });
    res.set("Cache-Control", "no-store").json({ rows, account: { name: req.account.name, email: req.account.email } });
  } catch (error) {
    fail(res, error);
  }
});

router.get("/:module", async (req, res) => {
  const module = req.params.module;
  const supabase = req.supabase;
  try {
    if (module === "settings") {
      const { data, error } = await getThresholds(supabase);
      if (error) throw error;
      return res.json({ thresholds: data, account: { name: req.account.name, email: req.account.email } });
    }
    if (module === "audit") {
      const { data, error } = await supabase.from("alerts").select("alert_id,zone_id,device_id,alert_type,severity,status,message,created_at").order("created_at", { ascending: false }).limit(500);
      if (error) throw error;
      return res.json({ rows: data || [], account: { name: req.account.name, email: req.account.email }, notice: "This history contains alert records. A general-purpose audit log is not configured." });
    }
    if (module === "readings") {
      const page = Math.max(1, Math.min(100000, Number.parseInt(req.query.page, 10) || 1));
      const pageSize = 10;
      const date = String(req.query.date || "");
      let query = supabase.from("air_quality_readings").select("reading_id,zone_id,device_id,sensor_id,mq135_value,air_quality_status,recorded_at", { count: "exact" });
      if (date && /^\d{4}-\d{2}-\d{2}$/.test(date) && !Number.isNaN(Date.parse(`${date}T00:00:00Z`))) {
        query = query.gte("recorded_at", `${date}T00:00:00Z`).lt("recorded_at", new Date(Date.parse(`${date}T00:00:00Z`) + 86400000).toISOString());
      }
      const { data, error, count } = await query.order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).range((page - 1) * pageSize, page * pageSize - 1);
      if (error) throw error;
      const [{ data: devices, error: devicesError }, { data: sensors, error: sensorsError }] = await Promise.all([
        supabase.from("devices").select("device_id,device_name,device_code"),
        supabase.from("sensors").select("sensor_id,sensor_name"),
      ]);
      if (devicesError || sensorsError) throw devicesError || sensorsError;
      const { data: limits, error: limitsError } = await getThresholds(supabase);
      if (limitsError) throw limitsError;
      const rows = (data || []).map((row) => {
        const status = qualityStatus(row.mq135_value, limits);
        return { ...row, status, status_class: statusClass(status) };
      });
      return res.json({ rows, total: count || 0, page, page_size: pageSize, has_next: page * pageSize < (count || 0), devices: devices || [], sensors: sensors || [], thresholds: limits, date, account: { name: req.account.name, email: req.account.email } });
    }

    const table = module === "users" ? "users" : module;
    const orders = { zones: "zone_id", devices: "device_id", sensors: "sensor_id", users: "user_id", alerts: "created_at" };
    if (!orders[module]) return res.status(404).json({ error: "Staff module not found." });
    const select = module === "users" ? "user_id,auth_user_id,name,email,role,account_status,created_at"
      : module === "alerts" ? "alert_id,zone_id,device_id,reading_id,alert_type,severity,message,status,created_at"
        : "*";
    const query = supabase.from(table).select(select).order(orders[module], { ascending: module !== "alerts", nullsFirst: false }).limit(5000);
    const { data, error } = await query;
    if (error) throw error;
    const linked = module === "devices"
      ? await supabase.from("zones").select("zone_id,zone_name").order("zone_id")
      : module === "sensors"
        ? await supabase.from("devices").select("device_id,device_name,device_code").order("device_id")
        : null;
    if (linked?.error) throw linked.error;
    res.set("Cache-Control", "no-store").json({ rows: data || [], options: linked?.data || [], account: { name: req.account.name, email: req.account.email } });
  } catch (error) { fail(res, error); }
});

router.get("/readings/export.csv", async (req, res) => {
  try {
    const records = [];
    for (let offset = 0; offset < 100000; offset += 1000) {
      const { data, error } = await req.supabase.from("air_quality_readings").select("reading_id,zone_id,device_id,sensor_id,mq135_value,air_quality_status,recorded_at").order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).range(offset, offset + 999);
      if (error) throw error;
      records.push(...(data || []));
      if ((data || []).length < 1000) break;
    }
    const columns = ["reading_id", "zone_id", "device_id", "sensor_id", "mq135_value", "air_quality_status", "recorded_at"];
    const csv = [columns.join(","), ...records.map((row) => columns.map((key) => `"${String(row[key] ?? "").replaceAll('"', '""')}"`).join(","))].join("\r\n");
    res.set({ "Content-Type": "text/csv; charset=utf-8", "Content-Disposition": "attachment; filename=airsense-readings.csv", "Cache-Control": "no-store" }).send(csv);
  } catch (error) { fail(res, error); }
});

router.delete("/readings/:id", async (req, res) => {
  try {
    if (!/^\d+$/.test(req.params.id)) return res.status(400).json({ error: "Select a valid reading ID." });
    const { data, error } = await req.supabase.from("air_quality_readings").delete().eq("reading_id", req.params.id).select("reading_id").maybeSingle();
    if (error) throw error;
    if (!data) return res.status(404).json({ error: "Reading not found or could not be deleted." });
    res.json({ message: "Reading deleted." });
  } catch (error) { fail(res, error); }
});

router.post("/thresholds", async (req, res) => {
  try {
    const values = [req.body?.good_max, req.body?.moderate_max, req.body?.hazardous_max];
    if (!values.every(Number.isInteger) || values[0] < 0 || values[0] >= values[1] || values[1] >= values[2] || values[2] > 600) {
      return res.status(400).json({ error: "Enter whole-number thresholds from 0 to 600 in increasing order." });
    }
    const { error } = await req.supabase.rpc("aqm_staff_update_thresholds", { p_good_max: values[0], p_moderate_max: values[1], p_hazardous_max: values[2] });
    if (error) throw error;
    res.json({ message: "Air-quality thresholds updated." });
  } catch (error) { fail(res, error); }
});

router.patch("/alerts/:id", async (req, res) => {
  try {
    const id = req.params.id;
    const status = req.body?.status;
    if (!/^\d+$/.test(id) || !["Active", "Acknowledged", "Resolved"].includes(status)) return res.status(400).json({ error: "Select a valid alert and status." });
    const { data, error } = await req.supabase.from("alerts").update({ status }).eq("alert_id", id).select("alert_id").maybeSingle();
    if (error) throw error;
    if (!data) return res.status(404).json({ error: "Alert not found or could not be updated." });
    res.json({ message: `Alert marked ${status.toLowerCase()}.` });
  } catch (error) { fail(res, error); }
});

router.patch("/accounts/:id", async (req, res) => {
  try {
    const id = Number(req.params.id);
    const { role, account_status: accountStatus } = req.body || {};
    if (!Number.isInteger(id) || id < 1 || !["User", "Staff"].includes(role) || !["Active", "Disabled"].includes(accountStatus)) return res.status(400).json({ error: "Select a valid account role and status." });
    const { error } = await req.supabase.rpc("aqm_staff_update_user", { p_user_id: id, p_role: role, p_account_status: accountStatus });
    if (error) throw error;
    res.json({ message: "Account role and status updated." });
  } catch (error) { fail(res, error); }
});

router.post("/password-reset", async (req, res) => {
  try {
    const email = String(req.body?.email || "").trim();
    if (!/^\S+@\S+\.\S+$/.test(email)) return res.status(400).json({ error: "Enter a valid email address." });
    const { data: user, error: lookupError } = await req.supabase.from("users").select("user_id").ilike("email", email).maybeSingle();
    if (lookupError) throw lookupError;
    if (!user) return res.status(404).json({ error: "No matching linked account was found." });
    const { error } = await req.supabase.auth.resetPasswordForEmail(email);
    if (error) throw error;
    res.json({ message: "Password reset email requested." });
  } catch (error) { fail(res, error); }
});

router.post("/records/:table", async (req, res) => {
  try {
    const table = TABLES[req.params.table];
    const tableName = req.params.table;
    if (!table) return res.status(404).json({ error: "This table cannot be managed here." });
    const action = req.body?.action;
    const id = req.body?.id;
    const submitted = req.body?.fields;
    const body = {};
    if (!["create", "update", "delete"].includes(action)) return res.status(400).json({ error: "Select a valid action." });
    if (action === "delete") {
      if (!/^\d+$/.test(String(id || "")) || req.body?.confirm !== "DELETE") return res.status(400).json({ error: "Enter DELETE and select a valid record ID." });
      const { data, error } = await req.supabase.from(tableName).delete().eq(table.id, id).select(table.id).maybeSingle();
      if (error) throw error;
      if (!data) return res.status(404).json({ error: "The record was not found." });
      return res.json({ message: "Record deleted." });
    }

    if (!submitted || typeof submitted !== "object" || Array.isArray(submitted)) return res.status(400).json({ error: "Enter record fields." });
    for (const field of table.fields) {
      if (!(field in submitted)) continue;
      let value = submitted[field];
      if (typeof value === "string") value = value.trim();
      if (value === "" && action === "update") value = null;
      if (value !== null && ["zone_id", "device_id"].includes(field)) {
        value = Number(value);
        if (!Number.isInteger(value) || value < 1) return res.status(400).json({ error: `Enter a valid ${field}.` });
      }
      body[field] = value;
    }
    if (action === "create") {
      if (["zones", "devices", "sensors"].includes(tableName)) {
        const existing = await allRows(req.supabase, tableName, table.id);
        if (existing.length > 0) {
          const labels = { zones: "zone", devices: "device", sensors: "sensor" };
          return res.status(409).json({ error: `This setup supports one ${labels[tableName]} only. Edit the registered ${labels[tableName]} instead.` });
        }
      }
      if (tableName === "devices") {
        const zones = await allRows(req.supabase, "zones", "zone_id");
        if (zones.length !== 1) return res.status(400).json({ error: zones.length ? "Keep one zone before adding the device." : "Add a zone before adding the device." });
        body.zone_id = zones[0].zone_id;
      }
      if (tableName === "sensors") {
        const devices = await allRows(req.supabase, "devices", "device_id");
        if (devices.length !== 1) return res.status(400).json({ error: devices.length ? "Keep one device before adding the sensor." : "Add a device before adding the sensor." });
        body.device_id = devices[0].device_id;
      }
      if (table.required.some((field) => body[field] === undefined || body[field] === null || body[field] === "")) return res.status(400).json({ error: `Required fields: ${table.required.join(", ")}.` });
      const { data, error } = await req.supabase.from(tableName).insert(body).select().single();
      if (error) throw error;
      return res.status(201).json({ message: "Record added.", record: data });
    }
    if (!/^\d+$/.test(String(id || ""))) return res.status(400).json({ error: "Select a valid record ID." });
    delete body.zone_id;
    delete body.device_id;
    if (!Object.keys(body).length) return res.status(400).json({ error: "Enter at least one field to update." });
    const { data, error } = await req.supabase.from(tableName).update(body).eq(table.id, id).select().maybeSingle();
    if (error) throw error;
    if (!data) return res.status(404).json({ error: "The record was not found." });
    res.json({ message: "Record updated.", record: data });
  } catch (error) { fail(res, error); }
});

module.exports = router;

