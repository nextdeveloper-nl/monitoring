-- PostgreSQL

CREATE TABLE monitoring_servers (
    id              bigserial NOT NULL,
    uuid            uuid NOT NULL DEFAULT gen_random_uuid(),
    name            varchar(255) NOT NULL,
    driver          varchar(64) NOT NULL,
    base_url        varchar(512) NOT NULL,
    credentials     text,                       -- encrypted by the model cast
    options         json,
    is_default      boolean NOT NULL DEFAULT false,
    is_active       boolean NOT NULL DEFAULT true,
    created_at      timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at      timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at      timestamp with time zone,
    CONSTRAINT monitoring_servers_pkey PRIMARY KEY (id),
    CONSTRAINT monitoring_servers_uuid_key UNIQUE (uuid)
);

CREATE UNIQUE INDEX monitoring_servers_name_unique ON monitoring_servers (name) WHERE deleted_at IS NULL;
