import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  async rewrites() {
    const defaultApiBase = process.env.NODE_ENV === "production" ? "https://mikapi.poulvia.com" : "http://127.0.0.1:8000";
    const apiBase = (process.env.API_INTERNAL_BASE?.trim() || defaultApiBase).replace(/\/+$/, "");

    return [
      {
        source: "/api/v1/:path*",
        destination: `${apiBase}/api/v1/:path*`,
      },
    ];
  },
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
