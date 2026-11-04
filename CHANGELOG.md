# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Transaction registration, status (with settlement status and refunds), confirmation, cancellation and refunds of the PayPo REST API v3.
- OAuth2 client credentials token cached per merchant account, refreshed once after HTTP 401.
- Notification route with X-PayPo-Signature verification and events: `TransactionStatusChanged`, `TransactionAccepted`, `TransactionCompleted`, `TransactionRejected`, `TransactionCanceled`, `SettlementReported`.
- Named connections, runtime credentials with `using()` and a connection resolver for tenant notifications.
- Read retries on connection errors and HTTP 5xx; explicit rate limit reporting.
