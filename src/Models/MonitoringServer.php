<?php

namespace NextDeveloper\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MonitoringServer extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'options' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getTable(): string
    {
        return config('monitoring.tables.servers', 'monitoring_servers');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(MonitoringTenant::class, 'monitoring_server_id');
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }
}
