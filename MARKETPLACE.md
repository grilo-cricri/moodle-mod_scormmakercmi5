# Moodle Plugins Directory metadata — cmi5 package (SCORM Maker)

This file contains the copy and technical metadata prepared for the Moodle Plugins Directory listing. The public
source repository is https://github.com/grilo-cricri/moodle-mod_scormmakercmi5. The screenshots, support channel and
maintainer account fields must be completed by the publisher before submission.

## Listing fields

| Field | Value |
|---|---|
| Display name | cmi5 package (SCORM Maker) |
| Component | `mod_scormmakercmi5` |
| Plugin type | Activity module |
| Release | 0.4.0 |
| Build | `2026110600` |
| Maturity | Stable |
| License | GNU GPL v3 or later (full text in `LICENSE`); `pix/monologo.svg` and `pix/icon.svg` are original ScormMaker.com.br artwork licensed under GPL v3 or later |
| Business model | Select Free or Paid in the submission form |

### Short description

Launches cmi5 packages from any authoring tool, records progress in a learning record store built into Moodle and
reports to teachers.

### Full description

cmi5 package (SCORM Maker) is a Moodle activity for cmi5 courses. A teacher uploads a cmi5 zip; the plugin reads its
assignable units, launches them for each learner, stores what the course reports in an xAPI learning record store
(LRS) that is part of the plugin, applies the cmi5 LMS rules, grades the activity and reports to the teacher.

Packages exported by SCORM Maker carry an Ed25519 signature. The plugin verifies it: a valid signature earns the
"SCORM Maker verified" mark and a rich report (lessons visited, time per lesson, answer to each question). A package
from any other tool, or with a missing or invalid signature, is not refused: it works as plain cmi5, is marked "not
signed" or "invalid", and gets the basic report.

The plugin needs no external service. The cmi5 LRS is built in, nothing is sent to a third party, and no account is
needed anywhere. The only data that leaves Moodle goes to the site of an assignable unit hosted outside the package,
when the package points to one, and only if the administrator turned on the setting `allowexternalau`, which is **off by
default**. When on, that host receives the learner's actor (site URL and Moodle user id), the registration and a token
that reads and writes that registration's statements, State and Agent Profile. Only new installs get the default:
an upgraded site keeps what it had (the upgrade writes `1` where no value was stored, as the old default was on).

## Features

- Create the activity from a cmi5 zip; invalid or unsafe packages are refused with the reason.
- Replace the package of an activity that already has learners, with confirmation.
- Built-in xAPI LRS (Statements, State, Agent Profile, Activity Profile) with the cmi5 LMS rules.
- Ed25519 signature verification of SCORM Maker packages; trusted and revoked keys managed by the administrator.
- Basic report for every package; rich report and activity-inside-the-lessons analysis for verified packages.
- Waive a unit for a learner, delete a learner's attempt, group mode, CSV, Excel and ODS downloads.
- Moodle gradebook, completion, backup and restore, Privacy API, English and Brazilian Portuguese.

## Requirements and compatibility

- Minimum Moodle requirement: 4.5 (`$plugin->requires = 2024100700`).
- Declared supported Moodle branches: 4.5 through 5.3 (`$plugin->supported = [405, 503]`).
- PHP with the `sodium` extension (already required by Moodle).
- The site setting `slasharguments` must be on, and the web server must pass the `Authorization` header to PHP.
- No dependency on other plugins.

## Optional companion plugin

[`local_scorm_maker_import`](https://github.com/grilo-cricri/moodle-local_scorm_maker_import) is a separate, optional
web-service plugin that lets SCORM Maker create the activity in a course through an authenticated web service. This
plugin works fully without it.

## Installation and setup

1. Install the ZIP as `mod/scormmakercmi5` (`public/mod/scormmakercmi5` on Moodle 5.1 and later).
2. Visit Site administration > Notifications, or run `php admin/cli/upgrade.php --non-interactive`.
3. Check `slasharguments` and the `Authorization` header (see `docs/admin.md`).
4. Review the settings under Site administration > Plugins > Activity modules > cmi5 package (SCORM Maker).

## Capabilities

| Capability | Default roles |
|---|---|
| `mod/scormmakercmi5:addinstance` | Teacher (editingteacher), Manager |
| `mod/scormmakercmi5:view` | Student, Non-editing teacher, Teacher, Manager |
| `mod/scormmakercmi5:viewreports` | Non-editing teacher, Teacher, Manager |
| `mod/scormmakercmi5:manageattempts` | Teacher, Manager |

## Security and privacy notes

The package runs JavaScript in the Moodle origin, as SCORM does, so `addinstance` carries the XSS risk flag. Every LRS
request needs a one-time fetch key or a session token, and requests from a foreign `Origin` are refused. Limits on
request size, statement size, batch size, package entries and uncompressed size are administrator settings.
The AU runs in an iframe without `sandbox`, as in `mod_scorm`, and a learner can forge their own statements (inherent to
cmi5/xAPI), so treat results as self-reported; the README has "Security notes for administrators". Opening the rich
report writes nothing; the daily task `recheck_current_packages` records the signature recheck.

The plugin stores personal data of learners (registration, unit status, sessions, statements, State, learner
preferences) inside Moodle and implements the Privacy API for export and deletion. See the README for the full list.

## Release notes for 0.4.0

Preparation for the Moodle Plugins Directory, see `CHANGES.md`:

- Added: `LICENSE` file (GNU GPL v3) in the plugin root.
- Added: size limits on every path that takes bytes from outside, with tests.
- Changed: default activity names come from language strings.
- Changed: stylesheet rules scoped under the module pages; links point to the `moodle-mod_scormmakercmi5` repository;
  comments and PHPDoc in English.
- Security: deleting a user account removes the learner's data of every activity (registrations, sessions, statements,
  State, Agent Profile); opening the rich report no longer writes (new daily task `recheck_current_packages`).
- Docs: README in English and `README.pt_br.md`; screenshots in `docs/screenshots/`.
- CI: moodle-plugin-ci on Moodle 4.5 to 5.3, PostgreSQL and MariaDB, with phpmd.

## Publisher-supplied Marketplace fields

| Marketplace field | Required action |
|---|---|
| Public source repository | https://github.com/grilo-cricri/moodle-mod_scormmakercmi5 |
| Issue tracker | https://github.com/grilo-cricri/moodle-mod_scormmakercmi5/issues (GitHub Issues enabled) |
| Documentation URL | https://github.com/grilo-cricri/moodle-mod_scormmakercmi5/blob/main/README.md |
| Support/discussion URL | Provide a public support or discussion channel. |
| Screenshots | Upload the PNGs in `docs/screenshots/` (taken on a Moodle 4.5 test site): activity settings, learner launch, teacher report, course page. |
| Test cmi5 package | Provide a public cmi5 zip for reviewers (a SCORM Maker export, or any cmi5 package). |
| Maintainer/provider details | Complete the publisher and support details in the Marketplace account. |
| Icon licensing | Confirm that the icons are original ScormMaker.com.br artwork licensed under GPL v3 or later. |

## Submission package

Submit the ZIP whose root directory is `scormmakercmi5/`.
