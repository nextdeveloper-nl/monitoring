<?php

namespace NextDeveloper\Monitoring\Authorization\Roles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use NextDeveloper\Commons\Helpers\DatabaseHelper;
use NextDeveloper\IAM\Authorization\Roles\AbstractRole;
use NextDeveloper\IAM\Authorization\Roles\IAuthorizationRole;
use NextDeveloper\IAM\Database\Models\Users;
use NextDeveloper\IAM\Helpers\UserHelper;

class MonitoringAdminRole extends AbstractRole implements IAuthorizationRole
{
    public const NAME = 'monitoring-admin';

    public const LEVEL = 100;

    public const DESCRIPTION = 'Monitoring service owner: connects monitoring servers, sees and manages every account tenant. Never granted to customers.';

    public const DB_PREFIX = 'monitoring';

    /**
     * The monitoring service owner sees everything: no additional WHERE conditions.
     */
    public function apply(Builder $builder, Model $model)
    {
        //  No restrictions for admin
    }

    public function checkPrivileges(?Users $users = null)
    {
        //
    }

    public function getModule()
    {
        return 'monitoring';
    }

    public function allowedOperations(): array
    {
            // The app's Authorize middleware maps a two-segment URL to "<module>_<object>:<operation>" and needs it listed here:
            // GET /monitoring/hosts is monitoring_hosts:read, POST /monitoring/hosts is monitoring_hosts:create, /monitoring/tenant is
            // monitoring_tenant. URLs with three or more segments (/monitoring/hosts/{id}, .../checks) are not checked by it;
            // the monitoring service enforces the member role (operator or read-only) on those.
        return [
            // Monitoring servers: the connection to a monitoring service instance (credentials are write-only)
            'monitoring_servers:read',
            'monitoring_servers:create',
            'monitoring_servers:update',
            'monitoring_servers:delete',

            // Tenants of every account
            'monitoring_tenants:read',
            'monitoring_tenants:create',
            'monitoring_tenants:update',
            'monitoring_tenants:delete',

            // Customer monitoring, in case the service owner uses it for their own account
            'monitoring_tenant:read',
            'monitoring_plugins:read',
            'monitoring_hosts:read',
            'monitoring_checks:read',
            'monitoring_alerts:read',
            'monitoring_channels:read',
            'monitoring_sites:read',
            'monitoring_hosts:create',
            'monitoring_hosts:update',
            'monitoring_hosts:delete',
            'monitoring_checks:create',
            'monitoring_checks:update',
            'monitoring_checks:delete',
            'monitoring_channels:create',
            'monitoring_channels:update',
            'monitoring_channels:delete',
            'monitoring_sites:create',
            'monitoring_sites:update',
            'monitoring_sites:delete',
            'monitoring_alerts:update',

            // Credentials checks log in with (SNMP, HTTP auth, ...). Secrets are write-only.
            'monitoring_credential_types:read',
            'monitoring_credentials:read',
            'monitoring_credentials:create',
            'monitoring_credentials:update',
            'monitoring_credentials:delete',

            // MQTT ingest credentials (devices send data to the monitoring service's broker). Passwords are shown once.
            'monitoring_mqtt_profiles:read',
            'monitoring_mqtt_unregistered:read',
            'monitoring_mqtt_credentials:read',
            'monitoring_mqtt_credentials:create',
            'monitoring_mqtt_credentials:update',
            'monitoring_mqtt_credentials:delete',
        ];
    }

    /**
     * The monitoring admin may change any monitoring record.
     */
    public function checkUpdatePolicy(Model $model, Users $user): bool
    {
        return $this->allows($model, 'update');
    }

    public function checkDeletePolicy(Model $model, Users $user): bool
    {
        return $this->allows($model, 'delete');
    }

    private function allows(Model $model, string $action): bool
    {
        return UserHelper::hasRole('system-admin') || in_array($model->getTable().':'.$action, $this->allowedOperations());
    }

    public function getLevel(): int
    {
        return self::LEVEL;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function canBeApplied(mixed $column): bool
    {
        if (self::DB_PREFIX === '*') {
            return true;
        }

        if (Str::startsWith($column, self::DB_PREFIX)) {
            return true;
        }

        return false;
    }

    public function getDbPrefix()
    {
        return self::DB_PREFIX;
    }

    public function checkRules(Users $_users): bool
    {
        return true;
    }
}
