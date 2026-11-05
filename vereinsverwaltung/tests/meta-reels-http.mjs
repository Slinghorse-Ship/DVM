import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
const fixture = JSON.parse(
  await readFile(process.env.DVM_META_REEL_HTTP_FIXTURE, "utf8"),
);
const bytes = await readFile(fixture.file);
let checks = 0;
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const url = fixture.origin + "/publication-video/" + fixture.token;
const full = await fetch(url);
check(
  full.status === 200,
  "actual anonymous native capability route returns original video without a login",
);
check(
  Buffer.from(await full.arrayBuffer()).equals(bytes),
  "actual capability response preserves every original AVC/AAC byte",
);
check(
  full.headers.get("content-type") === "video/mp4" &&
    full.headers.get("cache-control").includes("no-store") &&
    full.headers.get("x-content-type-options") === "nosniff",
  "actual provider file response has exact MIME and private cache/type headers",
);
const range = await fetch(url, { headers: { Range: "bytes=0-31" } });
check(
  range.status === 206 &&
    range.headers.get("content-range") === "bytes 0-31/" + bytes.length,
  "actual provider fetch can seek the exact original prefix",
);
check(
  Buffer.from(await range.arrayBuffer()).equals(bytes.subarray(0, 32)),
  "actual range preserves the original video bytes",
);
const head = await fetch(url, {
  method: "HEAD",
  headers: { Range: "bytes=-2" },
});
check(
  head.status === 206 &&
    head.headers.get("content-length") === "2" &&
    (await head.arrayBuffer()).byteLength === 0,
  "actual anonymous HEAD exposes length and no body",
);
const invalid = await fetch(url, { headers: { Range: "bytes=0-1,4-5" } });
check(
  invalid.status === 416 && (await invalid.arrayBuffer()).byteLength === 0,
  "actual unsupported multipart range exposes no original bytes",
);
const privateFile = await fetch(
  fixture.origin + "/social-video/" + fixture.video,
  { redirect: "manual" },
);
check(
  privateFile.status === 303,
  "ordinary private source still requires genuine login despite a separate publication capability",
);
const unknown = await fetch(
  fixture.origin + "/publication-video/" + "a".repeat(64),
);
check(unknown.status === 404, "unknown opaque capability exposes no video");
const malformed = await fetch(
  fixture.origin + "/publication-video/not-a-token",
);
check(
  malformed.status === 404,
  "malformed original-file capability is rejected",
);
const post = await fetch(url, { method: "POST", redirect: "manual" });
check(
  post.status === 405,
  "public provider download offers no mutation operation",
);
const robots = await fetch(fixture.origin + "/robots.txt");
const rules = await robots.text();
check(
  rules.includes("User-agent: *\nDisallow: /"),
  "development retains its existing general no-index robots choice",
);
console.log(
  JSON.stringify({ suite: "meta-reels-http", checks, live_transport: false }),
);
