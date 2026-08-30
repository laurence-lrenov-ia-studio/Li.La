begin;

alter table public.office_google_connections
    add column refresh_token_ciphertext text,
    add column refresh_token_nonce text,
    add column refresh_token_key_version smallint,
    add column refresh_token_updated_at timestamptz;

alter table public.office_google_connections
    drop constraint office_google_connections_identity_scopes_only,
    add constraint office_google_connections_allowed_scopes_only
        check (
            cardinality(scopes) between 3 and 4
            and scopes @> array['openid', 'email', 'profile']::text[]
            and (
                cardinality(scopes) = 3
                or scopes @> array['https://www.googleapis.com/auth/gmail.readonly']::text[]
            )
            and scopes <@ array[
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/gmail.readonly'
            ]::text[]
        ),
    add constraint office_google_connections_refresh_token_complete
        check (
            (refresh_token_ciphertext is null
                and refresh_token_nonce is null
                and refresh_token_key_version is null
                and refresh_token_updated_at is null)
            or
            (length(refresh_token_ciphertext) between 20 and 8192
                and length(refresh_token_nonce) between 20 and 128
                and refresh_token_key_version = 1
                and refresh_token_updated_at is not null)
        );

comment on column public.office_google_connections.refresh_token_ciphertext is
    'Encrypted Google refresh token; never plaintext.';
comment on column public.office_google_connections.refresh_token_nonce is
    'Nonce required to decrypt the Google refresh token.';
comment on column public.office_google_connections.refresh_token_key_version is
    'Private encryption key version used for the token envelope.';

commit;
