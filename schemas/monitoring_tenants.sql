-- PostgreSQL

CREATE TABLE monitoring_tenants (
    id                      bigserial NOT NULL,
    uuid                    uuid NOT NULL DEFAULT gen_random_uuid(),
    iam_account_id          bigint NOT NULL,
    monitoring_server_id    bigint NOT NULL,
    external_tenant_id      varchar(255) NOT NULL,  -- tenant id in the monitoring service
    name                    varchar(255) NOT NULL,
    status                  varchar(32) NOT NULL DEFAULT 'active',
    meta                    json,
    created_at              timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at              timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at              timestamp with time zone,
    CONSTRAINT monitoring_tenants_pkey PRIMARY KEY (id),
    CONSTRAINT monitoring_tenants_uuid_key UNIQUE (uuid),
    CONSTRAINT monitoring_tenants_server_fk FOREIGN KEY (monitoring_server_id) REFERENCES monitoring_servers (id)
);

CREATE INDEX monitoring_tenants_iam_account_id_idx ON monitoring_tenants (iam_account_id);
CREATE UNIQUE INDEX monitoring_tenants_server_external_unique
    ON monitoring_tenants (monitoring_server_id, external_tenant_id) WHERE deleted_at IS NULL;
