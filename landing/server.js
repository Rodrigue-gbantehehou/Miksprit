const { createServer } = require("node:http");
const next = require("next");

const dev = process.env.NODE_ENV !== "production";
const app = next({ dev });
const handle = app.getRequestHandler();

app.prepare().then(() => {
  const server = createServer((request, response) => handle(request, response));
  server.on("error", (error) => {
    console.error("Next.js server failed to start:", error);
    process.exit(1);
  });

  if (typeof PhusionPassenger !== "undefined") {
    PhusionPassenger.configure({ autoInstall: false });
    server.listen("passenger");
    return;
  }

  const port = Number(process.env.PORT || process.argv[2] || 3002);
  server.listen(port, process.env.HOST || "127.0.0.1", () => {
    console.log(`Landing server listening on port ${port}`);
  });
}).catch((error) => {
  console.error("Next.js application failed to prepare:", error);
  process.exit(1);
});
