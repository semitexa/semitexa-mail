# Semitexa Mail

Outbound email transport with SMTP, queue-safe delivery, Twig rendering, and storage-backed attachments.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

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

## Worker

Queued mail is delivered by a dedicated worker that does not start with the web server:

```bash
bin/semitexa mail:work [transport] [queue]   # transport defaults from EVENTS_ASYNC, queue defaults to "mail"
```
