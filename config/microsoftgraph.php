<?php
return [
   /*
   |--------------------------------------------------------------------------
   | Microsoft Graph OAuth2 Credentials
   |--------------------------------------------------------------------------
   |
   | These credentials are used to authenticate with the Microsoft Graph API
   | using OAuth2. You must configure these in your Azure AD App Registration.
   |
   */
   'tenant' => env('MS_TENANT_ID'),
   'client_id' => env('MS_CLIENT_ID'),
   'client_secret' => env('MS_CLIENT_SECRET'),
   'redirect' => env('MS_REDIRECT_URL'),
   /*
   |--------------------------------------------------------------------------
   | Post-OAuth Redirect
   |--------------------------------------------------------------------------
   |
   | After a user has authenticated with Microsoft, they will be redirected
   | to this URL. This can be a dashboard or confirmation page.
   |
   */
   'redirect_after_callback' => env('MS_REDIRECT_AFTER_CALLBACK_URL', '/'),
   /*
   |--------------------------------------------------------------------------
   | OneDrive Root Path
   |--------------------------------------------------------------------------
   |
   | Used by the package when accessing OneDrive storage. You can define
   | the default root directory here (optional).
   |
   */
   'onedrive_root' => env('MS_ONEDRIVE_ROOT', '/'),
   /*
   |--------------------------------------------------------------------------
   | Single User Mode (No-Reply Emails)
   |--------------------------------------------------------------------------
   |
   | When true, this bypasses session-based tokens and always uses the latest
   | stored access token from the database. This is ideal for no-reply-style
   | email sending with a single Microsoft 365 account.
   |
   | IMPORTANT:
   | After completing the initial OAuth flow at /microsoft/connect, you should
   | disable the OAuth routes to prevent further access.
   |
   */
   'single_user' => env('MICROSOFTGRAPH_SINGLE_USER', false),
   /*
   |--------------------------------------------------------------------------
   | Enable OAuth Routes
   |--------------------------------------------------------------------------
   |
   | Controls whether the /microsoft/connect and /microsoft/callback routes
   | are enabled. These are used during initial setup to authenticate the
   | Microsoft 365 user and store tokens in your database.
   |
   | Set this to false after setup for security.
   |
   */
   'enable_oauth_routes' => env('MICROSOFTGRAPH_ENABLE_OAUTH', false),
];