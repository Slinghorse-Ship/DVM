"use strict";
// Always create a fresh fixture; never log into the shared development apps.
const { spawn } = require("node:child_process");
const path = require("node:path");
if (process.env.KH_NATIVE_CI !== "1") {
  throw new Error(
    "Use the isolated local runner: bash tools/ci/local-native.sh --browsers",
  );
}
const child = spawn(
  "php",
  ["vereinsverwaltung/tests/ci-native.php", "native-reel-studio"],
  {
    cwd: path.resolve(__dirname, "../.."),
    env: { ...process.env, DVM_REEL_STUDIO_BROWSER: "1" },
    stdio: "inherit",
  },
);
child.on("error", () => {
  process.exitCode = 1;
});
child.on("exit", (code) => {
  process.exitCode = code ?? 1;
});
