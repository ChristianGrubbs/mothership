import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { buildCases, FIXTURE } from "./photoedit-schema-cases.mjs";

// The PHP schema test trusts this file for what PhotoEdit accepts and how large its export is.
test("the schema v2 cases PHP is tested against are what the vendored PhotoEdit core computes", async () => {
    const committed = JSON.parse(readFileSync(FIXTURE, "utf8"));
    assert.deepEqual(committed, await buildCases(), "regenerate: node packages/Mothership/DAM/tests/js/photoedit-schema-cases.mjs --write");
});
