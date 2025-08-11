<p align="center">
  <img src="https://github.com/LLoadout/assets/blob/master/LLoadout_microsoftgraph.png" width="500" title="LLoadout logo">
</p>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/delaneydev/microsoftgraph-365-mail.svg?style=flat-square)](https://packagist.org/packages/delaneydev/microsoftgraph-365-mail)
[![Total Downloads](https://img.shields.io/packagist/dt/delaneydev/microsoftgraph-365-mail.svg?style=flat-square)](https://packagist.org/packages/delaneydev/microsoftgraph-365-mail)

# Laravel Microsoft Graph (DelaneyDev Fork) Native Laravel Microsoft Mail driver 

**What this package does**

This package is a **Laravel mail driver for Microsoft 365** that sends email via **Microsoft Graph (OAuth2)**.  
It’s perfect for:

- **No-reply** and **single mailbox** sending (e.g., `noreply@yourdomain.com`) — **Single-User Mode** ✅
- Classic **per-user** OAuth sign-in (session-scoped tokens) — **Session Mode** ✅

> This is a **fork** of `lloadout/microsoftgraph`.  
> **DelaneyDev** adds **Single-User Mode** (database-stored token; no session required) while still supporting the original **Session Mode**.

---

## Quick Start (TL;DR)

1) Install & Publish + migrate
```bash
composer require delaneydev/microsoftgraph-365-mail

php artisan vendor:publish --tag=microsoftgraph-config
php artisan vendor:publish --tag=microsoftgraph-model
php artisan vendor:publish --tag=microsoftgraph-migrations
php artisan migrate
```

3. Create Azure App (delegated **Mail.Send**) and put IDs/secrets in `.env` (see below)

4. Connect once at `/microsoft/connect` while signed in as the **sending mailbox** (e.g., `noreply@...`)

5. Use like normal:

```php
Mail::to('user@example.com')->send(new MyMailable());
```

## 1) Azure App — Create and Grant Delegated Mail.Send (don’t change anything else)

1. **Azure Portal** → **Azure Active Directory** → **App registrations** → **New registration**
2. **Name:** `Laravel Microsoft Graph Mailer`
3. **Supported account types:** your tenant only (or multi-tenant if required)
4. **Redirect URI (Web):**

   ```
   https://your-domain.com/microsoft/callback
   ```
5. **Register**

**Copy credentials into `.env`:**

* From **Overview**:

  * Application (client) ID → `MS_CLIENT_ID`
  * Directory (tenant) ID → `MS_TENANT_ID` (or `common` if multi-tenant)
* From **Certificates & secrets**:

  * **New client secret** → copy **Value** → `MS_CLIENT_SECRET`

**Grant permissions:**

* **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions** → add:

  ```
  Mail.Send
  ```
* Click **Add permissions** and **Grant admin consent**.

---

## 2) Configure `.env`

> **Set these for both modes.** (Choose mode in the next section.)

```dotenv
MS_TENANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx   # or "common" if multi-tenant
MS_CLIENT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MS_CLIENT_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
MS_GRAPH_API_VERSION=v1.0
MS_REDIRECT_URL=https://your-domain.com/microsoft/callback
MS_REDIRECT_AFTER_CALLBACK_URL=https://your-domain.com/dashboard

MAIL_MAILER=microsoftgraph
```

---

## 3) Choose a Mode

### A) **Single-User Mode (Recommended for no-reply & single mailbox)**

**What it is:** Your app always sends as one mailbox (e.g., `noreply@yourdomain.com`).
Tokens are **stored in the database** (encrypted), not in the session. No per-request user login required.

**Enable in `.env`:**

```dotenv
MICROSOFTGRAPH_SINGLE_USER=true
MICROSOFTGRAPH_ENABLE_CONNECT=false
```

**Listen for the OAuth callback and store tokens (once):**
Add to `AppServiceProvider` or `EventServiceProvider`:

```php
use Illuminate\Support\Facades\Event;
use LLoadout\Microsoftgraph\Events\MicrosoftGraphCallbackReceived;
use App\Models\MicrosoftGraphAccessToken;
use Illuminate\Support\Facades\Crypt;
use Carbon\Carbon;

Event::listen(MicrosoftGraphCallbackReceived::class, function ($event) {
    $accessData = (array) Crypt::decrypt($event->accessData);
    MicrosoftGraphAccessToken::create([
        'access_token' => Crypt::encrypt($accessData['access_token']),
        'refresh_token' => Crypt::encrypt($accessData['refresh_token']),
        'expires_at' => Carbon::createFromTimestamp((int) $accessData['expires_on']),
    ]);
});
```

**Connect once as the sending mailbox:**

```
https://your-domain.com/microsoft/connect
```

Accept consent. The token is saved and will be reused automatically.

> After this, you can send mail without any user sessions:

```php
Mail::to('user@example.com')->send(new \App\Mail\MyMailable());
```

---

### B) **Session Mode (Upstream behaviour; per-user tokens)**

**What it is:** Each signed-in user authenticates with Microsoft; tokens are stored in **session** and used per request.

**Enable in `.env`:**

```dotenv
MICROSOFTGRAPH_ENABLE_OAUTH=true
MICROSOFTGRAPH_SINGLE_USER=false
```

**Routes:**

* Consent redirect: `https://your-domain.com/microsoft/connect`
* Callback (must match Azure Redirect URI): `https://your-domain.com/microsoft/callback`

**Put access data in the session after callback** (example listener):

```php
use App\Models\MicrosoftGraphAccessToken;
use LLoadout\Microsoftgraph\EventListeners\MicrosoftGraphCallbackReceived;

public function boot()
{
    Event::listen(function (MicrosoftGraphCallbackReceived $event) {
        session()->put('microsoftgraph-access-data', $event->accessData);
    });
}
```

> The package looks for `session('microsoftgraph-access-data')` when connecting.

---

## 4) Sending Email (same in both modes)

**Required API permission:** `Mail.Send` (Delegated)

**`.env`:**

```dotenv
MAIL_MAILER=microsoftgraph
# Ensure your "from" address is the account that granted consent
```

**Examples:**

```php
// Mailable
Mail::to('user@example.com')->send(new \App\Mail\MyMailable());

// Quick test
Mail::raw('Hello from Microsoft Graph', function ($message) {
    $message->to('john@doe.com')->subject('Graph Test');
});
```

---

## 5) Package Capabilities (Beyond Mail)

This package wraps Microsoft Graph endpoints for:

* **OneDrive Storage** (Laravel Storage driver)
* **Teams** (send messages, list teams/channels)
* **Excel** (read/write cell ranges)
* **Calendars**
* **Contacts**
* **Reading & handling Mail**

> You only need **Mail.Send** to send mail. Other features require additional permissions (see below).

---

## Storage usage (OneDrive)

**Permission:** `Files.ReadWrite.All`
**`.env`:**

```dotenv
MS_ONEDRIVE_ROOT="me/drive/root"
```

Use the `onedrive` disk like any Laravel filesystem disk:

```php
$disk = Storage::disk('onedrive');
$disk->makeDirectory('Test folder');
$disk->put('Test folder/file1.txt','Content');
$contents = Storage::disk('onedrive')->get('Test folder/file1.txt');
```

---

## Teams usage

**Permission:** `Chat.ReadWrite`
*(Extra examples require `Group.Read.All`, `Chat.Read.All`, `ChannelMessage.Read.All`, `ChannelMessage.Send`)*

```php
$teams = new \LLoadout\Microsoftgraph\Teams();
$joinedTeams = $teams->getJoinedTeams();
$channels = $teams->getChannels($team);
$chats = $teams->getChats();
$chat = $teams->getChat('your-chat-id');
$members = $teams->getMembersInChat($chat);
$teams->send($teamOrChat, 'Hello world!');
```

---

## Excel usage

**Permission:** `Files.ReadWrite.All`

```php
$excel = new \LLoadout\Microsoftgraph\Excel();

$excel->loadFile('Test folder/file1.xlsx');        // or ->loadFileById($fileId)
$values = ['B1'=>null,'B2'=>'01.01.23','B3'=>3,'B4'=>'250','B5'=>'120','B6'=>'30 cm'];
$excel->setCellValues('B1:B12', $values);
$result = $excel->getCellValues('H1:H20');
```

---

## Calendar usage

**Permission:** `Calendars.ReadWrite`

```php
$calendar = new \LLoadout\Microsoftgraph\Calendar();
$calendars = $calendar->getCalendars();

$event = $calendar->makeEvent(
  starttime: '2025-08-11T09:00:00',
  endtime:   '2025-08-11T10:00:00',
  timezone:  'Europe/London',
  subject:   'Standup',
  body:      'Daily sync',
  attendees: [['email'=>'teammate@domain.com','name'=>'Teammate']],
  isOnlineMeeting: true
);

$calendar->saveEvent($calendarEntity, $event);
```

---

## Contacts usage

**Permission:** `Contacts.ReadWrite`

```php
$contacts = new \LLoadout\Microsoftgraph\Contacts();
$list = $contacts->getContacts();
```

---

## Reading and handling mail

**Permissions:** `Mail.Read, Mail.ReadWrite, Mail.ReadBasic`

```php
$mail = app(\LLoadout\Microsoftgraph\Mail::class);

collect($mail->getMailFolders())->each(fn($f) => print $f['displayName']."\n");

$unread = $mail->getMailMessagesFromFolder('inbox', isRead: false);
collect($unread)->each(fn($m) => print $m['subject']."\n");
```

**Available methods**

```php
getMailFolders(): array|GraphResponse|mixed
getSubFolders($id): array|GraphResponse|mixed
getMailMessagesFromFolder($folder='inbox', $isRead=true, $skip=0, $limit=20): array
updateMessage($id, $data): array|GraphResponse|mixed
moveMessage($id, $destinationId): array|GraphResponse|mixed
getMessage($id): array|GraphResponse|mixed
getMessageAttachements($id): array|GraphResponse|mixed
```

---

## Differences: DelaneyDev Fork vs Upstream

| Topic                        | **DelaneyDev (this fork)**                               | **Upstream (lloadout/microsoftgraph)**       |
| ---------------------------- | -------------------------------------------------------- | -------------------------------------------- |
| Primary goal                 | **Single-User Mode** for no-reply/single mailbox sending | Session-based per-user OAuth                 |
| Token storage                | **Database** (encrypted) via model/migration             | **Session** (`microsoftgraph-access-data`)   |
| Sessions needed to send mail | **No** (after initial connect)                           | **Yes** (user must have session token)       |
| Best for                     | System emails, cron, queues, workers                     | User-initiated emails on behalf of each user |
| Session Mode                 | Still supported                                          | N/A (original behavior)                      |

You can enable **either** mode via `.env`.

---

## Troubleshooting

* **“The MAC is invalid”**
  Run the reset one-liner to clear caches and restart workers:

  ```bash
  php artisan down && php artisan cache:clear && php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan event:clear && php artisan clear-compiled && php artisan queue:restart && php artisan up
  ```

  Also ensure:

  * All servers share the same `APP_KEY`
  * Queue workers restarted after changing `.env`
  * Clear app cache/Redis if tokens were encrypted with an old key

* **403 / insufficient permissions**
  Confirm **Mail.Send (Delegated)** is added and **admin consent** granted in Azure.

* **Redirect URI mismatch**
  The Azure **Redirect URI** must exactly match `MS_REDIRECT_URL`.

---

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security Vulnerabilities

Please review [our security policy](../../security/policy).

## Credits

* Fork base: [Dieter Coopman](https://github.com/LLoadout)
* DelaneyDev additions: Single-User Mode + docs
* [All Contributors](../../contributors)

## License

MIT — see [LICENSE](LICENSE.md).

```

Want me to drop this straight into a `README.md` file for you?
```
