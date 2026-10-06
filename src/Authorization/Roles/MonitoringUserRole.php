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

class MonitoringUserRole extends AbstractRole implements IAuthorizationRole
{
    public const NAME = 'monitoring-user';

    public const LEVEL = 200;

    public const DESCRIPTION = 'Read-only monitoring within their account: sees hosts, checks, alerts, graphs and notification channels. Cannot change or acknowledge anything.';

    public const DB_PREFIX = 'monitoring';

    /**
     * Restricts queries to records belonging to the current account.
     */
    public function apply(Builder $builder, Model $model)
    {
        if (DatabaseHelper::isColumnExists($model->getTable(), 'iam_account_id')) {
            $builder->where('iam_account_id', UserHelper::currentAccount()->id);
        }
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
            // Their own account tenant
            'monitoring_tenants:read',

            // Read-only
            'monitoring_tenant:read',
            'monitoring_plugins:read',
            'monitoring_hosts:read',
            'monitoring_checks:read',
            'monitoring_alerts:read',
            'monitoring_channels:read',
            'monitoring_sites:read',
        ];
    }

    /**
     * May change a monitoring record only if the role allows the operation and it belongs to the current account.
     * Customer monitoring (hosts, checks, alerts) is not stored here; it is enforced by the monitoring service per member role.
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
        if (UserHelper::hasRole('system-admin')) {
            return true;
        }

        if (! in_array($model->getTable().':'.$action, $this->allowedOperations())) {
            return false;
        }

        if (DatabaseHelper::isColumnExists($model->getTable(), 'iam_account_id')) {
            return $model->iam_account_id == UserHelper::currentAccount()->id;
        }

        return true;
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
