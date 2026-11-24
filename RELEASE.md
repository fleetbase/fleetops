> v0.7.3 ~ "App Review sign-in for customer apps"

---
## Highlights

- **App Review sign-in for customer apps.** Apps that customers sign in to through Fleet-Ops' customer API (`v1/customers`) send a texted code, and Apple's and Google's reviewers can't receive one. Fleet-Ops → Settings → **Customer Apps** now turns App Review sign-in on and off for the organization, the way Settings → Navigator App does for Navigator.
  - Reviewers sign in with the number **+1 202 555 0145** and a six-digit code the Console shows once. Create a new code there at any time; the old one stops working at once. The number is from the range reserved for fiction and is never texted. It differs from Navigator's (+1 202 555 0144), because a customer and a driver are different accounts.
  - With access on, `customers/request-code`, `customers/login-with-sms` and `customers/request-creation-code` (SMS) answer for the number as if a code were sent, and send nothing. With access off, they answer 422 and say where to turn it on.
  - With the organization's code, `customers/verify-code` and `POST customers` (create) sign in as the organization's review customer, **App Reviewer**, created in its customers list the first time, and return the same response as any sign-in. They never answer `new_customer`. The organization is the one the app's API key belongs to, so a code only works in that organization's apps; a wrong code, or another organization's, is refused.
  - Orders the review customer places get `meta.app_review = true`.
  - New permissions `fleet-ops view customer-app-settings` and `fleet-ops update customer-app-settings`, a **CustomerAppSettingsManager** policy and a **Customer App Manager** role. Administrators have them already.

---
## Upgrading

- Requires `fleetbase/core-api` 2.0.5, which lets two App Review types share a model (Navigator and the customer apps are both registered on the organization).
- No migration. Run `php artisan fleetbase:create-permissions` (or the install's usual permission sync) so the new permissions, policy and role exist.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
