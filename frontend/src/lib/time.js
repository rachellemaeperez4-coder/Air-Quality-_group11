const PHILIPPINE_TIME_ZONE = "Asia/Manila";

export function formatReadingTime(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";

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
