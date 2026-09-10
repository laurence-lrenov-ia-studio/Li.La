begin;

do $$
begin
  if to_regclass('public.instances') is null
     or to_regclass('public.instance_members') is null
     or to_regclass('public.modules') is null
     or to_regclass('public.instance_modules') is null then
    raise exception using
      errcode = '42P01',
      message = 'Li.La access-control tables must exist before ai_usage_accounting';
  end if;
end;
$$;

create schema if not exists private authorization postgres;
revoke all on schema private from public, anon, authenticated, service_role;

create extension if not exists btree_gist with schema extensions;

create table private.ai_price_versions (
  id uuid primary key default gen_random_uuid(),
  provider text not null,
  model text not null,
  service_tier text not null default 'default',
  modality text not null default 'text',
  currency text not null,
  effective_from timestamptz not null,
  effective_to timestamptz,
  source_url text not null,
  source_published_at timestamptz,
  created_at timestamptz not null default clock_timestamp(),

  constraint ai_price_versions_provider_valid
    check (provider = lower(provider) and provider ~ '^[a-z0-9][a-z0-9._-]{1,63}$'),
  constraint ai_price_versions_model_valid
    check (length(model) between 1 and 160),
  constraint ai_price_versions_service_tier_valid
    check (service_tier = lower(service_tier) and service_tier ~ '^[a-z0-9][a-z0-9._-]{0,63}$'),
  constraint ai_price_versions_modality_valid
    check (modality = lower(modality) and modality ~ '^[a-z][a-z0-9._-]{1,31}$'),
  constraint ai_price_versions_currency_valid
    check (currency ~ '^[A-Z]{3}$'),
  constraint ai_price_versions_effective_window_valid
    check (effective_to is null or effective_to > effective_from),
  constraint ai_price_versions_source_url_valid
    check (source_url ~ '^https://'),
  constraint ai_price_versions_no_overlap
    exclude using gist (
      provider with =,
      model with =,
      service_tier with =,
      modality with =,
      tstzrange(effective_from, effective_to, '[)') with &&
    )
);

comment on table private.ai_price_versions is
  'Versioned provider price books. Only closing an open effective window is allowed; publish a new row for new prices.';

create table private.ai_price_rates (
  id uuid primary key default gen_random_uuid(),
  price_version_id uuid not null
    references private.ai_price_versions(id) on delete restrict,
  meter_code text not null,
  unit_code text not null,
  unit_quantity numeric(24,6) not null,
  unit_price numeric(24,12) not null,
  created_at timestamptz not null default clock_timestamp(),

  constraint ai_price_rates_meter_valid
    check (meter_code ~ '^[a-z][a-z0-9._-]{1,63}$'),
  constraint ai_price_rates_unit_valid
    check (unit_code ~ '^[a-z][a-z0-9._-]{0,31}$'),
  constraint ai_price_rates_unit_quantity_positive
    check (unit_quantity > 0),
  constraint ai_price_rates_unit_price_nonnegative
    check (unit_price >= 0),
  constraint ai_price_rates_version_meter_unique
    unique (price_version_id, meter_code)
);

comment on table private.ai_price_rates is
  'Immutable component rates, for example uncached input, cached input, output, image or audio meters.';

create index ai_price_rates_price_version_idx
  on private.ai_price_rates(price_version_id);

create table private.ai_consumption_periods (
  id uuid primary key default gen_random_uuid(),
  instance_id uuid not null
    references public.instances(id) on delete restrict,
  starts_at timestamptz not null,
  ends_at timestamptz not null,
  period_timezone text not null default 'Europe/Paris',
  status text not null default 'open',
  created_at timestamptz not null default clock_timestamp(),

  constraint ai_consumption_periods_window_valid
    check (ends_at > starts_at),
  constraint ai_consumption_periods_timezone_valid
    check (length(period_timezone) between 1 and 64),
  constraint ai_consumption_periods_status_valid
    check (status in ('open', 'closed')),
  constraint ai_consumption_periods_instance_window_unique
    unique (instance_id, starts_at, ends_at),
  constraint ai_consumption_periods_no_overlap
    exclude using gist (
      instance_id with =,
      tstzrange(starts_at, ends_at, '[)') with &&
    )
);

