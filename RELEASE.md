> v0.7.4 ~ "Drivers sign in with the platform token"

---
## Highlights

- **Driver apps can sign in with the instance's platform token instead of an organization's API key.** Navigator is built for an instance, not for one organization, so an organization's API key had to be built into the app only to reach the sign-in routes. These routes now also accept the platform token (generated in the instance’s admin settings), and the driver's organization comes from their account as before:
  - `POST v1/drivers/login`
  - `POST v1/drivers/login-with-sms`
  - `POST v1/drivers/verify-code`
  - `POST v1/drivers/forgot-password`
  - `POST v1/drivers/reset-password`
- API keys and driver tokens work on these routes exactly as before, so apps already installed are unaffected. Every other route still refuses the platform token.
- **Driver sign-up needs the organization's API key.** `verify-code` with `for=create_driver` answers 403 to a platform token: the token ships inside apps and belongs to no organization, so it can't create a driver in one.
- **App Review sign-in with the platform token names the organization.** The review number's code belongs to an organization, which an API key used to identify. A platform-token request sends `organization` (the organization's id) with `login-with-sms` and `verify-code`. Without it, the review number answers as it does where review access is off.

---
## Upgrading

- Requires `fleetbase/core-api` 2.0.6, which adds the `fleetbase.api-or-platform` middleware group and rate limits platform-token requests per client address.
- No migration.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
