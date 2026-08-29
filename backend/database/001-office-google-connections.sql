begin;

create table public.office_google_connections (
    user_id uuid primary key references auth.users (id) on delete cascade,
    google_subject text not null unique,
    google_email text not null,
    google_email_verified boolean not null default true,
    scopes text[] not null default array['openid', 'email', 'profile']::text[],
    is_connected boolean not null default true,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    connected_at timestamptz not null default now(),
    disconnected_at timestamptz,

    constraint office_google_connections_email_valid
        check (google_email = lower(google_email) and length(google_email) between 3 and 320),
    constraint office_google_connections_email_verified
        check (google_email_verified = true),
    constraint office_google_connections_identity_scopes_only
        check (
            cardinality(scopes) = 3
            and scopes @> array['openid', 'email', 'profile']::text[]
            and scopes <@ array['openid', 'email', 'profile']::text[]
        ),
    constraint office_google_connections_state_dates_valid
        check (
            (is_connected = true and disconnected_at is null)
            or (is_connected = false and disconnected_at is not null)
        )
);

comment on table public.office_google_connections is
    'OAUTH-00A: validated Google identity link only; no Google access or refresh token.';

alter table public.office_google_connections enable row level security;
alter table public.office_google_connections force row level security;

revoke all on table public.office_google_connections from anon, authenticated;

commit;
