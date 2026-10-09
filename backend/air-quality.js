function getThresholds(supabase) {
  return supabase
    .from("aqm_threshold_settings")
    .select("good_max, moderate_max, hazardous_max")
    .eq("setting_id", true)
    .single();
}

function qualityStatus(value, limits) {
  const reading = Number(value);
  if (value === null || value === undefined || !Number.isFinite(reading)) return "Unknown";
  if (reading < 0) return "Unknown";
  if (reading <= Number(limits.good_max)) return "Good";
  if (reading <= Number(limits.moderate_max)) return "Moderate";
  if (reading <= Number(limits.hazardous_max)) return "Hazardous";
  return "Very Hazardous";
}

function statusClass(status) {
  return {
    Good: "status-good",
    Moderate: "status-moderate",
    Hazardous: "status-hazard",
    "Very Hazardous": "status-very-hazardous",
  }[status] || "status-neutral";
}

module.exports = { getThresholds, qualityStatus, statusClass };
