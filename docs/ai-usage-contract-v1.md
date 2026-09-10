# Li.La AI usage accounting — server contract V1

## Scope

This contract is owned by PLATFORM. Com, Office, Pulse, Studio+, Chat and future
AI modules call it without reading or writing accounting tables directly.

The contract stores technical consumption metadata and frozen supplier cost
only. Prompts, generated output, conversations, emails, customer documents and
other business content are forbidden.

## Trust boundary

Only a server backend may call the RPCs. The browser must never receive the
Supabase secret key or call these functions.

Before reservation, a module backend authenticates the Supabase access token
and derives `user_id` from that verified identity. It must not accept `user_id`
or `instance_id` from browser JSON. PLATFORM then independently verifies the
active user/instance/module relationship in PostgreSQL.

The Hostinger configuration is stored outside Git and outside the public
document root. `LILA_PLATFORM_CONFIG_PATH` points to the private PHP config.
Use a modern, named `sb_secret_...` key and send it only as the `apikey` header.

## Reserve before the provider call

Endpoint:

```text
POST https://PROJECT_REF.supabase.co/rest/v1/rpc/ai_usage_reserve_v1
```

Headers:

```text
Accept: application/json
Content-Type: application/json
apikey: sb_secret_SERVER_ONLY
```

Body:

```json
{
  "p_contract_version": 1,
  "p_request_id": "11111111-1111-4111-8111-111111111111",
  "p_user_id": "UUID_FROM_VERIFIED_BACKEND_IDENTITY",
  "p_instance_id": "UUID_FROM_VERIFIED_BACKEND_ACCESS_CONTEXT",
  "p_module_code": "com",
  "p_workflow_code": "COM-01"
}
```

Successful result:

```json
[
  {
    "reservation_id": "44444444-4444-4444-8444-444444444444",
    "reservation_status": "reserved",
    "usage_event_id": null
  }
]
```

The uniqueness key is `(instance_id, module_id, request_id)`. A new reservation
returns `reserved`. Reusing the same request while it is in progress returns
`already_reserved`; the second HTTP request must not call the provider. Reusing
it after finalization returns `already_finalized` and its existing event ID.
Reusing it with another user or workflow is rejected.

Reservation time and consumption period are computed from the database server
clock. V1 creates a Europe/Paris calendar-month period when an instance has no
existing period covering the server time. No quota is evaluated.

## Finalize after the provider call

Endpoint:

```text
POST https://PROJECT_REF.supabase.co/rest/v1/rpc/ai_usage_finalize_v1
```

Body for a text response:

```json
{
  "p_contract_version": 1,
  "p_reservation_id": "44444444-4444-4444-8444-444444444444",
  "p_request_id": "11111111-1111-4111-8111-111111111111",
  "p_provider": "openai",
  "p_provider_response_id": "resp_...",
  "p_model": "MODEL_RETURNED_BY_PROVIDER",
  "p_service_tier": "default",
  "p_provider_created_at": "2026-09-10T12:00:00Z",
  "p_input_tokens": 1000,
  "p_cached_input_tokens": 400,
  "p_output_tokens": 200,
  "p_total_tokens": 1200,
  "p_outcome": "succeeded"
}
```

`p_provider_created_at` must come from the authenticated provider response. It
must never be accepted from the browser. PLATFORM rejects future timestamps and
timestamps materially preceding the reservation.

Allowed outcomes are:

- `succeeded`: administratively recorded and eligible for a future client gauge;
- `unusable_output`: supplier cost recorded, not client-chargeable;
- `provider_incomplete`: supplier cost recorded, not client-chargeable;
- `provider_error`: use only when the provider returned authoritative usage;
  supplier cost is recorded and is not client-chargeable.

Successful result:

```json
[
  {
    "usage_event_id": "55555555-5555-4555-8555-555555555555",
    "finalization_status": "finalized",
    "currency": "USD",
    "provider_cost_total": "0.001234000000",
    "customer_chargeable": true
  }
]
```

Finalization locks the reservation, resolves the price version effective at the
provider timestamp, inserts the event and its three charge lines, and marks the
reservation finalized in one transaction. Repeating the same finalization
returns `already_finalized`. A provider response ID already attached to another
reservation is rejected.

## Pricing contract

Before a model is enabled, PLATFORM must contain exactly one effective price
version for its `provider`, `model`, `service_tier` and `modality`. Text versions
must include these meters:

- `uncached_input_tokens`;
- `cached_input_tokens`;
- `output_tokens`.

Rates use exact `numeric` values, an explicit currency, a unit quantity and an
HTTPS source. Price rows are immutable. Publishing a new price requires a new,
non-overlapping effective version.

Cost formula:

```text
uncached input quantity = input_tokens - cached_input_tokens
component amount = round(quantity × unit_price / unit_quantity, 12)
total = uncached input + cached input + output + other future components
```

The applied rate, unit, currency and amount are copied into charge rows. Later
price changes therefore cannot alter historical events.

## Error handling

RPC errors contain technical codes such as `INSTANCE_MODULE_ACCESS_DENIED`,
`PRICE_VERSION_NOT_FOUND` or `PRICE_RATES_INCOMPLETE`. Module APIs must map them
to safe public errors and must not expose database messages, secrets or internal
identifiers beyond the existing `request_id`.

A finalization failure must be treated as an accounting incident. The caller
must retain only the allow-listed technical usage metadata needed for a retry;
it must never spool the prompt or generated result.

## Deployment boundary

This repository contains no real key and is not linked for production pushes.
Apply and test the migration on a local database or isolated Supabase branch
that already contains the Li.La access-control schema. Production migration and
module integration require separate validation and authorization.
