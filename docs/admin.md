# Administrator guide

## 1. Install and check the site

1. Install the plugin (`mod/scormmakercmi5`, or `public/mod/scormmakercmi5` on Moodle 5.1+) and run
   Site administration > Notifications.
2. Turn on **Use slash arguments** (`slasharguments`): Site administration > General > HTTP. Without it cmi5 content
   cannot load its files or reach the LRS; the activity page shows a warning.
3. Make the web server pass the `Authorization` header to PHP. The AU sends its token in that header; if it does not
   arrive, the course loads but nothing is recorded (HTTP 401, "the Authorization header did not reach the server").
   - Apache with PHP-FPM or CGI: add `CGIPassAuth On` to the virtual host or directory of Moodle.
   - nginx with PHP-FPM: add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` to the PHP location (or the
     included `fastcgi_params`).
   - Moodle also reads `REDIRECT_HTTP_AUTHORIZATION` and `getallheaders()`, so a rewrite rule that moves the header
     there works too.
4. Cron must run: the hourly task `cleanup_sessions` abandons idle sessions, and the daily task `recheck_current_packages`
   records the signature recheck of the current package of every activity (up to 200 per run, the oldest first) and queues
   the byte check, which the ad hoc task `recheck_package` runs at most once per `recheckminhours` per package.

## 2. Settings

Site administration > Plugins > Activity modules > cmi5 package (SCORM Maker). Every setting and its default is
listed in the README.

- **Limits** (request size, statements per request and per registration, session timeout, files and uncompressed
  size of a package, immediate recheck size). Raise them only if a legitimate course hits them; each is a whole number
  greater than zero.
- **Require reading learner preferences** (on): keep it on unless a third-party package never reads the learner
  preferences before `initialized`.
- **Allow AUs outside the package** (`allowexternalau`, **off** by default): a package may point an AU to an `https`
  site outside Moodle. With it on, that site receives the Moodle user id and the registration of the learner, and a
  token that reads and writes their statements, State and Agent Profile; turn it on only for sites you trust. Only new
  installs get the default: an upgraded site keeps what it had (on, when it never saved a value). Off: such packages
  are refused when imported or replaced, restore, course import, copy and duplicate bring such AUs in disabled (a warning in the restore log), and the AUs outside
  the package already on the site no longer launch (the learner sees a message saying so; a unit already open
  keeps its session until it ends). Turning it on again lets them launch. See "AUs outside the package" in the
  README.
- **Defaults for new activities**: Browse, Review and grading method.

## 3. Signing keys

SCORM Maker packages are checked against trusted keys. The plugin embeds the SCORM Maker keys.

- **Embedded keys** (public keys only):

  | Environment | `kid` | Accepted |
  |---|---|---|
  | Production | `sm-prod-2026-10` | always (from 0.3.1) |
  | Homologation (HML, test) | `sm-hml-2026-09` | only with `accepthmlkey` on |

  Versions 0.1.0 to 0.3.0 embedded only the HML key.
- **Accept the SCORM Maker test key** (`accepthmlkey`): **off** by default from 0.3.1 (it was on in 0.1.0 to 0.3.0,
  when the HML key was the only one). Upgrading to 0.3.1 turns it off on sites where no administrator ever changed it,
  with a notice on the upgrade page; a site where an administrator changed it keeps its value. With it off, packages
  signed in the HML test environment are "SCORM Maker signature invalid" (signed with the test key, which the site does not accept), also in the reports of packages already
  imported. If your site relied on HML packages, turn it on again here; keep it off on a production site.
- **Rotation and revocation** of the embedded keys ship as new plugin versions. A new key comes with a new `kid` and the
  old one stays embedded, so older packages keep verifying; a leaked key stays embedded and is marked revoked.
- **Additional trusted keys** (`trustedkeys`): one `kid=base64 public key` per line (Ed25519 public key, base64 of
  exactly 32 bytes; `kid` with letters, digits, `.`, `_`, `-`, up to 64 characters). Invalid lines, repeated `kid`s and
  `kid`s of embedded keys are refused when saving.
- **Revoked keys** (`revokedkids`): one `kid` per line. A revoked key stops being accepted immediately, including in
  the reports of packages already imported (the report rechecks the signature each time it opens). This is also the way
  to stop trusting an embedded key.

An unsigned or invalid package is not rejected: it works as plain cmi5 with the basic report.

## 4. Reporting credential (conformance suites and external readers)

The cmi5 conformance suite needs an LMS account that can read every statement. The plugin offers a read-only
credential; it can read statements and never write.

1. Site administration > Server > Web services > Overview: enable web services.
2. Site administration > Server > Web services > External services: enable `mod_scormmakercmi5_lrs`.
3. Authorised users of that service: add the user that will read (it must hold `mod/scormmakercmi5:viewreports` in
   the activities to be read; the statements of an activity are returned only when it does).
4. Manage tokens: create a permanent token for that user and service. Optionally set a valid-until date and an IP
   restriction.
5. The reader calls `GET <wwwroot>/mod/scormmakercmi5/lrs.php/statements` with
   `Authorization: Basic base64("wstoken:<token>")` and `X-Experience-API-Version: 1.0.3`.

The credential works only on GET of `statements`. Revoke the token to stop access. Never put the token in a URL or log.
It follows the group mode of each activity like the reports do: in separate groups, a user without
`moodle/site:accessallgroups` reads only the statements of the learners of their own groups. It reads only the
activities where its user has `viewreports`.

### Troubleshooting

The LRS answers an internal failure with 500 and a generic JSON message. The cause is logged only when debugging is
on: set Site administration > Development > Debugging to NORMAL (or higher) and look for lines starting with
`mod_scormmakercmi5 lrs` in the web server's PHP error log (shown on the page instead when "Display debug
messages" is on). Set it back afterwards.

## 5. Package limits and refusals

- AUs outside the package (an absolute `https` address) are accepted as not signed only when `allowexternalau` is on (off by default on a new site);
  an `http` address, a user name or password in the address, or a host a browser would write another way is refused.
- Refused: paths with `..`, `./`, `//`, absolute paths or drive letters, control
  characters, a backtick or any of `& < > " | ' :`; names over 255 characters; zips over `maxentries` or
  `maxunzippedmb`.
