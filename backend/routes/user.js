const express = require('express');
const { authenticate } = require('../authenticate');
const { getThresholds, qualityStatus, statusClass } = require('../air-quality');

const router = express.Router();

const PAGE_ACTIVITY = {
  dashboard: "Viewed the user dashboard.",
  readings: "Viewed sensor readings.",
  alerts: "Viewed air-quality alerts.",
};

router.post("/activity-logs", authenticate(), async (req, res) => {
  try {
    const activityType = String(req.body?.activity_type || "");
    const isPageVisit = Object.hasOwn(PAGE_ACTIVITY, activityType) && req.account.role === "user";
    if (!isPageVisit && activityType !== "sign_out") return res.status(400).json({ error: "Select a supported activity type." });
    const { error } = await req.supabase.from("user_activity_logs").insert({
      user_id: req.account.user_id,
      auth_user_id: req.account.auth_user_id,
      activity_type: isPageVisit ? `${activityType}_view` : "sign_out",
      description: isPageVisit ? PAGE_ACTIVITY[activityType] : "Signed out.",
    });
    if (error) throw error;
    res.status(201).json({ message: "Activity recorded." });
  } catch (error) {
    res.status(503).json({ error: "User activity could not be recorded. Check the activity log SQL migration." });
  }
});

router.get("/dashboard", authenticate("user"), async (req, res) => {
  try {
    const supabase = req.supabase;
    const afterId = /^\d+$/.test(String(req.query.after_id || "")) ? String(req.query.after_id) : "";
    const [{ data: limits, error: limitsError }, { data: latestRows, error: latestError }, { data: newRows, error: newError }, trendResult] = await Promise.all([
      getThresholds(supabase),
      supabase.from("air_quality_readings").select("reading_id, sensor_value:mq135_value, recorded_at").order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).limit(1),
      afterId
        ? supabase.from("air_quality_readings").select("reading_id, sensor_value:mq135_value, recorded_at").gt("reading_id", afterId).order("reading_id", { ascending: true }).limit(100)
        : supabase.from("air_quality_readings").select("reading_id, sensor_value:mq135_value, recorded_at").order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).limit(100),
      afterId
        ? Promise.resolve({ data: [], error: null })
        : supabase.from("air_quality_readings").select("reading_id, sensor_value:mq135_value, recorded_at").not("mq135_value", "is", null).gte("recorded_at", new Date(Date.now() - 32 * 86400000).toISOString()).order("recorded_at", { ascending: false }).limit(1000),
    ]);

    if (limitsError || latestError || newError || trendResult.error) {
      return res.status(503).json({ error: "Dashboard data could not be loaded. Check the account permissions and threshold setup." });
    }

    const format = (row) => ({
      reading_id: String(row.reading_id),
      sensor_value: row.sensor_value,
      recorded_at: row.recorded_at,
      status: qualityStatus(row.sensor_value, limits),
      status_class: statusClass(qualityStatus(row.sensor_value, limits)),
    });
    const formattedReadings = (newRows || []).map(format);
    const latest = latestRows?.[0] ? format(latestRows[0]) : null;
    res.set("Cache-Control", "no-store").json({
      account: { name: req.account.name, email: req.account.email },
      thresholds: limits,
      readings: afterId ? [] : formattedReadings,
      new_readings: afterId ? formattedReadings : [],
      latest,
      trend: (trendResult.data || []).map(format),
    });
  } catch (error) {
    res.status(500).json({ error: error.message || "Dashboard data could not be loaded." });
  }
});

