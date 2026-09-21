# Changelog — essabu/toli (PHP)

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-09-21

Contract version 2.

### Added

- `Toli::kinds()` reads the gateway's catalogue of question kinds, grouped by
  category, with the fields each kind requires and where its certainty lives.
- `Question\Generic` asks any kind the catalogue lists that has no typed
  helper yet — the fields travel exactly as given, and the gateway is what
  validates them.
- An answer of a shape this SDK does not know is returned as served: certainty
  from `confidence` when the engine gives one, and the route **ESCALATE** when
  it does not. It used to be a protocol error, which made a release of this SDK
  the thing standing between a customer and a kind the gateway already served.
- The readings contract: `Readings\Reader` ("read once, keep it, tell the
  room"), `ReadingStore`, `ReadingBroadcast`, `Subject`. One reading per
  subject and the model **asked for**, kept in wire shape so a later SDK can
  re-read an old row. The state that was sent is never kept and never
  broadcast.
- `Reading::toArray()` and `Reading::kinds()`, for a store to keep and re-read
  a reading; `Toli::defaultModel()`.

### Changed

- `Reading::value()` may now return an array or null, for a kind with no typed
  reading. The three typed helpers — choice, score, noul — are unchanged.
- The `Transport` interface gained `kindsUrl()`; the provider-direct transport
  has none, because the catalogue is the gateway's contract.

## [0.1.0]

First release: the three kinds, the three-route rule, dated thresholds, the
gateway transport, retries with the server's `retry-after` honoured.
