import type { NextConfig } from "next";

const defaultApiBase = process.env.NODE_ENV === "production" ? "https://mikapi.poulvia.com" : "http://127.0.0.1:8000";
const apiInternalBase = (process.env.API_INTERNAL_BASE?.trim() || defaultApiBase).replace(/\/+$/, "");

const nextConfig: NextConfig = {
  /* config options here */
  cacheComponents: true,
  partialPrefetching: true,
  async rewrites() {
    return [{ source: "/api/v1/:path*", destination: `${apiInternalBase}/api/v1/:path*` }];
  },
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