router.get("/readings", authenticate("user"), async (req, res) => {
  try {
    const search = String(req.query.search || "").trim().slice(0, 80);
    const selectedStatus = String(req.query.status || "");
    const dateFrom = String(req.query.date_from || "");
    const dateTo = String(req.query.date_to || "");
    const page = Math.max(1, Math.min(100000, Number.parseInt(req.query.page, 10) || 1));
    const pageSize = 10;
    const [{ data: limits, error: limitsError }] = await Promise.all([getThresholds(req.supabase)]);
    if (limitsError) return res.status(503).json({ error: "Air-quality thresholds are not configured." });

    const validDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(`${value}T00:00:00Z`)) && new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value;
    const invalidDateRange = Boolean((dateFrom && !validDate(dateFrom)) || (dateTo && !validDate(dateTo)) || (dateFrom && dateTo && dateFrom > dateTo));
    let query = req.supabase.from("air_quality_readings").select("reading_id, sensor_value:mq135_value, air_quality_status, recorded_at", { count: "exact" });

    if (!invalidDateRange) {
      if (dateFrom) query = query.gte("recorded_at", `${dateFrom}T00:00:00Z`);
      if (dateTo) query = query.lt("recorded_at", new Date(Date.parse(`${dateTo}T00:00:00Z`) + 86400000).toISOString());
    }

    const statusRanges = {
      good: (q) => q.gte("mq135_value", 0).lte("mq135_value", limits.good_max),
      moderate: (q) => q.gt("mq135_value", limits.good_max).lte("mq135_value", limits.moderate_max),
      hazardous: (q) => q.gt("mq135_value", limits.moderate_max).lte("mq135_value", limits.hazardous_max),
      very_hazardous: (q) => q.gt("mq135_value", limits.hazardous_max),
      unknown: (q) => q.or("mq135_value.is.null,mq135_value.lt.0"),
    };
    if (selectedStatus && statusRanges[selectedStatus]) query = statusRanges[selectedStatus](query);
    else if (selectedStatus) query = query.eq("reading_id", -1);

    if (search) {
      const normalized = search.toLowerCase().replace(/[^a-z0-9 _-]/g, "").trim();
      if (/^\d+$/.test(normalized)) query = query.eq("reading_id", Number(normalized));
      else {
        const match = Object.keys(statusRanges).find((key) => key.replace("_", " ").includes(normalized) || normalized.includes(key.replace("_", " ")));
        if (match) query = statusRanges[match](query);
        else query = query.eq("reading_id", -1);
      }
    }

    if (invalidDateRange) query = query.eq("reading_id", -1);
    const { data, error, count } = await query.order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).range((page - 1) * pageSize, page * pageSize - 1);
    if (error) return res.status(503).json({ error: "Readings could not be loaded. Check database permissions and migrations." });
    const rows = (data || []).map((row) => {
      const status = qualityStatus(row.sensor_value, limits);
      return { ...row, status, status_class: statusClass(status) };
    });
    res.set("Cache-Control", "no-store").json({ rows, page, page_size: pageSize, total: count || 0, has_next: (page * pageSize) < (count || 0), invalid_date_range: invalidDateRange, thresholds: limits, account: { name: req.account.name, email: req.account.email } });
  } catch (error) {
    res.status(500).json({ error: error.message || "Readings could not be loaded." });
  }
});

router.get("/alerts", authenticate("user"), async (req, res) => {
  try {
    const [{ data: limits, error: limitsError }, { data: rows, error: rowsError }] = await Promise.all([
      getThresholds(req.supabase),
      req.supabase.from("air_quality_readings").select("reading_id, device_id, sensor_value:mq135_value, recorded_at").not("mq135_value", "is", null).order("recorded_at", { ascending: false, nullsFirst: false }).order("reading_id", { ascending: false }).limit(500),
    ]);
    if (limitsError || rowsError) return res.status(503).json({ error: "Alert history could not be loaded. Check database permissions and threshold setup." });

    const counts = { Good: 0, Moderate: 0, Hazardous: 0, "Very Hazardous": 0, Unknown: 0 };
    const timeline = (rows || []).slice().sort((a, b) => new Date(a.recorded_at || 0) - new Date(b.recorded_at || 0) || Number(a.reading_id) - Number(b.reading_id));
    const active = new Map();
    const resolved = [];
    for (const reading of timeline) {
      const status = qualityStatus(reading.sensor_value, limits);
      counts[status] = (counts[status] || 0) + 1;
      const key = reading.device_id == null ? `reading:${reading.reading_id}` : `device:${reading.device_id}`;
      if (["Hazardous", "Very Hazardous"].includes(status)) {
        if (!active.has(key)) active.set(key, reading);
      } else if (["Good", "Moderate"].includes(status) && active.has(key)) {
        resolved.push({ trigger: active.get(key), resolved_at: reading.recorded_at });
        active.delete(key);
      }
    }
    const withStatus = (reading) => ({ ...reading, status: qualityStatus(reading.sensor_value, limits), status_class: statusClass(qualityStatus(reading.sensor_value, limits)) });
    res.set("Cache-Control", "no-store").json({
      counts,
      active: [...active.values()].reverse().map(withStatus),
      resolved: resolved.reverse().map((item) => ({ trigger: withStatus(item.trigger), resolved_at: item.resolved_at })),
      readings: (rows || []).map(withStatus),
      thresholds: limits,
      account: { name: req.account.name, email: req.account.email },
    });
  } catch (error) {
    res.status(500).json({ error: error.message || "Alert history could not be loaded." });
  }
});


module.exports = router;

