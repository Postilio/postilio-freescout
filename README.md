# Postilio for FreeScout

Two [FreeScout](https://freescout.net) modules for [Postilio](https://postilio.eu), European transactional email:

- **Postilio Dispatch - Send** (directory `PostilioSend`) sends the replies of a mailbox through the Postilio API (`POST
  /v1/emails`) instead of SMTP, with attachments, inline images and the `In-Reply-To`/`References` headers, so threads
  stay intact. Postilio reports back by webhook, and a reply that bounced shows as **Message not sent to customer** in
  the conversation, with Postilio's reason.
- **Postilio Dispatch - Inbound** (directory `PostilioInbound`) takes mail that Postilio receives for you (inbound mail)
  by webhook, and makes it a conversation, or a reply in its conversation, exactly as FreeScout does with mail it
  fetches by IMAP: customer, threading on `Message-ID`/`In-Reply-To`/`References`, attachments. No polling.

Each module works on its own and is switched on per mailbox. Use either or both.

> Version 0.1.0, tested with FreeScout 1.8.250 on PHP 8.2. Postilio's inbound mail is an invite-only preview; see
> [CHANGELOG.md](CHANGELOG.md).

## Install

1. Copy the `PostilioSend` and/or `PostilioInbound` directory into FreeScout's `Modules` directory, keeping the names:
   `Modules/PostilioSend`, `Modules/PostilioInbound`.
2. In FreeScout, open **Manage › Modules** and activate **Postilio Dispatch - Send** and/or **Postilio Dispatch -
   Inbound**. Activating the inbound module creates its table
   (`postilio_inbound_messages`).
3. Open a mailbox's settings: the menu now has **Postilio sending** and **Postilio inbound**. Only admins see them.

To update, replace the directories and run FreeScout's migrations (`php artisan migrate`, or deactivate and activate
the module); 0.1.1 has one for the inbound module.

The modules have no dependencies of their own; they use what FreeScout ships (Guzzle, SwiftMailer, its IMAP parser).

## Sending replies through Postilio (Postilio Dispatch - Send)

In Postilio:

1. Verify the domain of the mailbox's address (Domains in the portal). Postilio only sends from verified domains.
2. Create an API key of that project with the `emails:send` scope (**Keys & SMTP**). A test key (`pk_test_…`) goes
   through every check but never delivers: start with one.
3. Add a webhook endpoint with the URL that the **Postilio sending** page shows
   (`https://<your FreeScout>/postilio-send/webhook/<mailbox id>`) and the events `delivered`, `bounced`, `expired`,
   `suppressed` and `complained`. Copy its signing secret (`whsec_…`); Postilio shows it once.

In FreeScout, on **Postilio sending**: tick **Send through Postilio**, paste the API key and the signing secret, save.
The key and secret are stored encrypted and never shown again.

From then on every reply of the mailbox goes through Postilio, whatever the **Connection Settings** page says; that
page stays as it is, so unticking the box falls back to it. System mail (notifications to agents) never goes through
Postilio. Without a signing secret, replies are sent but FreeScout hears nothing about bounces.

