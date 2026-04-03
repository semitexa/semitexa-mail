# Semitexa Mail

Outbound email transport with SMTP, queue-safe delivery, Twig rendering, and storage-backed attachments.

## Purpose

Handles email composition and delivery. Supports multiple transport backends, MIME message construction with attachments from the storage layer, and Twig-based template rendering for rich email content.

## Role in Semitexa

Depends on `semitexa/core`, `semitexa/orm`, `semitexa/ssr`, and `semitexa/storage`. Uses ORM for queue-safe persistence, SSR for Twig template rendering, and Storage for resolving attachment references to file content.

## Key Features

- `SmtpMailTransport` with TLS and authentication
- `FakeMailTransport` and `NullMailTransport` for testing
- `MailTransportRegistry` for multi-transport routing
- `MimeBuilder` for standards-compliant MIME construction
- Storage-backed attachments via `semitexa/storage`
- Queue-safe message delivery