comment on table private.ai_consumption_periods is
  'Non-quota consumption windows. V1 creates calendar months in Europe/Paris when none exists.';

create index ai_consumption_periods_instance_idx
  on private.ai_consumption_periods(instance_id);

create table private.ai_execution_requests (
  id uuid primary key default gen_random_uuid(),
  request_id uuid not null,
  user_id uuid not null references auth.users(id) on delete restrict,
  instance_id uuid not null references public.instances(id) on delete restrict,
  module_id uuid not null references public.modules(id) on delete restrict,
  workflow_code text not null,
  consumption_period_id uuid not null
    references private.ai_consumption_periods(id) on delete restrict,
  status text not null default 'reserved',
  reserved_at timestamptz not null default clock_timestamp(),
  finalized_at timestamptz,

  constraint ai_execution_requests_workflow_valid
    check (workflow_code ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$'),
  constraint ai_execution_requests_status_valid
    check (status in ('reserved', 'finalized')),
  constraint ai_execution_requests_dates_valid
    check (
      (status = 'reserved' and finalized_at is null)
      or (status = 'finalized' and finalized_at is not null and finalized_at >= reserved_at)
    ),
  constraint ai_execution_requests_idempotency_unique
    unique (instance_id, module_id, request_id)
);

comment on table private.ai_execution_requests is
  'Server-authenticated idempotency reservations. No prompt or business payload is stored.';

create index ai_execution_requests_user_idx
  on private.ai_execution_requests(user_id);
create index ai_execution_requests_instance_idx
  on private.ai_execution_requests(instance_id);
create index ai_execution_requests_module_idx
  on private.ai_execution_requests(module_id);
create index ai_execution_requests_period_idx
  on private.ai_execution_requests(consumption_period_id);

create table private.ai_usage_events (
  id uuid primary key default gen_random_uuid(),
  reservation_id uuid not null unique
    references private.ai_execution_requests(id) on delete restrict,
  request_id uuid not null,
  user_id uuid not null references auth.users(id) on delete restrict,
  instance_id uuid not null references public.instances(id) on delete restrict,
  module_id uuid not null references public.modules(id) on delete restrict,
  module_code text not null,
  workflow_code text not null,
  consumption_period_id uuid not null
    references private.ai_consumption_periods(id) on delete restrict,
  provider text not null,
  provider_response_id text not null,
  model text not null,
  service_tier text not null,
  provider_created_at timestamptz not null,
  recorded_at timestamptz not null default clock_timestamp(),
  input_tokens bigint not null,
  cached_input_tokens bigint not null,
  output_tokens bigint not null,
  total_tokens bigint not null,
  outcome text not null,
  customer_chargeable boolean not null,
  price_version_id uuid not null
    references private.ai_price_versions(id) on delete restrict,
  currency text not null,
  provider_cost_input numeric(24,12) not null,
  provider_cost_cached_input numeric(24,12) not null,
  provider_cost_output numeric(24,12) not null,
  provider_cost_other numeric(24,12) not null default 0,
  provider_cost_total numeric(24,12) not null,

  constraint ai_usage_events_provider_response_unique
    unique (provider, provider_response_id),
  constraint ai_usage_events_provider_valid
    check (provider = lower(provider) and provider ~ '^[a-z0-9][a-z0-9._-]{1,63}$'),
  constraint ai_usage_events_provider_response_valid
    check (length(provider_response_id) between 1 and 255),
  constraint ai_usage_events_model_valid
    check (length(model) between 1 and 160),
  constraint ai_usage_events_service_tier_valid
    check (service_tier = lower(service_tier) and service_tier ~ '^[a-z0-9][a-z0-9._-]{0,63}$'),
  constraint ai_usage_events_module_code_valid
    check (module_code = lower(module_code) and module_code ~ '^[a-z0-9][a-z0-9._-]{1,63}$'),
  constraint ai_usage_events_workflow_valid
    check (workflow_code ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$'),
  constraint ai_usage_events_token_counts_valid
    check (
      input_tokens >= 0
      and cached_input_tokens >= 0
      and cached_input_tokens <= input_tokens
      and output_tokens >= 0
      and total_tokens >= 0
    ),
  constraint ai_usage_events_outcome_valid
    check (outcome in ('succeeded', 'unusable_output', 'provider_incomplete', 'provider_error')),
  constraint ai_usage_events_chargeability_valid
    check (customer_chargeable = (outcome = 'succeeded')),
  constraint ai_usage_events_currency_valid
    check (currency ~ '^[A-Z]{3}$'),
  constraint ai_usage_events_costs_nonnegative
    check (
      provider_cost_input >= 0
      and provider_cost_cached_input >= 0
      and provider_cost_output >= 0
      and provider_cost_other >= 0
      and provider_cost_total >= 0
    ),
  constraint ai_usage_events_cost_total_valid
    check (
      provider_cost_total = provider_cost_input
        + provider_cost_cached_input
        + provider_cost_output
        + provider_cost_other
    )
);

comment on table private.ai_usage_events is
  'Append-only technical and supplier-cost ledger. Business content, prompts and generated output are forbidden.';

create index ai_usage_events_user_recorded_idx
  on private.ai_usage_events(user_id, recorded_at desc);
create index ai_usage_events_instance_recorded_idx
  on private.ai_usage_events(instance_id, recorded_at desc);
create index ai_usage_events_module_recorded_idx
  on private.ai_usage_events(module_id, recorded_at desc);
create index ai_usage_events_period_idx
  on private.ai_usage_events(consumption_period_id);
create index ai_usage_events_price_version_idx
  on private.ai_usage_events(price_version_id);

create table private.ai_usage_charges (
  id uuid primary key default gen_random_uuid(),
  usage_event_id uuid not null
    references private.ai_usage_events(id) on delete restrict,
  price_rate_id uuid not null
    references private.ai_price_rates(id) on delete restrict,
  meter_code text not null,
  quantity numeric(24,6) not null,
  unit_code text not null,
  unit_quantity numeric(24,6) not null,
  unit_price numeric(24,12) not null,
  amount numeric(24,12) not null,
  currency text not null,
  created_at timestamptz not null default clock_timestamp(),

  constraint ai_usage_charges_event_meter_unique
    unique (usage_event_id, meter_code),
  constraint ai_usage_charges_meter_valid
    check (meter_code ~ '^[a-z][a-z0-9._-]{1,63}$'),
  constraint ai_usage_charges_unit_valid
    check (unit_code ~ '^[a-z][a-z0-9._-]{0,31}$'),
  constraint ai_usage_charges_values_valid
    check (quantity >= 0 and unit_quantity > 0 and unit_price >= 0 and amount >= 0),
  constraint ai_usage_charges_currency_valid
    check (currency ~ '^[A-Z]{3}$')
);

comment on table private.ai_usage_charges is
  'Immutable supplier charge snapshots by meter. Rates and amounts remain unchanged when later price books are published.';

create index ai_usage_charges_event_idx
  on private.ai_usage_charges(usage_event_id);
create index ai_usage_charges_rate_idx
  on private.ai_usage_charges(price_rate_id);

alter table private.ai_price_versions enable row level security;
alter table private.ai_price_versions force row level security;
alter table private.ai_price_rates enable row level security;
alter table private.ai_price_rates force row level security;
alter table private.ai_consumption_periods enable row level security;
alter table private.ai_consumption_periods force row level security;
alter table private.ai_execution_requests enable row level security;
alter table private.ai_execution_requests force row level security;
alter table private.ai_usage_events enable row level security;
alter table private.ai_usage_events force row level security;
alter table private.ai_usage_charges enable row level security;
alter table private.ai_usage_charges force row level security;

revoke all on all tables in schema private from public, anon, authenticated, service_role;
revoke all on all sequences in schema private from public, anon, authenticated, service_role;

create function private.ai_usage_prevent_mutation_v1()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception using
    errcode = '55000',
    message = format('%I.%I is append-only', tg_table_schema, tg_table_name);
end;
$$;

revoke all on function private.ai_usage_prevent_mutation_v1()
  from public, anon, authenticated, service_role;

create function private.ai_price_version_guard_v1()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if tg_op = 'DELETE' then
    raise exception using errcode = '55000', message = 'Price versions cannot be deleted';
  end if;

  if old.effective_to is not null
     or new.effective_to is null
     or new.effective_to <= old.effective_from
     or new.id is distinct from old.id
     or new.provider is distinct from old.provider
     or new.model is distinct from old.model
     or new.service_tier is distinct from old.service_tier
     or new.modality is distinct from old.modality
     or new.currency is distinct from old.currency
     or new.effective_from is distinct from old.effective_from
     or new.source_url is distinct from old.source_url
     or new.source_published_at is distinct from old.source_published_at
     or new.created_at is distinct from old.created_at then
    raise exception using errcode = '55000', message = 'Only an open price version effective_to may be set once';
  end if;

  return new;
end;
$$;

revoke all on function private.ai_price_version_guard_v1()
  from public, anon, authenticated, service_role;

create trigger ai_price_versions_guard
before update or delete on private.ai_price_versions
for each row execute function private.ai_price_version_guard_v1();

create trigger ai_price_rates_immutable
before update or delete on private.ai_price_rates
for each row execute function private.ai_usage_prevent_mutation_v1();

create trigger ai_usage_events_immutable
before update or delete on private.ai_usage_events
for each row execute function private.ai_usage_prevent_mutation_v1();

create trigger ai_usage_charges_immutable
before update or delete on private.ai_usage_charges
for each row execute function private.ai_usage_prevent_mutation_v1();

create function public.ai_usage_reserve_v1(
  p_contract_version smallint,
  p_request_id uuid,
  p_user_id uuid,
  p_instance_id uuid,
  p_module_code text,
  p_workflow_code text
)
returns table (
  reservation_id uuid,
  reservation_status text,
  usage_event_id uuid
)
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_now timestamptz := clock_timestamp();
  v_module_id uuid;
  v_period_id uuid;
  v_period_start timestamptz;
  v_period_end timestamptz;
  v_reservation private.ai_execution_requests%rowtype;
  v_event_id uuid;
  v_inserted integer := 0;
begin
  if p_contract_version <> 1 then
    raise exception using errcode = '22023', message = 'UNSUPPORTED_CONTRACT_VERSION';
  end if;
  if p_request_id is null or p_user_id is null or p_instance_id is null then
    raise exception using errcode = '22023', message = 'INVALID_IDENTITY_CONTEXT';
  end if;
  if p_module_code is null
     or p_module_code <> lower(p_module_code)
     or p_module_code !~ '^[a-z0-9][a-z0-9._-]{1,63}$' then
    raise exception using errcode = '22023', message = 'INVALID_MODULE_CODE';
  end if;
  if p_workflow_code is null
     or p_workflow_code !~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$' then
    raise exception using errcode = '22023', message = 'INVALID_WORKFLOW_CODE';
  end if;

  select m.id
    into v_module_id
  from public.modules as m
  where m.code = p_module_code
    and m.status = 'active'
  limit 1;

  if v_module_id is null then
    raise exception using errcode = '42501', message = 'MODULE_NOT_ACTIVE';
  end if;

  if not exists (
    select 1
    from public.instance_members as im
    join public.instances as i on i.id = im.instance_id
    join public.instance_modules as ixm
      on ixm.instance_id = i.id
     and ixm.module_id = v_module_id
    where im.instance_id = p_instance_id
      and im.user_id = p_user_id
      and im.status = 'active'
      and (im.access_start is null or im.access_start <= (v_now at time zone 'UTC')::date)
      and (im.access_end is null or im.access_end >= (v_now at time zone 'UTC')::date)
      and i.status = 'active'
      and (i.access_start is null or i.access_start <= (v_now at time zone 'UTC')::date)
      and (i.access_end is null or i.access_end >= (v_now at time zone 'UTC')::date)
      and (
        ixm.access_status = 'active'
        or (ixm.access_status = 'trial' and ixm.trial_end >= (v_now at time zone 'UTC')::date)
      )
  ) then
    raise exception using errcode = '42501', message = 'INSTANCE_MODULE_ACCESS_DENIED';
  end if;

  select p.id
    into v_period_id
  from private.ai_consumption_periods as p
  where p.instance_id = p_instance_id
    and p.starts_at <= v_now
    and p.ends_at > v_now
  order by p.starts_at desc
  limit 1;

  if v_period_id is null then
    v_period_start := date_trunc('month', v_now at time zone 'Europe/Paris')
      at time zone 'Europe/Paris';
    v_period_end := (date_trunc('month', v_now at time zone 'Europe/Paris') + interval '1 month')
      at time zone 'Europe/Paris';

    insert into private.ai_consumption_periods (
      instance_id, starts_at, ends_at, period_timezone
    ) values (
      p_instance_id, v_period_start, v_period_end, 'Europe/Paris'
    )
    on conflict do nothing;

    select p.id
      into v_period_id
    from private.ai_consumption_periods as p
    where p.instance_id = p_instance_id
      and p.starts_at <= v_now
      and p.ends_at > v_now
    order by p.starts_at desc
    limit 1;
  end if;

  if v_period_id is null then
    raise exception using errcode = '55000', message = 'CONSUMPTION_PERIOD_UNAVAILABLE';
  end if;

  insert into private.ai_execution_requests (
    request_id,
    user_id,
    instance_id,
    module_id,
    workflow_code,
    consumption_period_id,
    reserved_at
  ) values (
    p_request_id,
    p_user_id,
    p_instance_id,
    v_module_id,
    p_workflow_code,
    v_period_id,
    v_now
  )
  on conflict (instance_id, module_id, request_id) do nothing;

  get diagnostics v_inserted = row_count;

  select r.*
    into v_reservation
  from private.ai_execution_requests as r
  where r.instance_id = p_instance_id
    and r.module_id = v_module_id
    and r.request_id = p_request_id
  for update;

  if v_reservation.id is null then
    raise exception using errcode = '55000', message = 'RESERVATION_UNAVAILABLE';
  end if;

  if v_reservation.user_id <> p_user_id
     or v_reservation.workflow_code <> p_workflow_code then
    raise exception using errcode = '23505', message = 'REQUEST_ID_CONTEXT_MISMATCH';
  end if;

  select e.id
    into v_event_id
  from private.ai_usage_events as e
  where e.reservation_id = v_reservation.id;

  return query
  select
    v_reservation.id,
    case
      when v_event_id is not null then 'already_finalized'
      when v_inserted = 1 then 'reserved'
      else 'already_reserved'
    end,
    v_event_id;
end;
$$;

create function public.ai_usage_finalize_v1(
  p_contract_version smallint,
  p_reservation_id uuid,
  p_request_id uuid,
  p_provider text,
  p_provider_response_id text,
  p_model text,
  p_service_tier text,
  p_provider_created_at timestamptz,
  p_input_tokens bigint,
  p_cached_input_tokens bigint,
  p_output_tokens bigint,
  p_total_tokens bigint,
  p_outcome text
)
returns table (
  usage_event_id uuid,
  finalization_status text,
  currency text,
  provider_cost_total numeric(24,12),
  customer_chargeable boolean
)
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_now timestamptz := clock_timestamp();
  v_provider text := lower(trim(p_provider));
  v_provider_response_id text := trim(p_provider_response_id);
  v_model text := trim(p_model);
  v_service_tier text := lower(trim(p_service_tier));
  v_reservation private.ai_execution_requests%rowtype;
  v_module_code text;
  v_existing_event private.ai_usage_events%rowtype;
  v_price_version private.ai_price_versions%rowtype;
  v_input_rate private.ai_price_rates%rowtype;
  v_cached_rate private.ai_price_rates%rowtype;
  v_output_rate private.ai_price_rates%rowtype;
  v_uncached_input_tokens bigint;
  v_input_cost numeric(24,12);
  v_cached_cost numeric(24,12);
  v_output_cost numeric(24,12);
  v_total_cost numeric(24,12);
  v_event_id uuid;
  v_chargeable boolean;
begin
  if p_contract_version <> 1 then
    raise exception using errcode = '22023', message = 'UNSUPPORTED_CONTRACT_VERSION';
  end if;
  if p_reservation_id is null or p_request_id is null then
    raise exception using errcode = '22023', message = 'INVALID_RESERVATION';
  end if;
  if v_provider is null
     or v_provider !~ '^[a-z0-9][a-z0-9._-]{1,63}$' then
    raise exception using errcode = '22023', message = 'INVALID_PROVIDER';
  end if;
  if v_provider_response_id is null or length(v_provider_response_id) not between 1 and 255 then
    raise exception using errcode = '22023', message = 'INVALID_PROVIDER_RESPONSE_ID';
  end if;
  if v_model is null or length(v_model) not between 1 and 160 then
    raise exception using errcode = '22023', message = 'INVALID_MODEL';
  end if;
  if v_service_tier is null
     or v_service_tier !~ '^[a-z0-9][a-z0-9._-]{0,63}$' then
    raise exception using errcode = '22023', message = 'INVALID_SERVICE_TIER';
  end if;
  if p_provider_created_at is null
     or p_provider_created_at > v_now + interval '5 minutes' then
    raise exception using errcode = '22023', message = 'INVALID_PROVIDER_TIMESTAMP';
  end if;
  if p_input_tokens is null or p_input_tokens < 0
     or p_cached_input_tokens is null or p_cached_input_tokens < 0
     or p_cached_input_tokens > p_input_tokens
     or p_output_tokens is null or p_output_tokens < 0
     or p_total_tokens is null or p_total_tokens < 0 then
    raise exception using errcode = '22023', message = 'INVALID_USAGE_METRICS';
  end if;
  if p_outcome is null
     or p_outcome not in ('succeeded', 'unusable_output', 'provider_incomplete', 'provider_error') then
    raise exception using errcode = '22023', message = 'INVALID_OUTCOME';
  end if;

  select r.*
    into v_reservation
  from private.ai_execution_requests as r
  where r.id = p_reservation_id
    and r.request_id = p_request_id
  for update;

  if v_reservation.id is null then
    raise exception using errcode = 'P0002', message = 'RESERVATION_NOT_FOUND';
  end if;

  if p_provider_created_at < v_reservation.reserved_at - interval '15 minutes' then
    raise exception using errcode = '22023', message = 'INVALID_PROVIDER_TIMESTAMP';
  end if;

  select e.*
    into v_existing_event
  from private.ai_usage_events as e
  where e.reservation_id = v_reservation.id;

  if v_existing_event.id is not null then
    if v_existing_event.provider <> v_provider
       or v_existing_event.provider_response_id <> v_provider_response_id
       or v_existing_event.model <> v_model
       or v_existing_event.service_tier <> v_service_tier
       or v_existing_event.provider_created_at <> p_provider_created_at
       or v_existing_event.input_tokens <> p_input_tokens
       or v_existing_event.cached_input_tokens <> p_cached_input_tokens
       or v_existing_event.output_tokens <> p_output_tokens
       or v_existing_event.total_tokens <> p_total_tokens
       or v_existing_event.outcome <> p_outcome then
      raise exception using errcode = '23505', message = 'FINALIZATION_CONTEXT_MISMATCH';
    end if;

    return query
    select
      v_existing_event.id,
      'already_finalized'::text,
      v_existing_event.currency,
      v_existing_event.provider_cost_total,
      v_existing_event.customer_chargeable;
    return;
  end if;

  if exists (
    select 1
    from private.ai_usage_events as e
    where e.provider = v_provider
      and e.provider_response_id = v_provider_response_id
      and e.reservation_id <> v_reservation.id
  ) then
    raise exception using errcode = '23505', message = 'PROVIDER_RESPONSE_ALREADY_RECORDED';
  end if;

  select pv.*
    into v_price_version
  from private.ai_price_versions as pv
  where pv.provider = v_provider
    and pv.model = v_model
    and pv.service_tier = v_service_tier
    and pv.modality = 'text'
    and pv.effective_from <= p_provider_created_at
    and (pv.effective_to is null or pv.effective_to > p_provider_created_at)
  order by pv.effective_from desc
  limit 1;

  if v_price_version.id is null then
    raise exception using errcode = 'P0001', message = 'PRICE_VERSION_NOT_FOUND';
  end if;

  select r.* into v_input_rate
  from private.ai_price_rates as r
  where r.price_version_id = v_price_version.id
    and r.meter_code = 'uncached_input_tokens';

  select r.* into v_cached_rate
  from private.ai_price_rates as r
  where r.price_version_id = v_price_version.id
    and r.meter_code = 'cached_input_tokens';

  select r.* into v_output_rate
  from private.ai_price_rates as r
  where r.price_version_id = v_price_version.id
    and r.meter_code = 'output_tokens';

  if v_input_rate.id is null or v_cached_rate.id is null or v_output_rate.id is null then
    raise exception using errcode = 'P0001', message = 'PRICE_RATES_INCOMPLETE';
  end if;
  if v_input_rate.unit_code <> 'token'
     or v_cached_rate.unit_code <> 'token'
     or v_output_rate.unit_code <> 'token' then
    raise exception using errcode = 'P0001', message = 'PRICE_RATE_UNIT_INVALID';
  end if;

  v_uncached_input_tokens := p_input_tokens - p_cached_input_tokens;
  v_input_cost := round(
    v_uncached_input_tokens::numeric * v_input_rate.unit_price / v_input_rate.unit_quantity,
    12
  );
  v_cached_cost := round(
    p_cached_input_tokens::numeric * v_cached_rate.unit_price / v_cached_rate.unit_quantity,
    12
  );
  v_output_cost := round(
    p_output_tokens::numeric * v_output_rate.unit_price / v_output_rate.unit_quantity,
    12
  );
  v_total_cost := v_input_cost + v_cached_cost + v_output_cost;
  v_chargeable := p_outcome = 'succeeded';

  select m.code
    into v_module_code
  from public.modules as m
  where m.id = v_reservation.module_id;

  insert into private.ai_usage_events (
    reservation_id,
    request_id,
    user_id,
    instance_id,
    module_id,
    module_code,
    workflow_code,
    consumption_period_id,
    provider,
    provider_response_id,
    model,
    service_tier,
    provider_created_at,
    recorded_at,
    input_tokens,
    cached_input_tokens,
    output_tokens,
    total_tokens,
    outcome,
    customer_chargeable,
    price_version_id,
    currency,
    provider_cost_input,
    provider_cost_cached_input,
    provider_cost_output,
    provider_cost_other,
    provider_cost_total
  ) values (
    v_reservation.id,
    v_reservation.request_id,
    v_reservation.user_id,
    v_reservation.instance_id,
    v_reservation.module_id,
    v_module_code,
    v_reservation.workflow_code,
    v_reservation.consumption_period_id,
    v_provider,
    v_provider_response_id,
    v_model,
    v_service_tier,
    p_provider_created_at,
    v_now,
    p_input_tokens,
    p_cached_input_tokens,
    p_output_tokens,
    p_total_tokens,
    p_outcome,
    v_chargeable,
    v_price_version.id,
    v_price_version.currency,
    v_input_cost,
    v_cached_cost,
    v_output_cost,
    0,
    v_total_cost
  )
  returning id into v_event_id;

  insert into private.ai_usage_charges (
    usage_event_id, price_rate_id, meter_code, quantity,
    unit_code, unit_quantity, unit_price, amount, currency
  ) values
  (
    v_event_id, v_input_rate.id, v_input_rate.meter_code, v_uncached_input_tokens,
    v_input_rate.unit_code, v_input_rate.unit_quantity, v_input_rate.unit_price,
    v_input_cost, v_price_version.currency
  ),
  (
    v_event_id, v_cached_rate.id, v_cached_rate.meter_code, p_cached_input_tokens,
    v_cached_rate.unit_code, v_cached_rate.unit_quantity, v_cached_rate.unit_price,
    v_cached_cost, v_price_version.currency
  ),
  (
    v_event_id, v_output_rate.id, v_output_rate.meter_code, p_output_tokens,
    v_output_rate.unit_code, v_output_rate.unit_quantity, v_output_rate.unit_price,
    v_output_cost, v_price_version.currency
  );

  update private.ai_execution_requests
  set status = 'finalized', finalized_at = v_now
  where id = v_reservation.id;

  return query
  select
    v_event_id,
    'finalized'::text,
    v_price_version.currency,
    v_total_cost,
    v_chargeable;
end;
$$;

revoke all on function public.ai_usage_reserve_v1(
  smallint, uuid, uuid, uuid, text, text
) from public, anon, authenticated, service_role;

revoke all on function public.ai_usage_finalize_v1(
  smallint, uuid, uuid, text, text, text, text, timestamptz,
  bigint, bigint, bigint, bigint, text
) from public, anon, authenticated, service_role;

grant execute on function public.ai_usage_reserve_v1(
  smallint, uuid, uuid, uuid, text, text
) to service_role;

grant execute on function public.ai_usage_finalize_v1(
  smallint, uuid, uuid, text, text, text, text, timestamptz,
  bigint, bigint, bigint, bigint, text
) to service_role;

create view private.ai_usage_admin_monthly
with (security_invoker = true)
as
select
  e.instance_id,
  e.user_id,
  e.module_id,
  e.module_code,
  e.workflow_code,
  e.provider,
  e.model,
  e.service_tier,
  p.starts_at as period_start,
  p.ends_at as period_end,
  e.currency,
  count(*) as request_count,
  sum(e.input_tokens) as input_tokens,
  sum(e.cached_input_tokens) as cached_input_tokens,
  sum(e.output_tokens) as output_tokens,
  sum(e.total_tokens) as total_tokens,
  sum(e.provider_cost_total) as provider_cost_total,
  count(*) filter (where e.customer_chargeable) as customer_chargeable_request_count
from private.ai_usage_events as e
join private.ai_consumption_periods as p on p.id = e.consumption_period_id
group by
  e.instance_id,
  e.user_id,
  e.module_id,
  e.module_code,
  e.workflow_code,
  e.provider,
  e.model,
  e.service_tier,
  p.starts_at,
  p.ends_at,
  e.currency;

comment on view private.ai_usage_admin_monthly is
  'Administrative aggregation only. It is not exposed to client roles.';

revoke all on private.ai_usage_admin_monthly from public, anon, authenticated, service_role;

commit;