FreeScout counts the sending side as configured from what that page says: PHP mail and Sendmail always, SMTP once it
has a server. Leave it on PHP mail (FreeScout's default) when Postilio sends everything. Whether the mailbox shows as
connected on the dashboard also depends on receiving: through IMAP or POP3, or through Postilio Dispatch - Inbound.

| Postilio event | In FreeScout |
|---|---|
| `bounced`, `expired`, `suppressed` | the reply shows **Message not sent to customer** with Postilio's reason; the outgoing email log has the entry |
| `delivered` | an entry in the outgoing email log (a bounce that came first stays standing) |
| `complained` | an entry in the outgoing email log |

A refused message (say `422 unverified_sender_domain`) or an unreachable API fails the send as SMTP would: the reply
shows as not sent, with the error, and FreeScout retries it. Each send carries an `Idempotency-Key`, so a retry is
never delivered twice.

## Receiving mail through Postilio (Postilio Dispatch - Inbound)

In Postilio (see its [Inbound mail](https://docs.postilio.eu/inbound) guide):

1. Add a receiving domain, such as `support.example.com` or `inbound.example.com`, and publish its MX record (and its
   TXT proof when Postilio asks for one).
2. Create an API key of that project with the `inbound:read` scope. The module downloads each message with it.
3. Add a webhook endpoint with the URL that the **Postilio inbound** page shows
   (`https://<your FreeScout>/postilio-inbound/webhook/<mailbox id>`) and the `inbound` event. Copy its signing secret.
4. Add a route for the address (for example `exact` `support`) with the action `webhook` and that endpoint.

In FreeScout, on **Postilio inbound**: tick **Receive through Postilio**, paste the API key and the signing secret, save.

Saving it turned on sets the mailbox's incoming protocol to **Postilio**, a protocol the module adds. FreeScout then shows
the mailbox as connected (dashboard, mailbox menu, **Connection Settings › Fetching Emails** says *Active*), and its
`fetch-emails` command, which only takes mailboxes on IMAP or POP3, leaves the mailbox alone: no IMAP connection to a
server that is not there. Saving it turned off puts back the protocol the mailbox had (its IMAP or POP3 settings are
kept), so fetching starts again if those are filled in.

The mailbox's own address is best the address that receives the mail (`support@support.example.com`); if customers
write to another address that forwards to Postilio, FreeScout still files the mail in this mailbox, because the webhook
URL names the mailbox.

### What the webhook answers

The module answers `2xx` only once the message is in FreeScout, or never will be; any other answer makes Postilio
deliver it again (for up to three days), and Postilio keeps the content until then. Nothing gets lost while FreeScout is
down or slow.

| Answer | When |
|---|---|
| `204` | imported; or a repeat of a message that was imported already (no second conversation); or Postilio deleted the content before it could be fetched (`410`, logged: retrying never helps); or an event other than `inbound.received.v1` |
| `401` | the signature does not match, or its timestamp is more than five minutes off |
| `404` | the mailbox does not exist, or Postilio inbound is off for it |
| `400` | the body is not a Postilio event, or the `inboundId` is not an id |
| `503` | Postilio could not be reached for the message, or the same message is being imported by another delivery right now |
| `500` | FreeScout could not import the message (the reason is in **Manage › Logs › Fetch errors**) |

Each message is claimed by its `inboundId` before it is imported, so two deliveries at once never import it twice. A
claim that is older than five minutes (an import that died halfway) is taken over by the next delivery. FreeScout's own
`Message-ID` check catches the same mail arriving twice through other paths.

## How it works

FreeScout has no hook to add a sending method to the **Connection Settings** page. The send module listens to the moment
FreeScout configures its mailer for a mailbox (`mail.reapply_mail_config`) and puts a SwiftMailer transport for the
Postilio API in, when that mailbox has Postilio on.

Postilio writes its own `Message-ID` (`<{email id without dashes}@{sender domain}>`). The send module keeps that id on the
reply's thread, so the customer's answer, which refers to it, lands in the right conversation through FreeScout's own
threading, and the status webhook finds the reply by its email id.

The inbound module downloads the message as it arrived (`GET /v1/inbound/messages/{id}/raw`) and hands it to the same code
FreeScout's `fetch-emails` command uses for a message from IMAP.

Webhooks are checked as Postilio documents them ([Standard Webhooks](https://www.standardwebhooks.com/)): HMAC-SHA256
over `{webhook-id}.{webhook-timestamp}.{body}`, compared in constant time, a timestamp at most five minutes off, and both
signatures accepted during a secret rotation. The webhook routes run without FreeScout's session and CSRF middleware;
the signature is what authenticates them.

## Limitations

- **The sending method is on its own page.** The Connection Settings page keeps showing PHP mail, Sendmail or SMTP.
- **Status for the main recipient only.** Postilio gives every recipient its own email id; the module keeps the one of
  the customer in `To`. Bounces of Cc and Bcc recipients are not shown.
- **A long `References` header is shortened** to the newest message ids that fit in 900 characters, Postilio's limit
  for a header. Threading only needs the newest ones.
- **A sender name loses `@`, `,`, `"`, `<` and `>`**, which Postilio does not accept in a name.
- **The sender of inbound mail is not checked against DMARC**, the same as with IMAP. Postilio's event has the result
  (`auth.dmarc`), but the module imports the raw message as it is.
- **Postilio's `Message-ID` format is not part of its documented API yet.** Should it change, customer replies to a
  reply sent through the API thread by `References` and FreeScout's marker in the quoted text only.
- **Nothing to save on Fetching Emails for a Postilio mailbox.** That page still has its Save button, and FreeScout
  wants IMAP or POP3 settings there; switching back is done on the **Postilio inbound** page.
- **One API key per mailbox and module.** Several mailboxes can use the same key.

## Development

```sh
tools/test.sh            # clones FreeScout (branch dist), starts MariaDB, runs the module tests; all in Docker
DOCKER_LABEL=team=me tools/test.sh   # the same, with a label on the containers and network it starts
```

The tests are FreeScout feature tests: they send a real reply with FreeScout's own job, post signed webhooks and import
real messages, against a mocked Postilio API.

## License

MIT, see [LICENSE](LICENSE). FreeScout itself is AGPL-3.0.
