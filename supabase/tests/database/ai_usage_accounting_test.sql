begin;

create function pg_temp.assert_true(condition boolean, message text)
returns void
language plpgsql
as $$
begin
  if condition is distinct from true then
    raise exception 'ASSERTION FAILED: %', message;
  end if;
end;
$$;

select pg_temp.assert_true(
  to_regnamespace('private') is not null,
  'private schema exists'
);

select pg_temp.assert_true(
  not has_schema_privilege('anon', 'private', 'USAGE')
  and not has_schema_privilege('authenticated', 'private', 'USAGE'),
  'browser roles have no private schema usage'
);

select pg_temp.assert_true(
  not has_function_privilege(
    'anon',
    'public.ai_usage_reserve_v1(smallint,uuid,uuid,uuid,text,text)',
    'EXECUTE'
  )
  and not has_function_privilege(
    'authenticated',
    'public.ai_usage_reserve_v1(smallint,uuid,uuid,uuid,text,text)',
    'EXECUTE'
  )
  and has_function_privilege(
    'service_role',
    'public.ai_usage_reserve_v1(smallint,uuid,uuid,uuid,text,text)',
    'EXECUTE'
  ),
  'reserve RPC is server-only'
);

select pg_temp.assert_true(
  not has_function_privilege(
    'anon',
    'public.ai_usage_finalize_v1(smallint,uuid,uuid,text,text,text,text,timestamp with time zone,bigint,bigint,bigint,bigint,text)',
    'EXECUTE'
  )
  and not has_function_privilege(
    'authenticated',
    'public.ai_usage_finalize_v1(smallint,uuid,uuid,text,text,text,text,timestamp with time zone,bigint,bigint,bigint,bigint,text)',
    'EXECUTE'
  )
  and has_function_privilege(
    'service_role',
    'public.ai_usage_finalize_v1(smallint,uuid,uuid,text,text,text,text,timestamp with time zone,bigint,bigint,bigint,bigint,text)',
    'EXECUTE'
  ),
  'finalize RPC is server-only'
);

select pg_temp.assert_true(
  (
    select count(*) = 6
      and bool_and(c.relrowsecurity)
      and bool_and(c.relforcerowsecurity)
    from pg_class as c
    join pg_namespace as n on n.oid = c.relnamespace
    where n.nspname = 'private'
      and c.relname in (
        'ai_price_versions',
        'ai_price_rates',
        'ai_consumption_periods',
        'ai_execution_requests',
        'ai_usage_events',
        'ai_usage_charges'
      )
  ),
  'all accounting tables have forced RLS'
);

select pg_temp.assert_true(
  not has_table_privilege('anon', 'private.ai_usage_events', 'SELECT')
  and not has_table_privilege('authenticated', 'private.ai_usage_events', 'SELECT')
  and not has_table_privilege('service_role', 'private.ai_usage_events', 'SELECT'),
  'ledger has no direct Data API read grant'
);

insert into auth.users (id, email, aud, role, created_at, updated_at)
values
  (
    '10000000-0000-4000-8000-000000000001',
    'ai-ledger-user-1@example.invalid',
    'authenticated',
    'authenticated',
    clock_timestamp(),
    clock_timestamp()
  ),
  (
    '10000000-0000-4000-8000-000000000002',
    'ai-ledger-user-2@example.invalid',
    'authenticated',
    'authenticated',
    clock_timestamp(),
    clock_timestamp()
  );

insert into public.instances (id, code, name, status)
values
  (
    '20000000-0000-4000-8000-000000000001',
    'ai-ledger-test-instance',
    'AI ledger SQL test',
    'active'
  ),
  (
    '20000000-0000-4000-8000-000000000002',
    'ai-ledger-other-instance',
    'AI ledger isolated instance',
    'active'
  );

insert into public.modules (id, code, name, status)
values
  (
    '30000000-0000-4000-8000-000000000001',
    'test-ai',
    'AI ledger test module',
    'active'
  ),
  (
    '30000000-0000-4000-8000-000000000002',
    'test-ai-disabled',
    'AI ledger module not enabled for instance',
    'active'
  );

insert into public.instance_members (instance_id, user_id, role, status)
values
  (
    '20000000-0000-4000-8000-000000000001',
    '10000000-0000-4000-8000-000000000001',
    'member',
    'active'
  ),
  (
    '20000000-0000-4000-8000-000000000001',
    '10000000-0000-4000-8000-000000000002',
    'member',
    'active'
  );

