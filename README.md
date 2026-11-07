# cmi5 package (SCORM Maker) (mod_scormmakercmi5)

A Moodle activity that takes a **cmi5** package (zip) from any authoring tool, launches the course for the learner,
stores what the course reports in a **learning record store (LRS) built into Moodle** (no external LRS needed) and
shows reports to the teacher. Packages exported by SCORM Maker with a valid Ed25519 signature are marked
"SCORM Maker verified" and also get a **rich report** (lessons visited, time per lesson, answer to each question);
every other cmi5 package gets the **basic report**.

Status: stable (`0.4.0`).

*[Versão em português](README.pt_br.md)*

## Screenshots

Taken on Moodle 4.5:

- [Activity settings](docs/screenshots/01-activity-settings.png)
- [Learner launching the course](docs/screenshots/02-learner-launch.png)
- [Teacher report](docs/screenshots/03-teacher-report.png)
- [Course page with a cmi5 activity](docs/screenshots/04-course-with-cmi5-activity.png)

## Features

- Create the activity from a cmi5 zip: name, description and the list of assignable units (AUs, including those
  inside blocks) are read from `cmi5.xml`. A package that is invalid, points to a missing file or has an unsafe path
  is refused with a message that says why, and nothing is created (R1). An AU hosted outside the package (an `https`
  address) is accepted as unsigned only when `allowexternalau` is on; it is off by default on a new site (see "AUs
  outside the package").
- Replace the package of an activity that already has learners, with an explicit confirmation: attempts in progress
  stay on the previous package, new ones use the new one (R1.5).
- SCORM Maker signature check (Ed25519 over the canonical manifest plus the SHA-256 of every file). Three outcomes:
  verified, not signed, invalid (the package still works as plain cmi5, and the teacher sees the reason) (R2). A package
  from another authoring tool is simply "not signed": it launches and gets the basic report, nothing is refused.
- Trusted and revoked signing keys managed by the administrator (R3).
- Launch of each AU with the cmi5 launch parameters, one registration per learner per activity, resume with the same
  registration and State, `AnyWindow` embedded with full screen, `OwnWindow` in its own window, Browse and Review
  modes (R4).
- Built-in xAPI LRS for the launched AUs (Statements, State, Agent Profile, Activity Profile) that applies the cmi5
  LMS rules and answers 403 with the rule that was broken. Batches are all-or-nothing (R5).
- Grade in the gradebook (0-100) and activity completion "the learner satisfies the cmi5 course" (R6). An AU with no
  `masteryScore` and no passed/failed score is graded by its `scored` statements (Normal sessions only; per session
  the latest, across sessions the grading method of the activity).
- Basic report: course and per-AU status, grade, total time, sessions, first and last access, filters by status and
  group, CSV export, statements of each learner (R7). Group mode is respected.
- Rich report for verified SCORM Maker packages, per learner and per activity, split by package version (R8).
- Activity inside the lessons for verified SCORM Maker packages (profile 2): reach, time visible and interactions per
  block, items opened, media retention per 5-second band, clicks on the image, scenario choices, flashcard
  self-assessment and tour hotspots, as class totals (per learner only a summary). Nested blocks (analysis structure
  `v: 2`) are indented rows "Parent › Child". The download has nine tables, the same as the spreadsheet of the hosted
  course: Excel or ODS with one sheet per table, or CSV as a zip with one file per table (see
  [docs/teacher.md](docs/teacher.md)).
- Teachers can waive an AU for a learner (cmi5 `waived`) and delete a learner's attempt, each on a confirmation page.
- Optional integration: the activity can also be created from SCORM Maker through the web service of the separate
  plugin [`local_scorm_maker_import`](https://github.com/grilo-cricri/moodle-local_scorm_maker_import) (R9). This plugin
  works fully without it; the activity form and the cmi5 upload are all you need.
- Backup and restore (package always; registrations, statements and State only with user data), Privacy API,
  English and Brazilian Portuguese.

## Requirements

- Moodle 4.5 to 5.3 (`$plugin->requires = 2024100700`, `$plugin->supported = [405, 503]`).
- PHP 8.1 or later (whatever the Moodle version requires) with the `sodium` extension (already required by Moodle).
- The site setting **Use slash arguments** (`slasharguments`) must be on: cmi5 content loads its files and reaches the
  LRS through URLs with a slash path. The activity page shows a warning when it is off.
- The web server must pass the `Authorization` header to PHP (see [docs/admin.md](docs/admin.md)).

## Installation

1. Unzip into `mod/scormmakercmi5` (`public/mod/scormmakercmi5` on Moodle 5.1 and later), or install the zip from
   Site administration > Plugins > Install plugins.
2. Visit Site administration > Notifications, or run `php admin/cli/upgrade.php --non-interactive`.
3. Check `slasharguments` and the `Authorization` header, as described in [docs/admin.md](docs/admin.md).

## Upgrading

**0.3.0 to 0.3.1** embeds the SCORM Maker production key (`sm-prod-2026-10`) and turns the homologation (HML) key off
by default. No schema change. The upgrade sets `accepthmlkey` to off on every site where no administrator ever changed
it (the value was never saved, or the configuration changes log only has its first write) and prints a notice; a site
where an administrator changed it keeps its value. From then on, packages signed in the SCORM Maker HML environment
(`sm-hml-*`) are "SCORM Maker signature invalid" (reason: signed with the SCORM Maker test key, which the site does not accept), also in the reports of packages already imported; packages
exported from SCORM Maker production are verified. **If your site relied on HML packages**, turn `accepthmlkey` on
again in the plugin settings (see "Signing keys" below).

**0.2.0 to 0.3.0** adds one nullable column (`auorigin`) and its index to the AU table; no data is migrated and no ad
hoc task is queued. Things to review:

- The setting `allowexternalau` is now **off** by default: a package that points an AU to an `https` site outside
  Moodle is refused until an administrator turns it on (see "AUs outside the package" and "Privacy"). Only new
  installs get the new default: the upgrade keeps every existing site as it was (a stored value stays, and a site that
  never saved it, which ran with it on, gets `1` written), so an upgrade never starts refusing AUs. Review the setting
  on sites that did not mean to have it on.
- A token or fetch key is now refused when the request carries an `Origin` that is neither Moodle's nor the AU's. A
  site reached under a host different from `$CFG->wwwroot` (an alias, or a proxy that rewrites the host) must fix that,
  or its learners' progress is not recorded.
- SCORM Maker packages with nested blocks need 0.3.0: on 0.2.0 their block activity report says it is unavailable.
- Grades: an AU with no `masteryScore` and no passed/failed score now gets its grade from `scored` statements already stored the next time
  the grade is recomputed (the learner opens the activity or sends a graded statement, the grading method changes, or
  the gradebook is regraded).

**0.1.0 to 0.2.0** changes the schema only: new columns in the statements and package tables and a new index on the
statements table (on a large site the index may take minutes and lock writes to that table: run the upgrade in
maintenance mode). Packages exported before the activity analysis keep the rich report they had; only the section
"Activity inside the lessons" says it is unavailable. After the upgrade, cron runs three ad hoc tasks, each queued only
when there is something to do:

- "Fill the activity analysis columns of statements stored before version 0.2.0" (`backfill_profile_columns`);
- "Store the activity analysis structure of package versions imported before version 0.2.0"
  (`backfill_rich_structure`);
- "Repair the JPEG images of signed packages rewritten by the EXIF remover before version 0.2.0"
  (`repair_redacted_images`): on Moodle 4.4 and later the EXIF remover of the site rewrote the JPEG files of every
  import, so a signed package with a JPEG failed every recheck. The task gives back the signed bytes from the stored
  zip and rechecks the package; a file changed after the import is left as it is and the package stays invalid.

## Configuration

Site administration > Plugins > Activity modules > cmi5 package (SCORM Maker). Requires `moodle/site:config`.
Each limit must be a whole number greater than zero with at most 9 digits.

| Setting | Effect | Default |
|---|---|---|
| `maxbodykb` (Request size, KB) | Largest request body sent to the LRS; larger gets HTTP 413. | 1024 |
| `maxbatch` (Statements per request) | Largest number of statements in one request; larger gets 413. | 100 |
| `maxstatementkb` (Statement size, KB) | Largest single statement; larger gets 413. | 64 |
| `maxstatementsperreg` (Statements per registration) | Statements stored for one registration; further ones get 403. | 10000 |
| `maxanalysisperreg` (Analysis statements per registration) | Block summaries and decisions of SCORM Maker packages (profile 2) stored for one registration. They do not count in `maxstatementsperreg`; further ones are skipped without an error. | 20000 |
| `maxstatekb` (State document size, KB) | Largest State document; larger gets 413. | 1024 |
| `maxstatedocs` (State documents per registration) | State documents (distinct `stateId`) an AU may create for one learner and registration, LMS.LaunchData not counted; a new one above it gets 403, existing ones can still be replaced or deleted. | 100 |
| `sessiontimeout` (Session timeout, seconds) | Inactivity after which a session is abandoned and its token expires (hourly task). | 28800 (8 h) |
| `maxentries` (Files per package) | Largest number of entries in a package zip; larger is refused. | 20000 |
| `maxunzippedmb` (Uncompressed package size, MB) | Largest uncompressed package (zip-bomb guard); larger is refused. | 1024 |
| `recheckmaxmb` (Immediate recheck size, MB) | Packages up to this size are fully checked, in memory, when the rich report opens; larger ones, and the recorded byte check of every package, run in the background (queued by the daily task `recheck_current_packages`). | 100 |
| `recheckminhours` (Background recheck interval, hours) | A larger package is queued for a background recheck at most once in this time; opening the rich report meanwhile reuses the last full recheck. | 24 |
| `requireprefsread` (Require reading learner preferences) | The AU must read the learner preferences before `initialized`, as the cmi5 reference LMS requires. Turn off only for third-party content that does not read them. | on |
| `allowexternalau` (Allow AUs outside the package) | A package may point an AU to an `https` address outside Moodle; that site gets the user id, the registration and a token to read and write the learner's statements, State and Agent Profile. Off: such packages are refused when imported or replaced, such AUs are restored disabled (restore, course import, copy, duplicate; the restore logs a warning), and the AUs outside the package already on the site no longer launch (the learner gets a readable message). Learners see a notice that the activity is hosted outside Moodle. Off by default (opt-in): turning it on sends learner data to the external site. | off |
| `trustedkeys` (Additional trusted keys) | One `kid=base64 public key` per line (Ed25519, 32 bytes). Invalid lines are refused when saving. | empty |
| `revokedkids` (Revoked keys) | One `kid` per line. Packages signed with them stop being accepted, also in reports of packages already imported. | empty |
| `accepthmlkey` (Accept the SCORM Maker test key) | Accepts packages signed by the SCORM Maker homologation (HML) key, `sm-hml-2026-09`. Does not affect the production key. | off (on in 0.1.0 to 0.3.0, see below) |
| `browsemode` (Offer "Browse" by default) | Default for new activities: learners may open an AU in Browse mode, which does not count. | off |
| `reviewmode` (Always offer "Review" by default) | Default for new activities: Review for every AU; otherwise only for satisfied AUs. | off |
| `grademethod` (Grading method by default) | Default for new activities: Highest grade or Last grade. | Highest grade |

### Signing keys

The plugin embeds the public keys of SCORM Maker (only public keys; the private keys never leave SCORM Maker):

| Environment | `kid` | Public key (Ed25519, base64) | Accepted |
|---|---|---|---|
| Production | `sm-prod-2026-10` | `92zIJWqpNtJFD4dM069A4uLCcpNXZtyK1d2aESIe9xU=` | always (from 0.3.1) |
| Homologation (HML, a test environment) | `sm-hml-2026-09` | `G7Aw/GpCag3lzz/kqPgHf+bzfkVSj0riPLavTZWlfyM=` | only with `accepthmlkey` on |

Versions 0.1.0 to 0.3.0 embedded only the HML key, so `accepthmlkey` was on by default there; 0.3.1 turns it off (see
"Upgrading"). Keep it off on a production site: with it on, a package signed in the test environment counts as "SCORM
Maker verified".

Rotation and revocation of the embedded keys ship as new plugin versions: a new key is added with a new `kid` and the
old one stays embedded, so packages exported before keep verifying; a leaked key stays embedded and is listed as
revoked, so its packages show "revoked key" rather than "unknown key". The embedded keys cannot be removed in the
interface; to stop trusting one on your site at once, add its `kid` to `revokedkids`.

## AUs outside the package

An AU whose `url` in `cmi5.xml` is an absolute `https` address is launched at that address instead of from the
package. **This is opt-in: `allowexternalau` is off by default**, so a new site refuses such packages until an
administrator turns it on. Turning it on means that **the external host receives the learner's actor (site URL and
Moodle user id), the registration and a token** that reads and writes that registration's statements, State and Agent
Profile. Only new installs get the default 0: an existing site keeps what it had (it was on by default before 0.4.0,
and the upgrade writes `1` where no value was stored), so review it there. What changes:

- The package is "Not signed" (reason `external_au`) whatever signature the zip holds, and has no rich report and no
  block activity report: nothing guarantees that content.
- Only `https`; the host must be written as a browser writes it in the `Origin` header (a domain name, an
  international name in its `xn--` form, four decimal numbers for IPv4, the short form of IPv6); no user name or
  password; at most 2048 characters for the URL and 255 for its origin (`https://host[:port]`, stored in `auorigin`).
  The query follows the rules of a package AU (no cmi5 parameter of the LMS, no `;`, no repeated name).
- The external site gets the cmi5 launch parameters: the endpoint of the LRS of Moodle, the one-time fetch key, the
  actor (site URL and Moodle user id, never the name) and the registration. With the token it reads and writes the
  statements, State and Agent Profile of that registration, as a package AU does.
- CORS on `lrs.php` and `fetch.php` is opened only to that exact origin (no `*`, no echo of another origin,
  `Vary: Origin`, no credentials). A request from any other outside origin is refused after the token check (403 on
  the LRS, cmi5 error 2 on fetch, with the key not spent).
- Learners see "This activity is hosted outside Moodle (origin)." on the activity page and in the player; teachers
  with the reports see which origins the package opens.
- `allowexternalau` off: such packages are refused at import and replacement, backups bring such AUs in disabled
  (restore, course import, copy, duplicate), and those already on the site no longer launch (a unit already open
  keeps its session until it ends). On every restore the origin is computed again from the `url`, never read
  from the backup.
- Replacing a package with one that has an AU outside it needs `mod/scormmakercmi5:addinstance` in the course, besides
  the `moodle/course:manageactivities` of the edit form. Any other replacement needs only the latter, as in mod_scorm.

## Web services

| Name | Type | What it is |
|---|---|---|
| `mod_scormmakercmi5_get_session_state` | function, AJAX, read | Tells the player page whether the current user's cmi5 launch session is still open. Needs login and `mod/scormmakercmi5:view`. |
| `mod_scormmakercmi5_lrs` | external service, no functions, disabled, restricted users | The read-only reporting credential of the LRS. Lets one authorised user read statements with `Authorization: Basic base64("wstoken:<token>")` on `GET lrs.php/statements`, as cmi5 4.2.0.0-2 requires of a conformance test suite. It never writes and exposes no function. It follows the group mode of each activity like the reports do: in separate groups, a user without `moodle/site:accessallgroups` reads only the statements of the learners of their own groups. |

To enable the reporting credential, see [docs/admin.md](docs/admin.md).

Scheduled and event code: scheduled task `cleanup_sessions` (hourly: abandons sessions idle longer than
`sessiontimeout`, clears old token hashes); scheduled task `recheck_current_packages` (daily: records the signature and file-map
recheck of the current package of up to 200 activities per run, the oldest first, and queues the byte check; it hashes
no bytes itself; opening the rich report only reads, it never writes); ad hoc task `recheck_package` (full SHA-256 recheck
of the stored bytes of a package, queued at most once per `recheckminhours`);
ad hoc tasks queued once by the upgrade to 0.2.0 (`backfill_profile_columns`, `backfill_rich_structure`,
`repair_redacted_images`, see "Upgrading"); observer of `\core\event\user_deleted` (deletes the learner's data of every
activity and their saved preferences, through the code of the Privacy API); two listeners of the hook `\core_files\hook\before_file_created` (`db/hooks.php`) that keep the files
of verified packages byte for byte (see "Privacy").

## Capabilities

| Capability | What it allows | Risks | Default roles |
|---|---|---|---|
| `mod/scormmakercmi5:addinstance` | Add the activity to a course (the package runs JavaScript in the Moodle origin, like SCORM). | XSS | Teacher (editingteacher), Manager |
| `mod/scormmakercmi5:view` | Open the activity and launch the course. Not granted to guests: cmi5 needs a user and a registration. | none | Student, Non-editing teacher, Teacher, Manager |
| `mod/scormmakercmi5:viewreports` | See reports and statements of other learners. | personal data | Non-editing teacher, Teacher, Manager |
| `mod/scormmakercmi5:manageattempts` | Delete a learner's attempt and waive an AU. | data loss, personal data | Teacher, Manager |

Signing keys and limits need `moodle/site:config`. Creating the activity through the SCORM Maker web service needs
`moodle/course:manageactivities` and `mod/scormmakercmi5:addinstance`. Replacing the package of an activity needs
`moodle/course:manageactivities`, and also `addinstance` when the new package has an AU outside it.

## Known limits

- An AU hosted outside the package (absolute `https` `url` in `cmi5.xml`) makes the package unsigned, with no block
  activity report; its host must be a domain name, a plain IPv4 address or a short IPv6 address. No external LRS and
  no forwarding of data to another LRS. Turning `allowexternalau` off stops new launches of external AUs already
  imported; a session already open goes on until it ends.
- A request with an `Origin` that is neither Moodle's (`$CFG->wwwroot`) nor the AU's is refused, so the site must be
  reached under its `wwwroot` host.
- One AU at a time per registration. Launching an AU abandons any session still active in the same registration
  (cmi5 9.3.6), so two AUs open in two tabs do not coexist: opening the second abandons the first.
- Package paths are refused when they are absolute or contain `..`, `./`, `//`, a drive letter, control characters,
  a backtick or any of `& < > " | ' :`, or when a name is longer than 255 characters.
- The LRS and `fetch.php` always answer JSON, never HTML, except when the failure happens inside Moodle's
  `config.php` (database down, maintenance mode, broken configuration): then the AU only sees a non-2xx status.
- Moodle App (mobile) is not supported in this version.
- The order of statements inside a batch is the order of arrival, not of their `timestamp`.
- On MySQL/MariaDB (case- and accent-insensitive collation), State and Agent Profile ids that differ only by case or
  accent are the same document. PostgreSQL keeps them apart. Only the learner's own documents are affected.
- The lesson and question names in the rich report come from the AU (the item definitions in its statements), so a
  learner's course can name them. They are always shown escaped.

## cmi5 conformance

Result of the [CATAPULT](https://github.com/adlnet/CATAPULT) cmi5 LMS test suite (LTS, commit `806c0ba`, Node 24)
run against version 0.3.2 of this plugin through a custom LMS adapter that is not shipped with the plugin.

| Moodle / PHP | `package.js` | `runtime.js` |
|---|---|---|
| 4.5.14+ (Build 20260928) / PHP 8.3, PostgreSQL 17 | 20 of 21 passed, 1 expected failure | 66 of 66 passed |
| 5.2.3+ (Build 20260928) / PHP 8.3, PostgreSQL 17 | 20 of 21 passed, 1 expected failure | 66 of 66 passed |

- **Expected failure:** `101-one-thousand-aus.xml` (requirement 6.1.0.0-2). It is a standalone `cmi5.xml` with
  absolute AU URLs, and the plugin only imports zip packages hosted in the package. The zip variant of the same
  requirement (1001 AUs, relative URLs) imports and launches the first and the last AU.
- All 19 invalid packages (201-* to 210-*) are refused. No requirement is waived or skipped.
- On Moodle 4.5: 22 of 22 security scenarios passed (authentication, access, token scope, CSRF, IDOR, limits,
  escaping), and axe-core 4.10.2 found 0 WCAG 2.1 AA violations on the plugin's pages. On Moodle 5.2, axe-core
  found 0 violations on the activity page, the four reports, the index and the delete attempt page, and a package
  signed by SCORM Maker imported as verified, ran to completion and filled the grade, completion and rich report.

## Privacy

This plugin stores personal data of learners inside Moodle. Nothing is sent to an external service, except to the
site of an **AU outside the package** (external location `externalau` in the privacy metadata), which a teacher chooses
by uploading such a package, and which only works while the administrator keeps `allowexternalau` on (off by default
on a new site). That site receives, in the launch
URL, the account of the learner (the site URL and the Moodle user id, never the name or e-mail) and the registration,
and a token with which it reads and writes, for that registration, the statements, the State documents and the Agent
Profile (learner preferences) of the learner. What that site keeps is outside Moodle's privacy tools.

For each learner and activity it stores: the registration (an id, package version, whether the course is satisfied,
grade, dates); the status of each AU and block (completed, passed, failed, waived with reason, scores, dates); the
sessions (dates, mode, duration, and hashes of access tokens, which are never exported); every statement the course
sent (who: the Moodle user id, no name or e-mail; what happened, answers, times); the State documents the course saved
to resume; and, once per learner, the learner preferences (cmi5 Agent Profile) in the user's own context. It also
records, per package version, who uploaded it and when. It is stored to resume the course, to show reports and to
grade. The grade goes to the Moodle gradebook, which handles it on its own.

Moodle's privacy tools export all of it (organised by activity and AU) and delete it, per user and per context.
Deleting a learner's data removes registration, sessions, statements and State, and recomputes grade and completion.
The package versions are kept (registrations depend on them) and only the uploader becomes anonymous. Deleting a
user account also removes, from every activity, their registrations, sessions, statements and State and their saved
preferences, with the same code (grades and completion are removed by Moodle itself); the package versions a deleted
teacher uploaded stay, with the uploader set to nobody (`usermodified = 0`).

The activity analysis (subproject D) adds no new personal data: its columns in the statements table are read from
the stored statement, which the privacy tools already export and delete; per learner the report shows only a summary.

Image metadata: the files of a SCORM Maker package whose signature is **verified** are stored byte for byte, because
the signature covers every byte and the learner must receive the signed asset. Its images keep their metadata (EXIF,
including any GPS position) exactly as signed, even when the site setting `file_redactor_exifremoverenabled` removes
EXIF from other uploads; removing it before publishing is the author's responsibility. An **unsigned** package, or one
whose signature is **invalid**, follows the EXIF remover of the site, like any other upload. This is done by two listeners of `before_file_created` that act only on
the `package` and `content` areas of this plugin.

Residual risk: the deletion waits up to 60 seconds for a batch of statements in flight on the learner's attempt;
after that it revokes the tokens and deletes without waiting, then sweeps statements and State by activity and user.
A batch that had already passed its checks and commits after that sweep leaves its rows behind until the next
deletion. In practice a batch takes milliseconds.

## Security notes for administrators

Two properties are inherent to cmi5/xAPI and to how Moodle's own `mod_scorm` works; neither is a flaw to fix, and both
are worth knowing before you let teachers upload packages.

- **The AU runs in the same origin as Moodle, in an iframe without `sandbox`, or in its own window for an AU that asks
  for one.** Both are the same origin. An AU is a web page from the package a teacher uploaded, served from this site, so its script can do what any script of the site can do in the browser of the
  learner who opens it. This is the same model as `mod_scorm`, and the reason the capability
  `mod/scormmakercmi5:addinstance` is flagged `RISK_XSS` in Moodle's role editor. Give it only to roles you trust to
  upload content (by default Teacher and Manager), and prefer packages signed by SCORM Maker.
- **A learner can forge their own statements.** The AU sends the statements from the learner's browser with a token
  of the learner, so a learner who opens the browser tools can send any statement for their own registration (a
  "passed" included). That is how cmi5/xAPI works with any LMS and LRS that trusts the browser. Treat results of
  this activity as self-reported, and do not use them alone as proof for a high-stakes assessment; use the
  activity for learning and practice, or confirm what matters outside the package.

## Support / License

Source code: <https://github.com/grilo-cricri/moodle-mod_scormmakercmi5>

Report issues at <https://github.com/grilo-cricri/moodle-mod_scormmakercmi5/issues>.

GNU GPL v3 or later, full text in [`LICENSE`](LICENSE). Copyright 2026 ScormMaker.com.br.
