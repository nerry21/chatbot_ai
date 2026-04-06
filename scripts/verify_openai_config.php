<?php

declare(strict_types=1);

define('LARAVEL_START', microtime(true));

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

/**
 * Print a config key and its value (normalized for readability).
 */
function out(string $key): void
{
    $val = config($key);

    if (is_bool($val)) {
        $val = $val ? 'true' : 'false';
    } elseif (is_array($val)) {
        $val = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif ($val === null) {
        $val = 'null';
    }

    echo $key . '=' . $val . PHP_EOL;
}

$keys = [
    // default block
    'openai.default.provider',
    'openai.default.model',

    // tasks (new normalized)
    'openai.tasks.understanding.model',
    'openai.tasks.understanding.runtime_label',
    'openai.tasks.reply_draft.model',
    'openai.tasks.reply_draft.expect_json',
    'openai.tasks.grounded_response.model',
    'openai.tasks.summary.model',

    // optional new task
    'openai.tasks.reply_guard_fallback.model',

    // centralized fallbacks
    'openai.fallback_models.understanding',
    'openai.fallback_models.reply_draft',
    'openai.fallback_models.grounded_response',
    'openai.fallback_models.summary',

    // aliases
    'openai.task_aliases.intent',
    'openai.task_aliases.reply',
    'openai.task_aliases.grounded',
    'openai.task_aliases.summary',

    // telemetry (new include_* flags)
    'openai.telemetry.include_provider',
    'openai.telemetry.include_model',
    'openai.telemetry.include_latency',
    'openai.telemetry.include_http_status',
    'openai.telemetry.include_reasoning_effort',

    // json contract
    'openai.json_contract.understanding_expect_json',
    'openai.json_contract.reply_draft_expect_json',
    'openai.json_contract.grounded_response_expect_json',
    'openai.json_contract.summary_expect_json',

    // legacy compatibility
    'openai.models.default',
    'openai.models.intent',
    'openai.models.extraction',
    'openai.models.understanding',
    'openai.models.grounded_response',
    'openai.models.reply',
    'openai.models.summary',

    // legacy task still present
    'openai.tasks.reply.model',
];

foreach ($keys as $k) {
    out($k);
}
