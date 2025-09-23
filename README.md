# DoceboIntegration External Module

Overview
- Enrolls users requesting access to E\-Reg into Docebo learning plans based on their role.
- Integrates REDCap forms with Docebo via OAuth2 and Google Secret Manager.

Requirements
- REDCap with External Modules enabled.
- PHP 7.4+ and Composer.
- Google Cloud project with Secret Manager enabled and a service account that can access the secrets.
- Network access from REDCap server to `https://experience.stanford.edu`.

Installation
1. Copy the module folder to `www/modules-local/docebointegration_v9.9.9`.
2. From the module directory run:
   - `composer install`
3. Enable the module in REDCap (Control Center -> External Modules).

Secrets and System Settings
- System setting to set:
  - `google-project-id` — Google Cloud project id used by the `GoogleSecretManager`.
- Create the following secrets in Google Secret Manager (exact names used by the code):
  - `DOCEBO_CLIENT_ID`
  - `DOCEBO_CLIENT_SECRET`
  - `DOCEBO_USERNAME`
  - `DOCEBO_PASSWORD`

The module stores token state in External Modules system settings:
- `docebo-access-token`
- `docebo-refresh-token`
- `docebo-token-expiry`

REDCap Project Configuration
- Project-level EM setting:
  - `e-reg-request-forms` — name of the instrument (form) that triggers enrollment on survey completion.
- Expected REDCap fields used by the module (defaults in code):
  - Requester fields:
    - `requester_email`
    - `requester_f_name`
    - `requester_l_name`
    - `requester_sunet_sid`
    - `requester_affiliate`
  - Trainee fields (used when submitting on behalf of someone else):
    - `trainee_email`
    - `trainee_first_name`
    - `trainee_last_name`
    - `trainee_sunetid`
    - `trainee_affiliate`
  - Control fields:
    - `submitting_behalf` — boolean/choice used to detect if request is on behalf of someone else.
    - `trainee_primary_role` — expected to contain the Docebo learning plan id to enroll into.
    - `request_type` — value `3` (constant REQUEST_TYPE_USER_TRANING) triggers enrollment.

How it works
- On survey completion of the configured instrument the module:
  - Loads the appropriate user data (requester or trainee).
  - Locates or creates the Docebo user via the Docebo API.
  - Enrolls the user into the learning plan id stored in `trainee_primary_role`.

Security Notes
- Secrets are stored in Google Secret Manager. Ensure IAM permissions are limited to the REDCap service account only.
- Access and refresh tokens are persisted to REDCap External Modules system settings and may appear in backups. Consider restricting EM settings visibility and rotating credentials regularly.
- The module logs exceptions to REDCap; avoid exposing raw secrets in logs.

Troubleshooting
- Review REDCap project Log Events for "Docebo Integration" messages.
- Verify Google Secret Manager access and that secret names match the expected keys.
- Confirm Docebo credentials (client id/secret and user account) are valid.
- Test network connectivity from the REDCap server to `experience.stanford.edu`.

Development notes
- HTTP client uses Guzzle; auth logic is in `classes/doceboClient.php`.
- Secrets are fetched by `classes/GoogleSecretManager.php`.
- Adjust field names in the code if your project uses different variable names.

License
- MIT (or your preferred license).
