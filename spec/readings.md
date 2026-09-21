# Readings — the canonical store

What every framework package keeps, and the rule it keeps it for.

## The rule

**One reading per subject and model.** A second read of the same pair is
served from the store and never reaches the gateway. That keeps the cost of a
re-run at zero and the analysis stable under it. Reading again is explicit
(`again: true`) — after the questions changed, never the default.

## The table

| Column | Type | What it holds |
|---|---|---|
| `id` | uuid | — |
| `subject_type` | string(64) | the caller's own type: `ticket`, `document`, `call` |
| `subject_id` | string(191) | the caller's own identifier |
| `model` | string(64) | the model **asked for** — the pinning key |
| `served_model` | string(64) | the model the gateway reported serving |
| `answers` | json | the answers **in wire shape**, as served |
| `usage` | json | `{input_tokens, output_tokens}` |
| `read_at` | timestamp | — |

**Unique on `(subject_type, subject_id, model)`.** The index is the rule.

`answers` is kept as served, not parsed: a store that kept the parsed form
would freeze one SDK's reading of it, and a later SDK could not re-read an old
row. A kind with no typed reading carries its name alongside as `_kind`.

The state that was sent is **not** kept. It is the caller's data; the store
keeps the decision.

## The event

When a reading is recorded, the framework package fires an event carrying the
subject, the model asked and served, the answers in wire shape, the route per
question, and `read_at`. Never the state. Two channels: the subject type, for
lists; the subject, for the screen showing it.

## The ports

The core SDK ships the contracts; a framework package implements them:

- `ReadingStore` — `find(subject, model)`, `save(subject, model, reading)`, `forget(subject, model)`
- `ReadingBroadcast` — `recorded(stored)`
- `Reader` — `read(subject, state, questions, model?, again?)`: the behaviour, once

| Framework | Package | Status |
|---|---|---|
| Laravel | `essabu/toli-laravel` | shipped — migration, Eloquent store, broadcast event |
| Django, NestJS | — | the ports exist in the core; a package is one store, one event, one migration |
