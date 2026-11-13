# Setting up email (Gmail SMTP)

Marcomedia POS sends three kinds of email: a link to set a new password ("Forgot your password?"),
a sign-in link for an owner who lost the phone with the authenticator app, and the link that confirms
an email address in Profile. **Until a mailer is set up, the app hides those links instead of failing**
(`App\Support\EmailRecovery`). These steps set up Gmail on this PC and on the live site.

## 1. Make a Gmail App Password

Gmail doesn't let apps log in with the account's normal password. It gives each app its own one.

1. Use a Gmail account that belongs to the shop (for example a new `marcomedia.tangub@gmail.com`),
   not someone's personal account: password-reset emails will come from it.
2. Turn on **2-Step Verification** for that Google account: [myaccount.google.com](https://myaccount.google.com)
   → **Security** → **2-Step Verification**. App Passwords only exist while it's on.
3. Open [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords), type the name
   `Marcomedia POS`, and press **Create**.
4. Google shows a 16-letter password once, in four groups (`abcd efgh ijkl mnop`). Copy it **without
   the spaces**. You can't see it again; if it's lost, delete it on the same page and make a new one.

If that page says the setting isn't available, 2-Step Verification is still off, or it's a work or
school account whose administrator blocks App Passwords.

## 2. On this PC: `.env`

Open `.env` in the project folder and change the `MAIL_` lines to:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=marcomedia.tangub@gmail.com
MAIL_PASSWORD=abcdefghijklmnop
MAIL_FROM_ADDRESS=marcomedia.tangub@gmail.com
MAIL_FROM_NAME="Marcomedia POS"
```

- Port 587 switches to encryption by itself (STARTTLS). Port 465 also works: it's encrypted from the
  start. `MAIL_ENCRYPTION` isn't read by this version of Laravel, so it can stay or go.
- `MAIL_FROM_ADDRESS` must be the same Gmail address: Gmail only sends as the account that logs in.
  (Left out, it now defaults to `MAIL_USERNAME`.)
- `.env` is never committed, so the App Password stays on this PC.

Then restart the app: close the `start-local.bat` window and run it again. If `php artisan config:cache`
was ever run here, run `C:\php84\php.exe artisan config:clear` first.

## 3. Test it on the PC

In the project folder (put your own address in place of `you@example.com`):

```powershell
C:\php84\php.exe artisan tinker --execute="Mail::raw('Test from Marcomedia POS', fn (`$m) => `$m->to('you@example.com')->subject('Email works'));"
```

No output means it was sent. Check the inbox (and Spam). If it fails:

| Error says | What's wrong |
|---|---|
| `535 5.7.8 Username and Password not accepted` | The App Password is wrong, or the normal Gmail password was used. Make a new App Password. |
| `Connection could not be established` / `timed out` | The network blocks port 587. Try `MAIL_PORT=465`. |
| `Expected response code 250 but got 530` | `MAIL_USERNAME` or `MAIL_PASSWORD` is empty: check `.env` and restart the app. |

## 4. On the live site (Vercel)

Vercel doesn't read `.env`. In the Vercel dashboard open the project **marcomedia-tangub** →
**Settings** → **Environment Variables**, and add each of these for **Production**:

| Name | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp.gmail.com` |
| `MAIL_PORT` | `587` |
| `MAIL_USERNAME` | the Gmail address |
| `MAIL_PASSWORD` | the App Password (tick **Sensitive**) |
| `MAIL_FROM_NAME` | `Marcomedia POS` |

Don't add `APP_NAME`: the login cookie's name comes from it, so adding it logs everyone out once.

New settings only reach new deployments: open **Deployments**, the newest one → **⋯** → **Redeploy**.
After that, the login page shows **Forgot your password?**

## 5. Each person confirms their email

A reset link only goes to an email its owner has confirmed, because the link opens the account. The
one exception is an account with two-step verification on (every owner): its link still goes out,
because setting the new password also takes the code from the authenticator app. The lost-phone
sign-in link always needs a confirmed email.

1. The owner checks in **User Management** that each account's email is a real inbox that person can
   open (the old test addresses aren't).
2. Each person logs in, opens **Profile**, and presses **Send confirmation email**, then opens the email
   and clicks its link. User Management then shows the email as confirmed.

Changing an email later clears its confirmation: confirm the new one the same way.

## 6. Try it

1. Log out. Press **Forgot your password?** on the login page.
2. Type the confirmed email and press **Email me a link**. The page says the same thing for every
   address, so it never tells anyone which emails have accounts.
3. The email **Set a new password for Marcomedia POS** arrives. Its link works once, for 60 minutes.
4. Type the new password twice (at least 8 characters). An owner also types the 6-digit code from the
   authenticator app, or a recovery code.
5. **Save and log in.** Every other device that account was logged in on is logged out.

Someone who can't use email (no confirmed address, or no inbox) asks the owner to set a new password
in **User Management**. That also logs them out everywhere else.

## Limits and safety

- A free Gmail account sends about 500 emails a day: far more than password resets need.
- The App Password only lives in `.env` on this PC and in Vercel's settings. If it may have leaked,
  delete it at [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) and make
  a new one; nothing else changes.
