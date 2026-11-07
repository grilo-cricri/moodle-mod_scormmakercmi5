# Changes

Source and issue tracker: <https://github.com/grilo-cricri/moodle-mod_scormmakercmi5>.

## 0.4.0 (2026110600)

Preparation for the Moodle Plugins Directory. Capability defaults and privacy metadata: unchanged (deleting a user account now also removes the user's module
data). Configuration: one new setting (`maxstatedocs`) and `allowexternalau` now off by default on new installs (upgraded sites keep their behaviour). No schema change. Maturity is now stable.

- Added: `LICENSE` with the full GNU GPL v3 text (#1).
- Added: size limits on every path that takes bytes from outside, with tests (#3).
- Changed: default activity names come from language strings instead of hard-coded text (#5).
- Security: only a package whose signature is **verified** is stored byte for byte. A package with an invalid signature
  (any `META-INF/scormmaker-assinatura.json` that does not verify, even garbage) was stored byte for byte too, which
  let it bypass the file processing of the site (EXIF/GPS removal and every other `before_file_created` listener); it
  now gets that processing, like an unsigned package. The repair task `repair_redacted_images` only takes versions
  verified at import. Side effect: a package that was invalid at import and whose JPEG files were redacted can no
  longer become verified on a recheck (for example after its key is trusted); import it again to get it verified.
- Security: restoring a backup no longer trusts the signature status stored in it. A version that the backup calls
  "verified" comes back provisionally invalid, and becomes verified before its files are created only when its
  signature and file map hold with the keyring of the site; otherwise its files go through the file processing of the
  site (EXIF removal and every other `before_file_created` listener), as for any package that is not verified. A
  hand-made backup could claim "verified" and have its files stored byte for byte. Honest backups of signed packages
  still come back verified, byte for byte.
- Security: a file of a verified package is stored byte for byte only when its SHA-256 equals the one the signed
  manifest lists for its path (pkgfile); a file that does not match, or has no row, gets the file processing of the
  site. This covers import, replacement, restore and the repair task, so a backup with a signed manifest and swapped
  JPEGs no longer keeps their EXIF/GPS data.
  The restore computes `pkgfile.pathhash` from the path (a duplicate path in a hand-made backup is left out with a
  warning), and only the zip of the version, with the name and hash recorded for it, is kept verbatim in the package
  area.
- Security: the State API stores at most `maxstatedocs` documents (default 100, new setting) per learner, activity and
  registration; a new `stateId` above it gets 403 with an xAPI error body, while replacing, merging into or deleting an
  existing document still works. Before, an authenticated learner could create documents without bound during the
  session.
- Security: the read-only reporting credential (`wstoken`) of the LRS now follows the group mode of the activity, like
  the reports do. In separate groups, a user without `moodle/site:accessallgroups` reads only the statements of the
  learners of their own groups (a user with no group reads none); before, any user with `viewreports` read every
  learner of the activity. Visible groups and no groups: unchanged.
- Security: `allowexternalau` is now **off** by default (privacy by default; it was on). A package with an AU hosted
  outside it is refused until an administrator opts in, and the external host then receives the learner's actor,
  registration and a token (see the README). Only new installs get the default: the upgrade step keeps an
  existing site as it was (a stored value stays; where none was stored, which behaved as on, it writes `1`), so an
  upgrade never starts refusing AUs outside the package. Review the setting on sites that did not mean to have it on.
- Security: opening the rich report no longer writes. It used to record the recheck (and could queue the background
  byte check of a large package) on a GET; it now checks the package in memory (`verifier::evaluate()`) and shows the
  same result. The verdict is recorded by the new daily scheduled task `recheck_current_packages`, which
  records only the signature and file-map steps (no byte is read), at most 200 packages per run, the oldest recheck
  first, and queues the ad hoc `recheck_package`, which does the SHA-256 of the stored bytes at most once per
  `recheckminhours` per package. A package that cannot be checked is reported and goes to the back of the queue.
  New task, no schema change.
- Security: deleting a user account (`\core\event\user_deleted`) now removes the learner's registrations, sessions,
  statements and State in every activity, and the Agent Profile, through the code of the Privacy API
  (`provider::delete_all_data_of_user()`), without recomputing grades or completion (core already removed them).
  Package versions the deleted user uploaded stay, with `usermodified = 0`. Before, only the Agent Profile went.
- Docs: "Security notes for administrators" in the README (English and Brazilian Portuguese): the AU runs in the same
  origin in an iframe without `sandbox`, as in `mod_scorm` (`RISK_XSS` on `addinstance`), and a learner can forge their
  own statements, which is inherent to cmi5/xAPI.
- Changed: a restored version is always stored by what the signature proves on this site, never by the status the
  backup carries. A backup that says `invalid` for a version that verifies today (for example its key was trusted
  since) has its JPEG files stored with the file processing of the site (EXIF removal), so the recheck after the
  restore reports `filechanged` for them; a version without JPEG files simply ends `verified`. Import the package
  again to get it verified with the original bytes.
- Changed: every stylesheet rule of the plugin is scoped under the module pages, so it cannot leak into the rest of the
  site (Moodle concatenates the `styles.css` of every plugin).
- Added: support for Moodle 5.3 (`supported = [405, 503]`); PHPUnit attributes next to the docblock tags, so the suite runs
  without deprecations on PHPUnit 11.
- Changed: every source link points to the repository `moodle-mod_scormmakercmi5` (#9); comments and PHPDoc are in
  English (#10).
- Docs: README in English with a Brazilian Portuguese version in `README.pt_br.md`, `MARKETPLACE.md` with the listing
  fields, screenshots expected in `docs/screenshots/` (#6, #7).
- CI: GitHub Actions workflow running moodle-plugin-ci on Moodle 4.5 to 5.3, on PostgreSQL and MariaDB, with phpmd (#8).
- Packaging: development scripts, specs, build output and end-to-end tests are no longer in the repository.
- Upgrade: step 2026110600 writes `allowexternalau = 1` on sites with no stored value (so upgraded sites keep their
  behaviour); the site purges its caches.

## 0.3.2 (2026100501)

Capability defaults, privacy and configuration: unchanged. No schema change.

- Changed: the activity icon is now the SCORM Maker icon (the one the local plugin `scorm_maker_import` uses, GPL-3.0,
  by ScormMaker.com.br). It is in colour: the plugin declares `scormmakercmi5_is_branded()`, so Moodle does not recolour
  `pix/monologo.svg` by the activity purpose. `pix/icon.svg` is the same file, for the older icon paths.
- Upgrade: a version-only step 2026100501 so the sites purge their caches and show the new icon.

## 0.3.1 (2026100500)

Capability defaults and privacy: unchanged. **Configuration: changed** — `accepthmlkey` is off by default and the
upgrade turns it off on sites that never changed it. Read this before upgrading a site that uses packages signed in the
SCORM Maker homologation (HML) environment.

- Added: the SCORM Maker production key is embedded (`sm-prod-2026-10`, Ed25519 public key
  `92zIJWqpNtJFD4dM069A4uLCcpNXZtyK1d2aESIe9xU=`). Packages exported from SCORM Maker production are "SCORM Maker
  verified" whatever `accepthmlkey` says. The HML key `sm-hml-2026-09` stays embedded, behind `accepthmlkey`.
- Changed: `accepthmlkey` ("Accept the SCORM Maker test key") is off by default, and a value never saved now counts as
  off (it counted as on). The upgrade step 2026100500 writes 0 on every site where no administrator changed it (never
  saved, or only its first write in the configuration changes log), logs that change as a configuration change and
  prints a notice on the upgrade page. A site where an administrator changed the value keeps it.
- Upgrade notice: with the HML key off, packages signed in HML (`sm-hml-*`) are "SCORM Maker signature invalid" (reason: signed with the SCORM Maker test key, which the site does not accept)
  at import and in the reports of packages already imported. **Who relied on HML packages must turn `accepthmlkey` on
  in the plugin settings** (Site administration > Plugins > Activity modules > cmi5 package (SCORM Maker)).

## 0.3.0 (2026100300)

Capability defaults: unchanged. **Privacy: changed** — a package may now send learner data to a site outside Moodle
(an AU with an `https` address, see below); the privacy metadata declares it as the external location `externalau`.
Review the new setting `allowexternalau` (on by default) before upgrading a site that must keep every learner's data
inside Moodle.

- Added: an AU may live outside the package (an absolute `https` `url` in `cmi5.xml`). The package is accepted as
  "Not signed" (reason `external_au`), whatever signature the zip holds, and has no block activity report. Only `https`
  is accepted, the host must be written as a browser writes it (a domain name, an international name in its `xn--`
  form, four decimal numbers for IPv4 or the short form of IPv6), with no user name or password, at most 2048
  characters for the URL and 255 for the origin; the query follows the rules of a package AU. The launcher sends the
  learner to that address with the cmi5 parameters, and the origin is stored in the new column `auorigin`.
- Added: CORS on `lrs.php` and `fetch.php`, only for the exact origin of such an AU: no wildcard, no echo of another
  origin, `Vary: Origin`, no credentials (the token goes in `Authorization`). A preflight answers 204 only for an
  origin some AU of the site has. After the token, a request from another outside origin is refused (403
  `err_corsorigin` on the LRS; cmi5 error 2 on fetch, and the key is not spent). The token stays the authorisation.
- Changed: a token or fetch key of a package AU used with an `Origin` that is neither Moodle's nor the AU's is now
  refused (403 on the LRS, error 2 on fetch). A site reached under a host different from `$CFG->wwwroot` (an alias,
  a proxy that rewrites the host) must fix `wwwroot` or the proxy; `Origin: null` is still accepted for a package AU.
- Added: setting `allowexternalau` ("Allow AUs outside the package", on by default). Off: a package with such an AU
  is refused at import and replacement (`err_externalaudisabled`), and a restore, course import, copy or duplicate
  brings such an AU in disabled (no origin, a warning in the restore log). While it is off, the AUs outside the
  package already on the site do not launch either: the learner gets a readable message (`err_externalauoff`), and
  turning the setting on again lets them launch. Learners whose unit was already open keep it until that session
  ends.
- Added: "Back to the activity" in the player first asks the unit to close (a `postMessage` to the exact origin of the
  iframe) and leaves when the unit answers from that window and origin, or after 4 s. A SCORM Maker course closes its
  session before answering, so its deferred `failed` is sent and confirmed before `terminated` and is no longer
  refused for reaching the LRS after the session ended. A unit outside the package or of another vendor, which does
  not answer, still leaves after the timeout. Meanwhile the link is marked busy and a status message says the
  learner is leaving.
- Changed: the message of a launch refused because the AU is outside the package and the site does not allow it
  (`err_externalauoff`) now speaks to the learner; the message at import and replacement (`err_externalaudisabled`)
  still speaks to the teacher.
- Note for SCORM Maker courses **with no pass score**: from SCORM Maker 0.39.0 such a course (no `masteryScore`,
  `moveOn="Completed"`) ends with `completed` plus `scored`, no longer with `passed`. Packages exported before keep
  sending `passed` until they are exported again. Effects on the activity:
  - the unit and the course show "Completed" instead of "Passed";
  - the grade comes from `scored`, which needs this version: on 0.2.0 such a course gets **no grade**;
  - the completion rule "Satisfy the cmi5 course" is met by `completed`, as before;
  - a completion rule on a passing grade (core "Receive a passing grade") follows the grade and the grade to pass of
    the grade item, never a `passed` statement: set the grade to pass, or use "Satisfy the cmi5 course";
  - for a pass/fail result, give the course a pass score in SCORM Maker.
- Security: replacing the package of an activity with one that has an AU outside it also needs
  `mod/scormmakercmi5:addinstance` in the course (`err_externalaucapability`); any other replacement still needs only
  `moodle/course:manageactivities`, as in mod_scorm.
- Added: on restore the origin is computed again from the AU `url`, never taken from the backup, so a hand-made backup
  cannot open the CORS of the site to another origin.
- Added: learners see "This activity is hosted outside Moodle (origin)." on the activity page and in the player;
  teachers with the reports see that the package opens content outside Moodle and is treated as unsigned.
- Privacy: new external location `externalau` in the privacy metadata: the AU outside the package receives the
  account of the learner (the site URL and the Moodle user id, never the name) and the registration in the launch URL,
  and a token that reads and writes, for that registration, the statements, the State and the Agent Profile.
- Added: grade from the `scored` statement. An AU with no `masteryScore` and no passed/failed score is graded by its
  allowed `scored` statements about the AU itself (score.scaled from 0 to 1), from sessions launched in Normal mode
  only (Browse and Review never grade). The grade of a session is its latest `scored` by timestamp; across sessions
  the grading method of the activity applies as for passed/failed (highest session grade, or the grade of the most
  recent session). A passed/failed with a score still wins over `scored`, and an AU with a `masteryScore` is graded
  by passed/failed only (the SCORM Maker player sends `scored` only when there is no cut-off score).
- Added: nested blocks in the block activity report (analysis structure `v: 2`, with `pai`/`filhos`). A nested
  block is an indented row "Parent › Child" with its own numbers; the time visible of its parent includes the time the
  nested content was open, and the page says so. **A SCORM Maker package with nested blocks needs 0.3.0**: on 0.2.0
  its structure is refused, so the section "Activity inside the lessons" of that package says it is unavailable (the
  rest of the rich report works). The structure follows the contract of SCORM Maker (`contrato-dados-ricos.json`):
  at most 50 children per block, one level of nesting, depth 16, `ordem` from 0 to 2^31-1, lists and objects told
  apart.
- Changed: the download of the block activity has the nine tables of the spreadsheet of the hosted course (blocks,
  items, retention, choices, endings, scenario drop-off, cards, tour points, heat maps). Excel and ODS give one file
  with one sheet per table, numbers as number cells; CSV gives a zip with one file per table. Every text cell keeps
  the spreadsheet formula guard, and an activity name longer than the file system allows downloads too.
- Fixed: the group filter of the rich report and of the block activity report is a subquery on the group members,
  with one parameter per group instead of one per learner (a large group broke the query on SQL Server and Oracle).
  A learner in several groups is counted once.
- Security: the hourly task `cleanup_sessions` logs only the session id and the class of a failure, never its
  message (it could carry SQL or data of the row).
- Developers: the local test scripts fail when no test ran, and the Behat script passes its arguments intact.
- Upgrade (2026100300): new nullable column `auorigin` and its index on the AU table (`scormmakercmi5_au`); no data
  is migrated. The language strings changed, so the site purges its caches.

## 0.2.0 (2026100203)

- Fixed: the files of a package are stored byte for byte. Since Moodle 4.4 the EXIF remover of core
  (`file_redactor_exifremoverenabled`, on by default) rewrote every JPEG the import stored, so a signed SCORM Maker
  package with a JPEG image was verified at import and then "file changed" on every recheck (and lost the rich
  report). Two listeners of the hook `before_file_created` now keep the original bytes in the package and content
  areas of this plugin only, and only for signed packages; the rest of the site keeps the redaction.
- Privacy note: a signed package keeps the image metadata (EXIF, including any GPS position) exactly as it was signed,
  because the learner must receive the signed asset; removing that metadata before publishing is the author's
  responsibility. An unsigned package follows the EXIF remover of the site, as any other upload.
- Upgrade: the ad hoc task "Repair the JPEG images of signed packages rewritten by the EXIF remover before version
  0.2.0" gives back the signed bytes of those JPEG files from the stored zip and rechecks the package. A file changed
  after the import (through the File API or in the filedir), or a zip whose entry does not have the signed SHA-256, is
  left as it is and the package stays invalid.
- Added: activity analysis inside the lessons for SCORM Maker packages of the profile 2 (block summaries and
  decisions); new columns `smblock`, `smblocktype` and `smprofilever` in the statements table and `richstructure` in
  the package table, extracted when a statement is stored and when a package is imported (the analysis structure
  `analise/estrutura.json` is validated; a missing or invalid one never blocks the import).
- Added: download of the activity inside the lessons (one row per block: lesson, block, reached %, average visible
  time, interactions per learner, average watched %) as CSV, Excel or ODS, from the rich report of a verified package,
  with the same capability and group checks as the page and the spreadsheet formula guard of the basic report.
- Added: setting `maxanalysisperreg` (20000 by default). Block summaries and decisions of SCORM Maker packages
  (profile 2) do not count in `maxstatementsperreg`, so they never keep the completion out; above their own limit they
  are skipped without an error (one line in the PHP error log per registration) and the rest of the request is stored.
  The same verbs sent by any other content are ordinary statements.
- Security: the values of a block summary of the activity analysis are limited (media up to 86400 s, or 0 for a
  media of unknown duration with no watched segment, which then counts for reach, time and clicks only; at most 40
  watched segments inside the media, counts up to 100000, up to 500 items, a 10 x 10 heatmap, at most 8192 bytes for
  the summary extensions together). The LRS refuses a summary outside them with a 400 (`err_lrsanalysislimit`); the
  SCORM Maker player drops that one statement and goes on with the rest of its queue. A summary outside the limits
  stored before is ignored entirely by the block activity report, so one learner can no longer exhaust the memory of
  the report with a huge media duration. Statements that are not block summaries are not affected. The block
  activity report counts only the blocks, items and scenes of the verified analysis structure of the package, so its
  memory no longer grows with the number of distinct ids a learner sends. The answer of a decision is limited to 500
  characters (400 above it; a longer one stored before is ignored). The report folds each learner's media into
  per-band counters as soon as their rows end, so its memory no longer grows with the number of learners, and a video
  extension counts only on a block the structure marks as media.
- Security: more limits on the activity analysis, the same as the hosted course of SCORM Maker (contract
  `tests/fixtures/contrato-dados-ricos.json`). The duration of a block summary must be `PT<n>S` or `PT<n>.<d>S` and
  at most 86400 s (a `P1D` or `PT100000H` of one learner was added to the average time of the class), its
  milliseconds are computed from the decimal text (half up), `percentualAssistido` must be a number from 0 to 100, and
  a block summary or decision must have a timestamp (400 `err_lrsanalysislimit`) and be at most 16 KiB as a whole
  (413 `err_lrsstatementsize`; the SCORM Maker player sends at most 4 KiB). A summary outside these rules stored
  before is ignored by the report. A heatmap counts only on a block the analysis structure marks as spatial. Other
  statements are not affected.
- Upgrade (2026100202): the ad hoc task "Recompute the time of the block summaries of the activity analysis"
  recomputes the time column of the block summaries already stored with that rule (null outside it), in batches,
  after the upgrade (cron must run); only the rows of the activity analysis are read. Summaries stored by 0.1.0 get
  the same column from the task that fills their analysis columns.
- Upgrade (2026100203): texts of the block activity report changed; the new version makes the site purge its caches.
- Upgrade from 0.1.0: the statements already stored are not rewritten during the upgrade. If a SCORM Maker package
  with the activity analysis was used while the site ran 0.1.0, the ad hoc task "Fill the activity analysis columns
  of statements stored before version 0.2.0" fills their columns from the stored statement, in batches, after the
  upgrade (cron must run), and recounts the statements of those registrations without the analysis.
  The ad hoc task "Store the activity analysis structure of package versions imported before version 0.2.0" reads
  the structure (`analise/estrutura.json`) of the package versions imported by 0.1.0 from their stored files.
- Upgrade note: the upgrade builds a new index on the statements table (`scormmakercmi5_stmt`). On a large site this
  may take minutes and lock writes to that table while it runs (on PostgreSQL the index is not built concurrently):
  run it in maintenance mode.

## 0.1.0 (2026-10-01) — version 2026093007

First alpha release (`MATURITY_ALPHA`). Capability defaults and privacy: new plugin, nothing to review on upgrade.
Review `accepthmlkey` (on by default in this version, see README) before using it on a production site.

- Added: activity created from a cmi5 zip, with validation of `cmi5.xml`, refusal of AUs hosted outside the package
  and of unsafe paths, and package replacement with confirmation (R1).
- Added: SCORM Maker signature verification (Ed25519 and SHA-256 of every file): verified, not signed, invalid with
  the reason (R2).
- Added: embedded HML key `sm-hml-2026-09`, administrator settings `trustedkeys`, `revokedkids` and `accepthmlkey`
  (R3).
- Added: AU launch, one registration per learner, resume, AnyWindow and OwnWindow, Browse and Review modes, abandon
  of the previous session, AU status for the learner, AU waive by teachers (R4).
- Added: embedded xAPI LRS (Statements, State, Agent Profile, Activity Profile), cmi5 LMS statement rules, `satisfied`
  statements, all-or-nothing batches, configurable limits, read-only reporting credential through the external
  service `mod_scormmakercmi5_lrs` (R5).
- Added: gradebook grade (highest or last) and completion rule "satisfy the cmi5 course" (R6).
- Added: basic report with filters, groups, CSV and statements per learner; delete attempt (R7).
- Added: rich report for verified SCORM Maker packages, per learner and per activity (R8).
- Added: capabilities `addinstance`, `view`, `viewreports`, `manageattempts`; web service function
  `mod_scormmakercmi5_get_session_state`; scheduled task `cleanup_sessions` and ad hoc task `recheck_package`.
- Added: Privacy API (export and delete by user and by context), backup and restore, English and Brazilian
  Portuguese strings.
- Added: creation of the activity from SCORM Maker through `local_scorm_maker_import` (R9, separate plugin).
- Security: a document stored through the State API as `text/html` could be rendered as a page on the Moodle origin
  (stored XSS, reachable cross-site through the xAPI alternate request syntax). Every response of `lrs.php` and
  `fetch.php` now carries `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`; State and
  Agent Profile documents are served with `Content-Disposition: attachment`; a State `Content-Type` outside the media
  type grammar (RFC 9110 §8.3, no control character) is refused with 400 `err_lrsheader`, and one stored before this
  check is served as `application/octet-stream`. The stored type is still given back as written (xAPI 1.0.3).
- Fixed (final review): the list of the cmi5 activities of a course uses the course layout; an AU going full screen
  inside the full screen player no longer moves the focus to the player's button nor turns it off; a package larger
  than `recheckmaxmb` is queued for a background recheck at most once every `recheckminhours` (new setting, 24 by
  default) instead of on every opening of the rich report; a grade left behind by a failed gradebook update is repaired
  on the activity page and at launch; an attempt kept by a course reset (busy at the time) keeps its grade.
- Added (final review): when an attempt reaches `maxstatementsperreg`, the learner sees a message on the activity page
  and in the player, and the basic report flags "Statement limit reached".
