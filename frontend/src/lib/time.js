const PHILIPPINE_TIME_ZONE = "Asia/Manila";

export function parseReadingTime(value) {
  if (!value) return null;
  // Postgres timestamp columns without a zone can arrive as ISO strings with
  // no offset. Sensor ingestion uses the database clock (UTC), so interpret
  // those values as UTC before converting them for display.
  const timestamp = typeof value === "string"
    && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(value)
    ? `${value}Z`
    : value;
  const date = new Date(timestamp);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatReadingTime(value) {
  const date = parseReadingTime(value);
  if (!date) return "—";

  return date.toLocaleString("en-PH", {
    timeZone: PHILIPPINE_TIME_ZONE,
    dateStyle: "medium",
    timeStyle: "short",
    hour12: true,
  });
}

export function startOfPhilippineDay(value = new Date()) {
  const manila = new Date(value.getTime() + 8 * 60 * 60 * 1000);
  return Date.UTC(manila.getUTCFullYear(), manila.getUTCMonth(), manila.getUTCDate()) - 8 * 60 * 60 * 1000;
}
