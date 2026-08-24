declare const Netlify: {
  env: {
    get(name: string): string | undefined;
  };
};

const MAX_AUTHORIZATION_HEADER_LENGTH = 8192;
const UPSTREAM_TIMEOUT_MS = 12000;
const JSON_HEADERS = {
  "Content-Type": "application/json; charset=utf-8",
  "Cache-Control": "no-store, max-age=0",
  "Pragma": "no-cache",
  "X-Content-Type-Options": "nosniff",
  "Referrer-Policy": "no-referrer",
};

function jsonResponse(status: number, body: Record<string, unknown>): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: JSON_HEADERS,
  });
}

function isSameOriginRequest(req: Request): boolean {
  const origin = req.headers.get("origin");
  if (!origin) return false;

  try {
    return new URL(origin).origin === new URL(req.url).origin;
  } catch {
    return false;
  }
}

function readBearerToken(req: Request): string | null {
  const authorization = req.headers.get("authorization");
  if (
    !authorization ||
    authorization.length > MAX_AUTHORIZATION_HEADER_LENGTH ||
    !authorization.startsWith("Bearer ")
  ) {
    return null;
  }

  const token = authorization.slice(7).trim();
  if (
    token.length < 32 ||
    token.length > MAX_AUTHORIZATION_HEADER_LENGTH - 7 ||
    !/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(token)
  ) {
    return null;
  }

  return token;
}

function readAppsScriptUrl(): URL | null {
  const rawUrl = Netlify.env.get("HUB_APPS_SCRIPT_URL");
  if (!rawUrl) return null;

  try {
    const url = new URL(rawUrl);
    if (
      url.protocol !== "https:" ||
      url.hostname !== "script.google.com" ||
      !url.pathname.startsWith("/macros/s/") ||
      !url.pathname.endsWith("/exec")
    ) {
      return null;
    }
    return url;
  } catch {
    return null;
  }
}

function mapAppsScriptError(error: unknown): Response {
  switch (error) {
    case "AUTH_REQUIRED":
      return jsonResponse(401, { ok: false, error: "AUTH_REQUIRED" });
    case "HUB_ACCESS_DENIED":
      return jsonResponse(403, { ok: false, error: "HUB_ACCESS_DENIED" });
    case "SECURITY_MAINTENANCE":
      return jsonResponse(503, { ok: false, error: "SECURITY_MAINTENANCE" });
    default:
      return jsonResponse(503, { ok: false, error: "HUB_ACCESS_UNAVAILABLE" });
  }
}

export default async function handler(req: Request): Promise<Response> {
  if (req.method !== "POST") {
    return new Response(
      JSON.stringify({ ok: false, error: "METHOD_NOT_ALLOWED" }),
      {
        status: 405,
        headers: {
          ...JSON_HEADERS,
          "Allow": "POST",
        },
      },
    );
  }

  if (!isSameOriginRequest(req)) {
    return jsonResponse(403, { ok: false, error: "FORBIDDEN" });
  }

  const accessToken = readBearerToken(req);
  if (!accessToken) {
    return jsonResponse(401, { ok: false, error: "AUTH_REQUIRED" });
  }

  const appsScriptUrl = readAppsScriptUrl();
  const sharedSecret = Netlify.env.get("HUB_APPS_SCRIPT_SHARED_SECRET") || "";
  if (!appsScriptUrl || sharedSecret.length < 32) {
    return jsonResponse(503, { ok: false, error: "HUB_ACCESS_UNAVAILABLE" });
  }

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT_MS);

  try {
    const upstreamResponse = await fetch(appsScriptUrl, {
      method: "POST",
      redirect: "follow",
      headers: {
        "Content-Type": "application/json; charset=utf-8",
        "Accept": "application/json",
      },
      body: JSON.stringify({
        action: "getSecurePreview",
        access_token: accessToken,
        gateway_secret: sharedSecret,
      }),
      signal: controller.signal,
    });

    if (!upstreamResponse.ok) {
      return jsonResponse(503, { ok: false, error: "HUB_ACCESS_UNAVAILABLE" });
    }

    const responseText = await upstreamResponse.text();
    if (!responseText || responseText.length > 2_000_000) {
      return jsonResponse(503, { ok: false, error: "HUB_ACCESS_UNAVAILABLE" });
    }

    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(responseText) as Record<string, unknown>;
    } catch {
      return jsonResponse(503, { ok: false, error: "HUB_ACCESS_UNAVAILABLE" });
    }

    if (payload.ok !== true) {
      return mapAppsScriptError(payload.error);
    }

    return jsonResponse(200, payload);
  } catch {
    return jsonResponse(503, { ok: false, error: "HUB_ACCESS_UNAVAILABLE" });
  } finally {
    clearTimeout(timeoutId);
  }
}

export const config = {
  path: "/api/hub-secure-preview",
  method: ["POST"],
  rateLimit: {
    windowLimit: 30,
    windowSize: 60,
    aggregateBy: ["ip", "domain"],
  },
};
