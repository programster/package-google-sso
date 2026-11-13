# Setting up Google

- [What you need](#what-you-need)
- [Creating the client ID and secret](#creating-the-client-id-and-secret)
- [Redirect URIs](#redirect-uris)
- [Internal or external](#internal-or-external)
- [Testing or in production](#testing-or-in-production)
- [Keeping the secret safe](#keeping-the-secret-safe)

Before anyone can log in, Google needs to know about your site. This page walks through creating the client ID and
secret that `GoogleSsoClient` needs, in the Google Cloud console, and setting up the consent screen users see.

## What you need
Before users can log in with Google, Google needs to know about your app. This gives you two things to pass to
`GoogleSsoClient`:

- a **client ID**, which looks like `123456789012-abc123def456.apps.googleusercontent.com`, and is public (it is in the
  login URL),
- a **client secret**, which only your server should ever know.

You set these up in the [Google Cloud console](https://console.cloud.google.com/), which needs a Google account, and a
project to put the app in. Google moves its console around from time to time, so the names below may not match
exactly, but the steps stay the same.

## Creating the client ID and secret
1. In the Google Cloud console, create a project for your app, or pick an existing one.
2. Go to **Google Auth Platform** (in older versions of the console, **APIs & Services** and then **OAuth consent
   screen**). If it asks you to get started, give your app's name and a support email address, and choose who can
   log in (see [Internal or external](#internal-or-external)).
3. Under **Branding**, check the app name, logo and links. This is what users see on Google's consent screen.
4. Under **Clients** (or **APIs & Services** and then **Credentials**), create a client (an "OAuth client ID"), and
   choose **Web application** as the type.
5. Add your callback URL under **Authorised redirect URIs** (see [Redirect URIs](#redirect-uris)).
6. Create it, and copy the client ID and secret. Depending on the console, the secret may only be shown in full once,
   so keep it somewhere safe straight away.

You don't need to enable any APIs, or add any scopes. The package only asks for `openid`, `email` and `profile`, which
every app is allowed, and which don't need Google to verify your app.

## Redirect URIs
The redirect URI is where Google sends the user back to, and is the `googleCallbackUrl` you give `GoogleSsoClient`. It
must match one of the client's authorised redirect URIs **exactly**: the scheme, host, port and path, and even a
trailing slash. Otherwise, Google shows the user a `redirect_uri_mismatch` error, rather than sending them back.

- Register every one you use, e.g. both `https://example.com/login/callback` and
  `http://localhost:8080/login/callback` for development. A client can have several.
- They must use `https://`, except for `localhost`, which can use `http://` for development.
- They can't contain wildcards, or be a bare IP address (other than `127.0.0.1` for development).

It is often simplest to create a separate client for development, so that its secret isn't the one that's used in
production.

## Internal or external
Google asks who can log in to your app, as its "user type" or "audience":

- **Internal**: only users in your Google Workspace (or Cloud Identity) organisation. Google itself refuses anyone else,
  so this is the simplest way to make an app only for your organisation. It is only available if the Google Cloud
  project belongs to that organisation.
- **External**: anyone with a Google account, which is what a public site needs.

For an external app that should only let in your Workspace's users, check `getHostedDomain()` in your callback (see
[Security](security.md#only-letting-in-your-workspace)). Do this even for an internal app, if it would be a problem for
the check to be lost when someone changes the setting.

## Testing or in production
An external app starts in **Testing**, where only the Google accounts you add as test users can log in, and anyone
else is refused by Google. When you are ready for anyone to log in, publish the app (**Audience**, then **Publish
app**). As the package only asks for basic scopes, publishing doesn't need Google to review your app.

## Keeping the secret safe
- Keep the client secret out of your repository: load it from an environment variable or a secrets manager, e.g.
  `clientSecret: getenv("GOOGLE_CLIENT_SECRET")`.
- If the secret leaks, create a new one for the client in the console, and delete the old one.
- The client ID isn't a secret, as it is in the URL that every user is sent to.
