// Use same-origin /api in production; the Vercel Services rewrites route it
// to the Node service. Vite proxies the same path to localhost during dev.
const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || "").replace(/\/$/, "");

export async function apiRequest<T>(path: string, body?: unknown, method: "GET" | "POST" | "PATCH" | "DELETE" = "POST"): Promise<T> {
  let response: Response;
  try {
    const headers: Record<string, string> = { Accept: "application/json" };
    if (body !== undefined) headers["Content-Type"] = "application/json";
    if (path === "/api/auth/me" || path.startsWith("/api/user/") || path.startsWith("/api/staff/")) {
      const { getSupabaseClient } = await import("./supabase");
      const { data } = await getSupabaseClient().auth.getSession();
      if (data.session?.access_token) headers.Authorization = `Bearer ${data.session.access_token}`;
    }

    response = await fetch(`${apiBaseUrl}${path}`, {
      method,
      headers,
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
  } catch {
    throw new Error("Unable to reach the Node backend. Check that it is running and configured.");
  }

  const result = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(typeof result.error === "string" ? result.error : "The request failed. Please try again.");
  }
  return result as T;
}
