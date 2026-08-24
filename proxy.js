// Vercel Serverless Function
// File: api/proxy.js
//
// This proxy forwards the two report APIs used by index.html.
// It avoids exposing the upstream API URL directly as the browser's request target.

const UPSTREAM = "https://lautanapi.vercel.app";

function setCors(res) {
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "GET, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type");
}

module.exports = async (req, res) => {
  setCors(res);

  if (req.method === "OPTIONS") {
    return res.status(204).end();
  }

  if (req.method !== "GET") {
    return res.status(405).json({ error: "Method not allowed" });
  }

  const path = String(req.query.path || "").trim();

  const allowed = new Set([
    "/api/report/setoran-kasir",
    "/api/report/gabungan"
  ]);

  if (!allowed.has(path)) {
    return res.status(400).json({
      error: "Invalid API path",
      allowed: Array.from(allowed)
    });
  }

  const params = new URLSearchParams();

  for (const key of ["storeId", "userId", "periode1"]) {
    const value = req.query[key];
    if (value !== undefined && value !== null && String(value) !== "") {
      params.set(key, String(value));
    }
  }

  const target = `${UPSTREAM}${path}?${params.toString()}`;

  try {
    const response = await fetch(target, {
      method: "GET",
      headers: {
        Accept: "application/json, text/plain, */*"
      }
    });

    const text = await response.text();
    const contentType = response.headers.get("content-type") || "";

    res.status(response.status);
    res.setHeader("Cache-Control", "no-store");

    if (contentType.includes("application/json")) {
      try {
        return res.json(JSON.parse(text));
      } catch {
        return res.send(text);
      }
    }

    return res.send(text);
  } catch (error) {
    return res.status(502).json({
      error: "Gagal menghubungi API upstream",
      message: error instanceof Error ? error.message : String(error)
    });
  }
};
