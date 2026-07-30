# Production Acceptance — Story Bible / Character Bible / Continuity Pipeline

Tracks readiness for the AI Director pipeline (Story Bible → scene/character binding →
shot enrichment → continuity validation) to go to production. Updated as each criterion is
verified — do not treat any unchecked item as "probably fine."

## Technical acceptance (Phases 1-4) — ✅ DONE

- [x] **Phase 1** — Retry/backoff de-duplication, guaranteed `api_usages` logging, explicit
      `enrichment_status`/`prompt_version`/`validation_status`/`enriched_at` on shots.
      Fault-injection tests pass (`EnrichVideoShotsJobFaultInjectionTest`).
- [x] **Phase 2** — `AnalyzeStoryDirectionJob` + `StoryBibleAnalysisService`: map-reduce
      extraction, claim confidence/evidence invariant, versioned draft→validate→atomic-activate
      story bible, alias resolution, no-data-loss regenerate. Unit tests pass
      (`AnalyzeStoryDirectionJobTest`).
- [x] **Phase 2 live smoke test** — real OpenAI API, both `story_bible_batch_extraction` and
      `story_bible_reduce` json_schema calls accepted; a real data-quality bug (generic
      cultural groups persisted as characters) found and fixed, with a regression test
      (`StoryBibleSmokeTestFixtureRegressionTest`) reproducing it deterministically.
- [x] **Phase 3** — Scene↔bible/timeline/location binding, multi-character scene pivot,
      baseline-trait fallback, `story_bible_version_used`/`scene_direction_version` staleness,
      `enrichShotsChunk()`/`buildImagePrompt()` proven (via captured request body) to actually
      contain resolved Story Bible data. Tests pass (`StoryDirectionPromptTest`).
- [x] **Phase 4** — `ValidateStoryContinuityJob` + `ContinuityValidationService`: deterministic
      checks separated from AI semantic checks, severity/action derivation table, fingerprint
      upsert (no duplicate issues), selective regeneration, resolve-only-after-confirmed-
      revalidation, per-scene resilience. 13/13 required tests pass
      (`ContinuityValidationTest`).
- [x] **Full automated test suite is 100% green** — 47/47 tests pass, zero excused/
      "pre-existing" failures (`ExampleTest` fixed to assert the app's actual root-route
      redirect behavior instead of a stale default-Laravel assertion).

## Production acceptance — ⏳ IN PROGRESS

Everything below requires REAL content or a REAL OpenAI call — none of it is satisfied by
the synthetic-fixture tests above, which only prove pipeline *mechanics*, not output
*quality* on genuine narration.

- [x] **Real-content import mechanism ready.** `php artisan audiobook:import-chapters` used
      to import a real, full book: **AudioBook #21 — "Đại Đường Tây Vực Ký – Hành Trình Có
      Thật Vĩ Đại Hơn Cả Tây Du Ký"** (3 chapters, 30,854 chars).
