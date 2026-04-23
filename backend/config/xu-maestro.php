<?php

return [
    'default_timeout' => 120, // seconds
    'workflows_path'  => env('WORKFLOWS_PATH', base_path('../workflows')),
    'runs_path'       => env('RUNS_PATH', base_path('../runs')),
    'prompts_path'    => env('PROMPTS_PATH', base_path('../prompts')),
    'yolo_mode'       => env('XU_MAESTRO_YOLO_MODE', true),
];
