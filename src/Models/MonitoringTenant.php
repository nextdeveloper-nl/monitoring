<?php

namespace NextDeveloper\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use NextDeveloper\Monitoring\Enums\TenantStatus;

class MonitoringTenant extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected $casts = [
        'status' => TenantStatus::class,
        'meta' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getTable(): string
    {
        return config('monitoring.tables.tenants', 'monitoring_tenants');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(MonitoringServer::class, 'monitoring_server_id');
    }
}
