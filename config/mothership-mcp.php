<?php

$maxFileBytes = env('MOTHERSHIP_MCP_MAX_FILE_BYTES', 2 * 1024 * 1024 * 1024);
$maxFileBytes = (is_int($maxFileBytes) || (is_string($maxFileBytes) && ctype_digit($maxFileBytes))) && $maxFileBytes > 0
    ? (int) $maxFileBytes
    : 2 * 1024 * 1024 * 1024;

/*
 * The `mothership` library server (stdio, `php artisan mcp:start mothership`): the asset library for local creative
 * tools. Contract: docs/api/mothership-mcp.md.
 */
return [
    // The admin account every call acts as. The stdio server has no sign-in: whoever can start it on this machine
    // acts as this account, with that account's own permissions and folder access. 0 or unset: every tool refuses.
    'user_id'                 => (int) env('MOTHERSHIP_MCP_USER_ID', 0),
    // The largest local file library_save publishes.
    'max_file_bytes'          => $maxFileBytes,
    // Where library_fetch writes its copies when the call names no dest_dir. Empty: <system temp>/mothership-library.
    'fetch_dir'               => (string) env('MOTHERSHIP_MCP_FETCH_DIR', ''),
    // Folder ids library_search leaves out unless asked for by folder_id (QA and test output folders), e.g. "578".
    'search_excluded_folders' => array_values(array_filter(array_map('intval', explode(',', (string) env('MOTHERSHIP_MCP_SEARCH_EXCLUDED_FOLDERS', ''))))),
    // Redacted JSONL at storage/logs/mothership-mcp.jsonl (MothershipLog).
    'logging'                 => (bool) env('MOTHERSHIP_MCP_LOGGING', false),
];
