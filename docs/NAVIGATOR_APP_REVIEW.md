# Navigator App Review access

Apple and Google test every app before it goes on sale. Navigator signs drivers in with a
code sent by text message, and app store reviewers can't receive one. Apple often rejects an
app its reviewers can't sign in to.

App Review access gives reviewers a test phone number and a sign-in code that you enter in
App Store Connect and Google Play Console. Nobody is sent a text message.

## Turn it on

1. Open **Fleet-Ops → Settings → Navigator App** and find **App Review**.
2. Choose **Turn on**. A six-digit sign-in code is created.
3. Copy the code straight away. It is only shown once.
4. Enter the phone number and the code:
   - **App Store Connect:** your app's version page, under **App Review Information →
     Sign-in required**. Enter the phone number as the user name and the code as the
     password.
   - **Google Play Console:** **App content → App access**, as instructions with the phone
     number and the code.

The phone number is **+1 202 555 0144**. It belongs to a range kept for films and books, so it
can't reach a real person, and it is never sent a message. Every organization uses the same
number; the code decides which organization it signs in to, and one organization's code
doesn't work for another.

## What reviewers see

The reviewer signs in like a driver: they enter the phone number, Navigator asks for a code,
and they enter yours. The first time, a driver called **App Reviewer** is added to your
drivers list. It is never offered or assigned orders automatically (ad-hoc offers, nearest
driver and the orchestrator all leave it out). Assign an order to it by hand if you want the
reviewer to see one.

## Create a new code or turn it off

- **Create new code** replaces the code. The old one stops working at once.
- **Turn off** stops reviewers signing in. Turning it back on restores the same code, unless
  you created a new one.

Changing it needs the **update navigator-settings** permission; administrators always can.

## Self-hosted installs

The instance-wide review accounts still work alongside each organization's setting:

```
NAVIGATOR_BYPASS_VERIFICATION_CODE=<a secret code>
NAVIGATOR_REVIEW_ACCOUNTS=reviewer@example.com,+12025550100
```

## For developers

The mechanism is core-api's App Review access (`Fleetbase\Support\AppReview`, core-api
1.6.74 or later). FleetOps registers the organization (`Company`) as the `navigator` type in
`FleetOpsServiceProvider` (`Fleetbase\FleetOps\Support\NavigatorReview`). The Console uses
core's endpoints, scoped to the signed-in organization:

```
GET /int/v1/review-access/navigator/{company uuid or public id}
PUT /int/v1/review-access/navigator/{company uuid or public id}   {enabled?, regenerate_code?}
```

The response is `{"review_access": {type, enabled, phone, phone_display, has_code,
code_generated_at, enabled_at}}`, plus `code` only in the response that created it.
`POST /v1/drivers/login-with-sms` and `POST /v1/drivers/verify-code` (the API credential's
organization) accept the review number and the organization's code.