insert into public.instance_modules (instance_id, module_id, access_status)
values (
  '20000000-0000-4000-8000-000000000001',
  '30000000-0000-4000-8000-000000000001',
  'active'
);

insert into private.ai_price_versions (
  id,
  provider,
  model,
  service_tier,
  modality,
  currency,
  effective_from,
  source_url,
  source_published_at
) values (
  '40000000-0000-4000-8000-000000000001',
  'test-provider',
  'test-model-v1',
  'default',
  'text',
  'USD',
  clock_timestamp() - interval '1 day',
  'https://example.invalid/provider-pricing-v1',
  clock_timestamp() - interval '1 day'
);

insert into private.ai_price_rates (
  id, price_version_id, meter_code, unit_code, unit_quantity, unit_price
) values
  (
    '41000000-0000-4000-8000-000000000001',
    '40000000-0000-4000-8000-000000000001',
    'uncached_input_tokens',
    'token',
    1000000,
    10
  ),
  (
    '41000000-0000-4000-8000-000000000002',
    '40000000-0000-4000-8000-000000000001',
    'cached_input_tokens',
    'token',
    1000000,
    2
  ),
  (
    '41000000-0000-4000-8000-000000000003',
    '40000000-0000-4000-8000-000000000001',
    'output_tokens',
    'token',
    1000000,
    30
  );

create temporary table test_reserve_1 as
select *
from public.ai_usage_reserve_v1(
  1::smallint,
  '50000000-0000-4000-8000-000000000001',
  '10000000-0000-4000-8000-000000000001',
  '20000000-0000-4000-8000-000000000001',
  'test-ai',
  'TEST-01'
);

create temporary table test_reserve_1_repeat as
select *
from public.ai_usage_reserve_v1(
  1::smallint,
  '50000000-0000-4000-8000-000000000001',
  '10000000-0000-4000-8000-000000000001',
  '20000000-0000-4000-8000-000000000001',
  'test-ai',
  'TEST-01'
);

select pg_temp.assert_true(
  (select reservation_id from test_reserve_1)
    = (select reservation_id from test_reserve_1_repeat)
  and (select reservation_status from test_reserve_1_repeat) = 'already_reserved',
  'same request id returns the same reservation'
);

do $$
declare
  rejected boolean := false;
begin
  begin
    perform *
    from public.ai_usage_reserve_v1(
      1::smallint,
      '50000000-0000-4000-8000-000000000001',
      '10000000-0000-4000-8000-000000000002',
      '20000000-0000-4000-8000-000000000001',
      'test-ai',
      'TEST-01'
    );
  exception when unique_violation then
    rejected := true;
  end;
  if not rejected then
    raise exception 'ASSERTION FAILED: request id context mismatch was accepted';
  end if;
end;
$$;

create temporary table test_finalize_1 as
select *
from public.ai_usage_finalize_v1(
  1::smallint,
  (select reservation_id from test_reserve_1),
  '50000000-0000-4000-8000-000000000001',
  'test-provider',
  'provider-response-1',
  'test-model-v1',
  'default',
  clock_timestamp(),
  1000,
  400,
  200,
  1200,
  'succeeded'
);

select pg_temp.assert_true(
  (select provider_cost_total from test_finalize_1) = 0.012800000000,
  'uncached, cached and output costs are calculated separately'
);

select pg_temp.assert_true(
  (select customer_chargeable from test_finalize_1),
  'successful output is marked chargeable'
);

select pg_temp.assert_true(
  (select count(*) from private.ai_usage_charges
   where usage_event_id = (select usage_event_id from test_finalize_1)) = 3,
  'one immutable charge line exists for each text meter'
);

create temporary table test_finalize_1_repeat as
select *
from public.ai_usage_finalize_v1(
  1::smallint,
  (select reservation_id from test_reserve_1),
  '50000000-0000-4000-8000-000000000001',
  'test-provider',
  'provider-response-1',
  'test-model-v1',
  'default',
  clock_timestamp(),
  1000,
  400,
  200,
  1200,
  'succeeded'
);

select pg_temp.assert_true(
  (select usage_event_id from test_finalize_1)
    = (select usage_event_id from test_finalize_1_repeat)
  and (select finalization_status from test_finalize_1_repeat) = 'already_finalized',
  'repeated finalization is idempotent'
);

create temporary table test_reserve_2 as
select *
from public.ai_usage_reserve_v1(
  1::smallint,
  '50000000-0000-4000-8000-000000000002',
  '10000000-0000-4000-8000-000000000002',
  '20000000-0000-4000-8000-000000000001',
  'test-ai',
  'TEST-02'
);

