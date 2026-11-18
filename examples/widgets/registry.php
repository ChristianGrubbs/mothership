<?php

// Import selected entries explicitly in development; this file is not production configuration.
return [
    'docs-count' => ['title' => 'Documentation', 'description' => 'Articles available to your role', 'icon' => 'files', 'kind' => 'native', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'help', 'settings' => []],
    'docs-notes' => ['title' => 'Documentation notes', 'description' => 'Account-backed notes example', 'icon' => 'files', 'kind' => 'tool', 'entry' => 'index.html', 'sizes' => ['wide', 'large'], 'size' => 'wide', 'permission' => 'help', 'settings' => []],
];