- [x] **Story Bible generated on real content (AudioBook #21).** Required 3 real bug fixes
      surfaced only by real-book scale (see below) — v9 is active: 1 timeline, 1 location,
      10 characters.
  - Bug found live: single-call reduce made `gpt-5-mini`'s hidden reasoning consume the
    ENTIRE completion budget regardless of `reasoning_effort` (`reasoning_tokens ===
    max_tokens` exactly, across 4 different effort/budget combinations). Fixed by splitting
    the reduce into two calls (world/timelines/locations, then characters/phases separately)
    — mirrors the map-reduce pattern already used elsewhere in this pipeline.
  - Bug found live: default HTTP client timeout (360s) too short for a large completion —
    raised to 900s for this call (background job, not a web request).
  - Bug found live: scene-context roster prompt displayed timeline/location names with
    parenthetical annotations (time markers, alias hints) for readability, and the model
    echoed the whole decorated string back as the "name," which failed exact-match
    resolution against the bible's bare canonical names — both real scenes showed
    `unresolved` bindings. Fixed with a deterministic (non-fuzzy) resolver fallback that also
    tries matching after stripping one trailing `(...)` group, plus a tightened prompt
    instruction. Re-verified live: both scenes now resolve `status: resolved`.
- [x] **Live Phase 4 smoke test on real content (AudioBook #21, 2 scenes / 121 shots).**
      Used a real, naturally-occurring continuity error the user spotted during review
      (stronger evidence than an artificially injected one):
  - Real error: adjacent shots #15/#16 in scene #1 (same continuous moment — a guide's
    dagger attack at night, foiled by Huyền Trang calmly chanting) showed inconsistent
    setting (desert-with-camels vs. an indoor stone chamber) and inconsistent wardrobe for
    the guide character (civilian guide clothes vs. soldier armor). Root cause: the guide
    character ("Thạch Bàn Đà") exists in the Story Bible but was never bound to this scene,
    because the scene's retold text refers to him only as "gã này" (this guy), giving the
    binder no name to match against the roster — a real, documented blind spot, not yet
    fixed (noted below as a follow-up item).
  - **Validator did NOT catch this specific issue** — an honest negative finding. It DID
    catch 7 other issues in the same validation pass: 6 false positives (3 modern
    host-narration intro shots flagged as anachronistic against the scene's resolved
    historical-Chang'an binding — the scene-binding system doesn't yet distinguish
    non-diegetic host shots from in-story shots) and 1 genuine issue (shot #46: narration
    describing a robbery during travel, image inconsistently referencing Chang'an).
  - Triaged: accepted the 6 false positives via the Accept mechanism; selectively
    regenerated + revalidated the 1 genuine issue.
  - Selective regeneration touched **only 1 of 9 shot chunks**, only the 1 flagged shot —
    confirmed via chunk_indices in the response, not a blanket re-run.
  - Real revalidation confirmed the issue `resolved` (`validation_status: valid`,
    `continuity_error: []`) — caveat: the regenerated prompt still references Chang'an since
    the narration itself never names a location; a genuinely ambiguous case, not a clean-cut
    fix, worth a human look.
  - **Metrics**: model `gpt-5-mini`, strict json_schema accepted on every call. Validation: 2
    calls (84.86s/$0.0275 + 45.49s/$0.0184, one per scene). Selective regen: 1 call
    (22.85s/$0.0097). Revalidation: 1 call (43.27s/$0.0242). Total: 4 calls, ~196s, ≈$0.080.
- [x] **Full end-to-end run on a real, full-length audiobook.** AudioBook #21: Story Bible →
      scene binding → shot enrichment → continuity validation all completed on real content
      (121 shots across 2 scenes).
- [ ] **Full report exported**, covering: Story Bible (genre/timeline/etc), timelines,
      locations, characters, phases; scene binding resolved/unresolved counts; total shots;
      validation status counts; shots/chunks regenerated; remaining open issues; tokens/cost/
      duration broken down per phase (2/3/4).
- [ ] **Sample review export**: first 20 shots, middle 20 shots, last 20 shots, and every
      shot ever regenerated — each with narration, scene context, character phase,
      `image_request`, final image-generation prompt, issues, and final status.
- [ ] **Content-quality sign-off** — a human reviews the sample export above and confirms
      the Story Bible / Character Bible / continuity output is actually *correct* for the
      real book (not just structurally well-formed).

## Known follow-up items (found via real-content testing, not yet fixed)

- **Scene-to-character binding misses characters referred to only by pronoun/description**
  in the scene's retold text (not by their canonical name) — the binder has no name to match
  against the roster, so the character gets no wardrobe/appearance anchor and per-shot
  enrichment can drift inconsistently across shots depicting them (observed: a guide
  character shown in civilian clothes in one shot, soldier armor in the next).
- **Scene-level location binding doesn't handle scenes spanning multiple times/places** — a
  single scene can legitimately cover a desert ambush AND a later return to the capital; one
  location claim per scene can't represent both, causing shots for the earlier moment to be
  compared against the wrong resolved setting.
- **Scene-binding doesn't distinguish non-diegetic host/narrator shots from in-story shots**
  — intro/outro "channel host talking to camera" shots get compared against the scene's
  historical setting binding and flagged as anachronistic by Phase 4, when they were never
  meant to depict the story world at all.
- **Regenerated image/video URLs need cache-busting** (fixed this session) — the resolved
  asset path was a fixed filename per shot, so "Tạo ảnh khác" silently kept showing the
  browser-cached old image even after a real new image was generated. Fixed via a
  `?v=<updated_at>` query param on preview URLs.

## Gate

**Do not mark this pipeline production-ready until every box above is checked.** The
technical-acceptance section proves the machinery is correct; the production-acceptance
section proves it produces *good, correct output* on a real, long, real-world audiobook.
Passing only the first section is a common false-confidence trap for this kind of AI
pipeline — schema/mechanism correctness and output quality are genuinely independent
questions, and this file exists specifically so neither gets silently skipped. The known
follow-up items above are real, documented gaps — production sign-off should explicitly
accept or address them, not silently ignore them.

## Current blocker

Remaining work is the full report export, the sample review export, and human
content-quality sign-off — see the unchecked items above.
