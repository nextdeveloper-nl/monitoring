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

class MonitoringManagerRole extends AbstractRole implements IAuthorizationRole
{
    public const NAME = 'monitoring-manager';

    public const LEVEL = 150;

    public const DESCRIPTION = 'Monitoring operator within their account: sees and configures hosts, checks, sites and notification channels, acknowledges and resolves alerts, runs and tests checks. Cannot manage monitoring servers.';

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
        return [
            // Their own account tenant
            'monitoring_tenants:read',
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
