<p align="center">
    <img src="https://github.com/LLoadout/assets/blob/master/LLoadout_microsoftgraph.png" width="500" title="LLoadout logo">
</p>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lloadout/microsoftgraph.svg?style=flat-square)](https://packagist.org/packages/lloadout/microsoftgraph)
[![Total Downloads](https://img.shields.io/packagist/dt/lloadout/microsoftgraph.svg?style=flat-square)](https://packagist.org/packages/lloadout/microsoftgraph)

# Laravel Microsoft graph

## Use case 
Laravel package for using Microsoft mail, OneDrive, Teams, Excel, Calendars and Contacts

This package makes a wrapper around the Microsoft Graph API.

1. It provides a [Mail](#mail-usage) driver for Microsoft mail.
2. It provides a storage driver for [OneDrive](#storage-usage).
3. It provides functionality to interact with Microsoft [Teams](#teams-usage).
4. It provides the possibility to work with [Excel](#excel-usage), making it possible to write and read Excel files.
5. It allows you to manage [calendar](#calendar-usage) events.
6. It allows you to manage [contacts](#contacts-usage).
7. It allows you to read and handle [mail](#reading-and-handling-mail).

You need to register an app in the Microsoft Azure Portal to use this package. Follow the steps in the Microsoft docs:
https://docs.microsoft.com/en-us/graph/auth-register-app-v2

## Installation

You can install the package via composer:

```bash
composer require delaneydev/microsoftgraph-365-mail
```

Add this to your .env file and fill it with the values you specified in Microsoft Azure Portal app registration.  
If you created a multi-tenant app in Azure AD than you don't put your tentant id into the `MS_TENANT_ID` variable but you set it to `common`.

```
MS_TENANT_ID=
MS_CLIENT_ID=
MS_CLIENT_SECRET=
MS_GRAPH_API_VERSION=v1.0
MS_REDIRECT_URL=https://your-url.com/microsoft/callback
MS_REDIRECT_AFTER_CALLBACK_URL=https://your-url.com/dashboard
```

# Microsoft 365 Mail Integration (Single User — OAuth2)
Microsoft is deprecating SMTP for Office 365. This package provides a secure, OAuth2-based solution using Microsoft Graph — ideal for sending from a single `noreply@yourdomain.com` address.
---
## 1. Publish Required Files
Before registering the service provider, publish the config, model, and migration:
```bash
php artisan vendor:publish --tag=microsoftgraph-config
php artisan vendor:publish --tag=microsoftgraph-model
php artisan vendor:publish --tag=microsoftgraph-migrations
php artisan migrate
```

## 2. Register OAuth Token Listener
Add this listener in your AppServiceProvider or EventServiceProvider
```
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
## 3. Connect to Microsoft
Visit the following URL (while logged in as the sending account):
yourdomain/microsoft/connect
This securely stores the token used for sending mail.
## 4. Enable Single-User Mode
Update your .env:
```
MICROSOFTGRAPH_SINGLE_USER=true
MICROSOFTGRAPH_ENABLE_CONNECT=false
```
This disables session-based tokens and always uses the most recent stored token.
## 5. Send Mail Like Normal
```
Mail::to('user@example.com')->send(new MyMailable());
```
Laravel will use the microsoftgraph mail driver under the hood.
⸻
Notes
• This is a fork of the lloadout/microsoftgraph package.
• We’ve added automatic token storage and single-user mode for simpler, secure no-reply use.
Let me know if you want this in a file (`README.md`, etc.) or published to a repo.

## Connect your account (scoped to authenticated user)

make sure to enable 
```
MICROSOFTGRAPH_ENABLE_OAUTH=true
```
and disable 
```
MICROSOFTGRAPH_SINGLE_USER=false
```

The package uses OAuth and provides two routes

The first redirects you to the consent screen of Microsoft

```
https://your-url.com/microsoft/connect
```

The second is the callback url you need to specify in Microsoft Azure Portal app registration as redirect uri

```
https://your-url.com/microsoft/callback
```

The callback will fire an MicrosoftGraphCallbackReceived event, you have to listen for this event in your EventServiceProvider and store the accessData to a session variable `microsoftgraph-access-data`.
You can add your token store logic in a listener for this event.

```
use App\Models\MicrosoftGraphAccessToken;
use LLoadout\Microsoftgraph\EventListeners\MicrosoftGraphCallbackReceived;
public function boot()
{
    Event::listen(function (MicrosoftGraphCallbackReceived $event) {
        session()->put('microsoftgraph-access-data', $event->accessData); 
    });
}
```

The package will search for a session variable name `microsoftgraph-access-data` for establishing the connection. So
please provide this variable with your accessData as value when logging in.
For example:  On login, you get your accesData from the database and store it into the session
variable `microsoftgraph-access-data`.

After the callback, the package will redirect you to the url you specified in the `MS_REDIRECT_AFTER_CALLBACK_URL` variable. If this variable is not set, the package will redirect to the root of your application.

## Mail usage

### Configuration

You have to provide this API permissions: `Mail.send`

Set the environment variable MAIL_MAILER in your .env file

```
MAIL_MAILER=microsoftgraph
```

note: make sure your from address is the address you gave the consent to

### Usage

```php
Mail::send(new YourMailable());

Mail::raw('The body of my first test message', function($message) {
    $message->to('john@doe.com', 'John Doe')->subject('A mail send via lloadout/microsoftgraph');
});
```

### Reading and handling mail

You have to provide this API permissions: `Mail.Read, Mail.ReadWrite, Mail.ReadBasic`


#### Available methods

```php
    getMailFolders(): array|GraphResponse|mixed
    getSubFolders(id): array|GraphResponse|mixed
    getMailMessagesFromFolder([folder: string = 'inbox'], [isRead: true = true], [skip: int = 0], [limit: int = 20]): array
    updateMessage(id, data): array|GraphResponse|mixed
    moveMessage(id, destinationId): array|GraphResponse|mixed
    getMessage(id): array|GraphResponse|mixed
    getMessageAttachements(id): array|GraphResponse|mixed
```

```php
    $mail = app(Mail::class);

    collect($mail->getMailFolders())->each(function($folder){
        echo $folder['displayName']."<br />";
    });

    //get all unread messages from inbox
    collect($mail->getMailMessagesFromFolder('inbox', isRead: false))->each(function($message) use ($mail){
        echo $message['subject']."<br />";
    });
        
```

## Storage usage

### Configuration

You have to provide this API permissions: `Files.ReadWrite.all`

add the onedrive root to your .env file:

```
MS_ONEDRIVE_ROOT="me/drive/root"
```

### Available methods

All methods from the Laravel Storage facade are available. https://laravel.com/docs/8.x/filesystem#configuration

### Usage

The package created a disk called `onedrive`. This means that you can use all the methods as described in the Laravel docs: https://laravel.com/docs/8.x/filesystem#configuration

```php
$disk = Storage::disk('onedrive');
#create a dir
$disk->makeDirectory('Test folder');
#storing files
$disk->put('Test folder/file1.txt','Content of file 1');
$disk->put('Test folder/file2.txt','Content of file 2');
#getting files
Storage::disk('onedrive')->get('Test folder/file1.txt');
```

## Teams usage

### Configuration

You have to provide this API permissions: `Chat.ReadWrite`

### Available methods

```php
    getJoinedTeams(): array|GraphResponse|mixed
    getChannels(team): array|GraphResponse|mixed
    getChats(): array|GraphResponse|mixed
    getChat(id): array|GraphResponse|mixed
    getMembersInChat(chat): array|GraphResponse|mixed
    send(teamOrChat, message): array|GraphResponse|mixed
```

### Usage

First instantiate the Teams class

```php
$teamsClass = new Teams();
```

Get all the teams you are a member of ( additional permissions needed: `Group.Read.All` )

```php
$joinedTeams = $teamsClass->getJoinedTeams();
```

Get alle the channels for a team ( additional permissions needed: `Group.Read.All` )

```php
$channels = $teamsClass->getChannels($team);
```

Get all the chats for a user ( additional permissions needed: `Chat.Read.All` )

```php
$chats = $teamsClass->getChats(); 

```
Get a chat by a given id ( additional permissions needed: `Chat.Read.All` )

```php
$chats = $teamsClass->getChat('your-chat-id'); 
```

Get all the members in a channel ( additional permissions needed: `ChannelMessage.Read.All` )

```php
$members = $teamsClass->getMembersInChat($chat));
```

Send a message to a channel ( additional permissions needed: `ChannelMessage.Send` )

```php 
$teamsClass->send($teamOrChat,'Hello world!');
```

## Excel usage

### Configuration

You have to provide this API permissions: `Files.ReadWrite.all`

### Available methods

```php
    loadFile(file): void
    loadFileById(fileId): void
    setCellValues(cellRange, values: array): void
    getCellValues(cellRange): array
    recalculate(): void
    createSession(fileId): string
```

### Usage

First instantiate the Excel class

```php
$excelClass = new Excel();
```

Load a file from OneDrive

```php
$excelClass->loadFile('Test folder/file1.xlsx');
```          

Load a file by its id

```php
$excelClass->loadFileById($fileId);
```

Set cell values of a range

```php
$values = ['B1' => null, 'B2' => '01.01.23', 'B3' => 3, 'B4' => '250', 'B5' => '120', 'B6' => '30 cm', 'B7' => null, 'B8' => null, 'B9' => null, 'B10' => null, 'B11' => null, 'B12' => 2];
$excelClass->setCellValues('B1:B12', $values);
$excelClass->getCellValues('H1:H20');
```

## Calendar usage

### Configuration

You have to provide this API permissions: `Calendars.ReadWrite`

### Available methods

```php
    getCalendars(): array
    getCalendarEvents(calendar: Calendar): array
    saveEventToCalendar(calendar: Calendar, event: Event): GraphResponse|mixed
    makeEvent(starttime: string, endtime: string, timezone: string, subject: string, body: string, [attendees: array = [...]], [isOnlineMeeting: bool = false]): Event
```

### Usage

First instantiate the Calendar class

```php
$calendarClass = new Calendar();
```

Get all the calendars

```php
$calendars = $calendarClass->getCalendars();
``` 

Get all the events for a calendar

```php
$events = $calendarClass->getCalendarEvents($calendar);
```

Save an event to a calendar, the event object is a MicrosoftGraphEvent object
We made a helper function to create an event
object `Calendar::makeEvent(string $starttime, string $endtime, string $timezone, string $subject, string $body, array $attendees = [], bool $isOnlineMeeting = false)`

```php
$calendarClass->saveEvent($calendar, $event);
```

## Contacts usage

### Configuration

You have to provide this API permissions: `Contacts.ReadWrite`


### Available methods

```php
    getContacts(): array
```

### Usage

First instantiate the Contacts class

```php
$contactsClass = new Contacts();
```

Get all the contacts

```php
$contacts = $contactsClass->getContacts();
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Dieter Coopman](https://github.com/LLoadout)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
