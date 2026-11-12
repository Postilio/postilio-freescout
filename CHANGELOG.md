# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

## [0.1.0]

First version, tested with FreeScout 1.8.250.

### Added

- Postilio Dispatch - Send: per mailbox, send replies through the Postilio API (`POST /v1/emails`) with attachments,
  inline images, `In-Reply-To` and `References`, and an `Idempotency-Key` so a retry is never delivered twice. A refused
  or failed send shows as not sent in FreeScout and is retried, as with SMTP.
- Postilio Dispatch - Send: a webhook for Postilio's email events; a bounced, expired or suppressed reply shows as
  **Message not sent to customer** with Postilio's reason, and every event goes to the outgoing email log.
- Postilio Dispatch - Send: Postilio's `Message-ID` is kept on the reply, so the customer's answer lands in the right
  conversation.
- Postilio Dispatch - Inbound: a webhook for Postilio's inbound mail that imports each message as FreeScout's IMAP
  fetching does: conversation or reply, customer, attachments, threading on `Message-ID`, `In-Reply-To` and
  `References`.
- Postilio Dispatch - Inbound: every message is imported once, also when Postilio delivers it again or twice at once;
  the answer makes Postilio retry when FreeScout or the Postilio API was not available, so nothing is lost.
- Both: a settings page per mailbox (admins only) with the webhook URL to register; the API key and signing secret are
  stored encrypted. Webhooks are verified with the Standard Webhooks scheme, in constant time, with a five-minute window
  and secret rotation.