create temporary table test_finalize_2 as
select *
from public.ai_usage_finalize_v1(
  1::smallint,
  (select reservation_id from test_reserve_2),
  '50000000-0000-4000-8000-000000000002',
  'test-provider',
  'provider-response-2',
  'test-model-v1',
  'default',
  clock_timestamp(),
  500,
  0,
  100,
  600,
  'unusable_output'
);

select pg_temp.assert_true(
  not (select customer_chargeable from test_finalize_2),
  'unusable provider consumption is administrative-only'
);

select pg_temp.assert_true(
  (select count(distinct user_id) from private.ai_usage_events) = 2
  and (select count(*) from private.ai_usage_events
       where user_id = '10000000-0000-4000-8000-000000000001') = 1
  and (select count(*) from private.ai_usage_events
       where user_id = '10000000-0000-4000-8000-000000000002') = 1,
  'usage remains separated by authenticated user'
);

select pg_temp.assert_true(
  not exists (
    select 1
    from information_schema.columns
    where table_schema = 'private'
      and table_name in ('ai_execution_requests', 'ai_usage_events', 'ai_usage_charges')
      and column_name ~ '(prompt|content|conversation|document|email|output_text|generated)'
  ),
  'ledger contains no business-content column'
);

do $$
declare
  rejected boolean := false;
begin
  begin
    update private.ai_usage_events
    set outcome = 'provider_error'
    where id = (select usage_event_id from test_finalize_1);
  exception when object_not_in_prerequisite_state then
    rejected := true;
  end;
  if not rejected then
    raise exception 'ASSERTION FAILED: append-only event accepted an update';
  end if;
end;
$$;

do $$
declare
  rejected boolean := false;
begin
  begin
    perform *
    from public.ai_usage_reserve_v1(
      1::smallint,
      '50000000-0000-4000-8000-000000000011',
      '10000000-0000-4000-8000-000000000001',
      '20000000-0000-4000-8000-000000000002',
      'test-ai',
      'TEST-CROSS-INSTANCE'
    );
  exception when insufficient_privilege then
    rejected := true;
  end;
  if not rejected then
    raise exception 'ASSERTION FAILED: cross-instance reservation was accepted';
  end if;
end;
$$;

do $$
declare
  rejected boolean := false;
begin
  begin
    perform *
    from public.ai_usage_reserve_v1(
      1::smallint,
      '50000000-0000-4000-8000-000000000012',
      '10000000-0000-4000-8000-000000000001',
      '20000000-0000-4000-8000-000000000001',
      'test-ai-disabled',
      'TEST-DISABLED-MODULE'
    );
  exception when insufficient_privilege then
    rejected := true;
  end;
  if not rejected then
    raise exception 'ASSERTION FAILED: disabled module reservation was accepted';
  end if;
end;
$$;

select pg_temp.assert_true(
  (select provider_cost_total from private.ai_usage_events
   where id = (select usage_event_id from test_finalize_1)) = 0.012800000000,
  'historical total is stored on the event'
);

update private.ai_price_versions
set effective_to = clock_timestamp() + interval '1 hour'
where id = '40000000-0000-4000-8000-000000000001';

insert into private.ai_price_versions (
  id, provider, model, service_tier, modality, currency,
  effective_from, source_url, source_published_at
)
select
  '40000000-0000-4000-8000-000000000002',
  provider,
  model,
  service_tier,
  modality,
  currency,
  effective_to,
  'https://example.invalid/provider-pricing-v2',
  clock_timestamp()
from private.ai_price_versions
where id = '40000000-0000-4000-8000-000000000001';

select pg_temp.assert_true(
  (select provider_cost_total from private.ai_usage_events
   where id = (select usage_event_id from test_finalize_1)) = 0.012800000000,
  'publishing a later price version does not alter historical cost'
);

do $$
declare
  rejected boolean := false;
begin
  begin
    insert into private.ai_price_versions (
      provider, model, service_tier, modality, currency,
      effective_from, effective_to, source_url
    ) values (
      'test-provider',
      'test-model-v1',
      'default',
      'text',
      'USD',
      clock_timestamp(),
      clock_timestamp() + interval '2 hours',
      'https://example.invalid/overlap'
    );
  exception when exclusion_violation then
    rejected := true;
  end;
  if not rejected then
    raise exception 'ASSERTION FAILED: overlapping price version was accepted';
  end if;
end;
$$;

rollback;
