# Ellipsis — switch on the live backend

Do this once. Until you do, the app and admin keep working in on-device demo mode.

## 1. Create your private settings file
hPanel → **Files → File Manager**. Go **up one level** from `public_html` (to `domains/ellipsismusic.net/`).
Create a folder `ellipsis-data`, and inside it a file `config.local.php`:

```php
<?php
return [
  'secret'       => 'paste-64+-random-characters',
  'setup_key'    => 'paste-another-random-string',
  'admin_emails' => ['you@yourdomain.com'],          // these emails become admins when they sign up

  'site_url'  => 'https://ellipsismusic.net',
  'mail_from' => 'Ellipsis <hello@ellipsismusic.net>', // create this mailbox in hPanel → Emails

  'stripe_secret'         => '',   // sk_test_... then sk_live_...
  'stripe_webhook_secret' => '',   // whsec_...
  'stripe_price_premium'  => '',   // price_... ($5.99/mo)
  'stripe_price_industry' => '',   // price_... ($49/mo)
];
```
Never commit this file to GitHub.

## 2. Create the database
Open `https://ellipsismusic.net/api/setup?key=YOUR_SETUP_KEY` once. You should see `"ok": true`.
Check `https://ellipsismusic.net/api/v2/config` → `{"ok":true,...}`.

## 3. Become an admin
Open `/app/`, **Create account** with an email listed in `admin_emails`. Then open `/admin/` — it now shows
"Connected to the live server" — and sign in with that email and password.
Give teammates roles from Admin → Users (role changes are recorded in the audit log).

## 4. Payments (Stripe)
1. dashboard.stripe.com → Product catalog: add **Ellipsis Premium** $5.99/mo and **Ellipsis Industry** $49/mo. Copy each **price_…** id.
2. Developers → API keys → copy the **secret key**.
3. Developers → Webhooks → Add endpoint `https://ellipsismusic.net/api/v2/billing/webhook`. Select events:
   `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.paused`, `customer.subscription.trial_will_end`, `invoice.paid`, `invoice.payment_failed`. Copy the **whsec_…**.
4. Settings → Billing → Customer portal → turn on.
5. Paste all four values into `config.local.php`. Test with card 4242 4242 4242 4242, then switch Stripe to Live and swap the keys.

## 5. What turns on
- Real accounts, sign-in on any device, and your work synced to the server
- Suspend / ban / verify / plan changes in Admin take effect in the app
- Chat holds go to Admin → Moderation; blocked terms added there apply in every chat
- Live numbers at the top of Admin → Overview (users, DAU/MAU, signups, releases, Spike Backs, signings, MRR)
- Email + in-app notifications (referrals, verification, billing)
- Share links `/s/?id=…` with previews, and invite links that reward a month of Premium

## Still on you
- Register a DMCA agent (copyright.gov/dmca-directory) and put the details in `legal/dmca.html`
- Join a PRO / CMO and a distributor before paying royalties at scale; release codes start with QZ-ELP and are placeholders until you get a real ISRC registrant code
- iOS / Android store builds must use Apple / Google in-app purchase instead of Stripe
