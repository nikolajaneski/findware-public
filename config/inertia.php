<?php

return [
    // Homepage HTML is generated from the same React component during the build.
    // No Node SSR process or network request is needed on shared hosting.
    'ssr' => ['enabled' => false],
    'pages' => ['paths' => [resource_path('js/pages')], 'extensions' => ['tsx']],
    'testing' => ['ensure_pages_exist' => true],
];
