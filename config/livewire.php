<?php

/*
 * Only the settings KaffeTrack changes; everything else uses Livewire's defaults.
 */
return [
    'layout' => 'components.layouts.app',

    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2f6b4f',
    ],

    // Product photos are validated again in the component (image, ≤ 2 MB).
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:4096'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'mp3', 'm4a', 'jpg', 'jpeg', 'mpga', 'webp', 'wma'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
