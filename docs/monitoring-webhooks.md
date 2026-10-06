# Monitoring webhooks

A notification channel sends your monitoring incidents to a URL you control. This page covers what you receive and how to verify it.

## Create a channel

`POST /monitoring/channels`

```json
{
  "name": "Ops webhook",
  "url": "https://example.com/hooks/monitoring",
  "severity": ["critical"],
  "device_types": ["server"],
  "headers": {"X-Env": "production"}
}
```

- `url` must be a public http or https address. Private, local and cloud-metadata addresses cannot be reached and are refused.
- `severity`, `device_types` and `tags` are optional filters. Leave them out to receive everything.
- `headers` are sent with every delivery. Their values are write-only: they are never shown again, only their names.

The response contains `secret` (it starts with `whsec_`). **It is shown once, on create and on rotate. Store it now.** If you lose it, rotate it.

Other calls: `GET /channels`, `PATCH /channels/{id}`, `DELETE /channels/{id}`, `POST /channels/{id}/test`, `POST /channels/{id}/rotate-secret`, `GET /channels/{id}/deliveries?status=failed`, `POST /channels/{id}/deliveries/{delivery_id}/replay`.

## What you receive

A `POST` with a CloudEvents 1.0 JSON body:

| Field | Meaning |
| --- | --- |
| `type` | `monitoring.incident.opened`, `.updated`, `.acknowledged`, `.resolved`, or `monitoring.webhook.test` |
| `subject` | the incident id |
| `data.account_id` | your account id |
| `data.object` | the incident |
| `data.device` | the host: id, name, address, type, tags, and its containment path |
| `data.check` | the check: id, name, plugin, last output, runbook URL |

During an outage, related incidents can arrive together as one event whose `data.objects` lists them, with `data.group` giving the group id and size. In that case `data.object`, `data.device` and `data.check` are null. Incidents that are explained by a failing upstream host are held back and are only delivered if they are still failing once the upstream host recovers.

## Verify the signature

Each request carries three headers (Standard Webhooks):

- `webhook-id`: the event id. It is the same on every retry.
- `webhook-timestamp`: unix seconds.
- `webhook-signature`: `v1,<base64 signature>`. During a secret rotation it carries two signatures separated by a space.

The signature is `base64(HMAC-SHA256(key, "<webhook-id>.<webhook-timestamp>.<raw body>"))`, where `key` is the base64 decoding of your secret after the `whsec_` prefix. Use the raw request body, not a re-encoded one.

```php
$key = base64_decode(substr($secret, strlen('whsec_')));
$signed = $id.'.'.$timestamp.'.'.$rawBody;
$expected = 'v1,'.base64_encode(hash_hmac('sha256', $signed, $key, true));

$valid = false;
foreach (explode(' ', $signatureHeader) as $candidate) {
    $valid = $valid || hash_equals($expected, $candidate);
}
// Also reject a timestamp more than five minutes old.
```

## Delivery rules

- Delivery is at least once. **Deduplicate on `webhook-id`.**
- Answer with any 2xx to accept. Other answers and timeouts are retried with growing delays (5 s, 30 s, 2 min, 10 min, 30 min, then hourly) for 24 hours, after which the delivery is marked `failed`. Failed deliveries are listed under `/deliveries` and can be replayed.
- Events for one incident arrive in order.
- **Answering 410 Gone disables the channel** and cancels its pending deliveries. The channel then shows `enabled: false` and a `disabled_reason`. Never answer 410 for a temporary problem. To turn it back on, `PATCH` the channel with `{"enabled": true}`.
- Rotating the secret keeps the old one valid for 24 hours, and deliveries carry both signatures in that time.
