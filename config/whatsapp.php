<?php

/*
| WhatsApp integration options (docs/ANIS-WHATSAPP-SETTINGS-001.md).
|
| Retention periods are proposals pending the retention policy (DEC-WA-007,
| PND-WA-01). Automatic deletion stays off until that policy is approved
| (REQ-WA-60): even when "model:prune" runs, nothing is deleted while
| "pruning_enabled" is false, and no prune task is scheduled.
*/

return [

    'retention' => [
        'message_logs_days' => (int) env('WHATSAPP_MESSAGE_LOG_RETENTION_DAYS', 90),
        'settings_audit_days' => (int) env('WHATSAPP_SETTINGS_AUDIT_RETENTION_DAYS', 365),
        'pruning_enabled' => (bool) env('WHATSAPP_LOG_PRUNING_ENABLED', false),
    ],

];
