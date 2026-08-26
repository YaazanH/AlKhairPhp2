<?php

return [
    /*
    | Maximum size for a single user-uploaded image, in kilobytes.
    | Keep this below Livewire's temporary upload and web-server limits.
    */
    'image_max_kb' => (int) env('IMAGE_UPLOAD_MAX_KB', 20480),
];
