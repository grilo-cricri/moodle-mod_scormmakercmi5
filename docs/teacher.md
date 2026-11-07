# Teacher guide

You need `mod/scormmakercmi5:addinstance` to add the activity, `viewreports` to see learners' results and
`manageattempts` to waive a unit or delete an attempt (editing teachers and managers have these by default;
non-editing teachers can view reports).

## Add a cmi5 course

1. In the course, turn editing on and choose Add an activity or resource > cmi5 package (SCORM Maker).
2. Upload the cmi5 zip. Name and description come from the package; you can change them.
3. Set the grading method (Highest grade or Last grade), whether learners can use Browse, and whether Review is
   always offered.
4. Under Activity completion, choose "the learner satisfies the cmi5 course" if you want automatic completion.
5. Save. If the package is refused, the message says why (invalid `cmi5.xml`, a missing file, an unsafe path, or an AU
   outside the package that is not `https` or that the site does not allow). Nothing is created in that case.

Packages exported from SCORM Maker show "SCORM Maker verified", "Not signed by SCORM Maker" or "SCORM Maker signature
invalid" (with the reason). Only verified packages have the rich report.

An AU may live outside the package: an absolute `https` address in `cmi5.xml`. The activity then opens that site,
which receives the learner's Moodle user id and registration and records their progress in Moodle. Such a package
shows "Not signed by SCORM Maker" and has no rich report; learners see that the activity is hosted outside Moodle. Such
packages are refused unless the administrator turned on `allowexternalau` (off by default).

A package whose AU path in `cmi5.xml` contains an encoded slash (`%2F`) is accepted but the AU does not load (404).
SCORM Maker never writes such paths; a third-party package that does must be fixed at the source.

## Grades

The grade of an AU is the score of its passed/failed statement. An AU with no pass score (`masteryScore`) that never
sends a score with passed/failed (a SCORM Maker course with no pass score, for example) is graded by its `scored`
statements; an AU with a pass score is graded by passed/failed only: in each session the latest
one counts, and across sessions the grading method of the activity applies (highest, or the most recent session).
Sessions opened in Browse or Review never change the grade.

## Replace the package

Edit the activity settings and upload a new zip. If learners already started, tick the confirmation: attempts in
progress stay on the previous package until they finish, and new ones use the new one. A new package with an AU
outside it also needs the right to add this activity (`mod/scormmakercmi5:addinstance`) in the course.

## Follow your learners

Open the activity and use the report. You see each learner's course status, status of each AU, grade, total time,
sessions, first and last access. Filter by status and group (in separate-groups mode you only see your group) and
download the CSV. "View statements" lists what the course recorded for one learner (verb, object, result, date), which
helps when something seems wrong.

For a verified SCORM Maker package, the **rich report** adds, per learner, the lessons visited, time per lesson,
progress and the answer to each question, and per activity the average time per lesson, where learners stop and the hit
rate of each question. Data is kept apart by package version. If the signature fails when you open it, you get the
basic report and the reason.

## Waive a unit or delete an attempt

- **Waive assignable unit** (on the learner's detail): choose the unit and a reason, confirm. The unit counts as
  satisfied and the course is reevaluated.
- **Delete attempt**: on a confirmation page; it removes everything recorded for that learner in this activity
  (unit status, sessions, statements, State) and cannot be undone except from a backup. Use it to let a learner start
  over.

## When a learner reaches the statement limit

Each attempt stores at most `maxstatementsperreg` statements (10000 by default; the administrator sets it). When an
attempt reaches it, the course still opens but records nothing more, so it can no longer become satisfied. The learner
sees a message on the activity page and in the player, and the report shows "Statement limit reached" next to the
course status. **Delete attempt** resets it: the learner starts over with an empty attempt.

## Activity inside the lessons

Below the rich report of an activity, the section **Activity inside the lessons** shows what the class did in each
block of each lesson, for the package version you selected:

- **Reach**: how many learners reached the block (their player sent a summary for it), as a number and a percentage
  of the learners with activity. The player sends a summary when the block was on screen, or the learner interacted
  with it; a block with nothing to interact with needs at least one second on screen.
- **Average time visible**: the time the block was on screen, averaged over the learners who reached it.
- **Interactions per learner**: clicks and openings in the block, averaged over the learners who reached it.
- **Items seen or opened**: for blocks with tabs, steps, accordions, galleries and similar, how many learners saw or
  opened each item. The item already on screen when the block became visible (the first tab, the first step, the
  first photo of a slider, a marker that paused the interactive video) counts as seen without being opened.
- **Media retention**: for video and audio files and the interactive video, the share of learners watching each
  5-second band, how many skipped it and how many stopped there, and the average percentage watched. A video from a
  link (YouTube, Vimeo) only counts its visible time.
- **Clicks on the image**: for blocks where the learner clicks on an image (and the virtual tour, one map per scene),
  a 10 x 10 grid laid over the image of the package, with the number of clicks per cell in a table.
- **Choices per scene**: for the branching scenario, how many learners took each choice, the endings reached, and the
  scene where learners stopped without reaching an ending.
- **Self-assessment per card**: for flashcards, how many learners answered "Knew" and "Didn't know" for each
  card, hardest first.
- **Hotspots discovered**: for the virtual tour, how many learners found each point.

Only class totals are shown: no sequence of steps and no click position of any one learner. On a learner's page of
the rich report there is only their summary: blocks reached, time visible, media watched and their last decisions.

The section only appears for a package that is **SCORM Maker verified** when you open the report, exported by a
SCORM Maker version whose player sends the activity analysis (profile 2) and used on version 0.2.0 or later of this
plugin. For an older package the section says the activity is unavailable; the rest of the rich report works as
before. Groups apply as in the rest of the report: in separate-groups mode you only see your groups.

To download the activity, use **Download the activity of the blocks** in the section and choose CSV, Excel or ODS.
The download has the same nine tables as the spreadsheet of the hosted course: blocks (lesson, block, reached %,
average time visible in seconds, interactions per learner, average watched %), items, retention, choices, endings,
scenario drop-off, cards, tour points and heat maps (cell counts, row and column from 1). Excel and ODS give one file
with one sheet per table; CSV gives a zip with one file per table. It has class totals only and follows the group you
selected.

A nested block (indented, "Parent › Child") keeps its own numbers; the time visible of its parent includes the time
the nested content was open.

### Images of signed packages

The images of a package whose signature is verified are stored exactly as they were signed, so their metadata (EXIF,
including any GPS position recorded by the camera) is kept, even when the site removes EXIF from other uploads. Remove
that metadata from your images before you publish the course. A package that is not signed, or whose signature is
invalid, follows the EXIF setting of the site, like any other upload.
