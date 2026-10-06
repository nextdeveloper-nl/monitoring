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
| `type` | `monitoring.tenant.device_limit_reached` or `monitoring.tenant.check_limit_reached` (account limits, see below), `monitoring.incident.opened`, `.updated`, `.acknowledged`, `.resolved`, `.commented`, `.renotify` (a reminder for an open, unacknowledged incident), `.escalated` (an escalation step fired), or `monitoring.webhook.test` |
| `subject` | the incident id (or `group:<id>` for a grouped event) |
| `data.account_id` | your account id |
| `data.object` | the incident. It includes `object_key` and `object_name` (the port or outlet, for collector checks; `null` otherwise), `suppressed`, `root_incident_id` and `root_device_id`. After a suppression ended it carries `unsuppressed: true` |
| `data.device` | the host: id, name, address, type, tags, and its containment path |
| `data.check` | the check: id, name, plugin, last output, runbook URL, and `thresholds` |
| `data.route` | the channel's route: id, name, `step` (the escalation step) and `labels` |
| `data.links.incident` | a link to the incident, when the service is configured with one |
| `data.actor` | who acknowledged, resolved or commented (`kind`, `user_id`, `external_id`); `null` for the system |

Every incident arrives as its own event unless the channel sets `group_by` (see "Grouping, suppression and reminders" below), in which case related incidents can arrive together as one event.

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

## Grouping, suppression and reminders

- **Grouping:** a channel with `group_by` receives one event for several incidents that share those fields (for example one message for a whole switch outage). That event has the same `type`, the subject `group:<id>`, `data.group` (`id`, `key`, `count`) and `data.objects` (a list of `{object, device, check}`), and `data.object`, `data.device` and `data.check` are `null`. A group of one arrives as a plain event. Handle both shapes if you use `group_by`; a channel without it only ever gets plain events.
- **Suppression:** an incident explained by an upstream host failure (the switch is down, so the servers behind it are too) is held back and not delivered. When the upstream incident resolves and the problem is still there, it is delivered as a normal `monitoring.incident.opened` with `data.object.unsuppressed: true`.
- **Reminders:** a channel with `repeat_interval_seconds` receives `monitoring.incident.renotify` for incidents that are still open and unacknowledged.
- **Escalation:** a channel with `steps` receives `monitoring.incident.escalated` when a later step fires, with `data.route.step` and the step's labels merged into `data.route.labels`.
- **Collectors:** each object of a collector check has its own incident; `data.object.object_key` names it. If the device stops reporting an object, its incident resolves with `resolved_by: "object-gone"`.

## Account limit events

When creating a host or a check brings the account to its limit, a channel receives `monitoring.tenant.device_limit_reached` or `monitoring.tenant.check_limit_reached`; the next create then fails with 409 `limit-reached`. They are not incidents: `subject` is `tenant:<id>`, `data.object_type` is `Monitoring\Tenants`, `data.object` is `{"tenant_id", "resource": "devices"|"checks", "limit", "count"}`, and `data.device` and `data.check` are `null`.

A channel gets them by default unless it filters on severity, host type, tags, sites or checks (a limit event has none of those). **Your receiver must ignore event types it does not know**, so new event types never break it: check `type` before reading `data.device` or `data.check`.
