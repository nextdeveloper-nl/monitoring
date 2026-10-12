<?php

namespace NextDeveloper\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use NextDeveloper\Monitoring\Casts\PgTextArray;

/**
 * "When this kind of monitoring notification arrives for the account, trigger this pusher". The filters are optional,
 * an empty list means any. Table monitoring_notification_rules (created by hand, never by a migration).
 */
class NotificationRule extends Model
{
    use SoftDeletes;

    protected $table = 'monitoring_notification_rules';

    protected $guarded = ['id', 'uuid'];

    protected $casts = [
        'enabled' => 'boolean',
        'event_types' => PgTextArray::class,
        'severities' => PgTextArray::class,
        'alert_kinds' => PgTextArray::class,
        'host_ids' => PgTextArray::class,
        'last_triggered_at' => 'datetime',
        'trigger_count' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
