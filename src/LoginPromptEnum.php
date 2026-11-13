<?php

declare(strict_types = 1);

namespace Programster\GoogleSso;

/**
 * What Google should show the user when they go to log in (Google's "prompt" parameter).
 * https://developers.google.com/identity/protocols/oauth2/web-server#httprest_1
 */
enum LoginPromptEnum : string
{
    # Let Google decide: it skips straight back to your site if the user is logged in to one Google account and has
    # agreed before. Nothing is added to the login URL.
    case DEFAULT = "";

    # Always ask the user which Google account to use, even if they're only logged in to one, so that someone with
    # several accounts (e.g. personal and work) can't end up logged in with the wrong one.
    case SELECT_ACCOUNT = "select_account";

    # Always show the consent screen, even if the user has agreed before.
    case CONSENT = "consent";

    # Always ask which account to use, and then always show the consent screen.
    case SELECT_ACCOUNT_AND_CONSENT = "select_account consent";

    # Show nothing at all. If the user would have to log in, choose an account or agree to anything, Google sends them
    # back with an error instead (e.g. "login_required"), which the callback throws as an ExceptionGoogleLoginError,
    # whose isInteractionRequired() is true. For checking whether a user can be logged in without them noticing.
    case NONE = "none";
}