- Opening the rich report never writes: it checks the package in memory. The verdict that the basic report's link reads
  is recorded by the daily task `recheck_current_packages` (run it from Site administration > Server > Tasks to refresh it
  at once).
- Large packages (over `recheckmaxmb`) are rechecked in the background by the ad hoc task `recheck_package`, so the
  rich report may show the basic report until it finishes. A package rechecked in full less than `recheckminhours`
  (24 by default) ago is not queued again by the daily task.
- One AU at a time per registration: opening an AU abandons any active session of the same learner in that activity.
- If Moodle fails before the plugin runs (database down, maintenance mode, broken `config.php`), the LRS answers
  HTML or a non-2xx status instead of JSON; the course keeps retrying.
- Moodle App is not supported.
- Reach the site only under the host of `$CFG->wwwroot`: the LRS and `fetch.php` refuse a request whose `Origin` is
  neither Moodle's nor the one of the AU outside the package (403, or cmi5 error 2), so an alias host or a proxy that
  rewrites the host stops the recording of progress.

- Replacing the package of an existing activity needs only the right to edit the activity
  (`moodle/course:manageactivities`), not `mod/scormmakercmi5:addinstance`, as in mod_scorm: there is no separate
  capability for replacing. The exception is a new package with an AU outside it (an `https` address the learners are
  sent to): it also needs `mod/scormmakercmi5:addinstance` in the course, so a role that may edit activities but not
  add this one cannot point learners to another site.

## 6. Privacy and deletion

Personal data is described in the README. Use Moodle's privacy tools to export or delete a learner's data; the grade
and completion are recomputed. Teachers with `manageattempts` can also delete one learner's attempt in an activity.
