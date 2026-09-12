# Changelog

All notable changes to the Payzum payment module for Zen Cart are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-09-03

First release with a declared version. Everything below shipped today.

### ⚠️ Action required
- **New setting: "Set Failed Order Status"** (Modules → Payment → Payzum). Zen Cart ships no
  "Cancelled" status, so this one cannot be guessed: add a status under Localization → Orders Status
  and select it here. Until you do, an expired or failed invoice left the order sitting in the
  status it was created with — indistinguishable from one still waiting to be paid, which is how
  unpaid goods get shipped. Left unset, the module looks for a status named Cancelled or Failed and,
  failing that, leaves the order where it is and writes a warning into its history.

### Fixed
- **Duplicate detection could silently swallow a real payment.** The check matched the event id with
  a SQL `LIKE` without escaping wildcards, and `_` matches any single character — so an unrelated
  event could be mistaken for a repeat, the handler answered "duplicate", and the payment was never
  credited, with nothing written to the log. The match is now literal, and near-misses are logged.
- Expired and failed invoices now move the order to the cancelled status instead of rewriting
  whatever status it was in.
- The IPN verifies the amount and currency paid against the order before crediting it; an amount
  that cannot be read counts as a mismatch.
- Deliveries for the same order are serialised with a named database lock. (A row lock would have
  been a fake fix here: Zen Cart's tables are MyISAM, where row locking is a silent no-op.)
- Lock names are now scoped to the shop's database and table prefix. They are global to the MySQL
  server, so two Zen Cart installs on one host both had an order 42 and made each other's IPN answer
  `503` on deliveries that were never going to get through.
